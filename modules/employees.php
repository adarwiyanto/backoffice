<?php
function bo_emp_role_label(array $r): string {
  $source=strtolower((string)($r['source']??''));
  $key=strtolower(trim((string)($r['role_key']??'')));
  $label=trim((string)($r['role']??''));
  if($source==='dapur') return match($key){ 'owner'=>'Owner', 'admin_dapur'=>'Admin Dapur', 'manager_dapur','kepala_dapur'=>'Manajer Dapur', default => ($label!==''?$label:'Pegawai Dapur') };
  if($source==='adena') return match($key){ 'owner'=>'Owner', 'admin'=>'Admin Toko', 'manager_cabang','manager'=>'Manajer Toko', default => ($label!==''?$label:'Pegawai Toko') };
  return $label!==''?$label:'-';
}
function bo_emp_connection_name(array $conn): string { return (string)($conn['system_name'] ?? $conn['system_key'] ?? ucfirst((string)($conn['system_type']??'Sistem'))); }

$rows=[];
$connectionStates=[];
foreach(['adena','dapur'] as $type){
  foreach(bo_connections_by_type($type) as $conn){
    $name=bo_emp_connection_name($conn);
    $res=bo_api_request_connection($conn,'api/backoffice/employees.php');
    $ok=!empty($res['ok']);
    $payload=$res['data']??null;
    if($ok && !is_array($payload)){
      $ok=false;
      $res['message']='Format data pegawai tidak valid.';
    }
    $connectionStates[]=[
      'name'=>$name,
      'type'=>$type,
      'ok'=>$ok,
      'count'=>$ok?count($payload):0,
      'status_code'=>(int)($res['status_code']??0),
      'message'=>(string)($res['message']??($ok?'OK':'Endpoint pegawai gagal diakses.')),
    ];
    if($ok){
      foreach($payload as $row){
        if(!is_array($row)) continue;
        $row['_connection']=$name;
        if(empty($row['source'])) $row['source']=$type;
        $rows[]=$row;
      }
    }
  }
}
$src=$_GET['source']??'all';
if($src!=='all') $rows=array_values(array_filter($rows,fn($r)=>($r['source']??'')===$src));
usort($rows, fn($a,$b)=>strcmp(strtolower(($a['source']??'').($a['name']??'')), strtolower(($b['source']??'').($b['name']??''))));
?>
<style>
.employee-list.table-wrap{box-shadow:none;border-radius:10px}.employee-list table{min-width:900px}.employee-list th,.employee-list td{padding:5px 8px;font-size:12px;line-height:1.2;vertical-align:middle}.employee-list th{font-size:11px}.employee-list small{font-size:11px}.employee-list .btn{padding:4px 7px;border-radius:7px;font-size:12px}.employee-list .badge{padding:2px 6px;font-size:11px}.employee-name{font-weight:700}.employee-id{color:var(--muted);font-size:11px}.filters.compact-filters{margin-bottom:10px}.filters.compact-filters>*{max-width:180px}.employee-api-states{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.employee-api-state{padding:8px 10px;border:1px solid #e5e7eb;border-radius:9px;background:#fff;font-size:12px}.employee-api-state.failed{border-color:#fecaca;background:#fef2f2}.employee-api-state strong{display:block;margin-bottom:2px}
</style>
<div class="page-title"><div><h1>Semua Pegawai</h1><div class="muted">Gabungan pegawai toko dan dapur dari semua koneksi API aktif.</div></div></div>
<div class="employee-api-states">
<?php foreach($connectionStates as $state): ?>
  <div class="employee-api-state <?=$state['ok']?'':'failed'?>">
    <strong><?=e($state['name'])?> — <?=$state['ok']?'Terhubung':'Gagal'?></strong>
    <?php if($state['ok']): ?><?=e($state['count'])?> pegawai<?php else: ?>HTTP <?=e($state['status_code'])?> · <?=e($state['message'])?><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if(!$connectionStates): ?><div class="employee-api-state failed"><strong>Belum ada koneksi aktif</strong>Hubungkan Adena atau Dapur pada menu Integrasi.</div><?php endif; ?>
</div>
<form class="filters compact-filters"><div><label>Sumber</label><select name="source" onchange="this.form.submit()"><option value="all">Semua</option><option value="adena" <?=$src==='adena'?'selected':''?>>Toko / Adena</option><option value="dapur" <?=$src==='dapur'?'selected':''?>>Dapur</option></select><input type="hidden" name="p" value="employees"></div></form>
<div class="table-wrap employee-list"><table><thead><tr><th>Nama</th><th>Sumber</th><th>Role/Jabatan</th><th>Koneksi/Lokasi</th><th>HP/Email</th><th>Status</th><th>Aktivitas</th><th>KPI</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><span class="employee-name"><?=e($r['name']??'-')?></span><br><span class="employee-id">ID: <?=e($r['employee_id']??'-')?></span></td><td><span class="badge <?=($r['source']??'')==='dapur'?'warn':'ok'?>"><?=e(($r['source']??'')==='dapur'?'DAPUR':'TOKO')?></span></td><td><?=e(bo_emp_role_label($r))?></td><td><?=e($r['_connection']??'-')?> / <?=e($r['location']??'-')?></td><td><?=e($r['phone']??($r['email']??'-'))?></td><td><span class="badge <?=($r['is_active']??true)?'ok':'danger'?>"><?=($r['is_active']??true)?'Aktif':'Nonaktif'?></span></td><td><?=e($r['activity_count']??0)?></td><td><a class="btn" href="?p=kpi&source=<?=e($r['source']??'')?>&id=<?=e($r['employee_id']??'')?>">Lihat</a></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="8"><?php $failed=array_filter($connectionStates,fn($s)=>!$s['ok']); echo $failed?'Data pegawai belum tampil karena satu atau lebih endpoint gagal. Lihat status koneksi di atas.':'Belum ada data pegawai pada koneksi aktif.'; ?></td></tr><?php endif; ?></tbody></table></div>
