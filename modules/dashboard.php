<?php
require_once __DIR__.'/../core/ApiClient.php';

function bo_dash_norm_key(string $key): string {
  $key=trim($key);
  $key=preg_replace('~([a-z0-9])([A-Z])~','$1_$2',$key);
  $key=preg_replace('~([A-Z]+)([A-Z][a-z])~','$1_$2',$key);
  $key=strtolower(preg_replace('~[^a-z0-9]+~i','_',$key));
  return trim($key,'_');
}

function bo_dash_num($value): float {
  if(is_numeric($value)) return (float)$value;
  if(is_string($value)){
    $s=trim($value);
    if($s==='') return 0;
    $s=preg_replace('~[^0-9,\.\-]+~','',$s);
    if($s==='') return 0;
    if(substr_count($s,',')===1 && substr_count($s,'.')>=1){ $s=str_replace('.','',$s); $s=str_replace(',','.',$s); }
    elseif(substr_count($s,'.')>1){ $s=str_replace('.','',$s); }
    elseif(substr_count($s,',')===1 && substr_count($s,'.')===0){ $s=str_replace(',','.',$s); }
    return is_numeric($s) ? (float)$s : 0;
  }
  return 0;
}

function bo_dash_is_assoc(array $arr): bool {
  if($arr===[]) return false;
  return array_keys($arr)!==range(0,count($arr)-1);
}

function bo_dash_payload(array $res): array {
  foreach(['data','summary','dashboard','payload','result','metrics','stats'] as $k){
    if(isset($res[$k]) && is_array($res[$k])) return $res[$k];
  }
  return $res;
}

function bo_dash_find_key(array $data, array $keys): ?array {
  $want=[];
  foreach($keys as $k) $want[bo_dash_norm_key($k)]=true;
  foreach($data as $k=>$v){
    if(isset($want[bo_dash_norm_key((string)$k)]) && !is_array($v)) return ['key'=>(string)$k,'value'=>$v];
  }
  foreach($data as $v){
    if(is_array($v)){
      $found=bo_dash_find_key($v,$keys);
      if($found!==null) return $found;
    }
  }
  return null;
}

function bo_dash_value(array $data, array $keys): float {
  $found=bo_dash_find_key($data,$keys);
  return $found ? bo_dash_num($found['value']) : 0;
}


function bo_dash_context_value(array $data, array $contexts, array $keys): float {
  $ctx=[];
  foreach($contexts as $c) $ctx[bo_dash_norm_key($c)]=true;
  foreach($data as $k=>$v){
    if(is_array($v) && isset($ctx[bo_dash_norm_key((string)$k)])){
      $val=bo_dash_value($v,$keys);
      if($val>0) return $val;
    }
  }
  foreach($data as $v){
    if(is_array($v)){
      $val=bo_dash_context_value($v,$contexts,$keys);
      if($val>0) return $val;
    }
  }
  return 0;
}


function bo_dash_nested_value(array $data, array $parents, array $children): float {
  $parentMap=[]; foreach($parents as $p) $parentMap[bo_dash_norm_key($p)]=true;
  $childMap=[]; foreach($children as $c) $childMap[bo_dash_norm_key($c)]=true;
  foreach($data as $k=>$v){
    $nk=bo_dash_norm_key((string)$k);
    if(is_array($v) && isset($parentMap[$nk])){
      foreach($v as $ck=>$cv){
        if(!is_array($cv) && isset($childMap[bo_dash_norm_key((string)$ck)])){
          $num=bo_dash_num($cv);
          if($num>0) return $num;
        }
      }
      $val=bo_dash_value($v,$children);
      if($val>0) return $val;
    }
  }
  foreach($data as $v){
    if(is_array($v)){
      $val=bo_dash_nested_value($v,$parents,$children);
      if($val>0) return $val;
    }
  }
  return 0;
}

function bo_dash_deep_merge(array $base, array $add): array {
  foreach($add as $k=>$v){
    if(is_array($v)){
      $base[$k]=isset($base[$k]) && is_array($base[$k]) ? bo_dash_deep_merge($base[$k],$v) : $v;
      continue;
    }
    if(!array_key_exists($k,$base) || $base[$k]===null || $base[$k]==='' || (is_numeric($base[$k]) && (float)$base[$k]===0.0 && bo_dash_num($v)>0)){
      $base[$k]=$v;
    }
  }
  return $base;
}

function bo_dash_has_scalar_data(array $data): bool {
  foreach($data as $v){
    if(is_array($v)){ if(bo_dash_has_scalar_data($v)) return true; }
    elseif($v!==null && $v!=='') return true;
  }
  return false;
}

