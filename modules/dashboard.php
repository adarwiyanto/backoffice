<?php
require_once __DIR__.'/../core/OperationalSummary.php';
$month=$_GET['month']??date('Y-m');if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
$stores=bo_ops_fetch_summaries('adena',$month);$kitchens=bo_ops_fetch_summaries('dapur',$month);
$boExpenseTotal=0.0;$boPaymentPending=0.0;$monthStart=$month.'-01';$monthEnd=date('Y-m-d',strtotime($monthStart.' +1 month'));
try{$st=bo_exec('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=\'bo_expenses\'');if((int)$st->fetchColumn()>0){$st=bo_exec("SELECT COALESCE(SUM(amount),0) FROM bo_expenses WHERE expense_date>=? AND expense_date<? AND status IN ('approved','paid') AND deleted_at IS NULL",[$monthStart,$monthEnd]);$boExpenseTotal=(float)$st->fetchColumn();}}catch(Throwable $e){}
try{$st=bo_exec('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=\'bo_payment_requests\'');if((int)$st->fetchColumn()>0){$st=bo_exec("SELECT COALESCE(SUM(amount),0) FROM bo_payment_requests WHERE request_date>=? AND request_date<? AND status IN ('draft','submitted','approved') AND deleted_at IS NULL",[$monthStart,$monthEnd]);$boPaymentPending=(float)$st->fetchColumn();}}catch(Throwable $e){}

