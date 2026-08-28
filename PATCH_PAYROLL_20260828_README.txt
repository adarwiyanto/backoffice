PATCH BACK OFFICE - PAYROLL TOKO / DAPUR / ADMIN
Tanggal: 28 Agustus 2026

SCOPE
- Mengaktifkan tab Keuangan > Penggajian.
- Payroll dipisahkan per unit Toko, Dapur, dan Admin Back Office.
- Owner/superadmin dari pegawai host tidak ikut payroll; Back Office hanya mengambil user role admin.
- Pool insentif default 0% agar tidak ada bonus otomatis sebelum dikonfigurasi.
- Toko: basis = sales_revenue host toko, KPI = final_score KPI toko.
- Dapur: basis = sales_revenue + internal_distribution_value, KPI = total_points / target poin role (maksimum payroll score 120%).
- Admin Back Office: basis = omset eksternal konsolidasi toko + direct sales dapur; KPI diinput per bulan di Back Office.
- Insentif pegawai = Pool Unit x KPI pegawai / Total KPI eligible unit.
- Gaji pokok + tunjangan + lembur + adjustment + insentif - potongan = Take Home Pay.
- Status payroll: Draft > Final/Locked > Paid.
- Final menyimpan snapshot omset, KPI, formula, dan nominal sehingga histori tidak berubah.
- Finalisasi ditolak bila API omset/KPI sedang gagal dibaca.
- Payroll Final/Paid masuk estimasi laba-rugi; hanya Payroll Paid masuk arus kas.

FILE PATCH
1. core/Payroll.php (baru)
2. core/Migrations.php
3. modules/finance.php
4. db/20260828_001_payroll_store_dapur_backoffice.sql (baru)

INSTALASI
1. Backup database dan folder Back Office.
2. Import db/20260828_001_payroll_store_dapur_backoffice.sql melalui phpMyAdmin.
3. Upload/timpa file sesuai struktur folder.
4. Login Back Office > Pegawai, jalankan "Sync Pegawai Sekarang" agar assignment toko/dapur terbaru tersedia.
5. Buka Keuangan > Penggajian.
6. Atur % Pool tiap unit. Default seluruh unit adalah 0%.
7. Isi gaji pokok/tunjangan/lembur/potongan/adjustment dan status Eligible tiap pegawai.
8. Khusus Dapur, atur Target KPI Poin per role dari baris pegawai dapur.
9. Isi KPI bulanan Admin Back Office (0-120).
10. Klik "Simpan / Hitung Ulang Draft" dan periksa hasil.
11. Setelah benar, klik "Final & Kunci". Setelah pembayaran aktual, klik "Tandai Dibayar".

CATATAN
- Setting gaji pegawai toko/dapur disimpan per assignment; satu orang yang bekerja di dua unit dapat memiliki komponen berbeda.
- Distribusi internal dapur ke toko tidak menambah pendapatan konsolidasi grup, tetapi digunakan sebagai basis kinerja dapur.
- Tidak ada persentase pool yang diasumsikan oleh patch. Harus ditentukan manual oleh Owner/Admin.
