PATCH PAYROLL ADMIN + TENAGA HARIAN - 14 SEPTEMBER 2026

File yang berubah:
- core/Payroll.php
- modules/finance.php
- db/20260914_006_payroll_admin_daily_worker.sql

INSTALASI
1. Backup database dan file Back Office.
2. Import db/20260914_006_payroll_admin_daily_worker.sql melalui phpMyAdmin.
3. Upload/timpa core/Payroll.php dan modules/finance.php.
4. Buka Keuangan > Penggajian dan pilih periode.
5. Isi % Penggajian, % Admin, Omset Non-Gaji, dan khusus Dapur isi Gaji Tenaga Harian.
6. Isi Bagian Admin pada komponen pegawai yang menerima.
7. Hitung ulang Draft dan verifikasi Kontrol Budget sebelum Final & Kunci.

RUMUS
Basis Omset = Omset Bruto - Omset Non-Gaji
Budget Penggajian = Basis Omset x % Penggajian
Budget Admin = Basis Omset x % Admin
Pool Insentif Toko/Back Office = Budget Penggajian - Gaji Pokok - Lembur
Pool Insentif Dapur = Budget Penggajian - Gaji Pokok - Lembur - Gaji Tenaga Harian
THP = Gaji Pokok + Lembur + Bagian Admin + Insentif - BPJS Kesehatan - BPJS Ketenagakerjaan - PPh 21 - Punishment - Kasbon

CATATAN
- Omset Non-Gaji dikurangi terlebih dahulu sebelum kedua persentase dihitung.
- Gaji Tenaga Harian disimpan per unit Dapur/periode dan tidak diwariskan ke bulan baru.
- % Penggajian dan % Admin diwariskan ke periode berikutnya.
- Bagian Admin merupakan komponen payroll pegawai dan ikut menambah THP.
