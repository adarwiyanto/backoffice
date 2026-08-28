-- Adena Back Office - Payroll Toko, Dapur, dan Admin Back Office
-- 2026-08-28 | MySQL / MariaDB | timezone aplikasi Asia/Jakarta

CREATE TABLE IF NOT EXISTS bo_schema_migrations (
  migration VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_payroll_unit_settings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  unit_type VARCHAR(30) NOT NULL,
  system_key VARCHAR(80) NOT NULL,
  unit_name VARCHAR(160) NOT NULL,
  pool_percentage DECIMAL(8,4) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_payroll_unit (unit_type,system_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_payroll_kpi_targets (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  unit_type VARCHAR(30) NOT NULL,
  system_key VARCHAR(80) NOT NULL,
  role_key VARCHAR(80) NOT NULL,
  target_value DECIMAL(14,2) NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_payroll_target (unit_type,system_key,role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_payroll_employee_settings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  assignment_id BIGINT NULL,
  bo_user_id BIGINT NULL,
  base_salary DECIMAL(16,2) NOT NULL DEFAULT 0,
  allowance DECIMAL(16,2) NOT NULL DEFAULT 0,
  overtime DECIMAL(16,2) NOT NULL DEFAULT 0,
  deduction DECIMAL(16,2) NOT NULL DEFAULT 0,
  adjustment DECIMAL(16,2) NOT NULL DEFAULT 0,
  is_eligible TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_payroll_assignment (assignment_id),
  UNIQUE KEY uq_bo_payroll_admin_user (bo_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_payroll_admin_kpi (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  payroll_month CHAR(7) NOT NULL,
  bo_user_id BIGINT NOT NULL,
  score DECIMAL(8,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  updated_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_payroll_admin_kpi (payroll_month,bo_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_payroll_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  payroll_month CHAR(7) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  total_base_salary DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_incentive DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_allowance DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_overtime DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_deduction DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_adjustment DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_take_home DECIMAL(18,2) NOT NULL DEFAULT 0,
  snapshot_json LONGTEXT NULL,
  created_by BIGINT NULL,
  updated_by BIGINT NULL,
  finalized_by BIGINT NULL,
  finalized_at DATETIME NULL,
  paid_by BIGINT NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_payroll_month (payroll_month),
  KEY idx_bo_payroll_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_payroll_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT NOT NULL,
  source_type VARCHAR(30) NOT NULL,
  assignment_id BIGINT NULL,
  bo_user_id BIGINT NULL,
  person_id BIGINT NULL,
  employee_name_snapshot VARCHAR(160) NOT NULL,
  role_key_snapshot VARCHAR(80) NULL,
  unit_type VARCHAR(30) NOT NULL,
  system_key VARCHAR(80) NOT NULL,
  unit_name_snapshot VARCHAR(160) NOT NULL,
  revenue_base DECIMAL(18,2) NOT NULL DEFAULT 0,
  pool_percentage DECIMAL(8,4) NOT NULL DEFAULT 0,
  pool_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  kpi_raw DECIMAL(18,4) NOT NULL DEFAULT 0,
  kpi_target DECIMAL(18,4) NOT NULL DEFAULT 100,
  kpi_score DECIMAL(8,2) NOT NULL DEFAULT 0,
  kpi_weight DECIMAL(12,8) NOT NULL DEFAULT 0,
  kpi_source VARCHAR(100) NULL,
  incentive_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  base_salary DECIMAL(18,2) NOT NULL DEFAULT 0,
  allowance DECIMAL(18,2) NOT NULL DEFAULT 0,
  overtime DECIMAL(18,2) NOT NULL DEFAULT 0,
  deduction DECIMAL(18,2) NOT NULL DEFAULT 0,
  adjustment DECIMAL(18,2) NOT NULL DEFAULT 0,
  take_home DECIMAL(18,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  snapshot_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bo_payroll_items_run (run_id),
  KEY idx_bo_payroll_items_employee (person_id,bo_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20260828_payroll_store_dapur_backoffice',NOW());
