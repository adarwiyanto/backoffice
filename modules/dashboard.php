<?php
require_once __DIR__.'/../core/ApiClient.php';

function bo_dash_payload(array $res): array {
  $data=$res['data'] ?? $res['summary'] ?? $res;
  return is_array($data) ? $data : [];
}

function bo_dash_norm_key(string $key): string {
  return strtolower(preg_replace('~[^a-z0-9]+~i','_',trim($key)));
}

function bo_dash_find_key(array $data, array $keys): ?array {
  $want=[];
  foreach($keys as $k) $want[bo_dash_norm_key($k)]=true;
  foreach($data as $k=>$v){
    if(isset($want[bo_dash_norm_key((string)$k)])) return ['key'=>(string)$k,'value'=>$v];
  }
  foreach($data as $v){
    if(is_array($v)){
      $found=bo_dash_find_key($v,$keys);
      if($found!==null) return $found;
    }
  }
  return null;
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

function bo_dash_value(array $data, array $keys): float {
  $found=bo_dash_find_key($data,$keys);
  return $found ? bo_dash_num($found['value']) : 0;
}

function bo_dash_amount_today(array $data, float $trxToday): float {
  $amountKeys=['omset_today','omzet_today','revenue_today','total_revenue_today','gross_revenue_today','net_revenue_today','total_omset_today','total_omzet_today','gross_sales_today','net_sales_today','total_sales_amount_today','sales_amount_today','amount_today','total_amount_today'];
  $v=bo_dash_value($data,$amountKeys);
  if($v>0) return $v;
  $sales=bo_dash_find_key($data,['sales_today']);
  if(!$sales) return 0;
  $sv=bo_dash_num($sales['value']);
  if($sv>0 && ($trxToday<=0 || $sv!==$trxToday) && $sv>=1000) return $sv;
  return 0;
}

function bo_dash_amount_month(array $data): float {
  $amountKeys=['omset_month','omzet_month','revenue_month','total_revenue_month','gross_revenue_month','net_revenue_month','total_omset_month','total_omzet_month','gross_sales_month','net_sales_month','total_sales_amount_month','sales_amount_month','amount_month','total_amount_month'];
  $v=bo_dash_value($data,$amountKeys);
  if($v>0) return $v;
  $sales=bo_dash_find_key($data,['sales_month']);
  $sv=$sales ? bo_dash_num($sales['value']) : 0;
  return $sv>=1000 ? $sv : 0;
}

function bo_dash_adena_summary(): array {
  $sum=['sales_today'=>0,'transactions_today'=>0,'sales_month'=>0,'active_products'=>0,'employees_count'=>0,'ok_count'=>0,'total_count'=>0];
  foreach(bo_connections_by_type('adena') as $conn){
    $sum['total_count']++;
    $res=bo_api_request_connection($conn,'api/backoffice/dashboard_summary.php');
    if(!empty($res['ok'])) $sum['ok_count']++;
    $data=bo_dash_payload($res);
    $trx=bo_dash_value($data,['transactions_today','transaction_count_today','orders_today','order_count_today','total_transactions_today','sales_count_today','sales_today_count','receipts_today','receipt_count_today','jumlah_transaksi_hari_ini']);
    if($trx<=0){
      $salesAsCount=bo_dash_find_key($data,['sales_today']);
      if($salesAsCount){ $tmp=bo_dash_num($salesAsCount['value']); if($tmp>0 && $tmp<1000) $trx=$tmp; }
    }
    $sum['transactions_today'] += $trx;
    $sum['sales_today'] += bo_dash_amount_today($data,$trx);
    $sum['sales_month'] += bo_dash_amount_month($data);
    $sum['active_products'] += bo_dash_value($data,['active_products','products_count','product_count','total_products','produk_aktif']);
    $sum['employees_count'] += bo_dash_value($data,['employees_count','employee_count','active_employees','pegawai_count','jumlah_pegawai']);
  }
  return $sum;
}

function bo_dash_dapur_summary(): array {
  $sum=['productions_today'=>0,'pending_distributions'=>0,'active_finished_products'=>0,'employees_count'=>0,'ok_count'=>0,'total_count'=>0];
  foreach(bo_connections_by_type('dapur') as $conn){
    $sum['total_count']++;
    $res=bo_api_request_connection($conn,'api/backoffice/dashboard_summary.php');
    if(!empty($res['ok'])) $sum['ok_count']++;
    $data=bo_dash_payload($res);
    $sum['productions_today'] += bo_dash_value($data,['productions_today','production_today','total_productions_today','jumlah_produksi_hari_ini']);
    $sum['pending_distributions'] += bo_dash_value($data,['pending_distributions','distribution_pending','pending_distribution','transfer_pending','pending_transfers']);
    $sum['active_finished_products'] += bo_dash_value($data,['active_finished_products','finished_products_count','active_products','produk_jadi_aktif']);
    $sum['employees_count'] += bo_dash_value($data,['employees_count','employee_count','active_employees','pegawai_count','jumlah_pegawai']);
  }
  return $sum;
}

$ad=bo_dash_adena_summary();
$dp=bo_dash_dapur_summary();
?>
<div class="page-title"><div><h1>Dashboard</h1><div class="muted">Ringkasan operasional toko dan dapur.</div></div><a class="btn primary" href="?p=integration">Integrasi API</a></div>
<div class="grid">
  <div class="card metric"><div class="label">Omset Toko Hari Ini</div><div class="value"><?=money_id($ad['sales_today']??0)?></div><div class="sub"><?=e((int)($ad['transactions_today']??0))?> transaksi</div></div>
  <div class="card metric"><div class="label">Omset Bulan Ini</div><div class="value"><?=money_id($ad['sales_month']??0)?></div><div class="sub">Total toko terkoneksi</div></div>
  <div class="card metric"><div class="label">Produksi Hari Ini</div><div class="value"><?=e((int)($dp['productions_today']??0))?></div><div class="sub">Posting produksi dapur</div></div>
  <div class="card metric"><div class="label">Distribusi Pending</div><div class="value"><?=e((int)($dp['pending_distributions']??0))?></div><div class="sub">Dapur ke toko</div></div>
</div>
<div class="grid section"><div class="card metric"><div class="label">Produk Toko</div><div class="value"><?=e((int)($ad['active_products']??0))?></div></div><div class="card metric"><div class="label">Produk Jadi Dapur</div><div class="value"><?=e((int)($dp['active_finished_products']??0))?></div></div><div class="card metric"><div class="label">Pegawai Toko</div><div class="value"><?=e((int)($ad['employees_count']??0))?></div></div><div class="card metric"><div class="label">Pegawai Dapur</div><div class="value"><?=e((int)($dp['employees_count']??0))?></div></div></div>
