-- Adena Back Office - Jadwal kerja mingguan, cuti berbasis tanggal, dan eligibility omset payroll
-- Jalankan SETELAH db/20261004_009_payroll_leave_late_slip.sql

CREATE TABLE IF NOT EXISTS bo_employee_schedule_days (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_month CHAR(7) NOT NULL,
  employee_key VARCHAR(64) NOT NULL,
  work_date DATE NOT NULL,
  planned_status ENUM('work','off') NOT NULL DEFAULT 'work',
  final_status ENUM('work','off') NOT NULL DEFAULT 'work',
  notes VARCHAR(255) NULL,
  created_by BIGINT NULL,
  updated_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_schedule_day (employee_key,work_date),
  KEY idx_employee_schedule_month (schedule_month,employee_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_employee_leave_periods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  leave_month CHAR(7) NOT NULL,
  employee_key VARCHAR(64) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT NULL,
  updated_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_employee_leave_month (leave_month,employee_key),
  KEY idx_employee_leave_dates (start_date,end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bo_payroll_items
  ADD COLUMN IF NOT EXISTS leave_effective_days DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER leave_deduction,
  ADD COLUMN IF NOT EXISTS leave_revenue DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER leave_effective_days,
  ADD COLUMN IF NOT EXISTS eligible_revenue DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER leave_revenue;

-- Matikan nilai legacy supaya tidak lagi memotong/meredistribusikan insentif.
UPDATE bo_payroll_employee_period_settings
SET leave_days=0, leave_manual_deduction=0, leave_deduction_mode='auto';

INSERT IGNORE INTO bo_schema_migrations(migration_key,applied_at)
VALUES ('20261005_schedule_leave_eligibility',NOW());
