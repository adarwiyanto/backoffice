Patch 20260702 - Back Office

Perubahan minimal sesuai permintaan:
1. Sidebar SDM dirapikan menjadi KPI Pegawai Toko dan KPI Pegawai Dapur.
2. modules/kpi.php dibuat membaca KPI Dapur dari endpoint Dapur: api/backoffice/kpi_dapur.php.
3. Dashboard Back Office menampilkan Omset Dapur Bulan Ini.
4. Omset Dapur memakai harga jual Dapur aktual dari kitchen_sales_headers.total_amount, bukan asumsi 30%.
5. Dashboard menampilkan breakdown omset Dapur per toko tujuan yang dikirim oleh Dapur.
