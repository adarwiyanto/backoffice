PATCH BACK OFFICE 2026-09-15
Absensi SDM + Insentif Dapur 20/80 + Payroll BO Regional + Fix KPI Final & Kunci

URUTAN INSTALASI
1. Backup file dan database Back Office terlebih dahulu.
2. Upload/timpa file patch sesuai struktur folder.
3. Import SQL berikut melalui phpMyAdmin:
   db/20260915_008_attendance_dapur_incentive_bo_region.sql
4. Login ulang bila perlu, lalu buka:
   - SDM > Absensi
   - Keuangan > Penggajian
   - SDM > KPI Pegawai

PERUBAHAN
A. SDM > Absensi
- Menu baru Absensi pada bagian SDM.
- Absensi Pegawai Toko dan Pegawai Dapur dipisahkan.
- Filter periode bulanan.
- Target Hari Kerja diinput manual satu kali per kelompok/periode dan berlaku untuk seluruh pegawai pada kelompok tersebut.
- Jumlah hadir diinput manual satu per satu per pegawai.
- Jumlah hadir divalidasi tidak melebihi target hari kerja.

B. Payroll Dapur
- Sisa untuk Insentif dibagi:
  20% = komponen Kehadiran
  80% = komponen Kinerja/KPI
- Kehadiran pegawai = hadir pegawai / total hadir seluruh pegawai eligible Dapur x 20% sisa insentif.
- KPI pegawai = nilai KPI pegawai / total KPI seluruh pegawai eligible Dapur x 80% sisa insentif.
- Target hari kerja dibaca dari SDM > Absensi dan menjadi acuan/validasi periode.
- Finalisasi payroll Dapur ditolak bila target hari kerja atau total kehadiran belum diinput.
- Rincian payroll menampilkan kehadiran, bobot kehadiran, insentif kehadiran, bobot KPI, insentif kinerja, dan total insentif.

C. Admin Back Office
- Basic Omset dan % Penggajian dipisah antara Adena Belitung dan Adena Bangka.
- Tersedia pengurangan Omset Non-Gaji BO per wilayah sebelum menjadi Basic Omset.
- Budget Back Office = (Basic Omset Belitung x % Belitung) + (Basic Omset Bangka x % Bangka).
- Gaji pokok Admin Back Office tetap diinput manual per pegawai.
- Back Office tetap tanpa KPI dan sisa insentif dibagi rata kepada admin eligible.

D. KPI Final & Kunci
- Tombol Final & Kunci sekarang berada pada form yang sama dengan input nilai KPI.
- Saat Final & Kunci ditekan, nilai terakhir yang sedang tampil disimpan terlebih dahulu.
- Setelah itu weighted score/final score dihitung dan baru status dikunci.
- Nilai KPI tidak di-reset menjadi 0.
- Jika data/bobot tidak valid, finalisasi ditolak tanpa mengubah nilai yang sudah tersimpan.

FILE YANG BERUBAH
- index.php
- partials/sidebar.php
- modules/attendance.php (baru)
- modules/kpi.php
- modules/finance.php
- core/Payroll.php
- db/20260915_008_attendance_dapur_incentive_bo_region.sql (baru)

CATATAN UJI
- Seluruh file PHP pada paket sumber telah lolos php -l (syntax check).
- Patch ini dibuat di atas backoffice(20260915-055706).zip.
