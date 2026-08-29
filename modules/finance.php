<?php
require_once __DIR__.'/../core/OperationalSummary.php';
require_once __DIR__.'/../core/Payroll.php';

$view=(string)($_GET['view']??'summary');
$allowedViews=['summary','purchases','expenses','store_expenses','payments','settings','payroll','profit','cashflow'];
if(!in_array($view,$allowedViews,true)) $view='summary';
$month=(string)($_GET['month']??date('Y-m'));
if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=date('Y-m');
$start=$month.'-01';
$end=date('Y-m-d',strtotime($start.' +1 month'));
$user=bo_user()??[];
$userId=(int)($user['id']??0);
$canWrite=in_array(strtolower((string)($user['role_key']??'')),['owner','admin'],true);

function bo_fin_table_exists(string $table): bool {
  static $cache=[];
  if(array_key_exists($table,$cache)) return $cache[$table];
  try{
    $st=bo_exec('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);
    return $cache[$table]=((int)$st->fetchColumn()>0);
  }catch(Throwable $e){ return $cache[$table]=false; }
}
function bo_fin_uuid(): string {
  $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s',[...str_split(bin2hex($d),4)]);
}
function bo_fin_token(): string {
  bo_session_start();
  if(empty($_SESSION['bo_fin_csrf'])) $_SESSION['bo_fin_csrf']=bin2hex(random_bytes(24));
  return (string)$_SESSION['bo_fin_csrf'];
}
function bo_fin_check_token(): void {
  bo_session_start();
  $sent=(string)($_POST['csrf']??'');
  if($sent==='' || empty($_SESSION['bo_fin_csrf']) || !hash_equals((string)$_SESSION['bo_fin_csrf'],$sent)) throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman dan coba kembali.');
}
function bo_fin_status_label(string $s): string { return match($s){'draft'=>'Draft','submitted'=>'Diajukan','approved'=>'Disetujui','paid'=>'Dibayar','rejected'=>'Ditolak','cancelled'=>'Dibatalkan',default=>$s}; }
function bo_fin_number(string $prefix): string { return $prefix.'-'.date('Ymd-His').'-'.random_int(100,999); }
function bo_fin_audit(string $entity,int $id,string $action,array $payload,int $uid): void {
  if(!bo_fin_table_exists('bo_finance_audit_logs')) return;
  bo_exec('INSERT INTO bo_finance_audit_logs(entity_type,entity_id,action_key,payload_json,acted_by) VALUES(?,?,?,?,?)',[$entity,$id,$action,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$uid]);
}
function bo_fin_redirect(string $view,string $month,string $notice='',string $type='ok'): never {
  $q=['p'=>'finance','view'=>$view,'month'=>$month];
  if($notice!==''){$q['notice']=$notice;$q['notice_type']=$type;}
  header('Location: ?'.http_build_query($q));exit;
}
function bo_fin_post(string $key,string $default=''): string { return trim((string)($_POST[$key]??$default)); }
function bo_fin_rows(array $units,string $key): array {
  $out=[];
  foreach($units as $unit){ if(!$unit['ok']) continue; foreach((array)($unit['data'][$key]??[]) as $r){ if(!is_array($r))continue;$r['_unit']=$unit['name'];$out[]=$r; } }
  usort($out,fn($a,$b)=>strcmp((string)($b['date']??''),(string)($a['date']??'')));
  return $out;
}
function bo_fin_local_totals(string $start,string $end): array {
  $out=['expenses'=>0.0,'requests_pending'=>0.0,'requests_paid'=>0.0];
  if(bo_fin_table_exists('bo_expenses')){
    $st=bo_exec("SELECT COALESCE(SUM(amount),0) FROM bo_expenses WHERE expense_date>=? AND expense_date<? AND status IN ('approved','paid') AND deleted_at IS NULL",[$start,$end]);$out['expenses']=(float)$st->fetchColumn();
  }
  if(bo_fin_table_exists('bo_payment_requests')){
    $st=bo_exec("SELECT COALESCE(SUM(CASE WHEN status IN ('draft','submitted','approved') THEN amount ELSE 0 END),0) pending,COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid FROM bo_payment_requests WHERE request_date>=? AND request_date<? AND deleted_at IS NULL",[$start,$end]);
    $r=$st->fetch()?:[];$out['requests_pending']=(float)($r['pending']??0);$out['requests_paid']=(float)($r['paid']??0);
  }
  return $out;
}

$schemaReady=bo_fin_table_exists('bo_expense_categories')&&bo_fin_table_exists('bo_expenses')&&bo_fin_table_exists('bo_payment_requests');
$notice=(string)($_GET['notice']??'');$noticeType=(string)($_GET['notice_type']??'ok');

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$canWrite) bo_fin_redirect($view,$month,'Akses tulis hanya untuk Owner atau Admin.','err');
  if(!$schemaReady) bo_fin_redirect($view,$month,'SQL Back Office belum diimpor.','err');
  try{
    bo_fin_check_token();$action=bo_fin_post('action');
    if($action==='save_payroll_unit'){
      $unitType=bo_fin_post('unit_type');$systemKey=bo_fin_post('system_key');$unitName=bo_fin_post('unit_name');$pct=(float)str_replace(',','.',bo_fin_post('pool_percentage','0'));
      if(!in_array($unitType,['store','dapur','backoffice'],true)||$systemKey==='') throw new RuntimeException('Unit payroll tidak valid.');
      if($pct<0||$pct>100) throw new RuntimeException('Persentase pool harus 0–100%.');
      bo_payroll_seed_period($month);
      bo_exec("INSERT INTO bo_payroll_unit_period_settings(payroll_month,unit_type,system_key,unit_name,pool_percentage,is_active,created_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE unit_name=VALUES(unit_name),pool_percentage=VALUES(pool_percentage),is_active=VALUES(is_active),seeded_from_month=NULL,updated_at=NOW()",[$month,$unitType,$systemKey,$unitName!==''?$unitName:$systemKey,$pct,isset($_POST['is_active'])?1:0]);
      bo_fin_redirect('payroll',$month,'Persentase payroll unit disimpan.');
    }
    if($action==='save_payroll_employee'){
      $assignmentId=(int)($_POST['assignment_id']??0);$boUserId=(int)($_POST['bo_user_id']??0);
      if($assignmentId<=0&&$boUserId<=0) throw new RuntimeException('Pegawai payroll tidak valid.');
      $base=(float)str_replace(',','.',bo_fin_post('base_salary','0'));
      $overtime=(float)str_replace(',','.',bo_fin_post('overtime','0'));
      $punishment=(float)str_replace(',','.',bo_fin_post('punishment','0'));
      $kasbon=(float)str_replace(',','.',bo_fin_post('kasbon','0'));
      if(min($base,$overtime,$punishment,$kasbon)<0) throw new RuntimeException('Komponen payroll tidak boleh negatif.');
      bo_payroll_seed_period($month);
      bo_exec("INSERT INTO bo_payroll_employee_period_settings(payroll_month,assignment_id,bo_user_id,base_salary,overtime,punishment,kasbon,is_eligible,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE base_salary=VALUES(base_salary),overtime=VALUES(overtime),punishment=VALUES(punishment),kasbon=VALUES(kasbon),is_eligible=VALUES(is_eligible),notes=VALUES(notes),seeded_from_month=NULL,updated_at=NOW()",[$month,$assignmentId>0?$assignmentId:null,$boUserId>0?$boUserId:null,$base,$overtime,$punishment,$kasbon,isset($_POST['is_eligible'])?1:0,bo_fin_post('notes')]);
      if($assignmentId>0 && bo_fin_post('unit_type')==='dapur'){
        $role=strtolower(bo_fin_post('role_key'));$systemKey=bo_fin_post('system_key');$target=(float)str_replace(',','.',bo_fin_post('kpi_target','100'));
        if($role!==''&&$systemKey!==''&&$target>0) bo_exec("INSERT INTO bo_payroll_kpi_target_period_settings(payroll_month,unit_type,system_key,role_key,target_value,created_at) VALUES(?,'dapur',?,?,?,NOW()) ON DUPLICATE KEY UPDATE target_value=VALUES(target_value),seeded_from_month=NULL,updated_at=NOW()",[$month,$systemKey,$role,$target]);
      }
      bo_fin_redirect('payroll',$month,'Komponen payroll pegawai disimpan.');
    }
    if($action==='recalculate_payroll'){
      bo_payroll_save_run($month,$userId,false);bo_fin_redirect('payroll',$month,'Draft payroll dihitung ulang dari omset dan KPI terbaru.');
    }
    if($action==='finalize_payroll'){
      bo_payroll_save_run($month,$userId,true);bo_fin_redirect('payroll',$month,'Payroll difinalkan dan snapshot dikunci.');
    }
    if($action==='mark_payroll_paid'){
      $run=bo_exec("SELECT * FROM bo_payroll_runs WHERE payroll_month=? LIMIT 1",[$month])->fetch();if(!$run||$run['status']!=='final')throw new RuntimeException('Payroll harus berstatus Final sebelum ditandai Dibayar.');
      bo_exec("UPDATE bo_payroll_runs SET status='paid',paid_by=?,paid_at=NOW(),updated_by=?,updated_at=NOW() WHERE id=?",[$userId,$userId,(int)$run['id']]);bo_fin_redirect('payroll',$month,'Payroll ditandai sudah dibayar.');
    }
    if($action==='save_category'){
      $id=(int)($_POST['id']??0);$code=strtoupper(preg_replace('/[^A-Z0-9\-_]/','-',bo_fin_post('category_code')));$name=bo_fin_post('category_name');
      if($name==='') throw new RuntimeException('Nama jenis pengeluaran wajib diisi.');
      if($code==='') $code='CUSTOM-'.date('YmdHis');
      $p=[$code,$name,bo_fin_post('group_name'),bo_fin_post('description'),isset($_POST['requires_approval'])?1:0,isset($_POST['requires_evidence'])?1:0,(int)($_POST['sort_order']??0)];
      if($id>0){$p[]=$id;bo_exec('UPDATE bo_expense_categories SET category_code=?,category_name=?,group_name=?,description=?,requires_approval=?,requires_evidence=?,sort_order=?,updated_at=NOW() WHERE id=?',$p);}else{bo_exec('INSERT INTO bo_expense_categories(record_uuid,category_code,category_name,group_name,description,requires_approval,requires_evidence,sort_order,is_active,created_by) VALUES(?,?,?,?,?,?,?,?,1,?)',array_merge([bo_fin_uuid()],$p,[$userId]));}
      bo_fin_redirect('settings',$month,'Jenis pengeluaran disimpan.');
    }
    if($action==='toggle_category'){
      $id=(int)($_POST['id']??0);$active=(int)($_POST['active']??0)===1?1:0;bo_exec('UPDATE bo_expense_categories SET is_active=?,updated_at=NOW() WHERE id=?',[$active,$id]);bo_fin_redirect('settings',$month,'Status jenis pengeluaran diperbarui.');
    }
    if($action==='save_expense'){
      $categoryId=(int)($_POST['category_id']??0);$cat=bo_exec('SELECT * FROM bo_expense_categories WHERE id=? AND is_active=1 LIMIT 1',[$categoryId])->fetch();
      if(!$cat) throw new RuntimeException('Jenis pengeluaran wajib dipilih.');
      $title=bo_fin_post('title');$amount=(float)str_replace(',','.',bo_fin_post('amount','0'));$status=bo_fin_post('status','paid');
      if($title===''||$amount<=0) throw new RuntimeException('Judul dan nominal wajib diisi.');
      if(($cat['category_code']??'')==='OTHER'&&bo_fin_post('description')==='') throw new RuntimeException('Kategori Lain-lain memerlukan uraian rinci.');
      if(!in_array($status,['draft','submitted','approved','paid'],true)) $status='paid';
      $now=date('Y-m-d H:i:s');$expenseNo=bo_fin_number('BOEXP');
      bo_exec("INSERT INTO bo_expenses(record_uuid,expense_no,expense_date,category_id,category_name_snapshot,title,description,amount,vendor_name,payment_method,reference_no,evidence_reference,status,due_date,cost_center_type,cost_center_key,approved_by,approved_at,paid_by,paid_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[
        bo_fin_uuid(),$expenseNo,bo_fin_post('expense_date',date('Y-m-d')),$categoryId,$cat['category_name'],$title,bo_fin_post('description'),$amount,bo_fin_post('vendor_name'),bo_fin_post('payment_method'),bo_fin_post('reference_no'),bo_fin_post('evidence_reference'),$status,bo_fin_post('due_date')?:null,bo_fin_post('cost_center_type','backoffice'),bo_fin_post('cost_center_key')?:null,in_array($status,['approved','paid'],true)?$userId:null,in_array($status,['approved','paid'],true)?$now:null,$status==='paid'?$userId:null,$status==='paid'?$now:null,$userId
      ]);
      $id=(int)bo_db()->lastInsertId();bo_fin_audit('expense',$id,'created',['expense_no'=>$expenseNo,'amount'=>$amount,'status'=>$status],$userId);bo_fin_redirect('expenses',$month,'Pengeluaran Back Office disimpan.');
    }
    if($action==='save_payment_request'){
      $categoryId=(int)($_POST['category_id']??0);$cat=bo_exec('SELECT * FROM bo_expense_categories WHERE id=? AND is_active=1 LIMIT 1',[$categoryId])->fetch();
      if(!$cat) throw new RuntimeException('Jenis pengeluaran wajib dipilih.');
      $title=bo_fin_post('title');$amount=(float)str_replace(',','.',bo_fin_post('amount','0'));if($title===''||$amount<=0)throw new RuntimeException('Judul dan nominal wajib diisi.');if(($cat['category_code']??'')==='OTHER'&&bo_fin_post('description')==='')throw new RuntimeException('Kategori Lain-lain memerlukan uraian rinci.');
      $requestNo=bo_fin_number('BOPAY');
      bo_exec("INSERT INTO bo_payment_requests(record_uuid,request_no,request_date,source_type,source_key,category_id,category_name_snapshot,title,description,amount,vendor_name,due_date,reference_no,evidence_reference,status,requested_by) VALUES(?,?,?,'backoffice','backoffice',?,?,?,?,?,?,?,?,?,'submitted',?)",[
        bo_fin_uuid(),$requestNo,bo_fin_post('request_date',date('Y-m-d')),$categoryId,$cat['category_name'],$title,bo_fin_post('description'),$amount,bo_fin_post('vendor_name'),bo_fin_post('due_date')?:null,bo_fin_post('reference_no'),bo_fin_post('evidence_reference'),$userId
      ]);
      $id=(int)bo_db()->lastInsertId();bo_fin_audit('payment_request',$id,'submitted',['request_no'=>$requestNo,'amount'=>$amount],$userId);bo_fin_redirect('payments',$month,'Permintaan pembayaran diajukan.');
    }
    if($action==='payment_status'){
      $id=(int)($_POST['id']??0);$new=bo_fin_post('new_status');if(!in_array($new,['approved','paid','rejected','cancelled'],true))throw new RuntimeException('Status tidak valid.');
      bo_db()->beginTransaction();$r=bo_exec('SELECT * FROM bo_payment_requests WHERE id=? FOR UPDATE',[$id])->fetch();if(!$r)throw new RuntimeException('Permintaan tidak ditemukan.');
      $linked=(int)($r['linked_expense_id']??0);$now=date('Y-m-d H:i:s');
      if($new==='paid'&&$linked<=0){$eno=bo_fin_number('BOEXP');bo_exec("INSERT INTO bo_expenses(record_uuid,expense_no,expense_date,category_id,category_name_snapshot,title,description,amount,vendor_name,reference_no,evidence_reference,status,cost_center_type,cost_center_key,approved_by,approved_at,paid_by,paid_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,'paid','backoffice','backoffice',?,?,?,?,?)",[bo_fin_uuid(),$eno,date('Y-m-d'),$r['category_id'],$r['category_name_snapshot'],$r['title'],$r['description'],$r['amount'],$r['vendor_name'],$r['reference_no'],$r['evidence_reference'],$userId,$now,$userId,$now,$userId]);$linked=(int)bo_db()->lastInsertId();}
      bo_exec("UPDATE bo_payment_requests SET status=?,approved_by=IF(? IN ('approved','paid'),?,approved_by),approved_at=IF(? IN ('approved','paid'),?,approved_at),paid_by=IF(?='paid',?,paid_by),paid_at=IF(?='paid',?,paid_at),linked_expense_id=IF(? > 0,?,linked_expense_id),rejection_reason=?,version_no=version_no+1,updated_at=NOW() WHERE id=?",[$new,$new,$userId,$new,$now,$new,$userId,$new,$now,$linked,$linked,bo_fin_post('reason'),$id]);
      bo_fin_audit('payment_request',$id,'status_'.$new,['old_status'=>$r['status'],'new_status'=>$new,'linked_expense_id'=>$linked],$userId);bo_db()->commit();bo_fin_redirect('payments',$month,'Status permintaan diperbarui.');
    }
    throw new RuntimeException('Aksi tidak dikenal.');
  }catch(Throwable $e){if(bo_db()->inTransaction())bo_db()->rollBack();bo_fin_redirect($view,$month,$e->getMessage(),'err');}
}