function bo_dash_metric(array $data,array $paths,$default=0): float { return bo_ops_num(bo_ops_first($data,$paths,$default)); }
function bo_dash_card(string $title,string $value,string $sub=''): string { return '<div class="card metric"><div class="label">'.e($title).'</div><div class="value">'.$value.'</div>'.($sub!==''?'<div class="sub">'.e($sub).'</div>':'').'</div>'; }
function bo_dash_store_metrics(array $u): array {
 $d=$u['data'];return [
  'sales_today'=>bo_dash_metric($d,['revenue_today','omset_today',['today','revenue']]),
  'sales_month'=>bo_dash_metric($d,['revenue_month','omset_month','omset_bulan_ini',['month','revenue']]),
  'transactions_today'=>bo_dash_metric($d,['transactions_today',['today','transactions']]),
  'products'=>bo_dash_metric($d,['active_products','products']),
  'employees'=>bo_dash_metric($d,['employees_count','employee_count']),
  'prod_batches'=>bo_dash_metric($d,[['production','batches_today'],'productions_today']),
  'prod_qty'=>bo_dash_metric($d,[['production','qty_today'],'production_qty_today']),
  'dist_pending'=>bo_dash_metric($d,[['distribution','pending'],'pending_distributions']),
  'dist_received'=>bo_dash_metric($d,[['distribution','received'],'distribution_received']),
  'dist_returned'=>bo_dash_metric($d,[['distribution','returned'],'distribution_returned']),
  'dist_failed'=>bo_dash_metric($d,[['distribution','failed'],'distribution_failed']),
  'kpi_avg'=>bo_dash_metric($d,[['kpi','average_score']]),
  'kpi_assessed'=>bo_dash_metric($d,[['kpi','assessed_count']]),
  'kpi_unassessed'=>bo_dash_metric($d,[['kpi','unassessed_count']]),
  'purchases'=>bo_dash_metric($d,[['finance','purchase_external'],'purchase_total_month']),
  'expenses'=>bo_dash_metric($d,[['finance','expense_total'],'expense_total_month']),
  'profit'=>bo_dash_metric($d,[['finance','estimated_cash_profit'],'estimated_profit_month']),
 ];
}
function bo_dash_kitchen_metrics(array $u): array {
 $d=$u['data'];return [
  'prod_batches'=>bo_dash_metric($d,['productions_today',['production','batches_today']]),
  'prod_qty'=>bo_dash_metric($d,['production_qty_today',['production','qty_today']]),
  'products'=>bo_dash_metric($d,['active_finished_products','finished_products']),
  'employees'=>bo_dash_metric($d,['employees_count']),
  'dist_pending'=>bo_dash_metric($d,['pending_distributions',['distribution','sent']]),
  'dist_received'=>bo_dash_metric($d,[['distribution','received']]),
  'dist_returned'=>bo_dash_metric($d,[['distribution','cancelled']]),
  'dist_failed'=>bo_dash_metric($d,[['distribution','failed']]),
  'distribution_value'=>bo_dash_metric($d,['kitchen_revenue_month','dapur_omset_month']),
  'kpi_points'=>bo_dash_metric($d,[['kpi','total_points']]),
  'kpi_avg'=>bo_dash_metric($d,[['kpi','average_points']]),
  'purchases'=>bo_dash_metric($d,[['finance','purchase_total'],'purchase_total_month']),
  'expenses'=>bo_dash_metric($d,[['finance','expense_total'],'expense_total_month']),
 ];
}
$storeRows=[];$kitchenRows=[];$errors=[];
foreach($stores as $u){if(!$u['ok']){$errors[]=$u['name'].': '.$u['message'];continue;}$storeRows[]=['name'=>$u['name'],'m'=>bo_dash_store_metrics($u)];}
foreach($kitchens as $u){if(!$u['ok']){$errors[]=$u['name'].': '.$u['message'];continue;}$kitchenRows[]=['name'=>$u['name'],'m'=>bo_dash_kitchen_metrics($u)];}
$tot=['sales_today'=>0,'sales_month'=>0,'transactions_today'=>0,'store_employees'=>0,'kitchen_employees'=>0,'store_prod_batches'=>0,'store_prod_qty'=>0,'kitchen_prod_batches'=>0,'kitchen_prod_qty'=>0,'dist_pending'=>0,'dist_received'=>0,'dist_returned'=>0,'dist_failed'=>0,'store_purchases'=>0,'store_expenses'=>0,'kitchen_purchases'=>0,'kitchen_expenses'=>0,'store_profit'=>0,'store_kpi_assessed'=>0,'store_kpi_unassessed'=>0,'store_kpi_weighted'=>0,'kpi_store_employee_base'=>0,'kitchen_points'=>0];
foreach($storeRows as $r){$m=$r['m'];foreach(['sales_today','sales_month','transactions_today'] as $k)$tot[$k]+=$m[$k];$tot['store_employees']+=$m['employees'];$tot['store_prod_batches']+=$m['prod_batches'];$tot['store_prod_qty']+=$m['prod_qty'];$tot['dist_pending']+=$m['dist_pending'];$tot['dist_received']+=$m['dist_received'];$tot['dist_returned']+=$m['dist_returned'];$tot['dist_failed']+=$m['dist_failed'];$tot['store_purchases']+=$m['purchases'];$tot['store_expenses']+=$m['expenses'];$tot['store_profit']+=$m['profit'];$tot['store_kpi_assessed']+=$m['kpi_assessed'];$tot['store_kpi_unassessed']+=$m['kpi_unassessed'];$tot['store_kpi_weighted']+=$m['kpi_avg']*$m['kpi_assessed'];$tot['kpi_store_employee_base']+=$m['kpi_assessed'];}
foreach($kitchenRows as $r){$m=$r['m'];$tot['kitchen_employees']+=$m['employees'];$tot['kitchen_prod_batches']+=$m['prod_batches'];$tot['kitchen_prod_qty']+=$m['prod_qty'];$tot['dist_pending']+=$m['dist_pending'];$tot['dist_received']+=$m['dist_received'];$tot['dist_returned']+=$m['dist_returned'];$tot['dist_failed']+=$m['dist_failed'];$tot['kitchen_purchases']+=$m['purchases'];$tot['kitchen_expenses']+=$m['expenses'];$tot['kitchen_points']+=$m['kpi_points'];}
$storeDistPending=array_sum(array_map(static fn($r)=>(float)$r['m']['dist_pending'],$storeRows));
$storeDistReceived=array_sum(array_map(static fn($r)=>(float)$r['m']['dist_received'],$storeRows));
$storeDistReturned=array_sum(array_map(static fn($r)=>(float)$r['m']['dist_returned'],$storeRows));
$storeDistFailed=array_sum(array_map(static fn($r)=>(float)$r['m']['dist_failed'],$storeRows));
$kitchenDistPending=array_sum(array_map(static fn($r)=>(float)$r['m']['dist_pending'],$kitchenRows));
$kitchenDistReceived=array_sum(array_map(static fn($r)=>(float)$r['m']['dist_received'],$kitchenRows));
$kitchenDistFailed=array_sum(array_map(static fn($r)=>(float)$r['m']['dist_failed'],$kitchenRows));
$avgStoreKpi=$tot['kpi_store_employee_base']>0?$tot['store_kpi_weighted']/$tot['kpi_store_employee_base']:0;
$estimatedGroupProfit=$tot['sales_month']-$tot['store_purchases']-$tot['kitchen_purchases']-$tot['store_expenses']-$tot['kitchen_expenses']-$boExpenseTotal;
?>
<style>
.dashboard-section{margin-top:22px}.dashboard-section h2{margin:0 0 10px}.unit-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.unit-card h3{margin:0 0 10px}.unit-stat{display:flex;justify-content:space-between;gap:14px;padding:7px 0;border-bottom:1px solid #eef2f7}.unit-stat:last-child{border-bottom:0}.unit-stat span{color:#64748b}.unit-stat strong{text-align:right}.dashboard-note{font-size:12px;color:#64748b;margin-top:8px}.metric .value{font-size:1.55rem}.section-head{display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap}
</style>
<div class="page-title"><div><h1>Dashboard</h1><div class="muted">Ringkasan per cabang toko, dapur, KPI, produksi, distribusi, dan keuangan.</div></div><form class="filters" method="get"><input type="hidden" name="p" value="dashboard"><div><label>Periode</label><input type="month" name="month" value="<?=e($month)?>"></div><div><button class="btn primary">Tampilkan</button></div></form></div>

<section class="dashboard-section"><div class="section-head"><h2>Omset Toko</h2><a class="btn" href="?p=sales">Lihat Penjualan</a></div><div class="grid">
<?=bo_dash_card('Omset Hari Ini',money_id($tot['sales_today']),number_format((int)$tot['transactions_today'],0,',','.').' transaksi')?>
<?=bo_dash_card('Omset Bulan Ini',money_id($tot['sales_month']),'Gabungan seluruh host toko')?>
<?=bo_dash_card('Jumlah Toko Aktif',e(count($storeRows)),'Koneksi API terbaca')?>
</div><div class="unit-grid section"><?php foreach($storeRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?></h3><div class="unit-stat"><span>Omset hari ini</span><strong><?=money_id($r['m']['sales_today'])?></strong></div><div class="unit-stat"><span>Transaksi hari ini</span><strong><?=e((int)$r['m']['transactions_today'])?></strong></div><div class="unit-stat"><span>Omset bulan ini</span><strong><?=money_id($r['m']['sales_month'])?></strong></div></div><?php endforeach; if(!$storeRows): ?><div class="card">Belum ada toko aktif yang dapat dibaca.</div><?php endif; ?></div></section>

<section class="dashboard-section"><div class="section-head"><h2>Produksi</h2><a class="btn" href="?p=production">Detail Produksi</a></div><div class="grid">
<?=bo_dash_card('Batch Produksi Toko Hari Ini',e((int)$tot['store_prod_batches']),number_format($tot['store_prod_qty'],2,',','.').' qty')?>
<?=bo_dash_card('Batch Produksi Dapur Hari Ini',e((int)$tot['kitchen_prod_batches']),number_format($tot['kitchen_prod_qty'],2,',','.').' qty')?>
<?=bo_dash_card('Total Batch Hari Ini',e((int)($tot['store_prod_batches']+$tot['kitchen_prod_batches'])),'Toko + Dapur')?>
</div><div class="unit-grid section"><?php foreach($storeRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?> · Produksi Toko</h3><div class="unit-stat"><span>Batch hari ini</span><strong><?=e((int)$r['m']['prod_batches'])?></strong></div><div class="unit-stat"><span>Total qty</span><strong><?=e(number_format($r['m']['prod_qty'],2,',','.'))?></strong></div><div class="unit-stat"><span>Produk/SKU</span><strong><?=e((int)$r['m']['products'])?></strong></div></div><?php endforeach; foreach($kitchenRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?> · Produksi Dapur</h3><div class="unit-stat"><span>Batch hari ini</span><strong><?=e((int)$r['m']['prod_batches'])?></strong></div><div class="unit-stat"><span>Total qty</span><strong><?=e(number_format($r['m']['prod_qty'],2,',','.'))?></strong></div><div class="unit-stat"><span>Produk jadi aktif</span><strong><?=e((int)$r['m']['products'])?></strong></div></div><?php endforeach; ?></div></section>

<section class="dashboard-section"><div class="section-head"><h2>Distribusi</h2><a class="btn" href="?p=distribution">Detail Distribusi</a></div><div class="grid">
<?=bo_dash_card('Menunggu Konfirmasi di Toko',e((int)$storeDistPending),'Toko penerima sebagai sumber konfirmasi')?>
<?=bo_dash_card('Terkirim dari Dapur',e((int)$kitchenDistPending),'Belum berstatus diterima di dapur')?>
<?=bo_dash_card('Sudah Diterima Toko',e((int)$storeDistReceived),'Status dari host toko penerima')?>
<?=bo_dash_card('Dikembalikan oleh Toko',e((int)$storeDistReturned),'Perlu evaluasi bila berulang')?>
<?=bo_dash_card('Gagal Sinkron Dapur',e((int)$kitchenDistFailed),'Perlu ditindaklanjuti')?>
</div><div class="unit-grid section"><?php foreach($storeRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?> · Penerimaan</h3><div class="unit-stat"><span>Menunggu konfirmasi</span><strong><?=e((int)$r['m']['dist_pending'])?></strong></div><div class="unit-stat"><span>Sudah diterima</span><strong><?=e((int)$r['m']['dist_received'])?></strong></div><div class="unit-stat"><span>Dikembalikan</span><strong><?=e((int)$r['m']['dist_returned'])?></strong></div><div class="unit-stat"><span>Gagal</span><strong><?=e((int)$r['m']['dist_failed'])?></strong></div></div><?php endforeach; foreach($kitchenRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?> · Pengiriman</h3><div class="unit-stat"><span>Terkirim / pending</span><strong><?=e((int)$r['m']['dist_pending'])?></strong></div><div class="unit-stat"><span>Diterima toko</span><strong><?=e((int)$r['m']['dist_received'])?></strong></div><div class="unit-stat"><span>Dibatalkan</span><strong><?=e((int)$r['m']['dist_returned'])?></strong></div><div class="unit-stat"><span>Gagal sinkron</span><strong><?=e((int)$r['m']['dist_failed'])?></strong></div></div><?php endforeach; ?></div></section>

<section class="dashboard-section"><div class="section-head"><h2>Pegawai dan KPI</h2><a class="btn" href="?p=kpi&type=store&month=<?=e($month)?>">Detail KPI</a></div><div class="grid">
<?=bo_dash_card('Pegawai Toko',e((int)$tot['store_employees']),'Owner tidak dihitung')?>
<?=bo_dash_card('Pegawai Dapur',e((int)$tot['kitchen_employees']),'Owner tidak dihitung')?>
<?=bo_dash_card('Rata-rata KPI Toko',e(number_format($avgStoreKpi,2,',','.')),$tot['store_kpi_assessed'].' dinilai · '.$tot['store_kpi_unassessed'].' belum')?>
<?=bo_dash_card('Total Poin KPI Dapur',e(number_format($tot['kitchen_points'],2,',','.')),'Berbasis kegiatan')?>
</div><div class="unit-grid section"><?php foreach($storeRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?></h3><div class="unit-stat"><span>Jumlah pegawai</span><strong><?=e((int)$r['m']['employees'])?></strong></div><div class="unit-stat"><span>Rata-rata KPI</span><strong><?=e(number_format($r['m']['kpi_avg'],2,',','.'))?></strong></div><div class="unit-stat"><span>Sudah / belum dinilai</span><strong><?=e((int)$r['m']['kpi_assessed'])?> / <?=e((int)$r['m']['kpi_unassessed'])?></strong></div></div><?php endforeach; foreach($kitchenRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?></h3><div class="unit-stat"><span>Jumlah pegawai</span><strong><?=e((int)$r['m']['employees'])?></strong></div><div class="unit-stat"><span>Total poin KPI</span><strong><?=e(number_format($r['m']['kpi_points'],2,',','.'))?></strong></div><div class="unit-stat"><span>Rata-rata poin</span><strong><?=e(number_format($r['m']['kpi_avg'],2,',','.'))?></strong></div></div><?php endforeach; ?></div></section>

<section class="dashboard-section"><div class="section-head"><h2>Keuangan dan Estimasi Profit</h2><a class="btn" href="?p=finance&view=summary&month=<?=e($month)?>">Ringkasan Keuangan</a></div><div class="grid">
<?=bo_dash_card('Total Pembelian Eksternal',money_id($tot['store_purchases']+$tot['kitchen_purchases']),'Toko + pembelian bahan baku dapur')?>
<?=bo_dash_card('Pengeluaran Toko + Dapur',money_id($tot['store_expenses']+$tot['kitchen_expenses']),'Biaya nonstok unit operasional')?>
<?=bo_dash_card('Pengeluaran Back Office',money_id($boExpenseTotal),'Pajak, konsultan, dan biaya pusat')?>
<?=bo_dash_card('Permintaan Pembayaran Pending',money_id($boPaymentPending),'Permintaan lokal Back Office; belum menjadi biaya aktual')?>
<?=bo_dash_card('Estimasi Profit Operasional',money_id($estimatedGroupProfit),'Sudah memasukkan pengeluaran pusat; payroll/HPP final belum tersedia')?>
</div><div class="unit-grid section"><?php foreach($storeRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?> · Keuangan</h3><div class="unit-stat"><span>Omset bulan</span><strong><?=money_id($r['m']['sales_month'])?></strong></div><div class="unit-stat"><span>Pembelian eksternal</span><strong><?=money_id($r['m']['purchases'])?></strong></div><div class="unit-stat"><span>Pengeluaran</span><strong><?=money_id($r['m']['expenses'])?></strong></div><div class="unit-stat"><span>Estimasi surplus</span><strong><?=money_id($r['m']['profit'])?></strong></div></div><?php endforeach; foreach($kitchenRows as $r): ?><div class="card unit-card"><h3><?=e($r['name'])?> · Keuangan</h3><div class="unit-stat"><span>Pembelian bahan</span><strong><?=money_id($r['m']['purchases'])?></strong></div><div class="unit-stat"><span>Pengeluaran</span><strong><?=money_id($r['m']['expenses'])?></strong></div><div class="unit-stat"><span>Nilai distribusi internal</span><strong><?=money_id($r['m']['distribution_value'])?></strong></div></div><?php endforeach; ?><div class="card unit-card"><h3>Back Office · Biaya Pusat</h3><div class="unit-stat"><span>Pengeluaran aktual</span><strong><?=money_id($boExpenseTotal)?></strong></div><div class="unit-stat"><span>Permintaan pending</span><strong><?=money_id($boPaymentPending)?></strong></div></div></div><div class="dashboard-note">Profit saat ini merupakan estimasi operasional. Transfer dapur–toko diperlakukan sebagai transaksi internal. Laporan final nantinya menambahkan payroll, HPP berdasarkan persediaan, depresiasi, dan penyesuaian akrual.</div></section>

<?php if($errors): ?><div class="card section"><h3>Catatan Sinkronisasi</h3><?php foreach($errors as $err): ?><div class="alert danger"><?=e($err)?></div><?php endforeach; ?></div><?php endif; ?>
