-- Adena Back Office - Attendance + Dapur incentive 20/80 + Back Office regional payroll
-- 2026-09-15
-- Jalankan SETELAH db/20260914_007_payroll_optional_kpi.sql

CREATE TABLE IF NOT EXISTS bo_attendance_period_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attendance_month CHAR(7) NOT NULL,
  scope_key ENUM('store','dapur') NOT NULL,
  target_workdays DECIMAL(6,2) NOT NULL DEFAULT 0,
  created_by BIGINT NULL,
  updated_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_period_scope (attendance_month,scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_attendance_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attendance_month CHAR(7) NOT NULL,
  scope_key ENUM('store','dapur') NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  attended_days DECIMAL(6,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  created_by BIGINT NULL,
  updated_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_month_assignment (attendance_month,assignment_id),
  KEY idx_attendance_scope_month (scope_key,attendance_month),
  KEY idx_attendance_assignment (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bo_payroll_unit_settings
  ADD COLUMN IF NOT EXISTS bo_belitung_percentage DECIMAL(7,4) NOT NULL DEFAULT 0 AFTER pool_percentage,
  ADD COLUMN IF NOT EXISTS bo_bangka_percentage DECIMAL(7,4) NOT NULL DEFAULT 0 AFTER bo_belitung_percentage,
  ADD COLUMN IF NOT EXISTS bo_belitung_non_salary_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER bo_bangka_percentage,
  ADD COLUMN IF NOT EXISTS bo_bangka_non_salary_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER bo_belitung_non_salary_deduction;

ALTER TABLE bo_payroll_unit_period_settings
  ADD COLUMN IF NOT EXISTS bo_belitung_percentage DECIMAL(7,4) NOT NULL DEFAULT 0 AFTER pool_percentage,
  ADD COLUMN IF NOT EXISTS bo_bangka_percentage DECIMAL(7,4) NOT NULL DEFAULT 0 AFTER bo_belitung_percentage,
  ADD COLUMN IF NOT EXISTS bo_belitung_non_salary_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER non_salary_revenue_deduction,
  ADD COLUMN IF NOT EXISTS bo_bangka_non_salary_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER bo_belitung_non_salary_deduction;

-- Pertahankan persentase BO lama sebagai default Belitung agar upgrade tidak tiba-tiba menjadi nol.
UPDATE bo_payroll_unit_settings
SET bo_belitung_percentage=pool_percentage
WHERE unit_type='backoffice' AND bo_belitung_percentage=0 AND bo_bangka_percentage=0;

UPDATE bo_payroll_unit_period_settings
SET bo_belitung_percentage=pool_percentage
WHERE unit_type='backoffice' AND bo_belitung_percentage=0 AND bo_bangka_percentage=0;

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20260915_attendance_dapur_incentive_bo_region',NOW());
