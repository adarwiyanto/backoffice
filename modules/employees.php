<?php
require_once __DIR__.'/../core/Sync.php';

function bo_employees_redirect(string $message,string $type='success'): void {
  $query=[
    'p'=>'employees',
    'source'=>(string)($_GET['source'] ?? $_POST['source'] ?? 'all'),
    'status'=>(string)($_GET['status'] ?? $_POST['status'] ?? 'active'),
    'sync_notice'=>$message,
    'sync_type'=>$type,
  ];
  header('Location: ?'.http_build_query($query)); exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=(string)($_POST['action'] ?? '');
  try {
    if($action==='sync_employees'){
      $res=bo_sync_employees();
      $message='Sync pegawai selesai. Diterima: '.(int)($res['received'] ?? 0).', disimpan: '.(int)($res['saved'] ?? 0).'.';
      $type='success';
      if(empty($res['ok'])){ $type='error'; $message.=' Error: '.implode('; ', $res['errors'] ?? []); }
      bo_employees_redirect($message,$type);
    }
    if($action==='toggle_employee'){
      $id=(int)($_POST['id'] ?? 0);
      $person=bo_exec('SELECT id,canonical_name,manually_disabled FROM bo_employee_people WHERE id=? LIMIT 1',[$id])->fetch();
      if(!$person) bo_employees_redirect('Pegawai tidak ditemukan.','error');
      $disabled=(int)$person['manually_disabled'] ? 0 : 1;
      bo_exec('UPDATE bo_employee_people SET manually_disabled=?,updated_at=NOW() WHERE id=?',[$disabled,$id]);
      bo_employees_redirect(($disabled?'Pegawai dinonaktifkan dan dikeluarkan dari perhitungan.':'Pegawai diaktifkan kembali.'));
    }
    bo_employees_redirect('Aksi pegawai tidak dikenali.','error');
  } catch(Throwable $e){
    error_log('[BackOffice Employees] action='.$action.' error='.$e->getMessage().' file='.$e->getFile().':'.$e->getLine());
    bo_employees_redirect('Proses tidak dapat diselesaikan. Detail teknis telah dicatat pada error log.','error');
  }
}

$msg=trim((string)($_GET['sync_notice']??''));
$err=(($_GET['sync_type']??'')==='error')?$msg:'';
if($err!=='') $msg='';
$src=(string)($_GET['source'] ?? 'all');
$status=(string)($_GET['status'] ?? 'active');
if(!in_array($src,['all','adena','dapur'],true)) $src='all';
if(!in_array($status,['active','inactive','all'],true)) $status='active';
$rows=bo_employee_rows($src,$status);
?>
<style>
.employee-list.table-wrap{box-shadow:none;border-radius:10px}.employee-list table{min-width:980px}.employee-list th,.employee-list td{padding:6px 8px;font-size:12px;line-height:1.25;vertical-align:middle}.employee-list th{font-size:11px}.employee-list small{font-size:11px}.employee-list .btn{padding:4px 7px;border-radius:7px;font-size:12px}.employee-list .badge{padding:2px 6px;font-size:11px}.employee-name{font-weight:700}.employee-id{color:var(--muted);font-size:11px}.filters.compact-filters{margin-bottom:10px}.filters.compact-filters>*{max-width:190px}</style>
<div class="page-title"><div><h1>Pegawai</h1><div class="muted">Master pegawai toko dan dapur. Role owner tidak ditampilkan dan pegawai nonaktif tidak masuk perhitungan aktivitas.</div></div><form method="post"><input type="hidden" name="action" value="sync_employees"><input type="hidden" name="source" value="<?=e($src)?>"><input type="hidden" name="status" value="<?=e($status)?>"><button class="btn primary">Sync Pegawai Sekarang</button></form></div>
<?php if($msg): ?><div class="alert"><?=e($msg)?></div><?php endif; ?>
<?php if($err): ?><div class="alert danger"><?=e($err)?></div><?php endif; ?>
<form class="filters compact-filters" method="get">
  <input type="hidden" name="p" value="employees">
  <div><label>Sumber</label><select name="source" onchange="this.form.submit()"><option value="all">Semua</option><option value="adena" <?=$src==='adena'?'selected':''?>>Toko / Adena</option><option value="dapur" <?=$src==='dapur'?'selected':''?>>Dapur</option></select></div>
  <div><label>Status</label><select name="status" onchange="this.form.submit()"><option value="active" <?=$status==='active'?'selected':''?>>Aktif</option><option value="inactive" <?=$status==='inactive'?'selected':''?>>Nonaktif</option><option value="all" <?=$status==='all'?'selected':''?>>Semua status</option></select></div>
</form>
<div class="table-wrap employee-list"><table><thead><tr><th>Nama</th><th>Email/HP</th><th>Sumber</th><th>Role & Lokasi</th><th>Status</th><th>Aktivitas</th><th>Terakhir Sync</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($rows as $r): $inactive=(int)($r['manually_disabled']??0)===1; ?><tr>
  <td><span class="employee-name"><?=e($r['canonical_name']??'-')?></span><br><span class="employee-id">Master ID: <?=e($r['id']??'-')?></span></td>
  <td><?=e(($r['email'] ?? '') ?: '-')?><br><small><?=e(($r['phone'] ?? '') ?: '')?></small></td>
  <td><span class="badge <?=str_contains((string)($r['sources']??''),'dapur')?'warn':'ok'?>"><?=e(strtoupper((string)($r['sources']??'-')))?></span></td>
  <td><?=e($r['roles_locations']??'-')?><br><small><?=e($r['locations']??'')?></small></td>
  <td><span class="badge <?=$inactive?'danger':'ok'?>"><?=$inactive?'Nonaktif':'Aktif'?></span></td>
  <td><?=e((int)($r['activity_count']??0))?></td>
  <td><?=e($r['assignment_seen_at']??$r['last_seen_at']??'-')?></td>
  <td><form method="post"><input type="hidden" name="action" value="toggle_employee"><input type="hidden" name="id" value="<?=e($r['id'])?>"><input type="hidden" name="source" value="<?=e($src)?>"><input type="hidden" name="status" value="<?=e($status)?>"><button class="btn" data-confirm="<?=$inactive?'Aktifkan kembali pegawai ini?':'Nonaktifkan pegawai ini dari perhitungan?'?>"><?=$inactive?'Aktifkan':'Nonaktifkan'?></button></form></td>
</tr><?php endforeach; if(!$rows): ?><tr><td colspan="8">Tidak ada pegawai pada filter ini. Role owner memang tidak ditampilkan.</td></tr><?php endif; ?>
</tbody></table></div>
