-- Adena Back Office - Payroll BPJS, basis omset TJQ/PGK, KPI dapur 25%
-- 2026-09-03
-- Jalankan SETELAH 20260829_003_payroll_period_settings.sql

ALTER TABLE bo_payroll_employee_settings
  ADD COLUMN IF NOT EXISTS bpjs_kesehatan DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER overtime,
  ADD COLUMN IF NOT EXISTS bpjs_ketenagakerjaan DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER bpjs_kesehatan;

ALTER TABLE bo_payroll_employee_period_settings
  ADD COLUMN IF NOT EXISTS bpjs_kesehatan DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER overtime,
  ADD COLUMN IF NOT EXISTS bpjs_ketenagakerjaan DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER bpjs_kesehatan;

ALTER TABLE bo_payroll_runs
  ADD COLUMN IF NOT EXISTS total_bpjs_kesehatan DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_overtime,
  ADD COLUMN IF NOT EXISTS total_bpjs_ketenagakerjaan DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_bpjs_kesehatan;

ALTER TABLE bo_payroll_items
  ADD COLUMN IF NOT EXISTS bpjs_kesehatan DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER overtime,
  ADD COLUMN IF NOT EXISTS bpjs_ketenagakerjaan DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER bpjs_kesehatan;

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20260903_payroll_bpjs_revenue_kpi',NOW());