function bo_dash_fetch_summary_payload(array $conn, string $kind): array {
  $merged=[]; $ok=false; $errors=[]; $attempts=[];
  foreach(bo_dash_summary_endpoints() as $endpoint){
    $res=bo_api_request_connection($conn,$endpoint);
    $attempts[]=['endpoint'=>$endpoint,'ok'=>!empty($res['ok']),'status_code'=>$res['status_code']??($res['_http_code']??0),'message'=>$res['message']??''];
    if(empty($res['ok'])){ $errors[]=$res['message'] ?? ('Endpoint gagal: '.$endpoint); continue; }
    $ok=true;
    $payload=bo_dash_payload($res);
    if(is_array($payload) && bo_dash_has_scalar_data($payload)) $merged=bo_dash_deep_merge($merged,$payload);
    if($kind==='adena' && bo_dash_month_amount($merged)>0 && (bo_dash_today_amount($merged,bo_dash_transactions_today($merged))>0 || bo_dash_transactions_today($merged)>0)) break;
    // Endpoint resmi Dapur dapat valid mengembalikan nilai nol. Jangan lanjut menggabungkan endpoint fallback/legacy.
    if($kind==='dapur' && $endpoint==='api/backoffice/dashboard_summary.php' && is_array($payload) && bo_dash_has_scalar_data($payload)) break;
  }
  return ['ok'=>$ok,'data'=>$merged,'message'=>$ok?'':($errors[0] ?? 'API dashboard gagal'),'_attempts'=>$attempts];
}

function bo_dash_label(array $data, array $conn): string {
  $found=bo_dash_find_key($data,['branch_name','store_name','outlet_name','cabang_name','nama_cabang','nama_toko','shop_name','name','system_name']);
  $label=$found ? trim((string)$found['value']) : '';
  if($label!=='') return $label;
  return (string)($conn['system_name'] ?? $conn['system_key'] ?? 'Koneksi');
}

function bo_dash_branch_arrays(array $data): array {
  $keys=['branches','branch','stores','store','outlets','outlet','cabangs','cabang','shops','toko','locations','systems','connections'];
  foreach($data as $k=>$v){
    if(is_array($v) && isset(array_flip(array_map('bo_dash_norm_key',$keys))[bo_dash_norm_key((string)$k)])){
      if(bo_dash_is_assoc($v)) return [$v];
      $rows=[];
      foreach($v as $row){ if(is_array($row)) $rows[]=$row; }
      if($rows) return $rows;
    }
  }
  foreach($data as $v){
    if(is_array($v)){
      $rows=bo_dash_branch_arrays($v);
      if($rows) return $rows;
    }
  }
  return [];
}

function bo_dash_today_amount(array $data, float $trxToday): float {
  $amountKeys=[
    'omset_today','omzet_today','omset_hari_ini','omzet_hari_ini','total_omset_today','total_omzet_today','total_omset_hari_ini','total_omzet_hari_ini',
    'revenue_today','today_revenue','total_revenue_today','gross_revenue_today','net_revenue_today','gross_sales_today','net_sales_today',
    'sales_amount_today','today_sales_amount','total_sales_amount_today','amount_today','today_amount','total_amount_today','sales_total_today','total_sales_today','sales_today_amount'
  ];
  $v=bo_dash_value($data,$amountKeys);
  if($v>0){
    if($v<1000 && $trxToday>0 && (float)$v===(float)$trxToday) return 0;
    return $v;
  }
  $ctx=bo_dash_context_value($data,['today','hari_ini','daily'],['omset','omzet','revenue','gross_revenue','net_revenue','sales_amount','amount','total_amount','total_sales','sales_total','gross_sales','net_sales']);
  if($ctx>0){
    if($ctx<1000 && $trxToday>0 && (float)$ctx===(float)$trxToday) return 0;
    return $ctx;
  }
  $sales=bo_dash_find_key($data,['sales_today','penjualan_hari_ini']);
  if(!$sales) return 0;
  $sv=bo_dash_num($sales['value']);
  if($sv>0 && ($trxToday<=0 || $sv!==$trxToday) && $sv>=1000) return $sv;
  return 0;
}

