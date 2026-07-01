<?php
$step = $_POST['step'] ?? '';
$msg = '';
if ($step === 'install') {
  $host=$_POST['db_host']??'127.0.0.1'; $port=$_POST['db_port']??'3306'; $name=$_POST['db_name']??'adey8293_backoffice'; $user=$_POST['db_user']??'root'; $pass=$_POST['db_pass']??''; $base=$_POST['base_url']??'/backoffice';
  try{
    $pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");
    $sql=file_get_contents(__DIR__.'/../db/backoffice_schema.sql');
    foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') $pdo->exec($q); }
    $adminUser=$_POST['admin_user']??'owner'; $adminName=$_POST['admin_name']??'Owner'; $adminPass=$_POST['admin_pass']??'owner12345';
    $hash=password_hash($adminPass,PASSWORD_DEFAULT);
    $st=$pdo->prepare('INSERT INTO bo_users(username,name,password_hash,role_key,is_active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name), role_key=VALUES(role_key), is_active=1');
    $st->execute([$adminUser,$adminName,$hash,'owner']);
    $cfg="<?php\nreturn ".var_export(['app'=>['name'=>'Adena Back Office','base_url'=>$base,'session_name'=>'ADENA_BACKOFFICE_SESS','timezone'=>'Asia/Jakarta'],'db'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4']],true).";\n";
    file_put_contents(__DIR__.'/../config/app.php',$cfg);
    $msg='Instalasi berhasil. Silakan login.';
  }catch(Throwable $e){ $msg='Gagal instal: '.$e->getMessage(); }
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Back Office</title><link rel="stylesheet" href="../assets/css/backoffice.css"></head><body class="login-body"><div class="login-card"><h2>Install Adena Back Office</h2><?php if($msg): ?><div class="alert"><?=htmlspecialchars($msg)?></div><?php endif; ?><form method="post"><input type="hidden" name="step" value="install"><label>Base URL</label><input name="base_url" value="/backoffice"><div class="grid-2"><div><label>DB Host</label><input name="db_host" value="127.0.0.1"></div><div><label>DB Port</label><input name="db_port" value="3306"></div></div><label>DB Name</label><input name="db_name" value="adey8293_backoffice"><div class="grid-2"><div><label>DB User</label><input name="db_user" value="root"></div><div><label>DB Password</label><input name="db_pass" type="password"></div></div><hr><div class="grid-2"><div><label>Admin Username</label><input name="admin_user" value="owner"></div><div><label>Nama Admin</label><input name="admin_name" value="Owner"></div></div><label>Password Admin</label><input name="admin_pass" value="owner12345"><button class="btn primary" type="submit">Install / Update</button><p class="muted">Untuk hosting, isi database sesuai cPanel. Setelah berhasil, ubah password admin.</p></form></div></body></html>
