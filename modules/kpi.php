<?php
$type=$_GET['type']??'dapur'; if(!in_array($type,['store','dapur'],true)) $type='dapur';
$month=$_GET['month']??date('Y-m'); if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=date('Y-m');
$rows=[]; $errors=[]; $totalPoints=0; $sourceLabel=$type==='dapur'?'KPI Pegawai Dapur':'KPI Pegawai Toko';
if($type==='dapur'){
  foreach(bo_connections_by_type('dapur') as $conn){
    $res=bo_api_request_connection($conn,'api/backoffice/kpi_dapur.php',['month'=>$month]);
    if(empty($res['ok'])){ $errors[]=(string)($conn['system_name']??'Dapur').': '.($res['message']??'API gagal'); continue; }
    $payload=$res['data']??[]; $emps=$payload['employees']??($payload['data']['employees']??[]);
    if(is_array($payload) && isset($payload['total_points'])) $totalPoints+=(float)$payload['total_points'];
    if(is_array($emps)) foreach($emps as $r){ if(is_array($r)){ $r['_connection']=$conn['system_name']??'Dapur'; $rows[]=$r; } }
  }
} else {
  foreach(bo_connections_by_type('adena') as $conn){
    $res=bo_api_request_connection($conn,'api/backoffice/employees.php');
    if(empty($res['ok'])){ $errors[]=(string)($conn['system_name']??'Adena').': '.($res['message']??'API gagal'); continue; }
    $emps=$res['data']??[]; if(is_array($emps)) foreach($emps as $r){ if(is_array($r)){ $r['_connection']=$conn['system_name']??'Adena'; $rows[]=$r; } }
  }
}
if($type==='dapur' && $totalPoints<=0){ foreach($rows as $r) $totalPoints+=(float)($r['total_points']??0); }
?>
<div class="page-title"><div><h1><?=e($sourceLabel)?></h1><div class="muted"><?= $type==='dapur' ? 'Total poin bulanan pegawai Dapur dari sistem Dapur.' : 'KPI pegawai toko dipisahkan dari KPI Dapur.' ?></div></div></div>
<form class="filters"><input type="hidden" name="p" value="kpi"><input type="hidden" name="type" value="<?=e($type)?>"><div><label>Bulan</label><input type="month" name="month" value="<?=e($month)?>"></div><div><button class="btn primary">Filter</button></div><div><a class="btn" href="?p=kpi&type=store&month=<?=e($month)?>">KPI Toko</a></div><div><a class="btn" href="?p=kpi&type=dapur&month=<?=e($month)?>">KPI Dapur</a></div></form>
<div class="grid"><div class="card metric"><div class="label">Sumber</div><div class="value"><?=e($type==='dapur'?'Dapur':'Toko')?></div><div class="sub">Dipisahkan sesuai jenis pegawai</div></div><div class="card metric"><div class="label">Periode</div><div class="value"><?=e($month)?></div><div class="sub">Bulanan</div></div><div class="card metric"><div class="label">Pegawai Terbaca</div><div class="value"><?=e(count($rows))?></div><div class="sub">Dari API terkoneksi</div></div><div class="card metric"><div class="label">Total Poin</div><div class="value"><?=e(number_format($totalPoints,2,',','.'))?></div><div class="sub"><?= $type==='dapur' ? 'Akumulasi poin Dapur' : 'Belum dihitung dari toko' ?></div></div></div>
<?php if($errors): ?><div class="alert danger"><?php foreach($errors as $err): ?><div><?=e($err)?></div><?php endforeach; ?></div><?php endif; ?>
<div class="table-wrap section"><table><thead><tr><th>Nama</th><th>Sumber/Koneksi</th><th>Lokasi</th><th>Status</th><th>Jumlah Aktivitas</th><th>Total Poin</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><b><?=e($r['name']??'-')?></b><br><small>ID: <?=e($r['employee_id']??'-')?></small></td><td><?=e($r['_connection']??($r['source']??'-'))?></td><td><?=e($r['location']??'-')?></td><td><span class="badge <?=($r['is_active']??true)?'ok':'danger'?>"><?=($r['is_active']??true)?'Aktif':'Nonaktif'?></span></td><td><?=e($r['activity_count']??0)?></td><td><?=e(number_format((float)($r['total_points']??0),2,',','.'))?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="6">Data KPI belum tersedia atau API belum tersambung.</td></tr><?php endif; ?></tbody></table></div>