function bo_dash_month_amount(array $data): float {
  $amountKeys=[
    'omset_month','omzet_month','omset_bulan_ini','omzet_bulan_ini','total_omset_month','total_omzet_month','total_omset_bulan_ini','total_omzet_bulan_ini',
    'omset_this_month','omzet_this_month','this_month_omset','this_month_omzet','current_month_omset','current_month_omzet','mtd_omset','mtd_omzet',
    'revenue_month','monthly_revenue','month_revenue','this_month_revenue','revenue_this_month','current_month_revenue','mtd_revenue','total_revenue_month','total_revenue_this_month','gross_revenue_month','net_revenue_month',
    'gross_sales_month','net_sales_month','sales_amount_month','month_sales_amount','monthly_sales_amount','this_month_sales_amount','sales_amount_this_month','total_sales_amount_month',
    'amount_month','month_amount','monthly_amount','amount_this_month','this_month_amount','total_amount_month','total_amount_this_month',
    'sales_month_total','total_sales_month','sales_this_month','month_sales','monthly_sales','total_sales_this_month','sales_month','penjualan_bulan_ini','total_penjualan_bulan_ini',
    'totalOmsetBulanIni','omsetBulanIni','omzetBulanIni','salesThisMonth','totalSalesThisMonth','monthlySales','monthlyRevenue','monthRevenue'
  ];
  $v=bo_dash_value($data,$amountKeys);
  if($v>0) return $v;

  $monthContexts=['month','monthly','this_month','bulan_ini','current_month','mtd','month_to_date','periode_bulan_ini'];
  $amountChildren=['omset','omzet','revenue','gross_revenue','net_revenue','sales_amount','amount','total_amount','total_sales','sales_total','gross_sales','net_sales','sales','penjualan','nominal','value','total'];
  $ctx=bo_dash_context_value($data,$monthContexts,$amountChildren);
  if($ctx>0) return $ctx;

  $amountParents=['omset','omzet','revenue','sales','penjualan','total_sales','sales_total','gross_sales','net_sales','sales_amount','amount','total_amount','income','turnover'];
  $monthChildren=['month','monthly','this_month','bulan_ini','current_month','mtd','month_to_date','total_month','total_this_month','value_month','amount_month'];
  $nested=bo_dash_nested_value($data,$amountParents,$monthChildren);
  if($nested>0) return $nested;

  return 0;
}

function bo_dash_transactions_today(array $data): float {
  $trx=bo_dash_value($data,[
    'transactions_today','transaction_today','transaction_count_today','today_transaction_count','orders_today','order_count_today','today_order_count','total_transactions_today',
    'sales_count_today','sales_today_count','receipts_today','receipt_count_today','jumlah_transaksi_hari_ini','transaksi_hari_ini','trx_today','today_trx','invoice_count_today'
  ]);
  if($trx>0) return $trx;
  $ctx=bo_dash_context_value($data,['today','hari_ini','daily'],['transactions','transaction_count','orders','order_count','sales_count','receipts','receipt_count','trx','invoice_count','jumlah_transaksi','transaksi']);
  if($ctx>0) return $ctx;
  $salesAsCount=bo_dash_find_key($data,['sales_today']);
  if($salesAsCount){ $tmp=bo_dash_num($salesAsCount['value']); if($tmp>0 && $tmp<1000) return $tmp; }
  return 0;
}

function bo_dash_adena_branch(array $data, array $conn): array {
  $trx=bo_dash_transactions_today($data);
  return [
    'name'=>bo_dash_label($data,$conn),
    'sales_today'=>bo_dash_today_amount($data,$trx),
    'transactions_today'=>$trx,
    'sales_month'=>bo_dash_month_amount($data),
    'pending_distributions'=>bo_dash_value($data,[
      'pending_distributions','distribution_pending','pending_distribution','pending_delivery_count','distribusi_pending'
    ]),
    'active_products'=>bo_dash_value($data,['active_products','products_count','product_count','total_products','produk_aktif','jumlah_produk_aktif']),
    'employees_count'=>bo_dash_value($data,['employees_count','employee_count','active_employees','pegawai_count','jumlah_pegawai','staff_count']),
  ];
}

function bo_dash_dapur_branch(array $data, array $conn): array {
  return [
    'name'=>bo_dash_label($data,$conn),
    'productions_today'=>bo_dash_value($data,[
      'productions_today','production_today','total_productions_today','today_production_count','production_count_today','production_today_count','produksi_hari_ini','jumlah_produksi_hari_ini','total_produksi_hari_ini','finished_goods_today','kitchen_production_today'
    ]),
    'pending_distributions'=>bo_dash_value($data,[
      'pending_distributions','distribution_pending','pending_distribution','transfer_pending','pending_transfers','pending_delivery','pending_delivery_count','pending_stock_transfer','pending_stock_transfers','distribusi_pending','jumlah_distribusi_pending','awaiting_distribution','outgoing_pending','delivery_pending','stock_transfer_pending'
    ]),
    'active_finished_products'=>bo_dash_value($data,['active_finished_products','finished_products_count','finished_product_count','active_products','produk_jadi_aktif','jumlah_produk_jadi_aktif']),
    'employees_count'=>bo_dash_value($data,['employees_count','employee_count','active_employees','pegawai_count','jumlah_pegawai','staff_count']),
    'kitchen_revenue_month'=>bo_dash_value($data,['kitchen_revenue_month','dapur_omset_month','omset_dapur_bulan_ini','dapur_revenue_month','omset_dapur_month']),
  ];
}

