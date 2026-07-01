CREATE TABLE IF NOT EXISTS bo_system_connections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  system_key VARCHAR(50) NOT NULL UNIQUE,
  system_name VARCHAR(120) NOT NULL,
  system_type VARCHAR(50) NULL,
  base_url VARCHAR(255) NOT NULL,
  api_token TEXT NOT NULL,
  access_scope VARCHAR(80) NULL,
  status VARCHAR(30) NULL,
  is_active TINYINT(1) DEFAULT 1,
  paired_at DATETIME NULL,
  last_health_check_at DATETIME NULL,
  last_health_status VARCHAR(30) NULL,
  last_health_message TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bo_users (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(120) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_key VARCHAR(60) NOT NULL DEFAULT 'viewer',
  is_active TINYINT(1) DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bo_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(60) NOT NULL UNIQUE,
  role_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bo_role_permissions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(60) NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  can_view TINYINT(1) DEFAULT 0,
  can_create TINYINT(1) DEFAULT 0,
  can_edit TINYINT(1) DEFAULT 0,
  can_delete TINYINT(1) DEFAULT 0,
  can_approve TINYINT(1) DEFAULT 0,
  can_export TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bo_product_mappings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  adena_product_id VARCHAR(80) NULL,
  adena_product_name VARCHAR(180) NULL,
  adena_sku VARCHAR(100) NULL,
  dapur_finished_product_id VARCHAR(80) NULL,
  dapur_product_name VARCHAR(180) NULL,
  dapur_sku VARCHAR(100) NULL,
  mapping_status VARCHAR(30) DEFAULT 'active',
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bo_sync_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  system_key VARCHAR(50) NOT NULL,
  direction VARCHAR(20) NOT NULL,
  endpoint VARCHAR(180) NOT NULL,
  method VARCHAR(10) NOT NULL,
  status VARCHAR(30) NOT NULL,
  status_code INT NULL,
  request_payload LONGTEXT NULL,
  response_payload LONGTEXT NULL,
  message TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bo_audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NULL,
  action VARCHAR(120) NOT NULL,
  target_system VARCHAR(50) NULL,
  target_type VARCHAR(80) NULL,
  target_id VARCHAR(80) NULL,
  description TEXT NULL,
  payload_json LONGTEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bo_dashboard_cache (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  cache_key VARCHAR(120) NOT NULL,
  cache_date DATE NULL,
  payload_json LONGTEXT NOT NULL,
  expires_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO bo_roles(role_key,role_name) VALUES ('owner','Owner'),('admin','Admin'),('viewer','Viewer');



CREATE TABLE IF NOT EXISTS bo_pairing_requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  target_system VARCHAR(50) NOT NULL,
  target_name VARCHAR(160) NOT NULL,
  target_base_url VARCHAR(255) NOT NULL,
  request_code VARCHAR(90) NOT NULL,
  request_secret TEXT NULL,
  requester_name VARCHAR(160) NOT NULL DEFAULT 'Back Office',
  requester_type VARCHAR(50) NOT NULL DEFAULT 'backoffice',
  requested_scope VARCHAR(80) NOT NULL DEFAULT 'superadmin',
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  access_token TEXT NULL,
  message TEXT NULL,
  last_checked_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bo_pairing_code (request_code)
);