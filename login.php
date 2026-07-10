<?php
require_once __DIR__.'/core/Auth.php';
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $remember=isset($_POST['remember_me']) && $_POST['remember_me']==='1';
  if(bo_login($_POST['username']??'',$_POST['password']??'',$remember)){ header('Location: '.bo_url('index.php')); exit; }
  $err='Username/password salah atau user nonaktif.';
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login Back Office</title><link rel="stylesheet" href="assets/css/backoffice.css">
<style>
.remember-row{display:flex;align-items:flex-start;gap:9px;margin:12px 0 6px}.remember-row input{width:auto;margin-top:3px}
.remember-warning{display:none;margin:8px 0 14px;padding:10px 12px;border:1px solid #f59e0b;border-radius:8px;background:rgba(245,158,11,.12);color:#92400e;font-size:.85rem;line-height:1.4}.remember-warning.show{display:block}
</style></head><body class="login-body"><div class="login-card"><div class="brand-mark">A</div><h2>Adena Back Office</h2>
<p class="muted">Pusat kontrol toko dan dapur.</p><?php if($err): ?><div class="alert danger"><?=e($err)?></div><?php endif; ?>
<form method="post"><label>Username</label><input name="username" autocomplete="username" required autofocus>
<label>Password</label><input name="password" type="password" autocomplete="current-password" required>
<label class="remember-row"><input id="remember-me" type="checkbox" name="remember_me" value="1"><span>Remember me</span></label>
<div id="remember-warning" class="remember-warning" role="alert">Gunakan fitur ini hanya pada komputer pribadi. Jangan aktifkan pada komputer bersama atau perangkat umum karena akun dapat dibuka tanpa memasukkan username dan password.</div>
<button class="btn primary full" type="submit">Masuk</button></form></div>
<script>(function(){var c=document.getElementById('remember-me'),w=document.getElementById('remember-warning');if(c&&w)c.addEventListener('change',function(){w.classList.toggle('show',c.checked);});})();</script>
</body></html>