function bo_dash_summary_endpoints(): array {
  return [
    'api/backoffice/dashboard_summary.php',
    'api/backoffice/summary.php',
    'api/backoffice/dashboard.php',
    'api/dashboard_summary.php',
    'api/dashboard/summary.php'
  ];
}

function bo_dash_adena_summary(): array {
  $sum=['sales_today'=>0,'transactions_today'=>0,'sales_month'=>0,'pending_distributions'=>0,'active_products'=>0,'employees_count'=>0,'ok_count'=>0,'total_count'=>0,'branches'=>[],'errors'=>[]];
  foreach(bo_connections_by_type('adena') as $conn){
    $sum['total_count']++;
    $res=bo_dash_fetch_summary_payload($conn,'adena');
    if(!empty($res['ok'])) $sum['ok_count']++;
    else $sum['errors'][]=(string)($conn['system_name'] ?? $conn['system_key'] ?? 'Adena').': '.($res['message'] ?? 'API gagal');
    $data=bo_dash_payload($res);
    $rows=bo_dash_branch_arrays($data);
    if(!$rows) $rows=[$data];
    foreach($rows as $row){
      $b=bo_dash_adena_branch($row,$conn);
      $sum['branches'][]=$b;
      $sum['sales_today'] += $b['sales_today'];
      $sum['transactions_today'] += $b['transactions_today'];
      $sum['sales_month'] += $b['sales_month'];
      $sum['pending_distributions'] += $b['pending_distributions'];
      $sum['active_products'] += $b['active_products'];
      $sum['employees_count'] += $b['employees_count'];
    }
  }
  return $sum;
}

function bo_dash_dapur_summary(): array {
  $sum=['productions_today'=>0,'pending_distributions'=>0,'active_finished_products'=>0,'employees_count'=>0,'kitchen_revenue_month'=>0,'revenue_branches'=>[],'ok_count'=>0,'total_count'=>0,'branches'=>[],'errors'=>[]];
  foreach(bo_connections_by_type('dapur') as $conn){
    $sum['total_count']++;
    $res=bo_dash_fetch_summary_payload($conn,'dapur');
    if(!empty($res['ok'])) $sum['ok_count']++;
    else $sum['errors'][]=(string)($conn['system_name'] ?? $conn['system_key'] ?? 'Dapur').': '.($res['message'] ?? 'API gagal');
    $data=bo_dash_payload($res);
    $rows=bo_dash_branch_arrays($data);
    if(!$rows) $rows=[$data];
    foreach($rows as $row){
      $b=bo_dash_dapur_branch($row,$conn);
      $sum['branches'][]=$b;
      $sum['productions_today'] += $b['productions_today'];
      $sum['pending_distributions'] += $b['pending_distributions'];
      $sum['active_finished_products'] += $b['active_finished_products'];
      $sum['employees_count'] += $b['employees_count'];
      $sum['kitchen_revenue_month'] += (float)($b['kitchen_revenue_month'] ?? 0);
    }
    $revRows=bo_dash_kitchen_revenue_rows($data);
    foreach($revRows as $rr){ $sum['revenue_branches'][]=$rr; }
  }
  return $sum;
}

function bo_dash_kitchen_revenue_rows(array $data): array {
  $keys=['kitchen_revenue_branches','dapur_revenue_branches','omset_dapur_branches','dapur_omset_branches','stores'];
  foreach($data as $k=>$v){
    if(is_array($v) && in_array(bo_dash_norm_key((string)$k),array_map('bo_dash_norm_key',$keys),true)){
      $rows=[]; foreach($v as $row){ if(is_array($row)){ $rows[]=['name'=>(string)($row['name']??$row['store_name']??$row['branch_name']??'Toko'),'kitchen_revenue_month'=>bo_dash_value($row,['kitchen_revenue_month','dapur_omset_month','omset_dapur_bulan_ini','total_amount','revenue_month'])]; } }
      if($rows) return $rows;
    }
  }
  foreach($data as $v){ if(is_array($v)){ $rows=bo_dash_kitchen_revenue_rows($v); if($rows) return $rows; } }
  return [];
}

