PATCH BACKOFFICE UI INTEGRASI & PEGAWAI — 10 JULI 2026

Perubahan:
1. Integrasi API
   - Semua aksi POST dibungkus error boundary (try/catch).
   - Error PHP/SQL/API tidak lagi memutus render menjadi halaman putih.
   - Error teknis dicatat ke PHP error_log dan pengguna dikembalikan ke halaman dengan notifikasi.
   - Handler loading ganda dihapus.
   - Tombol yang benar-benar diklik menjadi tombol loading (event.submitter).

2. Pegawai
   - Menu dan judul "Semua Pegawai" diubah menjadi "Pegawai".
   - Role owner tidak disimpan pada sync baru dan tidak ditampilkan dari data lama.
   - Panel status sinkronisasi per cabang dihapus.
   - Ditambahkan filter Aktif / Nonaktif / Semua status.
   - Ditambahkan aksi Nonaktifkan / Aktifkan.
   - Status nonaktif manual disimpan di bo_employee_people.manually_disabled dan tidak tertimpa sync.
   - Pegawai nonaktif memiliki aktivitas 0 dalam agregasi lokal dan tidak masuk tampilan default.

Database:
- Tidak perlu import SQL manual.
- core/Migrations.php otomatis menambahkan kolom:
  bo_employee_people.manually_disabled TINYINT(1) NOT NULL DEFAULT 0

Validasi yang dilakukan:
- php -l pada semua file PHP yang diubah: lulus.
- node --check assets/js/backoffice.js: lulus.
- git diff --check: lulus.

Catatan instalasi:
- Backup file dan database terlebih dahulu.
- Timpa file sesuai struktur direktori.
- Buka Back Office satu kali agar migrasi otomatis berjalan.
- Uji menu Integrasi API dan Pegawai.
