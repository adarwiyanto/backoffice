PATCH KOREKSI PENGGAJIAN KOSONG - 2026-09-15

Masalah:
- Halaman Keuangan > Penggajian kosong setelah patch sebelumnya.
- Penyebab: isi bo_payroll_compute() terpotong/masuk ke bo_payroll_financial_map(), sehingga terjadi fatal error runtime.

Perbaikan:
1. Memulihkan bo_payroll_financial_map() sebagai fungsi pemetaan omset saja.
2. Memulihkan bo_payroll_compute() secara utuh.
3. Mempertahankan fitur patch sebelumnya:
   - Absensi SDM Toko/Dapur manual.
   - Target hari kerja manual per periode.
   - Insentif Dapur 20% kehadiran + 80% KPI.
   - Basis dan % payroll Back Office Belitung/Bangka terpisah.
   - Gaji pokok Back Office tetap manual.
   - KPI Final & Kunci menyimpan nilai terakhir dan tidak mereset menjadi 0.
4. KPI Dapur untuk pembobotan 80% memakai nilai KPI sinkron secara proporsional.

Instalasi:
- Timpa file core/Payroll.php pada Back Office dengan file dari patch ini.
- TIDAK perlu import SQL ulang apabila migrasi 20260915_008 sudah pernah dijalankan.
- Setelah upload, buka ulang Keuangan > Penggajian dan lakukan hard refresh (Ctrl+F5).

Validasi:
- Semua file PHP proyek telah dicek dengan php -l: tidak ada syntax error.
