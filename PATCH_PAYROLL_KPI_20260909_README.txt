PATCH BACK OFFICE - PAYROLL + KPI - 2026-09-09

FILE YANG DITIMPA:
1. core/Payroll.php
2. modules/finance.php
3. modules/kpi.php

SQL BARU:
- db/20260909_005_payroll_kpi_non_gaji_pph21.sql

URUTAN INSTALASI:
1. Backup file dan database Back Office.
2. Import db/20260909_005_payroll_kpi_non_gaji_pph21.sql melalui phpMyAdmin.
3. Timpa tiga file PHP di atas sesuai struktur folder.
4. Buka Keuangan > Penggajian dan pilih periode.
5. Isi "Pengurangan Omset Non-Gaji" pada masing-masing Toko/Dapur bila ada.
6. Isi PPh 21 pegawai bila ada.
7. Buka SDM > KPI, Mulai Penilaian, isi/edit KPI + bobot + nilai, lalu Final & Kunci.
8. Kembali ke Penggajian dan klik "Simpan / Hitung Ulang Draft".
9. Verifikasi pool insentif dan THP sebelum Final & Kunci.

LOGIKA PATCH:
- Basis payroll Toko/Dapur = Omset bruto - Pengurangan Omset Non-Gaji.
- Pengurangan non-gaji adalah satu nominal bulanan per unit dan tidak diwariskan ke bulan berikutnya.
- Back Office tetap memakai basis omset keseluruhan seperti sebelumnya.
- PPh 21 adalah potongan THP dan diwariskan sebagai komponen tetap ke periode berikutnya.
- Pool insentif = Budget Payroll - (Gaji Pokok + Lembur).
- Pool insentif dibagikan otomatis menurut bobot KPI final / poin KPI Dapur.
- Selisih pembulatan pembagian insentif dimasukkan ke pegawai berbobot terakhir agar total insentif tepat sama dengan pool.
- THP = Gaji Pokok + Lembur + Insentif - BPJS Kesehatan - BPJS Ketenagakerjaan - PPh 21 - Punishment - Kasbon.

KPI:
- Master KPI tetap menjadi template.
- Setelah Mulai Penilaian, item KPI pada assessment Draft dapat diedit langsung.
- Nama KPI, kategori, bobot, nilai maksimum, nilai aktual, dan catatan dapat diisi.
- KPI khusus assessment dapat ditambahkan dan item Draft dapat dihapus.
- Finalisasi membutuhkan minimal satu KPI dan total bobot tepat 100%.
