ADENA BACK OFFICE - PATCH 1

Sifat aplikasi:
- Aplikasi mandiri/domain sendiri.
- Database sendiri.
- Berhubungan dengan Adena/Toko dan Dapur melalui API.
- PATCH 1 masih read-only untuk integrasi, dashboard, pegawai gabungan, dan kerangka KPI.
- Tidak mengubah POS, kasir desktop, transaksi, stok, atau workflow produksi lama.

INSTALASI XAMPP
1. Extract folder `backoffice` ke `htdocs/backoffice`.
2. Buka `http://localhost/backoffice/install/`.
3. Isi database:
   Host: 127.0.0.1
   Port: 3306
   DB Name: adey8293_backoffice
   User: root
   Password: kosong bila XAMPP default
4. Klik Install / Update.
5. Login default sesuai input installer. Default awal:
   username: owner
   password: owner12345
6. Buka menu Integrasi API.
7. Isi Base URL dan token Adena/Dapur.

INSTALASI HOSTING
1. Upload folder `backoffice` ke subdomain/folder hosting, misalnya `backoffice.domain.com`.
2. Buat database MySQL baru dari cPanel.
3. Buka `https://backoffice.domain.com/install/`.
4. Isi host/user/password database sesuai cPanel.
5. Setelah sukses, login dan isi koneksi API Adena/Dapur.

PATCH API YANG HARUS DIPASANG
Aplikasi Back Office butuh dua patch API read-only:
- `adena_patch_backoffice_api.zip` dipasang ke aplikasi Adena/Toko.
- `dapur_patch_backoffice_api.zip` dipasang ke aplikasi Dapur.

TOKEN API
Back Office memakai header:
Authorization: Bearer TOKEN_PLAIN

Token plain disimpan di Back Office, sedangkan di Adena/Dapur disimpan sebagai SHA256 hash.
Gunakan generator:
php -r "echo hash('sha256','TOKEN_PLAIN_DI_SINI');"

CATATAN KEAMANAN
- Ubah password owner setelah instalasi.
- Jangan expose token plain di screenshot/log publik.
- Hapus/rename folder install setelah produksi bila sudah stabil.
