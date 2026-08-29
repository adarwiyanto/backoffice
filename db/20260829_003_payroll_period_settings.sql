-- Adena Back Office - Payroll Period Settings
-- 2026-08-29 | Menjadikan seluruh input payroll berbasis periode bulanan.
-- Jalankan SETELAH 20260828_002_kpi_payroll_revision.sql

CREATE TABLE IF NOT EXISTS bo_payroll_unit_period_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payroll_month CHAR(7) NOT NULL,
  unit_type ENUM('store','dapur','backoffice') NOT NULL,
  system_key VARCHAR(100) NOT NULL,
  unit_name VARCHAR(160) NOT NULL,
  pool_percentage DECIMAL(8,4) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  seeded_from_month CHAR(7) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_payroll_unit_period (payroll_month,unit_type,system_key),
  KEY idx_bo_payroll_unit_period_month (payroll_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_payroll_employee_period_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payroll_month CHAR(7) NOT NULL,
  assignment_id BIGINT UNSIGNED NULL,
  bo_user_id BIGINT UNSIGNED NULL,
  base_salary DECIMAL(16,2) NOT NULL DEFAULT 0,
  overtime DECIMAL(16,2) NOT NULL DEFAULT 0,
  punishment DECIMAL(16,2) NOT NULL DEFAULT 0,
  kasbon DECIMAL(16,2) NOT NULL DEFAULT 0,
  is_eligible TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  seeded_from_month CHAR(7) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_payroll_employee_period_assignment (payroll_month,assignment_id),
  UNIQUE KEY uq_bo_payroll_employee_period_admin (payroll_month,bo_user_id),
  KEY idx_bo_payroll_employee_period_month (payroll_month),
  KEY idx_bo_payroll_employee_period_assignment (assignment_id),
  KEY idx_bo_payroll_employee_period_admin (bo_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_payroll_kpi_target_period_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payroll_month CHAR(7) NOT NULL,
  unit_type ENUM('store','dapur','backoffice') NOT NULL DEFAULT 'dapur',
  system_key VARCHAR(100) NOT NULL,
  role_key VARCHAR(100) NOT NULL,
  target_value DECIMAL(12,2) NOT NULL DEFAULT 100,
  seeded_from_month CHAR(7) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_payroll_kpi_target_period (payroll_month,unit_type,system_key,role_key),
  KEY idx_bo_payroll_kpi_target_period_month (payroll_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20260829_payroll_period_settings',NOW());
