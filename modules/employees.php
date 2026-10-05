<?php
require_once __DIR__.'/../core/Sync.php';

function bo_employees_redirect(string $message,string $type='success',?string $month=null): void {
  $query=[
    'p'=>'employees',
    'source'=>(string)($_GET['source'] ?? $_POST['source'] ?? 'all'),
    'status'=>(string)($_GET['status'] ?? $_POST['status'] ?? 'active'),
    'month'=>$month ?: (string)($_GET['month'] ?? $_POST['month'] ?? date('Y-m')),
    'sync_notice'=>$message,
    'sync_type'=>$type,
  ];
  header('Location: ?'.http_build_query($query)); exit;
}
function bo_emp_valid_month(string $m): bool {return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$m);}
function bo_emp_valid_date_in_month(string $d,string $m): bool {return preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)&&str_starts_with($d,$m.'-')&&$d>=($m.'-01')&&$d<=date('Y-m-t',strtotime($m.'-01'));}
function bo_emp_schedule_ready(): bool {return function_exists('bo_table_exists')&&bo_table_exists('bo_employee_schedule_days')&&bo_table_exists('bo_employee_leave_periods');}
function bo_emp_schedule_people(): array {
  $out=[];
  $rows=bo_exec("SELECT a.id assignment_id,a.source_system,a.system_key,a.system_name,a.external_employee_id,a.role_key,a.role_label,a.location,p.canonical_name FROM bo_employee_assignments a JOIN bo_employee_people p ON p.id=a.person_id WHERE a.is_active=1 AND p.is_active=1 AND p.manually_disabled=0 AND LOWER(TRIM(COALESCE(a.role_key,''))) NOT IN ('owner','superadmin') ORDER BY a.source_system,a.system_name,p.canonical_name")->fetchAll()?:[];
  foreach($rows as $r){$r['employee_key']='assignment:'.(int)$r['assignment_id'];$r['source_label']=(string)$r['source_system']==='dapur'?'Dapur':'Toko';$out[]=$r;}
  if(bo_table_exists('bo_users'))foreach(bo_exec("SELECT id,name,username,role_key FROM bo_users WHERE is_active=1 AND LOWER(TRIM(role_key))='admin' ORDER BY name")->fetchAll()?:[] as $r){$out[]=['employee_key'=>'admin:'.(int)$r['id'],'canonical_name'=>(string)$r['name'],'system_name'=>'Back Office','source_label'=>'Back Office','role_label'=>'Admin','role_key'=>'admin','external_employee_id'=>(string)$r['username'],'location'=>''];}
  return $out;
}

