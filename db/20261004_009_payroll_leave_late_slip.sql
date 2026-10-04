-- Adena Back Office - Payroll cuti, terlambat/pulang cepat, redistribusi insentif & slip gaji
-- 2026-10-04
-- Jalankan SETELAH db/20260915_008_attendance_dapur_incentive_bo_region.sql

ALTER TABLE bo_payroll_employee_settings
  ADD COLUMN IF NOT EXISTS leave_deduction_mode ENUM('auto','manual') NOT NULL DEFAULT 'auto' AFTER kasbon,
  ADD COLUMN IF NOT EXISTS leave_days DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER leave_deduction_mode,
  ADD COLUMN IF NOT EXISTS leave_target_workdays DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER leave_days,
  ADD COLUMN IF NOT EXISTS leave_manual_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER leave_target_workdays,
  ADD COLUMN IF NOT EXISTS late_early_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER leave_manual_deduction,
  ADD COLUMN IF NOT EXISTS late_early_notes VARCHAR(500) NULL AFTER late_early_deduction;

ALTER TABLE bo_payroll_employee_period_settings
  ADD COLUMN IF NOT EXISTS leave_deduction_mode ENUM('auto','manual') NOT NULL DEFAULT 'auto' AFTER kasbon,
  ADD COLUMN IF NOT EXISTS leave_days DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER leave_deduction_mode,
  ADD COLUMN IF NOT EXISTS leave_target_workdays DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER leave_days,
  ADD COLUMN IF NOT EXISTS leave_manual_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER leave_target_workdays,
  ADD COLUMN IF NOT EXISTS late_early_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER leave_manual_deduction,
  ADD COLUMN IF NOT EXISTS late_early_notes VARCHAR(500) NULL AFTER late_early_deduction;

ALTER TABLE bo_payroll_items
  ADD COLUMN IF NOT EXISTS incentive_gross DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER kpi_source,
  ADD COLUMN IF NOT EXISTS leave_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER incentive_gross,
  ADD COLUMN IF NOT EXISTS late_early_deduction DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER leave_deduction,
  ADD COLUMN IF NOT EXISTS incentive_redistributed DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER late_early_deduction;

CREATE TABLE IF NOT EXISTS bo_settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO bo_schema_migrations(migration,applied_at)
VALUES ('20261004_payroll_leave_late_slip',NOW());
