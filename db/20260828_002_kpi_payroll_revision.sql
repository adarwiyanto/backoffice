-- Adena Back Office - Revisi KPI + Payroll berbasis % omset total THP
-- 2026-08-28 | Jalankan SETELAH 20260828_001_payroll_store_dapur_backoffice.sql

CREATE TABLE IF NOT EXISTS bo_kpi_masters (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  scope_key VARCHAR(30) NOT NULL,
  kpi_name VARCHAR(160) NOT NULL,
  category_name VARCHAR(120) NULL,
  description VARCHAR(500) NULL,
  weight DECIMAL(8,2) NOT NULL DEFAULT 0,
  max_score DECIMAL(10,2) NOT NULL DEFAULT 100,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_bo_kpi_master_scope (scope_key,is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_kpi_assessments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  scope_key VARCHAR(30) NOT NULL,
  assessment_month CHAR(7) NOT NULL,
  assignment_id BIGINT NULL,
  bo_user_id BIGINT NULL,
  system_key VARCHAR(80) NOT NULL,
  system_name VARCHAR(160) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  final_score DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_weight DECIMAL(10,2) NOT NULL DEFAULT 0,
  general_notes VARCHAR(1000) NULL,
  created_by BIGINT NULL,
  updated_by BIGINT NULL,
  finalized_by BIGINT NULL,
  finalized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_kpi_store_month (scope_key,assessment_month,assignment_id),
  UNIQUE KEY uq_bo_kpi_bo_month (scope_key,assessment_month,bo_user_id),
  KEY idx_bo_kpi_assessment_month (assessment_month,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bo_kpi_assessment_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  assessment_id BIGINT NOT NULL,
  master_id BIGINT NULL,
  kpi_name_snapshot VARCHAR(160) NOT NULL,
  category_snapshot VARCHAR(120) NULL,
  weight_snapshot DECIMAL(8,2) NOT NULL DEFAULT 0,
  max_score_snapshot DECIMAL(10,2) NOT NULL DEFAULT 100,
  score DECIMAL(10,2) NOT NULL DEFAULT 0,
  weighted_score DECIMAL(10,4) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  KEY idx_bo_kpi_items_assessment (assessment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE bo_payroll_employee_settings
  ADD COLUMN punishment DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER overtime,
  ADD COLUMN kasbon DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER punishment;

ALTER TABLE bo_payroll_runs
  ADD COLUMN total_punishment DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_overtime,
  ADD COLUMN total_kasbon DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_punishment;

ALTER TABLE bo_payroll_items
  ADD COLUMN payroll_budget DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER revenue_base,
  ADD COLUMN incentive_pool DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER pool_amount,
  ADD COLUMN punishment DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER overtime,
  ADD COLUMN kasbon DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER punishment;

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20260828_kpi_payroll_revision',NOW());
