<?php
require_once __DIR__.'/../core/Sync.php';
function bo_pair_code(): string { return 'BO-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(3))); }
function bo_pair_secret(): string { return bin2hex(random_bytes(32)); }
function bo_normalize_url(string $url): string { $url=trim($url); if($url!==''&&!preg_match('~^https://~i',$url)) $url='https://'.preg_replace('~^http://~i','',$url); return rtrim($url,'/'); }
function bo_scope_for_target(string $target): string { return 'backoffice_backup'; }
function bo_integration_redirect(string $message,string $type='success',string $modal=''): void {
  $url='?p=integration&notice='.rawurlencode($message).'&notice_type='.rawurlencode($type);
  if($modal!=='') $url.='&reopen='.rawurlencode($modal);

  // Normal path: index.php starts an output buffer, so the Location header remains valid.
  if (!headers_sent()) {
    header('Location: '.$url, true, 303);
    exit;
  }

  // Defensive fallback for unusual hosting/output-buffer settings. Never leave a white page.
  $safeUrl=htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
  echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
      .'<meta http-equiv="refresh" content="0;url='.$safeUrl.'">'
      .'<title>Memproses…</title></head><body>'
      .'<p>Permintaan selesai. <a href="'.$safeUrl.'">Kembali ke Integrasi API</a>.</p>'
      .'<script>window.location.replace('.json_encode($url, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).');</script>'
      .'</body></html>';
  exit;
}

$msg=trim((string)($_GET['notice']??''));
$noticeType=(($_GET['notice_type']??'success')==='error')?'error':'success';
$reopen=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($_GET['reopen']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
  $act=(string)($_POST['action']??'');
  try {
  if($act==='request_pairing'){
    $target=$_POST['target_system']??'adena';
    $name=trim($_POST['target_name']??ucfirst($target));
    $url=bo_normalize_url($_POST['target_base_url']??'');
    if($url==='') bo_integration_redirect('URL tujuan wajib diisi.','error','newPairModal');
    $code=bo_pair_code(); $secret=bo_pair_secret(); $cfg=bo_config(); $boUrl=rtrim($cfg['app']['base_url']??'', '/');
    $payload=['request_code'=>$code,'request_secret_hash'=>password_hash($secret,PASSWORD_DEFAULT),'requester_name'=>'Back Office','requester_type'=>'backoffice','requester_base_url'=>$boUrl,'target_type'=>$target,'callback_url'=>''];
    $res=bo_remote_json($url,'api/pairing/request.php',$payload,'POST');
    bo_exec('INSERT INTO bo_pairing_requests(target_system,target_name,target_base_url,request_code,request_secret,request_secret_hash,requester_name,requester_type,requested_scope,status,message,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())',[$target,$name,$url,$code,bo_encrypt_secret($secret),password_hash($secret,PASSWORD_DEFAULT),'Back Office','backoffice',bo_scope_for_target($target),!empty($res['ok'])?'pending':'failed',$res['message']??'']);
    bo_integration_redirect(!empty($res['ok'])?'Request pairing terkirim. Approve dari sistem tujuan.':'Request gagal: '.($res['message']??'error'),!empty($res['ok'])?'success':'error','pairingModal');
  }
  if($act==='check_pairing'){
    $id=(int)($_POST['id']??0); $r=bo_exec('SELECT * FROM bo_pairing_requests WHERE id=?',[$id])->fetch();
    if(!$r) bo_integration_redirect('Request pairing tidak ditemukan.','error','pairingModal');

    // Token dari server tujuan hanya dapat diambil satu kali. Jika token sudah pernah
    // diterima tetapi finalisasi koneksi sempat gagal, gunakan salinan terenkripsi lokal.
    $token=bo_decrypt_secret((string)($r['access_token_encrypted']??''));
    $scope=(string)($r['requested_scope']??bo_scope_for_target((string)$r['target_system']));
    $remoteMessage=(string)($r['message']??'');
    $status=(string)($r['status']??'pending');

    if($token===''){
      $secret=bo_decrypt_secret($r['request_secret'] ?? '');
      if($secret==='') $secret=(string)($r['request_secret'] ?? '');
      $res=bo_remote_json($r['target_base_url'],'api/pairing/status.php',['request_code'=>$r['request_code'],'request_secret'=>$secret],'GET');
      $status=(string)($res['status']??(!empty($res['ok'])?'pending':'failed'));
      $remoteMessage=(string)($res['message']??'');

      if($status!=='approved'){
        bo_exec('UPDATE bo_pairing_requests SET status=?,message=?,last_checked_at=NOW(),updated_at=NOW() WHERE id=?',[$status,$remoteMessage,$id]);
        bo_integration_redirect('Status pairing: '.$status.'. '.$remoteMessage,$status==='pending'?'success':'error','pairingModal');
      }

      $token=trim((string)($res['access_token']??''));
      $scope=(string)($res['access_scope']??bo_scope_for_target((string)$r['target_system']));
      if($token===''){
        $message='Approval diterima, tetapi access token tidak dikirim atau sudah pernah diambil. Buat pairing baru untuk koneksi ini.';
        bo_exec("UPDATE bo_pairing_requests SET status='approved_token_missing',message=?,last_checked_at=NOW(),updated_at=NOW() WHERE id=?",[$message,$id]);
        bo_integration_redirect($message,'error','pairingModal');
      }

      // Simpan token lebih dahulu sebelum menyentuh tabel koneksi. Dengan demikian,
      // kegagalan INSERT/UPDATE berikutnya masih dapat dilanjutkan tanpa meminta token ulang.
      $encryptedToken=bo_encrypt_secret($token);
      if($encryptedToken==='' || !hash_equals($token,bo_decrypt_secret($encryptedToken))){
        $message='Token diterima, tetapi gagal disimpan secara aman. Pairing belum difinalisasi.';
        bo_exec("UPDATE bo_pairing_requests SET status='failed',message=?,last_checked_at=NOW(),updated_at=NOW() WHERE id=?",[$message,$id]);
        bo_integration_redirect($message,'error','pairingModal');
      }
      bo_exec("UPDATE bo_pairing_requests SET status='approved_processing',message=?,access_token=NULL,access_token_hash=?,access_token_encrypted=?,requested_scope=?,last_checked_at=NOW(),updated_at=NOW() WHERE id=?",['Approval diterima. Menyelesaikan koneksi...',hash('sha256',$token),$encryptedToken,$scope,$id]);
    }

    $normalizedBase=bo_normalize_url((string)$r['target_base_url']);
    $pdo=bo_db();
    try {
      $pdo->beginTransaction();
      $existing=bo_exec('SELECT system_key FROM bo_system_connections WHERE LOWER(TRIM(TRAILING '/' FROM base_url))=LOWER(TRIM(TRAILING '/' FROM ?)) AND system_type=? ORDER BY is_active DESC,id ASC LIMIT 1',[$normalizedBase,$r['target_system']])->fetch();
      $systemKey=$existing['system_key']??bo_next_system_key($r['target_system']);
      bo_exec("UPDATE bo_system_connections SET is_active=0,status='inactive',updated_at=NOW() WHERE LOWER(TRIM(TRAILING '/' FROM base_url))=LOWER(TRIM(TRAILING '/' FROM ?)) AND system_type=? AND system_key<>?",[$normalizedBase,$r['target_system'],$systemKey]);
      $encryptedToken=bo_encrypt_secret($token);
      bo_exec('INSERT INTO bo_system_connections(system_key,system_name,system_type,base_url,api_token,api_token_hash,api_token_encrypted,access_scope,status,is_active,paired_at,token_last_rotated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,1,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE system_name=VALUES(system_name),system_type=VALUES(system_type),base_url=VALUES(base_url),api_token=NULL,api_token_hash=VALUES(api_token_hash),api_token_encrypted=VALUES(api_token_encrypted),access_scope=VALUES(access_scope),status=VALUES(status),is_active=1,paired_at=NOW(),token_last_rotated_at=NOW(),updated_at=NOW()',[$systemKey,$r['target_name'],$r['target_system'],$normalizedBase,null,hash('sha256',$token),$encryptedToken,$scope,'active']);
      bo_exec("UPDATE bo_pairing_requests SET status='approved',message='Pairing selesai dan koneksi aktif.',access_token=NULL,access_token_hash=?,access_token_encrypted=?,updated_at=NOW() WHERE id=?",[hash('sha256',$token),$encryptedToken,$id]);
      $pdo->commit();
    } catch(Throwable $finalizeError) {
      if($pdo->inTransaction()) $pdo->rollBack();
      bo_exec("UPDATE bo_pairing_requests SET status='approved_processing',message=?,updated_at=NOW() WHERE id=?",['Token sudah tersimpan. Finalisasi koneksi gagal dan dapat dicoba kembali: '.$finalizeError->getMessage(),$id]);
      throw $finalizeError;
    }
    bo_integration_redirect('Pairing disetujui dan koneksi aktif.');
  }
  if($act==='health_check'){ foreach(bo_exec('SELECT * FROM bo_system_connections WHERE is_active=1 ORDER BY id ASC')->fetchAll() as $conn) bo_health_check_connection($conn); bo_integration_redirect('Health check semua koneksi selesai.'); }
  if($act==='sync_employees'){ $r=bo_sync_employees(); bo_integration_redirect('Sync pegawai selesai. Diterima '.(int)$r['received'].', disimpan '.(int)$r['saved'].(empty($r['ok'])?'. Error: '.implode('; ',$r['errors']??[]):'.'),empty($r['ok'])?'error':'success','apiCenterModal'); }
  if($act==='backup_all'){ $r=bo_backup_all(); bo_integration_redirect('Backup selesai. Item: '.count($r['results']).(empty($r['ok'])?'. Error: '.implode('; ',$r['errors']):'.'),empty($r['ok'])?'error':'success','backupLogModal'); }
  if($act==='run_test'){
    $id=(int)($_POST['connection_id']??0); $test=(string)($_POST['test_key']??'health');
    $conn=bo_exec('SELECT * FROM bo_system_connections WHERE id=? LIMIT 1',[$id])->fetch();
    if($conn){ $r=bo_run_api_test($conn,$test); bo_integration_redirect('Test '.$test.' '.(!empty($r['ok'])?'berhasil':'gagal').': '.$r['message'],!empty($r['ok'])?'success':'error','apiLogModal'); }
    bo_integration_redirect('Koneksi tidak ditemukan.','error','apiCenterModal');
  }
  if($act==='run_all_tests'){
    $testsToRun=['health','auth','employees','products','sales','stock','transfer','transaction']; $ok=0; $fail=0;
    foreach(bo_exec('SELECT * FROM bo_system_connections WHERE is_active=1 ORDER BY id ASC')->fetchAll() as $conn){ foreach($testsToRun as $test){ $r=bo_run_api_test($conn,$test); !empty($r['ok'])?$ok++:$fail++; } }
    bo_integration_redirect('Test semua selesai. OK: '.$ok.', gagal: '.$fail.'.',$fail>0?'error':'success','apiLogModal');
  }
  if($act==='disable_connection'){
    $id=(int)($_POST['id']??0); bo_exec("UPDATE bo_system_connections SET is_active=0,status='inactive',updated_at=NOW() WHERE id=?",[$id]);
    bo_integration_redirect('Koneksi dinonaktifkan dan dihapus dari daftar koneksi aktif.');
  }
  if($act==='dismiss_pairing'){
    $id=(int)($_POST['id']??0); bo_exec("UPDATE bo_pairing_requests SET status='dismissed',updated_at=NOW() WHERE id=? AND status<>'approved'",[$id]);
    bo_integration_redirect('Request pairing dihapus dari daftar.','success','pairingModal');
  }
  bo_integration_redirect('Aksi integrasi tidak dikenali.','error');
  } catch (Throwable $e) {
    error_log('[BackOffice Integration] action='.$act.' error='.$e->getMessage().' file='.$e->getFile().':'.$e->getLine());
    $modalMap=[
      'request_pairing'=>'newPairModal','check_pairing'=>'pairingModal','dismiss_pairing'=>'pairingModal',
      'sync_employees'=>'apiCenterModal','run_test'=>'apiCenterModal','run_all_tests'=>'apiLogModal',
      'backup_all'=>'backupLogModal','health_check'=>'apiCenterModal','disable_connection'=>'apiCenterModal'
    ];
    bo_integration_redirect('Proses tidak dapat diselesaikan. Detail teknis telah dicatat pada error log.','error',$modalMap[$act]??'');
  }
}

$pairings=bo_exec("SELECT * FROM bo_pairing_requests WHERE status IN ('pending','failed','approved_processing','approved_token_missing') OR (status='approved' AND (access_token_encrypted IS NULL OR access_token_encrypted='')) ORDER BY id DESC LIMIT 100")->fetchAll();
$conns=bo_exec('SELECT * FROM bo_system_connections WHERE is_active=1 ORDER BY system_type,system_name,system_key')->fetchAll();
$logs=bo_exec('SELECT * FROM bo_sync_logs ORDER BY id DESC LIMIT 100')->fetchAll();
$tests=bo_exec('SELECT * FROM bo_api_test_runs ORDER BY id DESC LIMIT 100')->fetchAll();
$backupRuns=bo_exec('SELECT * FROM bo_backup_runs ORDER BY id DESC LIMIT 100')->fetchAll();
$pending=count(array_filter($pairings,fn($p)=>($p['status']??'')==='pending'));
$failedTests=count(array_filter($tests,fn($t)=>($t['status']??'')!=='success'));
?>
<style>
.integration-actions{display:flex;gap:9px;flex-wrap:wrap;margin:14px 0}.integration-actions .btn{min-height:38px}.integration-table table{min-width:780px}.integration-table th,.integration-table td{font-size:12px;padding:7px 8px;vertical-align:middle}.integration-inline-actions{display:flex;gap:6px;flex-wrap:wrap}.integration-modal{position:fixed;inset:0;background:rgba(15,23,42,.52);display:none;align-items:center;justify-content:center;padding:18px;z-index:9999}.integration-modal.open{display:flex}.integration-modal-box{background:#fff;border-radius:14px;width:min(980px,100%);max-height:88vh;overflow:auto;box-shadow:0 24px 70px rgba(0,0,0,.25)}.integration-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;background:#fff;z-index:2}.integration-modal-body{padding:16px}.integration-close{border:0;background:#f1f5f9;border-radius:8px;padding:7px 10px;cursor:pointer}.log-detail{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.empty-integration{padding:16px;text-align:center;color:var(--muted)}@media(max-width:760px){.integration-stats{grid-template-columns:1fr}.integration-actions .btn{width:100%}.integration-modal{padding:8px}.integration-modal-box{max-height:94vh}}
</style>
<div class="page-title"><div><h1>Integrasi & Pairing API</h1><div class="muted">Kelola pairing, koneksi aktif, pemeriksaan API, backup, dan log dari satu halaman ringkas.</div></div><div class="notif"><button type="button" class="btn" data-open-modal="pairingModal">🔔 <?=$pending?'<span class="notif-badge">'.$pending.'</span>':'0'?></button></div></div>
<?php if($msg): ?><div class="action-toast <?=$noticeType==='error'?'error':'success'?>" id="actionToast" role="status"><strong><?=$noticeType==='error'?'Aksi gagal':'Aksi berhasil'?></strong><span><?=e($msg)?></span><button type="button" aria-label="Tutup" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>
<div class="integration-actions">
<button type="button" class="btn primary" data-open-modal="newPairModal">＋ Buat Pairing</button><button type="button" class="btn" data-open-modal="pairingModal">Request Pairing <?=$pending?'('.$pending.')':''?></button><button type="button" class="btn" data-open-modal="apiCenterModal">API Center</button><button type="button" class="btn" data-open-modal="apiLogModal">API Test Log</button><button type="button" class="btn" data-open-modal="backupLogModal">Backup Log</button><button type="button" class="btn" data-open-modal="syncLogModal">Sync Log</button><button type="button" class="btn" data-open-modal="rulesModal">Aturan Akses</button>
</div>
<div class="section table-wrap integration-table"><h3>Koneksi Aktif</h3><table><thead><tr><th>Sistem</th><th>URL</th><th>Scope</th><th>Health</th><th>Sync</th><th>Test</th><th>Aksi</th></tr></thead><tbody><?php foreach($conns as $c): ?><tr><td><strong><?=e($c['system_name'])?></strong><br><small><?=e($c['system_key'])?> · <?=e($c['system_type']??'-')?></small></td><td><?=e($c['base_url'])?></td><td><?=e($c['access_scope']??'')?></td><td><span class="badge <?=($c['last_health_status']??'')==='ok'?'ok':'warn'?>"><?=e($c['last_health_status']??'belum dicek')?></span><br><small><?=e($c['last_health_message']??'')?></small></td><td><?=e($c['last_sync_at']??'-')?><br><small><?=e($c['last_sync_message']??'')?></small></td><td><form method="post" class="filters" style="margin:0"><input type="hidden" name="action" value="run_test"><input type="hidden" name="connection_id" value="<?=e($c['id'])?>"><select name="test_key"><option value="health">Koneksi</option><option value="auth">Autentikasi</option><option value="employees">Pegawai</option><option value="products">Produk</option><option value="sales">Penjualan</option><option value="stock">Stok</option><option value="transfer">Transfer</option><option value="transaction">Transaksi</option></select><button class="btn">Test</button></form></td><td><form method="post" onsubmit="return confirm('Nonaktifkan koneksi ini?');"><input type="hidden" name="action" value="disable_connection"><input type="hidden" name="id" value="<?=e($c['id'])?>"><button class="btn">Hapus</button></form></td></tr><?php endforeach; if(!$conns): ?><tr><td colspan="7" class="empty-integration">Belum ada koneksi aktif.</td></tr><?php endif; ?></tbody></table></div>

<div class="integration-modal" id="newPairModal"><div class="integration-modal-box"><div class="integration-modal-head"><h3>Request Pairing Baru</h3><button type="button" class="integration-close" data-close-modal>✕</button></div><div class="integration-modal-body"><form method="post"><input type="hidden" name="action" value="request_pairing"><label>Nama Koneksi</label><input name="target_name" placeholder="Adena Pangkal Pinang / Dapur"><label>Jenis Tujuan</label><select name="target_system"><option value="adena">Adena / Toko</option><option value="dapur">Dapur</option></select><label>HTTPS Tujuan</label><input name="target_base_url" placeholder="https://domain-tujuan.com" required><br><button class="btn primary">Kirim Request Pairing</button></form></div></div></div>
<div class="integration-modal" id="pairingModal"><div class="integration-modal-box"><div class="integration-modal-head"><h3>Request Pairing Aktif</h3><button type="button" class="integration-close" data-close-modal>✕</button></div><div class="integration-modal-body table-wrap integration-table"><table><thead><tr><th>Waktu</th><th>Tujuan</th><th>URL</th><th>Status</th><th>Pesan</th><th>Aksi</th></tr></thead><tbody><?php foreach($pairings as $p): $pairStatus=(string)($p['status']??''); $canFinalize=in_array($pairStatus,['pending','failed','approved_processing'],true); ?><tr><td><?=e($p['created_at'])?></td><td><?=e($p['target_name'])?><br><small><?=e($p['target_system'])?></small></td><td><?=e($p['target_base_url'])?></td><td><span class="badge <?=$pairStatus==='pending'?'warn':($pairStatus==='approved_processing'?'warn':'danger')?>"><?=e($pairStatus)?></span></td><td><?=e($p['message']??'')?></td><td><div class="integration-inline-actions"><?php if($canFinalize): ?><form method="post"><input type="hidden" name="action" value="check_pairing"><input type="hidden" name="id" value="<?=e($p['id'])?>"><button class="btn"><?=$pairStatus==='approved_processing'?'Selesaikan Koneksi':'Cek Status'?></button></form><?php endif; ?><form method="post"><input type="hidden" name="action" value="dismiss_pairing"><input type="hidden" name="id" value="<?=e($p['id'])?>"><button class="btn">Hapus</button></form></div><?php if($pairStatus==='approved_token_missing'): ?><small>Token dari pairing ini sudah tidak dapat diambil ulang. Buat request pairing baru.</small><?php endif; ?></td></tr><?php endforeach; if(!$pairings): ?><tr><td colspan="6" class="empty-integration"><strong>Tidak ada request pairing yang perlu ditindaklanjuti.</strong><br>Request hanya hilang setelah koneksi benar-benar tersimpan.</td></tr><?php endif; ?></tbody></table></div></div></div>
<div class="integration-modal" id="apiCenterModal"><div class="integration-modal-box"><div class="integration-modal-head"><h3>API Center</h3><button type="button" class="integration-close" data-close-modal>✕</button></div><div class="integration-modal-body"><form method="post" class="integration-actions"><button class="btn" name="action" value="health_check">Health Check Semua</button><button class="btn" name="action" value="sync_employees">Sync Pegawai</button><button class="btn" name="action" value="backup_all">Backup Semua</button><button class="btn primary" name="action" value="run_all_tests">Test Semua</button></form></div></div></div>
<div class="integration-modal" id="apiLogModal"><div class="integration-modal-box"><div class="integration-modal-head"><h3>API Test Log</h3><button type="button" class="integration-close" data-close-modal>✕</button></div><div class="integration-modal-body table-wrap integration-table"><table><thead><tr><th>Waktu</th><th>Sistem</th><th>Test</th><th>Endpoint</th><th>Status</th><th>Pesan</th></tr></thead><tbody><?php foreach($tests as $t): ?><tr><td><?=e($t['created_at'])?></td><td><?=e($t['system_key'])?></td><td><?=e($t['test_key'])?></td><td><?=e($t['endpoint'])?></td><td><span class="badge <?=$t['status']==='success'?'ok':'danger'?>"><?=e($t['status'])?></span></td><td><div class="log-detail" title="<?=e($t['message']??'')?>"><?=e($t['message']??'-')?></div></td></tr><?php endforeach; if(!$tests): ?><tr><td colspan="6" class="empty-integration">Belum ada test.</td></tr><?php endif; ?></tbody></table></div></div></div>
<div class="integration-modal" id="backupLogModal"><div class="integration-modal-box"><div class="integration-modal-head"><h3>Backup Log</h3><button type="button" class="integration-close" data-close-modal>✕</button></div><div class="integration-modal-body table-wrap integration-table"><table><thead><tr><th>Waktu</th><th>Sistem</th><th>Dataset</th><th>Status</th><th>Rows</th><th>Pesan</th></tr></thead><tbody><?php foreach($backupRuns as $b): ?><tr><td><?=e($b['started_at'])?></td><td><?=e($b['system_key'])?></td><td><?=e($b['dataset'])?></td><td><span class="badge <?=$b['status']==='success'?'ok':($b['status']==='running'?'warn':'danger')?>"><?=e($b['status'])?></span></td><td><?=e($b['rows_saved'])?>/<?=e($b['rows_received'])?></td><td><?=e($b['message']??'')?></td></tr><?php endforeach; if(!$backupRuns): ?><tr><td colspan="6" class="empty-integration">Belum ada backup run.</td></tr><?php endif; ?></tbody></table></div></div></div>
<div class="integration-modal" id="syncLogModal"><div class="integration-modal-box"><div class="integration-modal-head"><h3>Sync Log</h3><button type="button" class="integration-close" data-close-modal>✕</button></div><div class="integration-modal-body table-wrap integration-table"><table><thead><tr><th>Waktu</th><th>Sistem</th><th>Endpoint</th><th>Status</th><th>HTTP</th><th>Respons</th></tr></thead><tbody><?php foreach($logs as $l): $response=(string)($l['response_payload']??($l['message']??'')); ?><tr><td><?=e($l['created_at'])?></td><td><?=e($l['system_key'])?></td><td><?=e($l['endpoint'])?></td><td><span class="badge <?=$l['status']==='success'?'ok':'danger'?>"><?=e($l['status'])?></span></td><td><?=e($l['status_code'])?></td><td><div class="log-detail" title="<?=e($response)?>"><?=e($response!==''?$response:'-')?></div></td></tr><?php endforeach; if(!$logs): ?><tr><td colspan="6" class="empty-integration">Belum ada sync log.</td></tr><?php endif; ?></tbody></table></div></div></div>
<div class="integration-modal" id="rulesModal"><div class="integration-modal-box"><div class="integration-modal-head"><h3>Aturan Akses Integrasi</h3><button type="button" class="integration-close" data-close-modal>✕</button></div><div class="integration-modal-body"><ul class="scope-note"><li>Token disimpan terenkripsi dan hash; token tidak ditampilkan di halaman.</li><li>Scope Back Office dibatasi untuk backup/sinkronisasi dan dry-run test.</li><li>Request approved otomatis hilang dari daftar, tetapi histori tetap tersimpan.</li><li>Koneksi lama dapat dinonaktifkan melalui tombol Hapus.</li></ul></div></div></div>
<script>(function(){function closeModal(m){if(m)m.classList.remove('open')}document.querySelectorAll('[data-open-modal]').forEach(function(b){b.addEventListener('click',function(){var e=document.getElementById(b.getAttribute('data-open-modal'));if(e)e.classList.add('open')})});document.querySelectorAll('[data-close-modal]').forEach(function(b){b.addEventListener('click',function(){closeModal(b.closest('.integration-modal'))})});document.querySelectorAll('.integration-modal').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)closeModal(m)})});document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.integration-modal.open').forEach(closeModal)});var reopen=<?=json_encode($reopen,JSON_UNESCAPED_SLASHES)?>;if(reopen){var modal=document.getElementById(reopen);if(modal)modal.classList.add('open')}var toast=document.getElementById('actionToast');if(toast)setTimeout(function(){if(toast&&toast.parentNode)toast.remove()},8000)})();</script>