$month=(string)($_GET['month']??$_POST['month']??date('Y-m'));if(!bo_emp_valid_month($month))$month=date('Y-m');
$user=bo_user()??[];$canWrite=in_array(strtolower((string)($user['role_key']??'')),['owner','admin'],true);$uid=(int)($user['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=(string)($_POST['action'] ?? '');
  try {
    if($action==='sync_employees'){
      $res=bo_sync_employees();
      $message='Sync pegawai selesai. Diterima: '.(int)($res['received'] ?? 0).', disimpan: '.(int)($res['saved'] ?? 0).'.';
      $type='success';
      if(empty($res['ok'])){ $type='error'; $message.=' Error: '.implode('; ', $res['errors'] ?? []); }
      bo_employees_redirect($message,$type,$month);
    }
    if(!$canWrite)bo_employees_redirect('Perubahan jadwal/pegawai hanya dapat dilakukan Owner atau Admin.','error',$month);
    if($action==='toggle_employee'){
      $id=(int)($_POST['id'] ?? 0);
      $person=bo_exec('SELECT id,canonical_name,manually_disabled FROM bo_employee_people WHERE id=? LIMIT 1',[$id])->fetch();
      if(!$person) bo_employees_redirect('Pegawai tidak ditemukan.','error',$month);
      $disabled=(int)$person['manually_disabled'] ? 0 : 1;
      bo_exec('UPDATE bo_employee_people SET manually_disabled=?,updated_at=NOW() WHERE id=?',[$disabled,$id]);
      bo_employees_redirect(($disabled?'Pegawai dinonaktifkan dan dikeluarkan dari perhitungan.':'Pegawai diaktifkan kembali.'),'success',$month);
    }
    if(in_array($action,['save_schedule','add_leave','delete_leave'],true)&&!bo_emp_schedule_ready())throw new RuntimeException('Import db/20261005_010_schedule_leave_eligibility.sql terlebih dahulu.');
    if($action==='save_schedule'){
      $key=trim((string)($_POST['employee_key']??''));if(!preg_match('/^(assignment|admin):\d+$/',$key))throw new RuntimeException('Pegawai jadwal tidak valid.');
      $statuses=(array)($_POST['status']??[]);$notes=(array)($_POST['day_notes']??[]);$first=$month.'-01';$last=date('Y-m-t',strtotime($first));
      bo_db()->beginTransaction();
      for($d=$first;$d<=$last;$d=date('Y-m-d',strtotime($d.' +1 day'))){$st=(string)($statuses[$d]??'work');if(!in_array($st,['work','off'],true))$st='work';$note=trim((string)($notes[$d]??''));bo_exec("INSERT INTO bo_employee_schedule_days(schedule_month,employee_key,work_date,planned_status,final_status,notes,created_by,updated_by,created_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE final_status=VALUES(final_status),notes=VALUES(notes),updated_by=VALUES(updated_by),updated_at=NOW()",[$month,$key,$d,$st,$st,$note!==''?$note:null,$uid,$uid]);}
      bo_db()->commit();bo_employees_redirect('Jadwal final bulan '.$month.' disimpan. Jadwal awal tetap tersimpan sebagai rencana.','success',$month);
    }
    if($action==='add_leave'){
      $key=trim((string)($_POST['employee_key']??''));$start=trim((string)($_POST['start_date']??''));$end=trim((string)($_POST['end_date']??''));if(!preg_match('/^(assignment|admin):\d+$/',$key)||!bo_emp_valid_date_in_month($start,$month)||!bo_emp_valid_date_in_month($end,$month)||$end<$start)throw new RuntimeException('Rentang tanggal cuti tidak valid atau berada di luar periode payroll.');
      bo_exec('INSERT INTO bo_employee_leave_periods(leave_month,employee_key,start_date,end_date,notes,created_by,updated_by,created_at) VALUES(?,?,?,?,?,?,?,NOW())',[$month,$key,$start,$end,trim((string)($_POST['leave_notes']??''))?:null,$uid,$uid]);
      bo_employees_redirect('Periode cuti ditambahkan. Hari yang jadwal finalnya Libur tidak akan dihitung sebagai cuti efektif.','success',$month);
    }
    if($action==='delete_leave'){
      $id=(int)($_POST['leave_id']??0);bo_exec('DELETE FROM bo_employee_leave_periods WHERE id=? AND leave_month=?',[$id,$month]);bo_employees_redirect('Periode cuti dihapus.','success',$month);
    }
    bo_employees_redirect('Aksi pegawai tidak dikenali.','error',$month);
  } catch(Throwable $e){
    if(bo_db()->inTransaction())bo_db()->rollBack();
    error_log('[BackOffice Employees] action='.$action.' error='.$e->getMessage().' file='.$e->getFile().':'.$e->getLine());
    bo_employees_redirect($e->getMessage(),'error',$month);
  }
}

$msg=trim((string)($_GET['sync_notice']??''));
$err=(($_GET['sync_type']??'')==='error')?$msg:'';
if($err!=='') $msg='';
$src=(string)($_GET['source'] ?? 'all');
$status=(string)($_GET['status'] ?? 'active');
if(!in_array($src,['all','adena','dapur'],true)) $src='all';
if(!in_array($status,['active','inactive','all'],true)) $status='active';
$rows=bo_employee_rows($src,$status);$scheduleReady=bo_emp_schedule_ready();$schedulePeople=$scheduleReady?bo_emp_schedule_people():[];$schedule=[];$leaves=[];
if($scheduleReady){foreach(bo_exec('SELECT employee_key,work_date,planned_status,final_status,notes FROM bo_employee_schedule_days WHERE schedule_month=?',[$month])->fetchAll()?:[] as $r)$schedule[(string)$r['employee_key']][(string)$r['work_date']]=$r;foreach(bo_exec('SELECT id,employee_key,start_date,end_date,notes FROM bo_employee_leave_periods WHERE leave_month=? ORDER BY start_date',[$month])->fetchAll()?:[] as $r)$leaves[(string)$r['employee_key']][]=$r;}
$calendar=[];$first=$month.'-01';$last=date('Y-m-t',strtotime($first));for($d=$first;$d<=$last;$d=date('Y-m-d',strtotime($d.' +1 day')))$calendar[]=$d;
$schedulePayload=['month'=>$month,'calendar'=>$calendar,'schedule'=>$schedule,'leaves'=>$leaves];
?>
<style>
.employee-list.table-wrap{box-shadow:none;border-radius:10px}.employee-list table{min-width:980px}.employee-list th,.employee-list td{padding:6px 8px;font-size:12px;line-height:1.25;vertical-align:middle}.employee-list th{font-size:11px}.employee-list small{font-size:11px}.employee-list .btn{padding:4px 7px;border-radius:7px;font-size:12px}.employee-list .badge{padding:2px 6px;font-size:11px}.employee-name{font-weight:700}.employee-id{color:var(--muted);font-size:11px}.filters.compact-filters{margin-bottom:10px}.filters.compact-filters>*{max-width:190px}
.schedule-dialog{width:min(980px,96vw);border:0;border-radius:14px;padding:0}.schedule-dialog::backdrop{background:rgba(15,23,42,.55)}.schedule-body{padding:16px;max-height:78vh;overflow:auto}.schedule-head{display:flex;justify-content:space-between;align-items:center;padding:16px;border-bottom:1px solid #e5e7eb;gap:12px}.week-card{border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin:10px 0}.week-grid{display:grid;grid-template-columns:repeat(7,minmax(95px,1fr));gap:8px}.day-box{border:1px solid #eef2f7;border-radius:8px;padding:7px}.day-box .date{font-weight:700;font-size:12px;margin-bottom:5px}.day-box select{padding:6px;font-size:12px}.leave-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px;border-bottom:1px solid #eef2f7}.schedule-actions{position:sticky;bottom:0;background:#fff;border-top:1px solid #e5e7eb;padding:12px 16px;display:flex;justify-content:flex-end;gap:8px}.legend{display:flex;gap:10px;flex-wrap:wrap;font-size:12px}.legend span{padding:3px 7px;border-radius:999px;background:#f3f4f6}@media(max-width:760px){.week-grid{grid-template-columns:repeat(2,minmax(120px,1fr))}}
</style>
<div class="page-title"><div><h1>Pegawai</h1><div class="muted">Master pegawai serta jadwal kerja/libur dan cuti bulanan. Hari libur mingguan tetap merupakan hak pegawai dan tidak dihitung sebagai cuti efektif.</div></div><form method="post"><input type="hidden" name="action" value="sync_employees"><input type="hidden" name="source" value="<?=e($src)?>"><input type="hidden" name="status" value="<?=e($status)?>"><input type="hidden" name="month" value="<?=e($month)?>"><button class="btn primary">Sync Pegawai Sekarang</button></form></div>
<?php if($msg): ?><div class="alert"><?=e($msg)?></div><?php endif; ?>
<?php if($err): ?><div class="alert danger"><?=e($err)?></div><?php endif; ?>
<form class="filters compact-filters" method="get"><input type="hidden" name="p" value="employees"><div><label>Sumber</label><select name="source" onchange="this.form.submit()"><option value="all">Semua</option><option value="adena" <?=$src==='adena'?'selected':''?>>Toko / Adena</option><option value="dapur" <?=$src==='dapur'?'selected':''?>>Dapur</option></select></div><div><label>Status</label><select name="status" onchange="this.form.submit()"><option value="active" <?=$status==='active'?'selected':''?>>Aktif</option><option value="inactive" <?=$status==='inactive'?'selected':''?>>Nonaktif</option><option value="all" <?=$status==='all'?'selected':''?>>Semua status</option></select></div><div><label>Periode Jadwal</label><input type="month" name="month" value="<?=e($month)?>" onchange="this.form.submit()"></div></form>
<div class="table-wrap employee-list"><table><thead><tr><th>Nama</th><th>Email/HP</th><th>Sumber</th><th>Role & Lokasi</th><th>Status</th><th>Aktivitas</th><th>Terakhir Sync</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($rows as $r): $inactive=(int)($r['manually_disabled']??0)===1; ?><tr><td><span class="employee-name"><?=e($r['canonical_name']??'-')?></span><br><span class="employee-id">Master ID: <?=e($r['id']??'-')?></span></td><td><?=e(($r['email'] ?? '') ?: '-')?><br><small><?=e(($r['phone'] ?? '') ?: '')?></small></td><td><span class="badge <?=str_contains((string)($r['sources']??''),'dapur')?'warn':'ok'?>"><?=e(strtoupper((string)($r['sources']??'-')))?></span></td><td><?=e($r['roles_locations']??'-')?><br><small><?=e($r['locations']??'')?></small></td><td><span class="badge <?=$inactive?'danger':'ok'?>"><?=$inactive?'Nonaktif':'Aktif'?></span></td><td><?=e((int)($r['activity_count']??0))?></td><td><?=e($r['assignment_seen_at']??$r['last_seen_at']??'-')?></td><td><form method="post"><input type="hidden" name="action" value="toggle_employee"><input type="hidden" name="id" value="<?=e($r['id'])?>"><input type="hidden" name="source" value="<?=e($src)?>"><input type="hidden" name="status" value="<?=e($status)?>"><input type="hidden" name="month" value="<?=e($month)?>"><button class="btn" data-confirm="<?=$inactive?'Aktifkan kembali pegawai ini?':'Nonaktifkan pegawai ini dari perhitungan?'?>"><?=$inactive?'Aktifkan':'Nonaktifkan'?></button></form></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="8">Tidak ada pegawai pada filter ini. Role owner memang tidak ditampilkan.</td></tr><?php endif; ?></tbody></table></div>

<div class="card section"><div class="section-head"><div><h3>Jadwal Kerja, Libur & Cuti — <?=e($month)?></h3><div class="muted">Setoran jadwal dapat diedit sepanjang bulan. Simpan pertama menjadi rencana awal; perubahan berikutnya menjadi jadwal final. Payroll hanya menganggap cuti pada tanggal yang jadwal finalnya <b>Kerja</b>.</div></div><form method="get" class="filters"><input type="hidden" name="p" value="employees"><input type="hidden" name="source" value="<?=e($src)?>"><input type="hidden" name="status" value="<?=e($status)?>"><input type="month" name="month" value="<?=e($month)?>"><button class="btn">Tampilkan</button></form></div>
<?php if(!$scheduleReady): ?><div class="alert danger">Import <b>db/20261005_010_schedule_leave_eligibility.sql</b> terlebih dahulu.</div><?php else: ?>
<div class="legend"><span>Kerja = tetap eligible</span><span>Libur = tetap eligible/hak pegawai</span><span>Cuti efektif = periode cuti ∩ jadwal Kerja</span></div>
<div class="table-wrap section"><table><thead><tr><th>Pegawai</th><th>Unit</th><th>Rencana/Final</th><th>Periode Cuti</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($schedulePeople as $sp): $key=(string)$sp['employee_key'];$saved=count($schedule[$key]??[]);$leaveCount=count($leaves[$key]??[]); ?><tr><td><b><?=e($sp['canonical_name'])?></b><br><small><?=e($sp['external_employee_id']??'')?></small></td><td><?=e($sp['system_name'])?><br><small><?=e($sp['role_label']?:$sp['role_key'])?></small></td><td><?=$saved?>/<?=count($calendar)?> tanggal tersimpan</td><td><?=$leaveCount?> periode</td><td><button type="button" class="btn primary js-open-schedule" data-key="<?=e($key)?>" data-name="<?=e($sp['canonical_name'])?>" data-unit="<?=e($sp['system_name'])?>">Jadwal & Cuti</button></td></tr><?php endforeach;if(!$schedulePeople): ?><tr><td colspan="5">Belum ada pegawai aktif.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?></div>

<?php if($scheduleReady): ?><dialog class="schedule-dialog" id="scheduleDialog"><div class="schedule-head"><div><b id="scheduleName">Jadwal Pegawai</b><div class="muted" id="scheduleUnit"></div></div><button type="button" class="btn" onclick="document.getElementById('scheduleDialog').close()">Tutup</button></div><div class="schedule-body">
<form method="post" id="scheduleForm"><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="employee_key" id="scheduleEmployeeKey"><input type="hidden" name="month" value="<?=e($month)?>"><div class="alert">Pilih <b>Libur</b> hanya untuk hari libur normal. Jika tanggal tersebut berada di rentang cuti, status Libur tetap tidak dihitung sebagai cuti efektif.</div><div id="weeksContainer"></div><div class="schedule-actions"><button type="button" class="btn" onclick="document.getElementById('scheduleDialog').close()">Batal</button><?php if($canWrite): ?><button class="btn primary">Simpan Jadwal Final</button><?php endif; ?></div></form>
<div class="section"><h3>Periode Cuti</h3><div class="muted">Boleh lebih dari satu rentang dalam satu bulan. Sistem otomatis mengabaikan tanggal Libur di dalam rentang cuti.</div><div id="leaveList"></div><?php if($canWrite): ?><form method="post" class="filters section"><input type="hidden" name="action" value="add_leave"><input type="hidden" name="employee_key" id="leaveEmployeeKey"><input type="hidden" name="month" value="<?=e($month)?>"><div><label>Dari tanggal</label><input type="date" name="start_date" min="<?=e($first)?>" max="<?=e($last)?>" required></div><div><label>Sampai tanggal</label><input type="date" name="end_date" min="<?=e($first)?>" max="<?=e($last)?>" required></div><div><label>Catatan</label><input type="text" name="leave_notes" placeholder="Opsional"></div><div><button class="btn primary">Tambah Cuti</button></div></form><?php endif; ?></div></div></dialog>
<script type="application/json" id="schedulePayload"><?=json_encode($schedulePayload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
<script>(function(){const data=JSON.parse(document.getElementById('schedulePayload').textContent),dlg=document.getElementById('scheduleDialog'),weeks=document.getElementById('weeksContainer'),leaveList=document.getElementById('leaveList');const dayNames=['Min','Sen','Sel','Rab','Kam','Jum','Sab'];function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}function weekNo(d){const x=new Date(d+'T12:00:00');const one=new Date(x.getFullYear(),0,1);return Math.ceil((((x-one)/86400000)+one.getDay()+1)/7);}function render(key){document.getElementById('scheduleEmployeeKey').value=key;document.getElementById('leaveEmployeeKey').value=key;const grouped={};data.calendar.forEach(d=>{const w=weekNo(d);(grouped[w]??=[]).push(d)});weeks.innerHTML=Object.entries(grouped).map(([w,dates])=>`<div class="week-card"><div class="section-head"><b>Minggu ${w}</b><button type="button" class="btn js-week-work" data-week="${w}">Set semua Kerja</button></div><div class="week-grid">${dates.map(d=>{const r=(data.schedule[key]||{})[d]||{};const st=r.final_status||'work';const dt=new Date(d+'T12:00:00');return `<div class="day-box" data-week="${w}"><div class="date">${dayNames[dt.getDay()]} · ${d.slice(8,10)}</div><select name="status[${d}]" ${<?= $canWrite?'false':'true' ?>?'disabled':''}><option value="work" ${st==='work'?'selected':''}>Kerja</option><option value="off" ${st==='off'?'selected':''}>Libur</option></select><input type="text" name="day_notes[${d}]" value="${esc(r.notes||'')}" placeholder="Catatan" ${<?= $canWrite?'false':'true' ?>?'disabled':''}></div>`}).join('')}</div></div>`).join('');document.querySelectorAll('.js-week-work').forEach(b=>b.onclick=()=>document.querySelectorAll(`.day-box[data-week="${b.dataset.week}"] select`).forEach(s=>s.value='work'));
 const ls=data.leaves[key]||[];leaveList.innerHTML=ls.length?ls.map(r=>`<div class="leave-row"><div><b>${esc(r.start_date)} s/d ${esc(r.end_date)}</b><br><small>${esc(r.notes||'')}</small></div>${<?= $canWrite?'true':'false' ?>?`<form method="post"><input type="hidden" name="action" value="delete_leave"><input type="hidden" name="month" value="${esc(data.month)}"><input type="hidden" name="leave_id" value="${esc(r.id)}"><button class="btn" data-confirm="Hapus periode cuti ini?">Hapus</button></form>`:''}</div>`).join(''):'<div class="muted section">Belum ada periode cuti.</div>';}
document.querySelectorAll('.js-open-schedule').forEach(b=>b.addEventListener('click',()=>{document.getElementById('scheduleName').textContent=b.dataset.name;document.getElementById('scheduleUnit').textContent=b.dataset.unit+' · '+data.month;render(b.dataset.key);dlg.showModal();}));})();</script><?php endif; ?>
