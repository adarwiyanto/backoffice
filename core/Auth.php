<?php
require_once __DIR__.'/Database.php';
require_once __DIR__.'/Helpers.php';
bo_bootstrap_schema();

const BO_REMEMBER_LOGIN_LIFETIME = 315360000;

function bo_session_start(): void {
  $cfg=bo_config();
  if(session_status()!==PHP_SESSION_ACTIVE){
    $secure=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
    session_name($cfg['app']['session_name'] ?? 'ADENA_BACKOFFICE_SESS');
    session_set_cookie_params(['path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
  }
}
function bo_remember_cookie_name(): string { return 'ADENA_BACKOFFICE_REMEMBER'; }
function bo_remember_cookie_secure(): bool {
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
}
function bo_ensure_remember_table(): void {
  bo_exec("CREATE TABLE IF NOT EXISTS bo_remember_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    selector CHAR(24) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    password_fingerprint CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(id),
    UNIQUE KEY uq_bo_remember_selector(selector),
    KEY idx_bo_remember_user(user_id),
    KEY idx_bo_remember_expires(expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function bo_remember_set_cookie(string $value,int $expires): void {
  if(headers_sent()) return;
  setcookie(bo_remember_cookie_name(),$value,['expires'=>$expires,'path'=>'/','secure'=>bo_remember_cookie_secure(),'httponly'=>true,'samesite'=>'Lax']);
}
function bo_remember_forget_current_device(): void {
  $raw=(string)($_COOKIE[bo_remember_cookie_name()]??''); $parts=explode(':',$raw,2);
  if(count($parts)===2 && preg_match('/^[a-f0-9]{24}$/',$parts[0])){
    try { bo_ensure_remember_table(); bo_exec('DELETE FROM bo_remember_tokens WHERE selector=?',[$parts[0]]); } catch(Throwable $e){}
  }
  unset($_COOKIE[bo_remember_cookie_name()]); bo_remember_set_cookie('',time()-42000);
}
function bo_remember_issue(int $userId,string $passwordHash): void {
  bo_remember_forget_current_device(); bo_ensure_remember_table();
  try { bo_exec('DELETE FROM bo_remember_tokens WHERE expires_at<=NOW()'); } catch(Throwable $e){}
  $selector=bin2hex(random_bytes(12)); $validator=bin2hex(random_bytes(32)); $expires=time()+BO_REMEMBER_LOGIN_LIFETIME;
  bo_exec('INSERT INTO bo_remember_tokens(user_id,selector,validator_hash,password_fingerprint,expires_at) VALUES(?,?,?,?,?)',[
    $userId,$selector,hash('sha256',$validator),hash('sha256',$passwordHash),date('Y-m-d H:i:s',$expires)
  ]);
  bo_remember_set_cookie($selector.':'.$validator,$expires);
}
function bo_remember_restore_user(): ?array {
  static $attempted=false; if($attempted) return null; $attempted=true;
  $raw=(string)($_COOKIE[bo_remember_cookie_name()]??''); $parts=explode(':',$raw,2);
  if(count($parts)!==2 || !preg_match('/^[a-f0-9]{24}$/',$parts[0]) || !preg_match('/^[a-f0-9]{64}$/',$parts[1])){
    if($raw!=='') bo_remember_forget_current_device(); return null;
  }
  try {
    bo_ensure_remember_table();
    $st=bo_exec('SELECT t.id token_id,t.validator_hash,t.password_fingerprint,u.*
      FROM bo_remember_tokens t JOIN bo_users u ON u.id=t.user_id
      WHERE t.selector=? AND t.expires_at>NOW() AND u.is_active=1 LIMIT 1',[$parts[0]]);
    $u=$st->fetch();
    $valid=$u && hash_equals((string)$u['validator_hash'],hash('sha256',$parts[1]))
      && hash_equals((string)$u['password_fingerprint'],hash('sha256',(string)$u['password_hash']));
    if(!$valid){ bo_remember_forget_current_device(); return null; }
    $tokenId=(int)$u['token_id'];
    $sessionUser=['id'=>$u['id'],'username'=>$u['username'],'name'=>$u['name'],'role_key'=>$u['role_key'],'must_change_password'=>(int)($u['must_change_password'] ?? 0)];
    $expires=time()+BO_REMEMBER_LOGIN_LIFETIME;
    bo_exec('UPDATE bo_remember_tokens SET expires_at=?,last_used_at=NOW() WHERE id=?',[date('Y-m-d H:i:s',$expires),$tokenId]);
    bo_remember_set_cookie($raw,$expires); session_regenerate_id(true); $_SESSION['bo_user']=$sessionUser; return $sessionUser;
  } catch(Throwable $e){ return null; }
}
function bo_user(): ?array {
  bo_session_start();
  if(empty($_SESSION['bo_user'])) bo_remember_restore_user();
  return $_SESSION['bo_user']??null;
}
function bo_require_login(): void {
  $u=bo_user();
  if(!$u){ header('Location: '.bo_url('login.php')); exit; }
  $path=basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if(!empty($u['must_change_password']) && $path!=='password_change.php' && $path!=='logout.php'){
    header('Location: '.bo_url('password_change.php')); exit;
  }
}
function bo_login(string $username,string $password,bool $remember=false): bool {
  $st=bo_exec('SELECT * FROM bo_users WHERE username=? AND is_active=1 LIMIT 1',[$username]); $u=$st->fetch();
  if(!$u || !password_verify($password,$u['password_hash'])) return false;
  $passwordHash=(string)$u['password_hash']; bo_session_start(); session_regenerate_id(true);
  $_SESSION['bo_user']=['id'=>$u['id'],'username'=>$u['username'],'name'=>$u['name'],'role_key'=>$u['role_key'],'must_change_password'=>(int)($u['must_change_password'] ?? 0)];
  if($remember) bo_remember_issue((int)$u['id'],$passwordHash); else bo_remember_forget_current_device();
  bo_exec('UPDATE bo_users SET last_login_at=NOW() WHERE id=?',[$u['id']]); return true;
}
function bo_change_own_password(int $userId,string $newPassword): void {
  $hash=password_hash($newPassword,PASSWORD_DEFAULT);
  bo_exec('UPDATE bo_users SET password_hash=?,must_change_password=0,password_changed_at=NOW(),updated_at=NOW() WHERE id=?',[$hash,$userId]);
  try { bo_exec('DELETE FROM bo_remember_tokens WHERE user_id=?',[$userId]); } catch(Throwable $e) {}
  bo_session_start();
  if(!empty($_SESSION['bo_user'])) $_SESSION['bo_user']['must_change_password']=0;
}
function bo_logout(): void { bo_session_start(); bo_remember_forget_current_device(); $_SESSION=[]; session_destroy(); }
