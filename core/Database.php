<?php
function bo_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $path = __DIR__ . '/../config/app.php';
  if (!is_file($path)) { header('Location: install/index.php'); exit; }
  $cfg = require $path;
  date_default_timezone_set($cfg['app']['timezone'] ?? 'Asia/Jakarta');
  return $cfg;
}
function bo_db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = bo_config()['db'];
  $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
  $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  return $pdo;
}
function bo_exec(string $sql, array $p=[]): PDOStatement { $st=bo_db()->prepare($sql); $st->execute($p); return $st; }

function bo_bootstrap_schema(): void {
  static $done=false; if($done) return; $done=true;
  $path=__DIR__.'/Migrations.php';
  if(is_file($path)){
    require_once $path;
    if(function_exists('bo_ensure_schema')) bo_ensure_schema();
  }
}
