<?php
require_once __DIR__.'/../core/Sync.php';
$msg=''; $err='';
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='sync_employees'){
  $res=bo_sync_employees();
  $msg='Sync pegawai selesai. Diterima: '.(int)$res['received'].', disimpan: '.(int)$res['saved'].'.';
  if(empty($res['ok'])) $err=implode('; ',$res['errors'] ?? []);
}
$src=$_GET['source']??'all';
$rows=bo_employee_rows($src);
if(!$rows){
  $res=bo_sync_employees();
  $rows=bo_employee_rows($src);
  if(!empty($res['received'])) $msg='Pegawai otomatis disinkronkan saat halaman dibuka. Disimpan: '.(int)$res['saved'].'.';
}
?>
<style>.employee-list.table-wrap{box-shadow:none;border-radius:10px}.employee-list table{min-width:980px}.employee-list th,.employee-list td{padding:6px 8px;font-size:12px;line-height:1.25;vertical-align:middle}.employee-list th{font-size:11px}.employee-list small{font-size:11px}.employee-list .btn{padding:4px 7px;border-radius:7px;font-size:12px}.employee-list .badge{padding:2px 6px;font-size:11px}.employee-name{font-weight:700}.employee-id{color:var(--muted);font-size:11px}.filters.compact-filters{margin-bottom:10px}.filters.compact-filters>*{max-width:190px}</style>
<div class="page-title"><div><h1>Semua Pegawai</h1><div class="muted">Master pegawai BackOffice dari sinkronisasi toko/dapur, dengan dedup berdasarkan email.</div></div><form method="post"><input type="hidden" name="action" value="sync_employees"><button class="btn primary">Sync Pegawai Sekarang</button></form></div>
<?php if($msg): ?><div class="alert"><?=e($msg)?></div><?php endif; ?>
<?php if($err): ?><div class="alert danger"><?=e($err)?></div><?php endif; ?>
<form class="filters compact-filters"><div><label>Sumber</label><select name="source" onchange="this.form.submit()"><option value="all">Semua</option><option value="adena" <?=$src==='adena'?'selected':''?>>Toko / Adena</option><option value="dapur" <?=$src==='dapur'?'selected':''?>>Dapur</option></select><input type="hidden" name="p" value="employees"></div></form>
<div class="table-wrap employee-list"><table><thead><tr><th>Nama</th><th>Email/HP</th><th>Sumber</th><th>Role & Lokasi</th><th>Status</th><th>Aktivitas</th><th>Terakhir Sync</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
  <td><span class="employee-name"><?=e($r['canonical_name']??'-')?></span><br><span class="employee-id">Master ID: <?=e($r['id']??'-')?></span></td>
  <td><?=e($r['email'] ?: '-')?><br><small><?=e($r['phone'] ?: '')?></small></td>
  <td><span class="badge <?=str_contains((string)($r['sources']??''),'dapur')?'warn':'ok'?>"><?=e(strtoupper((string)($r['sources']??'-')))?></span></td>
  <td><?=e($r['roles_locations']??'-')?><br><small><?=e($r['locations']??'')?></small></td>
  <td><span class="badge <?=((int)($r['assignment_active']??$r['is_active']??1))?'ok':'danger'?>"><?=((int)($r['assignment_active']??$r['is_active']??1))?'Aktif':'Nonaktif'?></span></td>
  <td><?=e((int)($r['activity_count']??0))?></td>
  <td><?=e($r['assignment_seen_at']??$r['last_seen_at']??'-')?></td>
</tr><?php endforeach; if(!$rows): ?><tr><td colspan="7">Data belum tersedia. Pastikan API toko sudah pairing aktif, lalu klik Sync Pegawai Sekarang.</td></tr><?php endif; ?>
</tbody></table></div>
