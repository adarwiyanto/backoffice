<?php
require_once __DIR__.'/../core/OperationalSummary.php';

$view=(string)($_GET['view']??'summary');
$allowedViews=['summary','purchases','expenses','payments','settings','payroll','profit','cashflow'];
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
$estimatedProfit=$storeSales-$consolidatedCosts;
$purchaseRows=array_merge(bo_fin_rows($stores,'purchases'),bo_fin_rows($kitchens,'purchases'));
$expenseRows=array_merge(bo_fin_rows($stores,'expenses'),bo_fin_rows($kitchens,'expenses'));
$requestRows=array_merge(bo_fin_rows($stores,'payment_requests'),bo_fin_rows($kitchens,'payment_requests'));
$categories=$schemaReady?bo_exec('SELECT * FROM bo_expense_categories ORDER BY is_active DESC,sort_order,category_name')->fetchAll():[];
$localExpenses=$schemaReady?bo_exec('SELECT * FROM bo_expenses WHERE expense_date>=? AND expense_date<? AND deleted_at IS NULL ORDER BY id DESC',[$start,$end])->fetchAll():[];
$localRequests=$schemaReady?bo_exec('SELECT * FROM bo_payment_requests WHERE request_date>=? AND request_date<? AND deleted_at IS NULL ORDER BY id DESC',[$start,$end])->fetchAll():[];
$editCategory=null;if($schemaReady&&(int)($_GET['edit']??0)>0)$editCategory=bo_exec('SELECT * FROM bo_expense_categories WHERE id=?',[(int)$_GET['edit']])->fetch()?:null;
$csrf=bo_fin_token();
?>
<style>
.finance-tabs{display:flex;gap:7px;flex-wrap:wrap;margin:12px 0}.finance-tabs a{padding:8px 10px;border:1px solid var(--border);background:#fff;border-radius:10px}.finance-tabs a.active{background:#eaf4ff;border-color:#cfe8ff;color:#0a5ea7;font-weight:800}.finance-unit{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}.finance-pair{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #edf2f7;padding:7px 0}.finance-pair:last-child{border-bottom:0}.finance-pair span{color:var(--muted)}.finance-actions{display:flex;gap:5px;flex-wrap:wrap}.finance-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}@media(max-width:960px){.finance-form-grid{grid-template-columns:1fr}}
</style>
<div class="page-title"><div><h1>Keuangan</h1><div class="muted">Rekap toko, dapur, dan pengeluaran lokal Back Office.</div></div><form class="filters" method="get"><input type="hidden" name="p" value="finance"><input type="hidden" name="view" value="<?=e($view)?>"><div><label>Periode</label><input type="month" name="month" value="<?=e($month)?>"></div><div><button class="btn primary">Tampilkan</button></div></form></div>
<div class="finance-tabs">
<?php foreach(['summary'=>'Ringkasan','purchases'=>'Pembelian','expenses'=>'Pengeluaran','payments'=>'Permintaan Pembayaran','settings'=>'Setting Jenis','payroll'=>'Penggajian','profit'=>'Laba Rugi','cashflow'=>'Arus Kas'] as $k=>$label): ?><a class="<?=$view===$k?'active':''?>" href="?p=finance&view=<?=$k?>&month=<?=e($month)?>"><?=e($label)?></a><?php endforeach; ?>
</div>
<?php if($notice!==''): ?><div class="alert <?=$noticeType==='err'?'danger':''?>"><?=e($notice)?></div><?php endif; ?>
<?php if(!$schemaReady): ?><div class="alert danger"><b>Struktur database Back Office belum siap.</b><br>Import <code>db/20260711_001_finance_sync_foundation.sql</code> melalui phpMyAdmin, lalu muat ulang halaman. Data lama tidak diubah oleh SQL tersebut.</div><?php endif; ?>

<?php if($view==='summary'): ?>
<div class="grid">
  <div class="card metric"><div class="label">Omset Seluruh Toko</div><div class="value"><?=money_id($storeSales)?></div><div class="sub">Pendapatan eksternal bulan <?=e($month)?></div></div>
  <div class="card metric"><div class="label">Total Pembelian Eksternal</div><div class="value"><?=money_id($storePurchases+$kitchenPurchases)?></div><div class="sub">Toko + bahan baku dapur</div></div>
  <div class="card metric"><div class="label">Total Pengeluaran</div><div class="value"><?=money_id($storeExpenses+$kitchenExpenses+$local['expenses'])?></div><div class="sub">Toko + dapur + Back Office</div></div>
  <div class="card metric"><div class="label">Estimasi Profit Operasional</div><div class="value"><?=money_id($estimatedProfit)?></div><div class="sub">Belum termasuk payroll dan HPP persediaan final</div></div>
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

<?php elseif($view==='settings'): ?>
<?php if(!$canWrite): ?><div class="alert">Setting hanya dapat diubah Owner atau Admin.</div><?php elseif($schemaReady): ?><div class="grid-2"><div class="card"><h3><?=!empty($editCategory)?'Edit':'Tambah'?> Jenis Pengeluaran</h3><form method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="save_category"><input type="hidden" name="id" value="<?=e($editCategory['id']??0)?>"><label>Kode<input name="category_code" value="<?=e($editCategory['category_code']??'')?>"></label><label>Nama Jenis<input name="category_name" value="<?=e($editCategory['category_name']??'')?>" required></label><label>Kelompok<input name="group_name" value="<?=e($editCategory['group_name']??'')?>"></label><label>Urutan<input type="number" name="sort_order" value="<?=e($editCategory['sort_order']??0)?>"></label><label>Deskripsi<textarea name="description"><?=e($editCategory['description']??'')?></textarea></label><label><input style="width:auto" type="checkbox" name="requires_approval" <?=!empty($editCategory['requires_approval'])?'checked':''?>> Memerlukan approval</label><label><input style="width:auto" type="checkbox" name="requires_evidence" <?=!empty($editCategory['requires_evidence'])?'checked':''?>> Memerlukan bukti</label><button class="btn primary">Simpan</button></form></div><div class="card"><h3>Prinsip Kategori</h3><p>Kategori tetap digunakan untuk rekap, sedangkan uraian transaksi tetap dapat diketik manual.</p><p>Kategori <b>Lain-lain</b> tetap tersedia, tetapi uraian transaksi wajib dibuat spesifik.</p></div></div><div class="table-wrap section"><table><thead><tr><th>Kode</th><th>Jenis</th><th>Kelompok</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($categories as $r): ?><tr><td><?=e($r['category_code'])?></td><td><?=e($r['category_name'])?></td><td><?=e($r['group_name']??'-')?></td><td><?=$r['is_active']?'<span class="badge ok">Aktif</span>':'<span class="badge">Nonaktif</span>'?></td><td><div class="finance-actions"><a class="btn" href="?p=finance&view=settings&month=<?=e($month)?>&edit=<?=$r['id']?>">Edit</a><form method="post"><input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="toggle_category"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="active" value="<?=$r['is_active']?0:1?>"><button class="btn"><?=$r['is_active']?'Nonaktifkan':'Aktifkan'?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<?php elseif($view==='payroll'): ?>
<div class="card"><h3>Penggajian — Fondasi Fase Berikutnya</h3><p>Modul penggajian belum diaktifkan pada patch ini agar formula gaji, tunjangan, lembur, potongan, insentif KPI toko, dan remunerasi poin dapur dapat dibangun setelah identitas pegawai lintas host stabil.</p><p>Data KPI toko dan dapur pada patch ini sudah disiapkan agar dapat menjadi snapshot komponen payroll.</p></div>

<?php elseif($view==='profit'): ?>
<div class="card"><h3>Estimasi Laba Rugi Konsolidasi — <?=e($month)?></h3><div class="finance-pair"><span>Penjualan toko</span><strong><?=money_id($storeSales)?></strong></div><div class="finance-pair"><span>− Pembelian eksternal toko</span><strong><?=money_id($storePurchases)?></strong></div><div class="finance-pair"><span>− Pembelian bahan baku dapur</span><strong><?=money_id($kitchenPurchases)?></strong></div><div class="finance-pair"><span>− Pengeluaran toko</span><strong><?=money_id($storeExpenses)?></strong></div><div class="finance-pair"><span>− Pengeluaran dapur</span><strong><?=money_id($kitchenExpenses)?></strong></div><div class="finance-pair"><span>− Pengeluaran Back Office</span><strong><?=money_id($local['expenses'])?></strong></div><div class="finance-pair"><span><b>Estimasi laba operasional</b></span><strong><?=money_id($estimatedProfit)?></strong></div></div><div class="alert section">Transfer internal dapur–toko sebesar <?=money_id(max($storeInternal,$internalDistribution))?> hanya ditampilkan sebagai informasi dan tidak dikurangkan lagi. Nilai ini belum merupakan laba akuntansi final karena payroll, persediaan awal/akhir, HPP penjualan, depresiasi, pajak terutang, serta penyesuaian akrual belum lengkap.</div>

<?php elseif($view==='cashflow'): ?>
<?php $cashOut=$storePurchases+$kitchenPurchases+$storeExpenses+$kitchenExpenses+$local['expenses'];$netCash=$storeSales-$cashOut; ?><div class="grid"><div class="card metric"><div class="label">Arus Kas Masuk Operasional</div><div class="value"><?=money_id($storeSales)?></div><div class="sub">Pendapatan penjualan yang tercatat</div></div><div class="card metric"><div class="label">Arus Kas Keluar Teridentifikasi</div><div class="value"><?=money_id($cashOut)?></div><div class="sub">Pembelian dan pengeluaran berstatus aktual</div></div><div class="card metric"><div class="label">Arus Kas Bersih Sementara</div><div class="value"><?=money_id($netCash)?></div><div class="sub">Belum termasuk payroll dan mutasi kas nonoperasional</div></div></div>
<?php endif; ?>
