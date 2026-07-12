<?php
$backupRoot = __DIR__;
require_once $backupRoot.'/core/backup_safe.php';
backup_safe_register($backupRoot, 'BACKOFFICE OAuth connect', 'html');
require_once $backupRoot.'/core/Auth.php';
bo_require_login();
$u = bo_user();
if (($u['role_key'] ?? '') !== 'owner') { http_response_code(403); exit('Akses hanya owner.'); }
try {
    bo_session_start();
    $token = (string)($_GET['token'] ?? '');
    $expected = (string)($_SESSION['bo_backup_csrf'] ?? '');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) throw new RuntimeException('Token koneksi tidak valid atau kedaluwarsa. Buka kembali menu backup.');
    require_once $backupRoot.'/core/BackupAdapter.php';
    $svc = bo_backup_service();
    $clientId = trim((string)$svc->get('oauth_client_id', ''));
    $clientSecret = trim((string)$svc->get('oauth_client_secret', ''));
    if ($clientId === '' || $clientSecret === '') throw new RuntimeException('Google OAuth Client ID atau Client Secret belum tersimpan.');
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? 'https' : 'http';
    $relative = bo_url('backup_google_callback.php');
    $callback = preg_match('~^https?://~i', $relative) ? $relative : $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').'/'.ltrim($relative, '/');
    $state = bin2hex(random_bytes(24));
    $_SESSION['backup_oauth_state'] = $state;
    $_SESSION['backup_oauth_started_at'] = time();
    $url = $svc->authorizationUrl($callback, $state);
    backup_safe_write_log($backupRoot, 'BACKOFFICE OAuth connect', 'OAuth redirect generated for '.$callback);
    if (!headers_sent()) { header('Location: '.$url, true, 302); backup_safe_finish(); exit; }
    echo '<p>Redirect otomatis tidak tersedia.</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Buka Otorisasi Google</a></p>';
} catch (Throwable $e) {
    $message = backup_safe_capture($backupRoot, 'BACKOFFICE OAuth connect', $e);
    echo '<div style="margin:20px;padding:16px;border:1px solid #fca5a5;background:#fef2f2;color:#991b1b;border-radius:12px"><b>Koneksi Google Drive gagal dimulai.</b><br>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'<p><a href="'.htmlspecialchars(bo_url('index.php?p=settings'), ENT_QUOTES, 'UTF-8').'">Kembali ke Setting Backup</a></p></div>';
}
backup_safe_finish();