$stores=bo_ops_fetch_financials('adena',$month,true);
$kitchens=bo_ops_fetch_financials('dapur',$month,true);
$local=bo_fin_local_totals($start,$end);
$storeSales=bo_ops_sum($stores,fn($d)=>bo_ops_num($d['sales_revenue']??0));
$storePurchases=bo_ops_sum($stores,fn($d)=>bo_ops_num($d['purchase_external']??$d['purchase_total']??0));
$storeInternal=bo_ops_sum($stores,fn($d)=>bo_ops_num($d['purchase_internal']??0));
$storeExpenses=bo_ops_sum($stores,fn($d)=>bo_ops_num($d['expense_total']??0));
$kitchenPurchases=bo_ops_sum($kitchens,fn($d)=>bo_ops_num($d['purchase_total']??0));
$kitchenExpenses=bo_ops_sum($kitchens,fn($d)=>bo_ops_num($d['expense_total']??0));
$internalDistribution=bo_ops_sum($kitchens,fn($d)=>bo_ops_num($d['internal_distribution_value']??0));
$pendingRequests=bo_ops_sum($stores,fn($d)=>bo_ops_num($d['payment_request_pending']??0))+bo_ops_sum($kitchens,fn($d)=>bo_ops_num($d['payment_request_pending']??0))+$local['requests_pending'];
$consolidatedCosts=$storePurchases+$kitchenPurchases+$storeExpenses+$kitchenExpenses+$local['expenses'];
$payrollAccounting=0.0;$payrollPaidCash=0.0;if(bo_payroll_schema_ready()){try{$pr=bo_exec("SELECT status,total_take_home FROM bo_payroll_runs WHERE payroll_month=? LIMIT 1",[$month])->fetch();if($pr&&in_array((string)$pr['status'],['final','paid'],true))$payrollAccounting=(float)$pr['total_take_home'];if($pr&&$pr['status']==='paid')$payrollPaidCash=(float)$pr['total_take_home'];}catch(Throwable $e){}}
$estimatedProfit=$storeSales-$consolidatedCosts-$payrollAccounting;
$purchaseRows=array_merge(bo_fin_rows($stores,'purchases'),bo_fin_rows($kitchens,'purchases'));
$expenseRows=array_merge(bo_fin_rows($stores,'expenses'),bo_fin_rows($kitchens,'expenses'));
$requestRows=array_merge(bo_fin_rows($stores,'payment_requests'),bo_fin_rows($kitchens,'payment_requests'));
$storeExpenseRows=[];$storeExpenseErrors=[];foreach(bo_connections_by_type('adena') as $conn){$res=bo_api_request_connection($conn,'api/backoffice/expenses_list.php',['start_date'=>$start,'end_date'=>date('Y-m-d',strtotime($end.' -1 day'))]);if(empty($res['ok'])){$storeExpenseErrors[]=(string)($conn['system_name']??$conn['system_key']).': '.($res['message']??'gagal');continue;}$d=bo_ops_payload($res);foreach((array)($d['rows']??[]) as $r){$r['_unit']=bo_ops_unit_name($conn,$d);$storeExpenseRows[]=$r;}}usort($storeExpenseRows,fn($a,$b)=>strcmp((string)($b['date']??''),(string)($a['date']??'')));
$categories=$schemaReady?bo_exec('SELECT * FROM bo_expense_categories ORDER BY is_active DESC,sort_order,category_name')->fetchAll():[];
$localExpenses=$schemaReady?bo_exec('SELECT * FROM bo_expenses WHERE expense_date>=? AND expense_date<? AND deleted_at IS NULL ORDER BY id DESC',[$start,$end])->fetchAll():[];
$localRequests=$schemaReady?bo_exec('SELECT * FROM bo_payment_requests WHERE request_date>=? AND request_date<? AND deleted_at IS NULL ORDER BY id DESC',[$start,$end])->fetchAll():[];
$editCategory=null;if($schemaReady&&(int)($_GET['edit']??0)>0)$editCategory=bo_exec('SELECT * FROM bo_expense_categories WHERE id=?',[(int)$_GET['edit']])->fetch()?:null;
$csrf=bo_fin_token();
?>
<style>
.finance-tabs{display:flex;gap:7px;flex-wrap:wrap;margin:12px 0}.finance-tabs a{padding:8px 10px;border:1px solid var(--border);background:#fff;border-radius:10px}.finance-tabs a.active{background:#eaf4ff;border-color:#cfe8ff;color:#0a5ea7;font-weight:800}.finance-unit{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}.finance-pair{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #edf2f7;padding:7px 0}.finance-pair:last-child{border-bottom:0}.finance-pair span{color:var(--muted)}.finance-actions{display:flex;gap:5px;flex-wrap:wrap}.finance-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}@media(max-width:960px){.finance-form-grid{grid-template-columns:1fr}}
</style>
<div class="page-title"><div><h1>Keuangan</h1><div class="muted">Rekap toko, dapur, dan pengeluaran lokal Back Office.</div></div><form class="filters" method="get"><input type="hidden" name="p" value="finance"><input type="hidden" name="view" value="<?=e($view)?>"><div><label><?= $view==='payroll' ? 'Periode Penggajian' : 'Periode' ?></label><input type="month" name="month" value="<?=e($month)?>"></div><div><button class="btn primary">Tampilkan</button></div></form></div>
<div class="finance-tabs">
<?php foreach(['summary'=>'Ringkasan','purchases'=>'Pembelian','expenses'=>'Pengeluaran','store_expenses'=>'Pengeluaran Toko','payments'=>'Permintaan Pembayaran','settings'=>'Setting Jenis','payroll'=>'Penggajian','profit'=>'Laba Rugi','cashflow'=>'Arus Kas'] as $k=>$label): ?><a class="<?=$view===$k?'active':''?>" href="?p=finance&view=<?=$k?>&month=<?=e($month)?>"><?=e($label)?></a><?php endforeach; ?>
</div>
<?php if($notice!==''): ?><div class="alert <?=$noticeType==='err'?'danger':''?>"><?=e($notice)?></div><?php endif; ?>
<?php if(!$schemaReady): ?><div class="alert danger"><b>Struktur database Back Office belum siap.</b><br>Import <code>db/20260711_001_finance_sync_foundation.sql</code> melalui phpMyAdmin, lalu muat ulang halaman. Data lama tidak diubah oleh SQL tersebut.</div><?php endif; ?>

<?php if($view==='summary'): ?>
<div class="grid">
  <div class="card metric"><div class="label">Omset Seluruh Toko</div><div class="value"><?=money_id($storeSales)?></div><div class="sub">Pendapatan eksternal bulan <?=e($month)?></div></div>
  <div class="card metric"><div class="label">Total Pembelian Eksternal</div><div class="value"><?=money_id($storePurchases+$kitchenPurchases)?></div><div class="sub">Toko + bahan baku dapur</div></div>
  <div class="card metric"><div class="label">Total Pengeluaran</div><div class="value"><?=money_id($storeExpenses+$kitchenExpenses+$local['expenses'])?></div><div class="sub">Toko + dapur + Back Office</div></div>
  <div class="card metric"><div class="label">Estimasi Profit Operasional</div><div class="value"><?=money_id($estimatedProfit)?></div><div class="sub">Payroll Final/Paid sudah diperhitungkan; HPP persediaan final belum lengkap</div></div>
</div>
<div class="grid section">
  <div class="card metric"><div class="label">Transfer Internal Terdeteksi</div><div class="value"><?=money_id(max($storeInternal,$internalDistribution))?></div><div class="sub">Tidak dikurangkan lagi pada konsolidasi</div></div>
  <div class="card metric"><div class="label">Permintaan Pembayaran Pending</div><div class="value"><?=money_id($pendingRequests)?></div><div class="sub">Belum dianggap pengeluaran aktual</div></div>
  <div class="card metric"><div class="label">Pengeluaran Back Office</div><div class="value"><?=money_id($local['expenses'])?></div><div class="sub">Pajak, konsultan, biaya pusat, dan lainnya</div></div>
</div>
<div class="finance-unit section">
<?php foreach(array_merge($stores,$kitchens) as $u): ?><div class="card"><h3><?=e($u['name'])?></h3><?php if(!$u['ok']): ?><div class="alert danger"><?=e($u['message'])?></div><?php else: $d=$u['data']; ?><div class="finance-pair"><span>Penjualan</span><strong><?=money_id($d['sales_revenue']??0)?></strong></div><div class="finance-pair"><span>Pembelian eksternal</span><strong><?=money_id($d['purchase_external']??$d['purchase_total']??0)?></strong></div><div class="finance-pair"><span>Pengeluaran</span><strong><?=money_id($d['expense_total']??0)?></strong></div><div class="finance-pair"><span>Permintaan pending</span><strong><?=money_id($d['payment_request_pending']??0)?></strong></div><?php endif; ?></div><?php endforeach; ?>
</div>

<?php elseif($view==='purchases'): ?>
<div class="grid"><div class="card metric"><div class="label">Pembelian Toko Eksternal</div><div class="value"><?=money_id($storePurchases)?></div></div><div class="card metric"><div class="label">Pembelian Bahan Dapur</div><div class="value"><?=money_id($kitchenPurchases)?></div></div><div class="card metric"><div class="label">Pembelian Internal Toko</div><div class="value"><?=money_id($storeInternal)?></div><div class="sub">Dieliminasi dalam profit konsolidasi</div></div></div>
<div class="table-wrap section"><table><thead><tr><th>Tanggal/No</th><th>Unit</th><th>Supplier</th><th>Tipe</th><th>Status</th><th>Nominal</th><th>Internal</th></tr></thead><tbody><?php foreach($purchaseRows as $r): ?><tr><td><?=e($r['date']??'-')?><br><small><?=e($r['transaction_no']??$r['external_id']??'')?></small></td><td><?=e($r['_unit'])?></td><td><?=e($r['supplier']??'-')?></td><td><?=e($r['purchase_type']??'Pembelian')?></td><td><?=e($r['status']??'-')?></td><td><?=money_id($r['amount']??0)?></td><td><?=!empty($r['is_internal'])?'<span class="badge warn">Ya</span>':'Tidak'?></td></tr><?php endforeach; if(!$purchaseRows): ?><tr><td colspan="7">Belum ada data pembelian pada periode ini atau endpoint sumber belum tersedia.</td></tr><?php endif; ?></tbody></table></div>

<?php elseif($view==='expenses'): ?>
<?php if($canWrite&&$schemaReady): ?><div class="card"><h3>Tambah Pengeluaran Back Office</h3><form method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="save_expense"><div class="finance-form-grid"><label>Tanggal<input type="date" name="expense_date" value="<?=date('Y-m-d')?>" required></label><label>Jenis<select name="category_id" required><option value="">- pilih -</option><?php foreach($categories as $c)if((int)$c['is_active']===1): ?><option value="<?=$c['id']?>"><?=e($c['category_name'])?></option><?php endif; ?></select></label><label>Judul/Uraian Singkat<input name="title" required></label><label>Nominal<input type="number" step="0.01" min="0" name="amount" required></label><label>Vendor/Penerima<input name="vendor_name"></label><label>Metode Pembayaran<input name="payment_method"></label><label>No Referensi<input name="reference_no"></label><label>Referensi Bukti<input name="evidence_reference" placeholder="Nama file/no nota"></label><label>Jatuh Tempo<input type="date" name="due_date"></label><label>Status<select name="status"><option value="paid">Dibayar</option><option value="approved">Disetujui</option><option value="submitted">Diajukan</option><option value="draft">Draft</option></select></label><label>Beban Biaya<select name="cost_center_type"><option value="backoffice">Back Office/Perusahaan</option><option value="store">Toko tertentu</option><option value="kitchen">Dapur</option><option value="all_units">Seluruh unit</option></select></label><label>Kode Unit/Host (opsional)<input name="cost_center_key"></label></div><label>Uraian Rinci<textarea name="description"></textarea></label><button class="btn primary">Simpan Pengeluaran</button></form></div><?php endif; ?>
<div class="table-wrap section"><table><thead><tr><th>Tanggal/No</th><th>Sumber</th><th>Jenis</th><th>Uraian</th><th>Vendor</th><th>Status</th><th>Nominal</th></tr></thead><tbody><?php foreach($localExpenses as $r): ?><tr><td><?=e($r['expense_date'])?><br><small><?=e($r['expense_no'])?></small></td><td>Back Office</td><td><?=e($r['category_name_snapshot'])?></td><td><b><?=e($r['title'])?></b><br><small><?=e($r['description']??'')?></small></td><td><?=e($r['vendor_name']??'-')?></td><td><?=e(bo_fin_status_label($r['status']))?></td><td><?=money_id($r['amount'])?></td></tr><?php endforeach; foreach($expenseRows as $r): ?><tr><td><?=e($r['date']??'-')?><br><small><?=e($r['transaction_no']??'')?></small></td><td><?=e($r['_unit'])?></td><td><?=e($r['category']??'-')?></td><td><?=e($r['description']??'-')?></td><td><?=e($r['vendor']??'-')?></td><td><?=e(bo_fin_status_label((string)($r['status']??'')))?></td><td><?=money_id($r['amount']??0)?></td></tr><?php endforeach; if(!$localExpenses&&!$expenseRows): ?><tr><td colspan="7">Belum ada pengeluaran pada periode ini.</td></tr><?php endif; ?></tbody></table></div>

<?php elseif($view==='payments'): ?>
<?php if($canWrite&&$schemaReady): ?><div class="card"><h3>Permintaan Pembayaran Back Office</h3><form method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="save_payment_request"><div class="finance-form-grid"><label>Tanggal<input type="date" name="request_date" value="<?=date('Y-m-d')?>" required></label><label>Jenis<select name="category_id" required><option value="">- pilih -</option><?php foreach($categories as $c)if((int)$c['is_active']===1): ?><option value="<?=$c['id']?>"><?=e($c['category_name'])?></option><?php endif; ?></select></label><label>Judul Tagihan<input name="title" required></label><label>Nominal<input type="number" step="0.01" min="0" name="amount" required></label><label>Vendor/Penerima<input name="vendor_name"></label><label>Jatuh Tempo<input type="date" name="due_date"></label><label>No Referensi<input name="reference_no"></label><label>Referensi Bukti<input name="evidence_reference"></label></div><label>Uraian<textarea name="description"></textarea></label><button class="btn primary">Ajukan Pembayaran</button></form></div><?php endif; ?>
<div class="table-wrap section"><table><thead><tr><th>Tanggal/No</th><th>Sumber</th><th>Tagihan</th><th>Vendor</th><th>Jatuh Tempo</th><th>Status</th><th>Nominal</th><th>Aksi</th></tr></thead><tbody><?php foreach($localRequests as $r): ?><tr><td><?=e($r['request_date'])?><br><small><?=e($r['request_no'])?></small></td><td>Back Office</td><td><b><?=e($r['title'])?></b><br><small><?=e($r['category_name_snapshot'])?></small></td><td><?=e($r['vendor_name']??'-')?></td><td><?=e($r['due_date']??'-')?></td><td><?=e(bo_fin_status_label($r['status']))?></td><td><?=money_id($r['amount'])?></td><td><?php if($canWrite&&!in_array($r['status'],['paid','rejected','cancelled'],true)): ?><form method="post" class="finance-actions"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="payment_status"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn" name="new_status" value="approved">Setujui</button><button class="btn primary" name="new_status" value="paid">Dibayar</button><button class="btn" name="new_status" value="rejected">Tolak</button></form><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; foreach($requestRows as $r): ?><tr><td><?=e($r['date']??'-')?><br><small><?=e($r['transaction_no']??'')?></small></td><td><?=e($r['_unit'])?></td><td><b><?=e($r['description']??'-')?></b><br><small><?=e($r['category']??'')?></small></td><td><?=e($r['vendor']??'-')?></td><td><?=e($r['due_date']??'-')?></td><td><?=e(bo_fin_status_label((string)($r['status']??'')))?></td><td><?=money_id($r['amount']??0)?></td><td><span class="muted">Kelola dari unit sumber</span></td></tr><?php endforeach; if(!$localRequests&&!$requestRows): ?><tr><td colspan="8">Belum ada permintaan pembayaran.</td></tr><?php endif; ?></tbody></table></div>

<?php elseif($view==='store_expenses'): ?>
<style>.pay-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}.pay-modal.open{display:flex}.pay-box{background:#fff;border-radius:14px;max-width:680px;width:100%;max-height:85vh;overflow:auto;padding:20px}.pay-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 18px}.pay-grid div{padding:8px 0;border-bottom:1px solid #eef2f7}@media(max-width:650px){.pay-grid{grid-template-columns:1fr}}</style>
<div class="grid"><div class="card metric"><div class="label">Total Pengeluaran Toko</div><div class="value"><?=money_id(array_sum(array_column($storeExpenseRows,'amount')))?></div></div><div class="card metric"><div class="label">Pembayaran Guide</div><div class="value"><?=money_id(array_sum(array_map(fn($r)=>($r['type']??'')==='guide'?(float)$r['amount']:0,$storeExpenseRows)))?></div></div></div>
<div class="table-wrap section"><table><thead><tr><th>Tanggal</th><th>Toko</th><th>Jenis</th><th>Penerima/Guide</th><th>Nominal</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($storeExpenseRows as $r): $payload=htmlspecialchars(json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8'); ?><tr><td><?=e($r['date']??'-')?><br><small><?=e($r['transaction_no']??'')?></small></td><td><?=e($r['_unit'])?></td><td><?=e(($r['type']??'')==='guide'?'Pembayaran Guide':ucwords(str_replace('_',' ',(string)($r['type']??'Pengeluaran'))))?></td><td><?=e($r['recipient']??'-')?></td><td><?=money_id($r['amount']??0)?></td><td><?=e(bo_fin_status_label((string)($r['status']??'')))?></td><td><button type="button" class="btn js-pay-detail" data-row="<?=$payload?>">Detail</button></td></tr><?php endforeach;if(!$storeExpenseRows):?><tr><td colspan="7">Belum ada pengeluaran toko pada periode ini.</td></tr><?php endif;?></tbody></table></div>
<?php foreach($storeExpenseErrors as $err):?><div class="alert danger section"><?=e($err)?></div><?php endforeach;?>
<div class="pay-modal" id="payModal"><div class="pay-box"><div class="section-head"><h3>Detail Pembayaran</h3><button type="button" class="btn" id="payClose">Tutup</button></div><div id="payDetail"></div></div></div>
<script>(function(){const m=document.getElementById('payModal'),d=document.getElementById('payDetail');function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}document.querySelectorAll('.js-pay-detail').forEach(b=>b.addEventListener('click',()=>{const r=JSON.parse(b.dataset.row);d.innerHTML=`<div class="pay-grid"><div><b>Toko</b><br>${esc(r._unit)}</div><div><b>No transaksi</b><br>${esc(r.transaction_no)}</div><div><b>Tanggal</b><br>${esc(r.date)}</div><div><b>Jenis</b><br>${esc(r.type)}</div><div><b>Penerima/Guide</b><br>${esc(r.recipient||'-')}</div><div><b>Nominal</b><br>${esc(r.amount||0)}</div><div><b>Metode pembayaran</b><br>${esc(r.payment_method||'-')}</div><div><b>Referensi</b><br>${esc(r.reference_no||'-')}</div><div><b>Status</b><br>${esc(r.status||'-')}</div><div><b>Qty × biaya</b><br>${esc(r.qty||0)} × ${esc(r.unit_cost||0)}</div></div><div class="section"><b>Keterangan</b><p>${esc(r.description||'-')}</p><small>${esc(r.notes||'')}</small></div>`;m.classList.add('open');}));document.getElementById('payClose').onclick=()=>m.classList.remove('open');m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')});})();</script>

<?php elseif($view==='settings'): ?>
<?php if(!$canWrite): ?><div class="alert">Setting hanya dapat diubah Owner atau Admin.</div><?php elseif($schemaReady): ?><div class="grid-2"><div class="card"><h3><?=!empty($editCategory)?'Edit':'Tambah'?> Jenis Pengeluaran</h3><form method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="save_category"><input type="hidden" name="id" value="<?=e($editCategory['id']??0)?>"><label>Kode<input name="category_code" value="<?=e($editCategory['category_code']??'')?>"></label><label>Nama Jenis<input name="category_name" value="<?=e($editCategory['category_name']??'')?>" required></label><label>Kelompok<input name="group_name" value="<?=e($editCategory['group_name']??'')?>"></label><label>Urutan<input type="number" name="sort_order" value="<?=e($editCategory['sort_order']??0)?>"></label><label>Deskripsi<textarea name="description"><?=e($editCategory['description']??'')?></textarea></label><label><input style="width:auto" type="checkbox" name="requires_approval" <?=!empty($editCategory['requires_approval'])?'checked':''?>> Memerlukan approval</label><label><input style="width:auto" type="checkbox" name="requires_evidence" <?=!empty($editCategory['requires_evidence'])?'checked':''?>> Memerlukan bukti</label><button class="btn primary">Simpan</button></form></div><div class="card"><h3>Prinsip Kategori</h3><p>Kategori tetap digunakan untuk rekap, sedangkan uraian transaksi tetap dapat diketik manual.</p><p>Kategori <b>Lain-lain</b> tetap tersedia, tetapi uraian transaksi wajib dibuat spesifik.</p></div></div><div class="table-wrap section"><table><thead><tr><th>Kode</th><th>Jenis</th><th>Kelompok</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($categories as $r): ?><tr><td><?=e($r['category_code'])?></td><td><?=e($r['category_name'])?></td><td><?=e($r['group_name']??'-')?></td><td><?=$r['is_active']?'<span class="badge ok">Aktif</span>':'<span class="badge">Nonaktif</span>'?></td><td><div class="finance-actions"><a class="btn" href="?p=finance&view=settings&month=<?=e($month)?>&edit=<?=$r['id']?>">Edit</a><form method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="toggle_category"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="active" value="<?=$r['is_active']?0:1?>"><button class="btn"><?=$r['is_active']?'Nonaktifkan':'Aktifkan'?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<?php elseif($view==='payroll'): ?>
<?php
$payrollReady=bo_payroll_schema_ready();$payrollUnits=$payrollReady?bo_payroll_unit_settings($month):[];$payrollEmpSettings=$payrollReady?bo_payroll_employee_settings($month):[];
$payrollAssignments=$payrollReady?bo_payroll_assignment_rows():[];$payrollAdmins=$payrollReady?bo_payroll_admin_rows():[];$payrollRun=$payrollReady?bo_payroll_run($month):null;
$payrollLocked=$payrollRun&&in_array((string)$payrollRun['status'],['final','paid'],true);$payrollCalc=null;$payrollItems=[];$lockedBudget=0.0;
if($payrollReady){if($payrollLocked){$payrollItems=$payrollRun['items']??[];$seen=[];foreach($payrollItems as $pi){$uk=(string)($pi['unit_type']??'').':'.(string)($pi['system_key']??'');if(!isset($seen[$uk])){$lockedBudget+=(float)($pi['payroll_budget']??$pi['pool_amount']??0);$seen[$uk]=1;}}}else{$payrollCalc=bo_payroll_compute($month);$payrollItems=$payrollCalc['items'];}}
?>
<?php if(!$payrollReady): ?><div class="alert danger">Import <b>db/20260829_003_payroll_period_settings.sql</b> terlebih dahulu.</div><?php else: ?>
<div class="alert"><b>Periode payroll: <?=e($month)?></b>. Seluruh omset, KPI, % payroll, gaji pokok, lembur, punishment, kasbon, draft, dan finalisasi mengikuti bulan ini. Saat membuka bulan baru, komponen tetap otomatis diwarisi dari periode tersimpan sebelumnya; punishment dan kasbon selalu mulai dari 0, sedangkan insentif dihitung ulang dari omset + KPI bulan berjalan.</div>
<div class="grid">
 <div class="card metric"><div class="label">Budget Payroll</div><div class="value"><?=money_id($payrollLocked?$lockedBudget:($payrollCalc['totals']['budget']??0))?></div><div class="sub">Σ omset unit × % payroll masing-masing</div></div>
 <div class="card metric"><div class="label">Gaji Pokok</div><div class="value"><?=money_id($payrollLocked?($payrollRun['total_base_salary']??0):($payrollCalc['totals']['base_salary']??0))?></div></div>
 <div class="card metric"><div class="label">Insentif KPI</div><div class="value"><?=money_id($payrollLocked?($payrollRun['total_incentive']??0):($payrollCalc['totals']['incentive']??0))?></div></div>
 <div class="card metric"><div class="label">Take Home Pay</div><div class="value"><?=money_id($payrollLocked?($payrollRun['total_take_home']??0):($payrollCalc['totals']['take_home']??0))?></div><div class="sub">Gaji pokok + lembur + insentif − punishment − kasbon</div></div>
</div>
<?php if(!$payrollLocked): ?>
<div class="card section payroll-unit-settings"><div class="section-head"><div><h3>Persentase Payroll per Unit</h3><div class="muted">Persentase berbeda untuk setiap Toko, Dapur, dan Back Office. Nilai ini merupakan budget total THP, bukan bonus tambahan.</div></div></div><div class="table-wrap"><table><thead><tr><th>Jenis</th><th>Unit</th><th>Basis Omset</th><th>% Payroll</th><th>Budget THP</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($payrollUnits as $u): $formId='pu-'.preg_replace('/[^a-zA-Z0-9_-]/','-',(string)$u['unit_type'].'-'.(string)$u['system_key']);$basis=0;if($payrollCalc){if($u['unit_type']==='store')$basis=(float)($payrollCalc['financial']['store'][$u['system_key']]['revenue']??0);elseif($u['unit_type']==='dapur')$basis=(float)($payrollCalc['financial']['dapur'][$u['system_key']]['revenue']??0);else $basis=(float)($payrollCalc['financial']['backoffice_revenue']??0);}$budget=$basis*(float)$u['pool_percentage']/100; ?><tr><td><?=e(strtoupper($u['unit_type']))?><form id="<?=e($formId)?>" method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="save_payroll_unit"><input type="hidden" name="unit_type" value="<?=e($u['unit_type'])?>"><input type="hidden" name="system_key" value="<?=e($u['system_key'])?>"><input type="hidden" name="unit_name" value="<?=e($u['unit_name'])?>"></form></td><td><b><?=e($u['unit_name'])?></b><br><small><?=e($u['system_key'])?></small></td><td><?=money_id($basis)?></td><td><input form="<?=e($formId)?>" type="number" name="pool_percentage" min="0" max="100" step="0.01" value="<?=e($u['pool_percentage'])?>"></td><td><b><?=money_id($budget)?></b></td><td><label><input form="<?=e($formId)?>" style="width:auto" type="checkbox" name="is_active" <?=!empty($u['is_active'])?'checked':''?>> Aktif</label></td><td><button form="<?=e($formId)?>" class="btn">Simpan</button></td></tr><?php endforeach; ?></tbody></table></div></div>

<div class="card section"><h3>Komponen Payroll Pegawai Toko & Dapur</h3><div class="muted">KPI Toko diinput melalui SDM → KPI Toko. KPI Dapur tetap sinkron dari website Dapur.</div><div class="table-wrap payroll-scroll"><table><thead><tr><th>Pegawai</th><th>Unit / Role</th><th>Gaji Pokok</th><th>Lembur</th><th>Punishment</th><th>Kasbon</th><th>Target KPI Dapur</th><th>Eligible</th><th></th></tr></thead><tbody>
<?php foreach($payrollAssignments as $a): $es=$payrollEmpSettings['assignment:'.(int)$a['assignment_id']]??[];$isDapur=(string)$a['source_system']==='dapur';$target=$isDapur?bo_payroll_target($month,'dapur',(string)$a['system_key'],(string)$a['role_key']):100;$formId='pe-'.(int)$a['assignment_id']; ?><tr><td><b><?=e($a['canonical_name'])?></b><br><small><?=e($a['external_employee_id'])?></small><form id="<?=e($formId)?>" method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="save_payroll_employee"><input type="hidden" name="assignment_id" value="<?=e($a['assignment_id'])?>"><input type="hidden" name="unit_type" value="<?=$isDapur?'dapur':'store'?>"><input type="hidden" name="system_key" value="<?=e($a['system_key'])?>"><input type="hidden" name="role_key" value="<?=e($a['role_key'])?>"></form></td><td><?=e($a['system_name'])?><br><small><?=e($a['role_label']?:$a['role_key'])?></small></td><td><input form="<?=e($formId)?>" type="number" min="0" step="1000" name="base_salary" value="<?=e($es['base_salary']??0)?>"></td><td><input form="<?=e($formId)?>" type="number" min="0" step="1000" name="overtime" value="<?=e($es['overtime']??0)?>"></td><td><input form="<?=e($formId)?>" type="number" min="0" step="1000" name="punishment" value="<?=e($es['punishment']??$es['deduction']??0)?>"></td><td><input form="<?=e($formId)?>" type="number" min="0" step="1000" name="kasbon" value="<?=e($es['kasbon']??0)?>"></td><td><?php if($isDapur): ?><input form="<?=e($formId)?>" type="number" min="0.01" step="1" name="kpi_target" value="<?=e($target)?>"><?php else: ?><span class="muted">KPI lokal BO</span><?php endif; ?></td><td><input form="<?=e($formId)?>" style="width:auto" type="checkbox" name="is_eligible" <?=!isset($es['is_eligible'])||!empty($es['is_eligible'])?'checked':''?>></td><td><button form="<?=e($formId)?>" class="btn">Simpan</button></td></tr><?php endforeach;if(!$payrollAssignments): ?><tr><td colspan="9">Belum ada assignment pegawai aktif. Jalankan Sync Pegawai terlebih dahulu.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="card section"><h3>Admin Back Office</h3><div class="muted">KPI Admin tidak lagi diinput di Penggajian. Gunakan SDM → KPI → KPI Back Office. Owner tetap tidak masuk otomatis.</div><div class="table-wrap payroll-scroll"><table><thead><tr><th>Admin</th><th>Gaji Pokok</th><th>Lembur</th><th>Punishment</th><th>Kasbon</th><th>Eligible</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($payrollAdmins as $a):$es=$payrollEmpSettings['admin:'.(int)$a['id']]??[];$gForm='pa-'.(int)$a['id']; ?><tr><td><b><?=e($a['name'])?></b><br><small><?=e($a['username'])?></small><form id="<?=e($gForm)?>" method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="save_payroll_employee"><input type="hidden" name="bo_user_id" value="<?=e($a['id'])?>"></form></td><td><input form="<?=e($gForm)?>" type="number" min="0" step="1000" name="base_salary" value="<?=e($es['base_salary']??0)?>"></td><td><input form="<?=e($gForm)?>" type="number" min="0" step="1000" name="overtime" value="<?=e($es['overtime']??0)?>"></td><td><input form="<?=e($gForm)?>" type="number" min="0" step="1000" name="punishment" value="<?=e($es['punishment']??$es['deduction']??0)?>"></td><td><input form="<?=e($gForm)?>" type="number" min="0" step="1000" name="kasbon" value="<?=e($es['kasbon']??0)?>"></td><td><input form="<?=e($gForm)?>" style="width:auto" type="checkbox" name="is_eligible" <?=!isset($es['is_eligible'])||!empty($es['is_eligible'])?'checked':''?>></td><td><button form="<?=e($gForm)?>" class="btn">Simpan Gaji</button></td></tr><?php endforeach;if(!$payrollAdmins): ?><tr><td colspan="7">Belum ada user role Admin aktif.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>

<?php if(!$payrollLocked&&$payrollCalc): ?><div class="card section"><h3>Kontrol Budget per Unit</h3><div class="table-wrap"><table><thead><tr><th>Unit</th><th>Omset</th><th>% Payroll</th><th>Budget THP</th><th>Gaji Pokok + Lembur</th><th>Sisa untuk Insentif</th><th>Realisasi THP</th><th>Selisih</th></tr></thead><tbody><?php foreach($payrollCalc['unit_summary'] as $us): ?><tr><td><b><?=e($us['unit_name'])?></b><br><small><?=e($us['unit_type'])?></small></td><td><?=money_id($us['revenue'])?></td><td><?=e(number_format((float)$us['percentage'],2,',','.'))?>%</td><td><?=money_id($us['budget'])?></td><td><?=money_id($us['fixed_gross'])?></td><td><?=money_id($us['incentive_pool'])?></td><td><?=money_id($us['realization'])?></td><td><span class="badge <?=!empty($us['over_budget'])?'danger':'ok'?>"><?=money_id($us['variance'])?></span></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>

<div class="card section"><div class="section-head"><div><h3>Perhitungan Payroll</h3><div class="muted">Budget unit = omset × % payroll. Insentif = sisa budget setelah gaji pokok + lembur, lalu dibagi berdasarkan KPI. Punishment dan kasbon mengurangi THP dan tidak dibagikan ulang.</div></div><?php if(!$payrollLocked): ?><div class="finance-actions"><form method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="recalculate_payroll"><button class="btn">Simpan / Hitung Ulang Draft</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="finalize_payroll"><button class="btn primary" data-confirm="Finalisasi akan mengunci snapshot omset, KPI, persentase dan nominal bulan ini. Lanjutkan?">Final & Kunci</button></form></div><?php endif; ?></div>
<?php foreach((array)($payrollCalc['financial_errors']??[]) as $er): ?><div class="alert danger"><?=e($er)?></div><?php endforeach;foreach((array)($payrollCalc['kpi_errors']??[]) as $er): ?><div class="alert danger"><?=e($er)?></div><?php endforeach; ?>
<div class="table-wrap payroll-scroll"><table><thead><tr><th>Pegawai</th><th>Unit</th><th>Basis Omset</th><th>% Payroll</th><th>KPI</th><th>Bobot Insentif</th><th>Insentif</th><th>Gaji Pokok</th><th>Lembur</th><th>Punishment</th><th>Kasbon</th><th>Take Home Pay</th></tr></thead><tbody>
<?php foreach($payrollItems as $it): $snap=$payrollLocked; ?><tr><td><b><?=e($snap?$it['employee_name_snapshot']:$it['employee_name'])?></b><br><small><?=e($snap?$it['role_key_snapshot']:$it['role_key'])?></small></td><td><?=e($snap?$it['unit_name_snapshot']:$it['unit_name'])?><br><small><?=e($it['unit_type'])?></small></td><td><?=money_id($it['revenue_base']??0)?></td><td><?=e(number_format((float)($it['pool_percentage']??0),2,',','.'))?>%<br><small>Budget <?=money_id($it['payroll_budget']??$it['pool_amount']??0)?></small></td><td><b><?=e(number_format((float)($it['kpi_score']??0),2,',','.'))?></b><br><small><?=e($it['kpi_source']??'')?><?=($it['unit_type']??'')==='dapur'?' · '.number_format((float)($it['kpi_raw']??0),2,',','.').'/'.number_format((float)($it['kpi_target']??0),2,',','.') : ''?></small></td><td><?=e(number_format((float)($it['kpi_weight']??0)*100,2,',','.'))?>%</td><td><b><?=money_id($snap?($it['incentive_amount']??0):($it['incentive']??0))?></b></td><td><?=money_id($it['base_salary']??0)?></td><td><?=money_id($it['overtime']??0)?></td><td><?=money_id($it['punishment']??0)?></td><td><?=money_id($it['kasbon']??0)?></td><td><b><?=money_id($it['take_home']??0)?></b></td></tr><?php endforeach;if(!$payrollItems): ?><tr><td colspan="12">Belum ada pegawai eligible untuk payroll.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if($payrollLocked): ?><div class="alert section">Payroll <?=e($month)?> sudah <b><?=e(strtoupper($payrollRun['status']))?></b>. Snapshot omset, KPI, persentase dan nominal terkunci.<?php if(($payrollRun['status']??'')==='final'): ?><form method="post" style="display:inline-block;margin-left:8px"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="mark_payroll_paid"><button class="btn primary" data-confirm="Tandai payroll ini sudah dibayar?">Tandai Dibayar</button></form><?php endif; ?></div><?php endif; ?>
<?php endif; ?>

<?php elseif($view==='profit'): ?>
<div class="card"><h3>Estimasi Laba Rugi Konsolidasi — <?=e($month)?></h3><div class="finance-pair"><span>Penjualan toko</span><strong><?=money_id($storeSales)?></strong></div><div class="finance-pair"><span>− Pembelian eksternal toko</span><strong><?=money_id($storePurchases)?></strong></div><div class="finance-pair"><span>− Pembelian bahan baku dapur</span><strong><?=money_id($kitchenPurchases)?></strong></div><div class="finance-pair"><span>− Pengeluaran toko</span><strong><?=money_id($storeExpenses)?></strong></div><div class="finance-pair"><span>− Pengeluaran dapur</span><strong><?=money_id($kitchenExpenses)?></strong></div><div class="finance-pair"><span>− Pengeluaran Back Office</span><strong><?=money_id($local['expenses'])?></strong></div><div class="finance-pair"><span>− Payroll Final/Paid</span><strong><?=money_id($payrollAccounting)?></strong></div><div class="finance-pair"><span><b>Estimasi laba operasional</b></span><strong><?=money_id($estimatedProfit)?></strong></div></div><div class="alert section">Transfer internal dapur–toko sebesar <?=money_id(max($storeInternal,$internalDistribution))?> hanya ditampilkan sebagai informasi dan tidak dikurangkan lagi. Payroll hanya masuk laba rugi setelah berstatus Final/Paid. Nilai ini belum merupakan laba akuntansi final karena persediaan awal/akhir, HPP penjualan, depresiasi, pajak terutang, serta penyesuaian akrual belum lengkap.</div>

<?php elseif($view==='cashflow'): ?>
<?php $cashOut=$storePurchases+$kitchenPurchases+$storeExpenses+$kitchenExpenses+$local['expenses']+$payrollPaidCash;$netCash=$storeSales-$cashOut; ?><div class="grid"><div class="card metric"><div class="label">Arus Kas Masuk Operasional</div><div class="value"><?=money_id($storeSales)?></div><div class="sub">Pendapatan penjualan yang tercatat</div></div><div class="card metric"><div class="label">Arus Kas Keluar Teridentifikasi</div><div class="value"><?=money_id($cashOut)?></div><div class="sub">Pembelian dan pengeluaran berstatus aktual</div></div><div class="card metric"><div class="label">Arus Kas Bersih Sementara</div><div class="value"><?=money_id($netCash)?></div><div class="sub">Payroll berstatus Paid sudah masuk arus kas; mutasi kas nonoperasional belum lengkap</div></div></div>
<?php endif; ?>
