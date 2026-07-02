<?php
function bo_emp_role_label(array $r): string {
  $source=strtolower((string)($r['source']??''));
  $key=strtolower(trim((string)($r['role_key']??'')));
  $label=trim((string)($r['role']??''));
  if($source==='dapur') return match($key){ 'owner'=>'Owner', 'admin_dapur'=>'Admin Dapur', 'manager_dapur','kepala_dapur'=>'Manajer Dapur', default => ($label!==''?$label:'Pegawai Dapur') };
  if($source==='adena') return match($key){ 'owner'=>'Owner', 'admin'=>'Admin Toko', 'manager_cabang','manager'=>'Manajer Toko', default => ($label!==''?$label:'Pegawai Toko') };
  return $label!==''?$label:'-';
}
$rows=[];
foreach(bo_connections_by_type('adena') as $conn){ $res=bo_api_request_connection($conn,'api/backoffice/employees.php'); foreach(($res['data']??[]) as $row){ $row['_connection']=$conn['system_name'] ?? $conn['system_key'] ?? 'Adena'; $rows[]=$row; } }
foreach(bo_connections_by_type('dapur') as $conn){ $res=bo_api_request_connection($conn,'api/backoffice/employees.php'); foreach(($res['data']??[]) as $row){ $row['_connection']=$conn['system_name'] ?? $conn['system_key'] ?? 'Dapur'; $rows[]=$row; } }
$src=$_GET['source']??'all'; if($src!=='all') $rows=array_values(array_filter($rows,fn($r)=>($r['source']??'')===$src));
usort($rows, fn($a,$b)=>strcmp(strtolower(($a['source']??'').($a['name']??'')), strtolower(($b['source']??'').($b['name']??''))));
?>
<style>.employee-list.table-wrap{box-shadow:none;border-radius:10px}.employee-list table{min-width:900px}.employee-list th,.employee-list td{padding:5px 8px;font-size:12px;line-height:1.2;vertical-align:middle}.employee-list th{font-size:11px}.employee-list small{font-size:11px}.employee-list .btn{padding:4px 7px;border-radius:7px;font-size:12px}.employee-list .badge{padding:2px 6px;font-size:11px}.employee-name{font-weight:700}.employee-id{color:var(--muted);font-size:11px}.filters.compact-filters{margin-bottom:10px}.filters.compact-filters>*{max-width:180px}</style>
<div class="page-title"><div><h1>Semua Pegawai</h1><div class="muted">Gabungan pegawai toko dan dapur dari semua koneksi API aktif.</div></div></div>
<form class="filters compact-filters"><div><label>Sumber</label><select name="source" onchange="this.form.submit()"><option value="all">Semua</option><option value="adena" <?=$src==='adena'?'selected':''?>>Toko / Adena</option><option value="dapur" <?=$src==='dapur'?'selected':''?>>Dapur</option></select><input type="hidden" name="p" value="employees"></div></form>
<div class="table-wrap employee-list"><table><thead><tr><th>Nama</th><th>Sumber</th><th>Role/Jabatan</th><th>Koneksi/Lokasi</th><th>HP/Email</th><th>Status</th><th>Aktivitas</th><th>KPI</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><span class="employee-name"><?=e($r['name']??'-')?></span><br><span class="employee-id">ID: <?=e($r['employee_id']??'-')?></span></td><td><span class="badge <?=($r['source']??'')==='dapur'?'warn':'ok'?>"><?=e(($r['source']??'')==='dapur'?'DAPUR':'TOKO')?></span></td><td><?=e(bo_emp_role_label($r))?></td><td><?=e($r['_connection']??'-')?> / <?=e($r['location']??'-')?></td><td><?=e($r['phone']??($r['email']??'-'))?></td><td><span class="badge <?=($r['is_active']??true)?'ok':'danger'?>"><?=($r['is_active']??true)?'Aktif':'Nonaktif'?></span></td><td><?=e($r['activity_count']??0)?></td><td><a class="btn" href="?p=kpi&source=<?=e($r['source']??'')?>&id=<?=e($r['employee_id']??'')?>">Lihat</a></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="8">Data belum tersedia atau API belum tersambung.</td></tr><?php endif; ?></tbody></table></div>
