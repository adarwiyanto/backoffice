-- Adena Back Office - Payroll/KPI revision 2026-09-09
-- Jalankan SETELAH db/20260903_004_payroll_bpjs_revenue_kpi.sql
-- Scope:
-- 1) Pengurangan Omset Non-Gaji per unit/periode (Toko + Dapur)
-- 2) PPh 21 pegawai
-- 3) Snapshot omset bruto/pengurangan pada payroll final

ALTER TABLE bo_payroll_unit_period_settings
  ADD COLUMN IF NOT EXISTS non_salary_revenue_deduction DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER pool_percentage;

ALTER TABLE bo_payroll_employee_settings
  ADD COLUMN IF NOT EXISTS pph21 DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER bpjs_ketenagakerjaan;

ALTER TABLE bo_payroll_employee_period_settings
  ADD COLUMN IF NOT EXISTS pph21 DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER bpjs_ketenagakerjaan;

ALTER TABLE bo_payroll_runs
  ADD COLUMN IF NOT EXISTS total_pph21 DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_bpjs_ketenagakerjaan;

ALTER TABLE bo_payroll_items
  ADD COLUMN IF NOT EXISTS revenue_gross DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER unit_name_snapshot,
  ADD COLUMN IF NOT EXISTS revenue_non_salary_deduction DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER revenue_gross,
  ADD COLUMN IF NOT EXISTS pph21 DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER bpjs_ketenagakerjaan;

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20260909_payroll_kpi_non_gaji_pph21',NOW());
