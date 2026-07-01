<?php
require_once __DIR__.'/Database.php';
require_once __DIR__.'/Helpers.php';
function bo_session_start(): void { $cfg=bo_config(); if(session_status()!==PHP_SESSION_ACTIVE){ session_name($cfg['app']['session_name'] ?? 'ADENA_BACKOFFICE_SESS'); session_start(); } }
function bo_user(): ?array { bo_session_start(); return $_SESSION['bo_user'] ?? null; }
function bo_require_login(): void { if(!bo_user()){ header('Location: '.bo_url('login.php')); exit; } }
function bo_login(string $username, string $password): bool {
  $st=bo_exec('SELECT * FROM bo_users WHERE username=? AND is_active=1 LIMIT 1',[$username]); $u=$st->fetch();
  if(!$u || !password_verify($password,$u['password_hash'])) return false;
  bo_session_start(); $_SESSION['bo_user']=['id'=>$u['id'],'username'=>$u['username'],'name'=>$u['name'],'role_key'=>$u['role_key']];
  bo_exec('UPDATE bo_users SET last_login_at=NOW() WHERE id=?',[$u['id']]); return true;
}
function bo_logout(): void { bo_session_start(); $_SESSION=[]; session_destroy(); }
