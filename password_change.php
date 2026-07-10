<?php
require_once __DIR__.'/core/Auth.php';
bo_require_login();
$u=bo_user();
$err=''; $msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $p1=(string)($_POST['password'] ?? '');
  $p2=(string)($_POST['password_confirm'] ?? '');
  if(strlen($p1)<8) $err='Password baru minimal 8 karakter.';
  elseif($p1!==$p2) $err='Konfirmasi password tidak sama.';
  else {
    bo_change_own_password((int)$u['id'],$p1);
    header('Location: '.bo_url('index.php')); exit;
  }
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ganti Password</title><link rel="stylesheet" href="assets/css/backoffice.css"></head>
<body class="login-body"><div class="login-card"><div class="brand-mark">A</div><h2>Ganti Password Awal</h2>
<p class="muted">Password awal hanya untuk masuk pertama kali. Buat password sendiri sebelum lanjut.</p>
<?php if($err): ?><div class="alert danger"><?=e($err)?></div><?php endif; ?>
<form method="post"><label>Password Baru</label><input name="password" type="password" autocomplete="new-password" required autofocus>
<label>Ulangi Password Baru</label><input name="password_confirm" type="password" autocomplete="new-password" required>
<button class="btn primary full" type="submit">Simpan Password</button></form>
<p><a class="btn full" href="logout.php">Logout</a></p></div></body></html>
