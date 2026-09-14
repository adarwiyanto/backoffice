-- Adena Back Office - Payroll Admin & Gaji Tenaga Harian
-- 2026-09-14
-- Jalankan SETELAH db/20260909_005_payroll_kpi_non_gaji_pph21.sql

-- % Penggajian tetap memakai kolom pool_percentage untuk kompatibilitas data lama.
-- Urutan basis: omset bruto - omset non-gaji, baru diterapkan % penggajian dan % admin.

ALTER TABLE bo_payroll_unit_settings
  ADD COLUMN IF NOT EXISTS admin_percentage DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER pool_percentage;

ALTER TABLE bo_payroll_unit_period_settings
  ADD COLUMN IF NOT EXISTS admin_percentage DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER pool_percentage,
  ADD COLUMN IF NOT EXISTS daily_worker_salary DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER non_salary_revenue_deduction;

ALTER TABLE bo_payroll_employee_settings
  ADD COLUMN IF NOT EXISTS admin_share DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER overtime;

ALTER TABLE bo_payroll_employee_period_settings
  ADD COLUMN IF NOT EXISTS admin_share DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER overtime;

ALTER TABLE bo_payroll_runs
  ADD COLUMN IF NOT EXISTS total_admin_share DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_overtime;

ALTER TABLE bo_payroll_items
  ADD COLUMN IF NOT EXISTS admin_percentage DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER payroll_budget,
  ADD COLUMN IF NOT EXISTS admin_budget DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER admin_percentage,
  ADD COLUMN IF NOT EXISTS daily_worker_salary DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER admin_budget,
  ADD COLUMN IF NOT EXISTS admin_share DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER overtime;

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20260914_payroll_admin_daily_worker',NOW());
