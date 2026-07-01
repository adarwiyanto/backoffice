<?php
$lockFile = __DIR__ . '/../storage/install.lock';

function bo_install_lock_exists($lockFile) {
  return is_file($lockFile);
}

function bo_render_locked() {
  http_response_code(403);
  ?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Installer Terkunci</title>
  <link rel="stylesheet" href="../assets/css/backoffice.css">
</head>
<body class="login-body">
  <div class="login-card">
    <h2>Installer Terkunci</h2>
    <div class="alert">Back Office sudah di-install. Untuk keamanan, halaman install tidak bisa dijalankan ulang.</div>
    <p class="muted">Jika benar-benar perlu reinstall, hapus file <code>storage/install.lock</code> secara manual dari server.</p>
    <a class="btn primary" href="../login.php">Ke Login</a>
  </div>
</body>
</html><?php
  exit;
}

if (bo_install_lock_exists($lockFile)) {
  bo_render_locked();
}

$step = $_POST['step'] ?? '';
$msg = '';

if ($step === 'install') {
  $host=$_POST['db_host']??'127.0.0.1';
  $port=$_POST['db_port']??'3306';
  $name=$_POST['db_name']??'adey8293_backoffice';
  $user=$_POST['db_user']??'root';
  $pass=$_POST['db_pass']??'';
  $base=$_POST['base_url']??'/backoffice';

  try{
    $pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");

    $sql=file_get_contents(__DIR__.'/../db/backoffice_schema.sql');
    foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){
      if($q!=='') $pdo->exec($q);
    }

    $adminUser=$_POST['admin_user']??'owner';
    $adminName=$_POST['admin_name']??'Owner';
    $adminPass=$_POST['admin_pass']??'owner12345';
    $hash=password_hash($adminPass,PASSWORD_DEFAULT);

    $st=$pdo->prepare('INSERT INTO bo_users(username,name,password_hash,role_key,is_active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role_key=VALUES(role_key), is_active=1');
    $st->execute([$adminUser,$adminName,$hash,'owner']);

    $cfg="<?php\nreturn ".var_export([
      'app'=>[
        'name'=>'Adena Back Office',
        'base_url'=>$base,
        'session_name'=>'ADENA_BACKOFFICE_SESS',
        'timezone'=>'Asia/Jakarta'
      ],
      'db'=>[
        'host'=>$host,
        'port'=>$port,
        'name'=>$name,
        'user'=>$user,
        'pass'=>$pass,
        'charset'=>'utf8mb4'
      ]
    ],true).";\n";
    file_put_contents(__DIR__.'/../config/app.php',$cfg);

    if (!is_dir(dirname($lockFile))) {
      mkdir(dirname($lockFile), 0755, true);
    }
    $lockPayload = "installed_at=".date('c').PHP_EOL."db_name=".$name.PHP_EOL."base_url=".$base.PHP_EOL;
    if (file_put_contents($lockFile, $lockPayload, LOCK_EX) === false) {
      throw new RuntimeException('Instalasi berhasil, tetapi gagal membuat install.lock. Cek permission folder storage.');
    }

    $msg='Instalasi berhasil. Installer sudah dikunci. Silakan login.';
  }catch(Throwable $e){
    $msg='Gagal instal: '.$e->getMessage();
  }
}
?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Install Back Office</title>
  <link rel="stylesheet" href="../assets/css/backoffice.css">
</head>
<body class="login-body">
  <div class="login-card">
    <h2>Install Adena Back Office</h2>
    <?php if($msg): ?><div class="alert"><?=htmlspecialchars($msg)?></div><?php endif; ?>
    <?php if($msg && strpos($msg,'Instalasi berhasil')!==false): ?>
      <p><a class="btn primary" href="../login.php">Login Sekarang</a></p>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="step" value="install">
      <label>Base URL</label><input name="base_url" value="/backoffice">
      <div class="grid-2">
        <div><label>DB Host</label><input name="db_host" value="127.0.0.1"></div>
        <div><label>DB Port</label><input name="db_port" value="3306"></div>
      </div>
      <label>DB Name</label><input name="db_name" value="adey8293_backoffice">
      <div class="grid-2">
        <div><label>DB User</label><input name="db_user" value="root"></div>
        <div><label>DB Password</label><input name="db_pass" type="password"></div>
      </div>
      <hr>
      <div class="grid-2">
        <div><label>Admin Username</label><input name="admin_user" value="owner"></div>
        <div><label>Nama Admin</label><input name="admin_name" value="Owner"></div>
      </div>
      <label>Password Admin</label><input name="admin_pass" value="owner12345">
      <button class="btn primary" type="submit">Install</button>
      <p class="muted">Untuk hosting, isi database sesuai cPanel. Setelah berhasil, installer otomatis dikunci.</p>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
