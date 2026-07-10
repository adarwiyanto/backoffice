<?php
require_once __DIR__.'/../core/Sync.php';
function bo_pair_code(): string { return 'BO-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(3))); }
function bo_pair_secret(): string { return bin2hex(random_bytes(32)); }
function bo_normalize_url(string $url): string { $url=trim($url); if($url!==''&&!preg_match('~^https://~i',$url)) $url='https://'.preg_replace('~^http://~i','',$url); return rtrim($url,'/'); }
function bo_scope_for_target(string $target): string { return 'backoffice_backup'; }
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $act=$_POST['action']??'';
 if($act==='request_pairing'){
  $target=$_POST['target_system']??'adena'; $name=trim($_POST['target_name']??ucfirst($target)); $url=bo_normalize_url($_POST['target_base_url']??'');
  if($url==='') $msg='URL tujuan wajib diisi.'; else {
    $code=bo_pair_code(); $secret=bo_pair_secret(); $cfg=bo_config(); $boUrl=rtrim($cfg['app']['base_url']??'', '/');
    $payload=['request_code'=>$code,'request_secret_hash'=>password_hash($secret,PASSWORD_DEFAULT),'requester_name'=>'Back Office','requester_type'=>'backoffice','requester_base_url'=>$boUrl,'target_type'=>$target,'callback_url'=>''];
    $res=bo_remote_json($url,'api/pairing/request.php',$payload,'POST');
    bo_exec('INSERT INTO bo_pairing_requests(target_system,target_name,target_base_url,request_code,request_secret,request_secret_hash,requester_name,requester_type,requested_scope,status,message,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())',[$target,$name,$url,$code,bo_encrypt_secret($secret),password_hash($secret,PASSWORD_DEFAULT),'Back Office','backoffice',bo_scope_for_target($target),!empty($res['ok'])?'pending':'failed',$res['message']??'']);
    $msg=!empty($res['ok'])?'Request pairing terkirim. Approve dari sistem tujuan.':'Request gagal: '.($res['message']??'error');
  }
 }
 if($act==='check_pairing'){
  $id=(int)($_POST['id']??0); $st=bo_exec('SELECT * FROM bo_pairing_requests WHERE id=?',[$id]); $r=$st->fetch();
  if($r){
    $secret=bo_decrypt_secret($r['request_secret'] ?? '');
    if($secret==='') $secret=(string)($r['request_secret'] ?? '');
    $res=bo_remote_json($r['target_base_url'],'api/pairing/status.php',['request_code'=>$r['request_code'],'request_secret'=>$secret],'GET');
    $status=$res['status']??($res['ok']?'pending':'failed');
    bo_exec('UPDATE bo_pairing_requests SET status=?,message=?,last_checked_at=NOW(),updated_at=NOW() WHERE id=?',[$status,$res['message']??'', $id]);
    if($status==='approved' && !empty($res['access_token'])){
      $scope=$res['access_scope']??bo_scope_for_target($r['target_system']);
      $existing=bo_exec('SELECT system_key FROM bo_system_connections WHERE base_url=? AND (system_type=? OR system_key=?) LIMIT 1',[$r['target_base_url'],$r['target_system'],$r['target_system']])->fetch();
      $systemKey=$existing['system_key']??bo_next_system_key($r['target_system']);
      $token=(string)$res['access_token'];
      bo_exec('INSERT INTO bo_system_connections(system_key,system_name,system_type,base_url,api_token,api_token_hash,api_token_encrypted,access_scope,status,is_active,paired_at,token_last_rotated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,1,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE system_name=VALUES(system_name),system_type=VALUES(system_type),base_url=VALUES(base_url),api_token=NULL,api_token_hash=VALUES(api_token_hash),api_token_encrypted=VALUES(api_token_encrypted),access_scope=VALUES(access_scope),status=VALUES(status),is_active=1,paired_at=NOW(),token_last_rotated_at=NOW(),updated_at=NOW()',[$systemKey,$r['target_name'],$r['target_system'],$r['target_base_url'],null,hash('sha256',$token),bo_encrypt_secret($token),$scope,'active']);
      bo_exec('UPDATE bo_pairing_requests SET access_token=NULL,access_token_hash=?,access_token_encrypted=? WHERE id=?',[hash('sha256',$token),bo_encrypt_secret($token),$id]);
    }
    $msg='Status pairing: '.$status;
  }
 }
 if($act==='health_check'){ foreach(bo_exec('SELECT * FROM bo_system_connections WHERE is_active=1 ORDER BY id ASC')->fetchAll() as $conn) bo_health_check_connection($conn); $msg='Health check selesai.'; }
 if($act==='sync_employees'){ $r=bo_sync_employees(); $msg='Sync pegawai selesai. Diterima '.(int)$r['received'].', disimpan '.(int)$r['saved'].'.'; if(empty($r['ok'])) $msg.=' Error: '.implode('; ',$r['errors']??[]); }
 if($act==='backup_all'){ $r=bo_backup_all(); $msg='Backup selesai. Item: '.count($r['results']).'.'.(empty($r['ok'])?' Error: '.implode('; ',$r['errors']):''); }
 if($act==='run_test'){
   $id=(int)($_POST['connection_id']??0); $test=(string)($_POST['test_key']??'health');
   $conn=bo_exec('SELECT * FROM bo_system_connections WHERE id=? LIMIT 1',[$id])->fetch();
   if($conn){ $r=bo_run_api_test($conn,$test); $msg='Test '.$test.' '.(!empty($r['ok'])?'berhasil':'gagal').': '.$r['message']; }
 }
 if($act==='run_all_tests'){
   $tests=['health','auth','employees','products','sales','stock','transfer','transaction']; $ok=0; $fail=0;
   foreach(bo_exec('SELECT * FROM bo_system_connections WHERE is_active=1 ORDER BY id ASC')->fetchAll() as $conn){
     foreach($tests as $test){ $r=bo_run_api_test($conn,$test); if(!empty($r['ok'])) $ok++; else $fail++; }
   }
   $msg='Test semua selesai. OK: '.$ok.', gagal: '.$fail.'.';
 }
}
$pairings=bo_exec('SELECT * FROM bo_pairing_requests ORDER BY id DESC LIMIT 100')->fetchAll();
$conns=bo_exec('SELECT * FROM bo_system_connections ORDER BY system_key')->fetchAll();
$logs=bo_exec('SELECT * FROM bo_sync_logs ORDER BY id DESC LIMIT 50')->fetchAll();
$tests=bo_exec('SELECT * FROM bo_api_test_runs ORDER BY id DESC LIMIT 30')->fetchAll();
$backupRuns=bo_exec('SELECT * FROM bo_backup_runs ORDER BY id DESC LIMIT 30')->fetchAll();
$pending=0; foreach($pairings as $p){ if($p['status']==='pending') $pending++; }
?>
<div class="page-title"><div><h1>Integrasi & Pairing API</h1><div class="muted">Request pairing otomatis. Tidak perlu input token manual.</div></div><div class="notif"><button class="btn">🔔 <?= $pending?'<span class="notif-badge">'.$pending.'</span>':'' ?></button></div></div>
<style>.notif-badge{background:#ef4444;color:#fff;border-radius:999px;padding:2px 7px;font-size:11px}.scope-note li{margin:4px 0}@media(max-width:760px){input,select,.btn{min-height:42px;font-size:15px}}</style>
<?php if($msg): ?><div class="card" style="border-color:#bfdbfe;background:#eff6ff"><?=e($msg)?></div><?php endif; ?>
<div class="grid-2"><div class="card"><h3>Request Pairing Baru</h3><form method="post"><input type="hidden" name="action" value="request_pairing"><label>Nama Koneksi</label><input name="target_name" placeholder="Adena Pangkal Pinang / Dapur"><label>Jenis Tujuan</label><select name="target_system"><option value="adena">Adena / Toko</option><option value="dapur">Dapur</option></select><label>HTTPS Tujuan</label><input name="target_base_url" placeholder="https://domain-tujuan.com" required><br><button class="btn primary">Kirim Request Pairing</button></form></div><div class="card"><h3>API Center</h3><ul class="scope-note"><li>Pairing otomatis; token baru disimpan encrypted/hash.</li><li>Scope BO hanya backup/sync read-only dan dry-run test.</li><li>Test memakai dry-run dan tidak membuat transaksi asli.</li><li>Sync pegawai menyimpan master pegawai BO agar tidak hilang saat API gagal.</li></ul><form method="post" class="filters"><button class="btn" name="action" value="health_check">Health Check Semua</button><button class="btn" name="action" value="sync_employees">Sync Pegawai</button><button class="btn" name="action" value="backup_all">Backup Semua</button><button class="btn primary" name="action" value="run_all_tests">Test Semua</button></form></div></div>
<div class="section table-wrap"><h3>Request Pairing</h3><table><thead><tr><th>Waktu</th><th>Tujuan</th><th>URL</th><th>Status</th><th>Pesan</th><th>Aksi</th></tr></thead><tbody><?php foreach($pairings as $p): ?><tr><td><?=e($p['created_at'])?></td><td><?=e($p['target_name'])?><br><small><?=e($p['target_system'])?></small></td><td><?=e($p['target_base_url'])?></td><td><span class="badge <?=$p['status']==='approved'?'ok':($p['status']==='pending'?'warn':'danger')?>"><?=e($p['status'])?></span></td><td><?=e($p['message']??'')?></td><td><form method="post"><input type="hidden" name="action" value="check_pairing"><input type="hidden" name="id" value="<?=e($p['id'])?>"><button class="btn">Cek Status</button></form></td></tr><?php endforeach; if(!$pairings): ?><tr><td colspan="6" class="muted">Belum ada request.</td></tr><?php endif; ?></tbody></table></div>
<div class="section table-wrap"><h3>Koneksi Aktif</h3><table><thead><tr><th>Sistem</th><th>URL</th><th>Scope</th><th>Health</th><th>Sync</th><th>Test Dry-run</th></tr></thead><tbody><?php foreach($conns as $c): ?><tr><td><?=e($c['system_name'])?></td><td><?=e($c['base_url'])?></td><td><?=e($c['access_scope']??'')?></td><td><span class="badge <?=($c['last_health_status']??'')==='ok'?'ok':'warn'?>"><?=e($c['last_health_status']??'belum dicek')?></span><br><small><?=e($c['last_health_message']??'')?></small></td><td><?=e($c['last_sync_at']??'-')?><br><small><?=e($c['last_sync_message']??'')?></small></td><td><form method="post" class="filters" style="margin:0"><input type="hidden" name="action" value="run_test"><input type="hidden" name="connection_id" value="<?=e($c['id'])?>"><select name="test_key"><option value="health">Koneksi</option><option value="auth">Autentikasi</option><option value="employees">Ambil Pegawai</option><option value="products">Backup Produk</option><option value="sales">Backup Penjualan</option><option value="stock">Backup Stok</option><option value="transfer">Transfer</option><option value="transaction">Transaksi</option></select><button class="btn">Test</button></form></td></tr><?php endforeach; ?></tbody></table></div>
<div class="section table-wrap"><h3>Backup Runs</h3><table><thead><tr><th>Waktu</th><th>Sistem</th><th>Dataset</th><th>Status</th><th>Rows</th><th>Pesan</th></tr></thead><tbody><?php foreach($backupRuns as $b): ?><tr><td><?=e($b['started_at'])?></td><td><?=e($b['system_key'])?></td><td><?=e($b['dataset'])?></td><td><span class="badge <?=$b['status']==='success'?'ok':($b['status']==='running'?'warn':'danger')?>"><?=e($b['status'])?></span></td><td><?=e($b['rows_saved'])?>/<?=e($b['rows_received'])?></td><td><?=e($b['message']??'')?></td></tr><?php endforeach; if(!$backupRuns): ?><tr><td colspan="6" class="muted">Belum ada backup run.</td></tr><?php endif; ?></tbody></table></div>
<div class="section table-wrap"><h3>API Test Log</h3><table><thead><tr><th>Waktu</th><th>Sistem</th><th>Test</th><th>Endpoint</th><th>Status</th><th>Pesan</th></tr></thead><tbody><?php foreach($tests as $t): ?><tr><td><?=e($t['created_at'])?></td><td><?=e($t['system_key'])?></td><td><?=e($t['test_key'])?></td><td><?=e($t['endpoint'])?></td><td><span class="badge <?=$t['status']==='success'?'ok':'danger'?>"><?=e($t['status'])?></span></td><td><?=e($t['message']??'')?></td></tr><?php endforeach; if(!$tests): ?><tr><td colspan="6" class="muted">Belum ada test.</td></tr><?php endif; ?></tbody></table></div>
<div class="section table-wrap"><h3>Sync Log</h3><table><thead><tr><th>Waktu</th><th>Sistem</th><th>Endpoint</th><th>Status</th><th>Code</th></tr></thead><tbody><?php foreach($logs as $l): ?><tr><td><?=e($l['created_at'])?></td><td><?=e($l['system_key'])?></td><td><?=e($l['endpoint'])?></td><td><span class="badge <?=$l['status']==='success'?'ok':'danger'?>"><?=e($l['status'])?></span></td><td><?=e($l['status_code'])?></td></tr><?php endforeach; ?></tbody></table></div>
