-- ADENA BACK OFFICE - KEUANGAN LOKAL DAN FONDASI SINKRONISASI
-- Aman untuk database berjalan. Tidak menghapus tabel lama.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS bo_expense_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  category_code VARCHAR(80) NOT NULL,
  category_name VARCHAR(160) NOT NULL,
  group_name VARCHAR(120) NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  requires_approval TINYINT(1) NOT NULL DEFAULT 0,
  requires_evidence TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_expense_category_code (category_code),
  UNIQUE KEY uq_bo_expense_category_uuid (record_uuid),
  KEY idx_bo_expense_category_active (is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  expense_no VARCHAR(80) NOT NULL,
  expense_date DATE NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  category_name_snapshot VARCHAR(160) NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  vendor_name VARCHAR(190) NULL,
  payment_method VARCHAR(80) NULL,
  reference_no VARCHAR(120) NULL,
  evidence_reference VARCHAR(255) NULL,
  status ENUM('draft','submitted','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'paid',
  due_date DATE NULL,
  cost_center_type ENUM('backoffice','store','kitchen','all_units') NOT NULL DEFAULT 'backoffice',
  cost_center_key VARCHAR(100) NULL,
  approved_by BIGINT NULL,
  approved_at DATETIME NULL,
  paid_by BIGINT NULL,
  paid_at DATETIME NULL,
  created_by BIGINT NULL,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_expenses_no (expense_no),
  UNIQUE KEY uq_bo_expenses_uuid (record_uuid),
  KEY idx_bo_expenses_date_status (expense_date,status),
  KEY idx_bo_expenses_cost_center (cost_center_type,cost_center_key,expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_payment_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  request_no VARCHAR(80) NOT NULL,
  request_date DATE NOT NULL,
  source_type ENUM('backoffice','store','kitchen') NOT NULL DEFAULT 'backoffice',
  source_key VARCHAR(100) NULL,
  category_id BIGINT UNSIGNED NULL,
  category_name_snapshot VARCHAR(160) NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  vendor_name VARCHAR(190) NULL,
  due_date DATE NULL,
  reference_no VARCHAR(120) NULL,
  evidence_reference VARCHAR(255) NULL,
  status ENUM('draft','submitted','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  requested_by BIGINT NULL,
  approved_by BIGINT NULL,
  approved_at DATETIME NULL,
  paid_by BIGINT NULL,
  paid_at DATETIME NULL,
  linked_expense_id BIGINT UNSIGNED NULL,
  rejection_reason TEXT NULL,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_payment_requests_no (request_no),
  UNIQUE KEY uq_bo_payment_requests_uuid (record_uuid),
  KEY idx_bo_payment_requests_status (request_date,status),
  KEY idx_bo_payment_requests_source (source_type,source_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_finance_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  action_key VARCHAR(50) NOT NULL,
  payload_json LONGTEXT NULL,
  acted_by BIGINT NULL,
  acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bo_finance_audit_entity (entity_type,entity_id,acted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_sync_inbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_system_key VARCHAR(100) NOT NULL,
  event_uuid CHAR(36) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  external_id VARCHAR(120) NOT NULL,
  operation VARCHAR(30) NOT NULL,
  entity_version INT NOT NULL DEFAULT 1,
  payload_json LONGTEXT NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  occurred_at DATETIME NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  process_status ENUM('received','processed','failed','conflict') NOT NULL DEFAULT 'received',
  process_message TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_sync_inbox_source_event (source_system_key,event_uuid),
  KEY idx_bo_sync_inbox_status (process_status,received_at),
  KEY idx_bo_sync_inbox_entity (source_system_key,entity_type,external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_sync_cursors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_system_key VARCHAR(100) NOT NULL,
  dataset VARCHAR(80) NOT NULL,
  last_cursor VARCHAR(255) NULL,
  last_external_updated_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  last_status VARCHAR(30) NULL,
  last_message TEXT NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_sync_cursor (source_system_key,dataset)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_sync_conflicts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_system_key VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  external_id VARCHAR(120) NOT NULL,
  local_version INT NULL,
  remote_version INT NULL,
  local_payload_json LONGTEXT NULL,
  remote_payload_json LONGTEXT NULL,
  status ENUM('open','resolved_local','resolved_remote','merged','ignored') NOT NULL DEFAULT 'open',
  resolution_notes TEXT NULL,
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by BIGINT NULL,
  PRIMARY KEY (id),
  KEY idx_bo_sync_conflict_status (status,detected_at),
  KEY idx_bo_sync_conflict_entity (source_system_key,entity_type,external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_financial_sync_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_system_key VARCHAR(100) NOT NULL,
  source_type ENUM('store','kitchen','backoffice') NOT NULL,
  entity_type ENUM('purchase','expense','payment_request','payroll') NOT NULL,
  external_id VARCHAR(120) NOT NULL,
  record_uuid CHAR(36) NULL,
  transaction_date DATE NOT NULL,
  category_code VARCHAR(80) NULL,
  category_name VARCHAR(160) NULL,
  description VARCHAR(255) NULL,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  status VARCHAR(30) NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  counterparty_system_key VARCHAR(100) NULL,
  payload_json LONGTEXT NULL,
  external_updated_at DATETIME NULL,
  synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bo_financial_sync_record (source_system_key,entity_type,external_id),
  KEY idx_bo_financial_sync_period (transaction_date,entity_type,status),
  KEY idx_bo_financial_sync_source (source_system_key,transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bo_expense_categories(record_uuid,category_code,category_name,group_name,sort_order,is_active)
VALUES
(UUID(),'TAX','Pajak','Korporat',10,1),
(UUID(),'CONSULTANT','Konsultan','Jasa Profesional',20,1),
(UUID(),'ACCOUNTING','Akuntan','Jasa Profesional',30,1),
(UUID(),'LEGAL','Legal / Hukum','Jasa Profesional',40,1),
(UUID(),'LICENSE','Perizinan','Korporat',50,1),
(UUID(),'SOFTWARE','Langganan Software','Teknologi',60,1),
(UUID(),'BANK-ADMIN','Administrasi Bank','Administrasi',70,1),
(UUID(),'MARKETING','Pemasaran','Operasional',80,1),
(UUID(),'TRAVEL','Perjalanan Dinas','Operasional',90,1),
(UUID(),'OFFICE','Biaya Kantor Pusat','Operasional',100,1),
(UUID(),'OTHER','Lain-lain','Lainnya',999,1);
