<?php
$lockFile = __DIR__ . '/../storage/install.lock';
if (!is_dir(dirname($lockFile))) {
  mkdir(dirname($lockFile), 0755, true);
}
if (is_file($lockFile)) {
  echo "OK: install.lock sudah ada.\n";
  exit;
}
$payload = "installed_at=".date('c').PHP_EOL."created_by=create_install_lock.php".PHP_EOL;
if (file_put_contents($lockFile, $payload, LOCK_EX) === false) {
  http_response_code(500);
  echo "ERROR: gagal membuat storage/install.lock. Cek permission folder storage.\n";
  exit;
}
echo "OK: storage/install.lock berhasil dibuat.\n";
echo "PENTING: hapus file tools/create_install_lock.php setelah ini.\n";