function bo_dash_money_lines(array $branches, string $field): string {
  $out='';
  foreach($branches as $b){
    $val=(float)($b[$field] ?? 0);
    $out.='<div class="dash-break-row"><span>'.e($b['name'] ?? 'Koneksi').'</span><strong>'.money_id($val).'</strong></div>';
  }
  return $out ?: '<span class="muted">Belum ada koneksi aktif.</span>';
}

function bo_dash_count_lines(array $branches, string $field, string $suffix=''): string {
  $out='';
  foreach($branches as $b){
    $val=(int)round((float)($b[$field] ?? 0));
    $out.='<div class="dash-break-row"><span>'.e($b['name'] ?? 'Koneksi').'</span><strong>'.e($val).($suffix!==''?' '.e($suffix):'').'</strong></div>';
  }
  return $out ?: '<span class="muted">Belum ada koneksi aktif.</span>';
}

$ad=bo_dash_adena_summary();
$dp=bo_dash_dapur_summary();
?>
<style>
.dash-breakdown{margin-top:10px;border-top:1px solid #e5e7eb;padding-top:8px;font-size:12px;line-height:1.45}.dash-break-row{display:flex;justify-content:space-between;gap:10px;margin:4px 0}.dash-break-row span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dash-break-row strong{white-space:nowrap}.dash-alert{margin-top:8px;font-size:12px;color:#92400e}.metric .sub{min-height:18px}
</style>
<div class="page-title"><div><h1>Dashboard</h1><div class="muted">Ringkasan operasional toko dan dapur.</div></div><a class="btn primary" href="?p=integration">Integrasi API</a></div>
<div class="grid">
  <div class="card metric"><div class="label">Omset Toko Hari Ini</div><div class="value"><?=money_id($ad['sales_today']??0)?></div><div class="sub"><?=e((int)($ad['transactions_today']??0))?> transaksi total</div><div class="dash-breakdown"><?=bo_dash_money_lines($ad['branches']??[],'sales_today')?></div></div>
  <div class="card metric"><div class="label">Omset Total Bulan Ini</div><div class="value"><?=money_id($ad['sales_month']??0)?></div><div class="sub">Total toko terkoneksi</div><div class="dash-breakdown"><?=bo_dash_money_lines($ad['branches']??[],'sales_month')?></div></div>
  <div class="card metric"><div class="label">Omset Dapur Bulan Ini</div><div class="value"><?=money_id($dp['kitchen_revenue_month']??0)?></div><div class="sub">Harga jual Dapur aktual</div><div class="dash-breakdown"><?=bo_dash_money_lines($dp['revenue_branches']??[],'kitchen_revenue_month')?></div></div>
  <div class="card metric"><div class="label">Produksi Hari Ini</div><div class="value"><?=e((int)($dp['productions_today']??0))?></div><div class="sub">Posting produksi dapur</div><div class="dash-breakdown"><?=bo_dash_count_lines($dp['branches']??[],'productions_today')?></div></div>
  <div class="card metric"><div class="label">Distribusi Pending</div><div class="value"><?=e((int)($ad['pending_distributions']??0))?></div><div class="sub">Belum dikonfirmasi toko</div><div class="dash-breakdown"><?=bo_dash_count_lines($ad['branches']??[],'pending_distributions')?></div></div>
</div>
<div class="grid section"><div class="card metric"><div class="label">Produk Toko</div><div class="value"><?=e((int)($ad['active_products']??0))?></div><div class="dash-breakdown"><?=bo_dash_count_lines($ad['branches']??[],'active_products')?></div></div><div class="card metric"><div class="label">Produk Jadi Dapur</div><div class="value"><?=e((int)($dp['active_finished_products']??0))?></div><div class="dash-breakdown"><?=bo_dash_count_lines($dp['branches']??[],'active_finished_products')?></div></div><div class="card metric"><div class="label">Pegawai Toko</div><div class="value"><?=e((int)($ad['employees_count']??0))?></div><div class="dash-breakdown"><?=bo_dash_count_lines($ad['branches']??[],'employees_count')?></div></div><div class="card metric"><div class="label">Pegawai Dapur</div><div class="value"><?=e((int)($dp['employees_count']??0))?></div><div class="dash-breakdown"><?=bo_dash_count_lines($dp['branches']??[],'employees_count')?></div></div></div>
<?php if(!empty($ad['errors']) || !empty($dp['errors'])): ?><div class="card section"><h3>Catatan Sinkron Dashboard</h3><div class="dash-alert"><?php foreach(array_merge($ad['errors']??[],$dp['errors']??[]) as $err): ?><div><?=e($err)?></div><?php endforeach; ?></div></div><?php endif; ?>
