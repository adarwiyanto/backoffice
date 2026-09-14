-- Adena Back Office - Optional KPI Toko + Back Office non-KPI
-- 2026-09-14
-- Jalankan SETELAH db/20260914_006_payroll_admin_daily_worker.sql

ALTER TABLE bo_payroll_unit_settings
  ADD COLUMN IF NOT EXISTS incentive_method ENUM('kpi','equal') NOT NULL DEFAULT 'kpi' AFTER admin_percentage;

ALTER TABLE bo_payroll_unit_period_settings
  ADD COLUMN IF NOT EXISTS incentive_method ENUM('kpi','equal') NOT NULL DEFAULT 'kpi' AFTER admin_percentage;

ALTER TABLE bo_payroll_items
  ADD COLUMN IF NOT EXISTS incentive_method ENUM('kpi','equal') NOT NULL DEFAULT 'kpi' AFTER daily_worker_salary;

-- Back Office selalu tanpa KPI; sisa pool insentif dibagi rata ke admin eligible.
UPDATE bo_payroll_unit_settings
SET incentive_method='equal', updated_at=NOW()
WHERE unit_type='backoffice';

UPDATE bo_payroll_unit_period_settings
SET incentive_method='equal', updated_at=NOW()
WHERE unit_type='backoffice';

-- Adena Bangka/PGK default tanpa KPI. Tetap dapat diubah per periode dari UI payroll.
UPDATE bo_payroll_unit_settings
SET incentive_method='equal', updated_at=NOW()
WHERE unit_type='store'
  AND (LOWER(system_key) LIKE '%bangka%' OR LOWER(system_key) LIKE '%pgk%' OR LOWER(unit_name) LIKE '%bangka%' OR LOWER(unit_name) LIKE '%pangkal%');

UPDATE bo_payroll_unit_period_settings
SET incentive_method='equal', updated_at=NOW()
WHERE unit_type='store'
  AND (LOWER(system_key) LIKE '%bangka%' OR LOWER(system_key) LIKE '%pgk%' OR LOWER(unit_name) LIKE '%bangka%' OR LOWER(unit_name) LIKE '%pangkal%');

-- Dapur tetap menggunakan KPI sinkron.
UPDATE bo_payroll_unit_settings SET incentive_method='kpi', updated_at=NOW() WHERE unit_type='dapur';
UPDATE bo_payroll_unit_period_settings SET incentive_method='kpi', updated_at=NOW() WHERE unit_type='dapur';

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20260914_payroll_optional_kpi',NOW());
