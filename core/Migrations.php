<?php
require_once __DIR__.'/Database.php';

function bo_table_exists(string $table): bool {
  try {
    $st=bo_exec('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
    return (int)$st->fetchColumn()>0;
  } catch(Throwable $e) { return false; }
}

function bo_column_exists(string $table,string $column): bool {
  try {
    $st=bo_exec('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
    return (int)$st->fetchColumn()>0;
  } catch(Throwable $e) { return false; }
}

function bo_index_exists(string $table,string $index): bool {
  try {
    $st=bo_exec('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$index]);
    return (int)$st->fetchColumn()>0;
  } catch(Throwable $e) { return false; }
}

function bo_migration_done(string $name): bool {
  try {
    bo_db()->exec("CREATE TABLE IF NOT EXISTS bo_schema_migrations (
      migration VARCHAR(120) NOT NULL PRIMARY KEY,
      applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st=bo_exec('SELECT migration FROM bo_schema_migrations WHERE migration=? LIMIT 1',[$name]);
    return (bool)$st->fetch();
  } catch(Throwable $e) { return false; }
}

function bo_mark_migration(string $name): void {
  try { bo_exec('INSERT IGNORE INTO bo_schema_migrations(migration,applied_at) VALUES(?,NOW())',[$name]); } catch(Throwable $e) {}
}

function bo_add_column_if_missing(string $table,string $column,string $definition): void {
  if(!bo_column_exists($table,$column)) bo_db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function bo_add_index_if_missing(string $table,string $index,string $definition): void {
  if(!bo_index_exists($table,$index)) bo_db()->exec("ALTER TABLE {$table} ADD {$definition}");
}

function bo_ensure_schema(): void {
  static $done=false; if($done) return; $done=true;
  try {
    bo_migration_done('bootstrap');

    if(bo_table_exists('bo_users')){
      bo_add_column_if_missing('bo_users','must_change_password','TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
      bo_add_column_if_missing('bo_users','password_changed_at','DATETIME NULL AFTER last_login_at');
      bo_add_column_if_missing('bo_users','updated_at','DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
      bo_add_index_if_missing('bo_users','idx_bo_users_role','KEY idx_bo_users_role (role_key)');
      bo_add_index_if_missing('bo_users','idx_bo_users_email','KEY idx_bo_users_email (email)');
    }

    if(bo_table_exists('bo_system_connections')){
      try { bo_db()->exec('ALTER TABLE bo_system_connections MODIFY api_token TEXT NULL'); } catch(Throwable $e) {}
      bo_add_column_if_missing('bo_system_connections','api_token_hash','CHAR(64) NULL AFTER api_token');
      bo_add_column_if_missing('bo_system_connections','api_token_encrypted','LONGTEXT NULL AFTER api_token_hash');
      bo_add_column_if_missing('bo_system_connections','token_last_rotated_at','DATETIME NULL AFTER api_token_encrypted');
      bo_add_column_if_missing('bo_system_connections','last_sync_at','DATETIME NULL AFTER last_health_message');
      bo_add_column_if_missing('bo_system_connections','last_sync_status','VARCHAR(30) NULL AFTER last_sync_at');
      bo_add_column_if_missing('bo_system_connections','last_sync_message','TEXT NULL AFTER last_sync_status');
      if(function_exists('bo_encrypt_secret')){
        foreach(bo_exec("SELECT id,api_token FROM bo_system_connections WHERE api_token IS NOT NULL AND api_token<>'' AND (api_token_encrypted IS NULL OR api_token_encrypted='')")->fetchAll() as $legacy){
          $plain=(string)$legacy['api_token'];
          bo_exec('UPDATE bo_system_connections SET api_token=NULL,api_token_hash=?,api_token_encrypted=?,token_last_rotated_at=COALESCE(token_last_rotated_at,NOW()),updated_at=NOW() WHERE id=?',[hash('sha256',$plain),bo_encrypt_secret($plain),(int)$legacy['id']]);
        }
      }
    }

    if(bo_table_exists('bo_pairing_requests')){
      bo_add_column_if_missing('bo_pairing_requests','request_secret_hash','VARCHAR(255) NULL AFTER request_secret');
      bo_add_column_if_missing('bo_pairing_requests','access_token_hash','CHAR(64) NULL AFTER access_token');
      bo_add_column_if_missing('bo_pairing_requests','access_token_encrypted','LONGTEXT NULL AFTER access_token_hash');
    }

    bo_db()->exec("CREATE TABLE IF NOT EXISTS bo_employee_people (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      canonical_name VARCHAR(160) NOT NULL,
      email VARCHAR(190) NULL,
      email_norm VARCHAR(190) NULL,
      phone VARCHAR(80) NULL,
      identity_key VARCHAR(220) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NULL,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_bo_employee_identity (identity_key),
      KEY idx_bo_employee_email_norm (email_norm),
      KEY idx_bo_employee_name (canonical_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    bo_db()->exec("CREATE TABLE IF NOT EXISTS bo_employee_assignments (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      person_id BIGINT NOT NULL,
      source_system VARCHAR(50) NOT NULL,
      system_key VARCHAR(80) NOT NULL,
      system_name VARCHAR(160) NULL,
      external_employee_id VARCHAR(100) NOT NULL,
      username VARCHAR(120) NULL,
      role_key VARCHAR(80) NULL,
      role_label VARCHAR(140) NULL,
      location VARCHAR(160) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      activity_count INT NOT NULL DEFAULT 0,
      raw_json LONGTEXT NULL,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NULL,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_bo_employee_assignment (source_system,system_key,external_employee_id),
      KEY idx_bo_employee_assignment_person (person_id),
      KEY idx_bo_employee_assignment_source (source_system,system_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    bo_db()->exec("CREATE TABLE IF NOT EXISTS bo_backup_runs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      system_key VARCHAR(80) NOT NULL,
      dataset VARCHAR(80) NOT NULL,
      mode VARCHAR(30) NOT NULL DEFAULT 'incremental',
      status VARCHAR(30) NOT NULL,
      rows_received INT NOT NULL DEFAULT 0,
      rows_saved INT NOT NULL DEFAULT 0,
      message TEXT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL,
      KEY idx_bo_backup_runs_system (system_key,dataset,started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    bo_db()->exec("CREATE TABLE IF NOT EXISTS bo_backup_records (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      system_key VARCHAR(80) NOT NULL,
      dataset VARCHAR(80) NOT NULL,
      external_id VARCHAR(120) NOT NULL,
      external_updated_at DATETIME NULL,
      payload_json LONGTEXT NOT NULL,
      payload_hash CHAR(64) NOT NULL,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NULL,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_bo_backup_record (system_key,dataset,external_id),
      KEY idx_bo_backup_dataset (dataset,external_updated_at),
      KEY idx_bo_backup_system_dataset (system_key,dataset)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    bo_db()->exec("CREATE TABLE IF NOT EXISTS bo_api_test_runs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      system_key VARCHAR(80) NOT NULL,
      test_key VARCHAR(80) NOT NULL,
      endpoint VARCHAR(180) NOT NULL,
      status VARCHAR(30) NOT NULL,
      status_code INT NULL,
      message TEXT NULL,
      response_payload LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_bo_api_test_runs (system_key,test_key,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    bo_mark_migration('20260710_backoffice_sync_security_users');
  } catch(Throwable $e) {
    try { bo_exec('INSERT INTO bo_sync_logs(system_key,direction,endpoint,method,status,status_code,message,created_at) VALUES(?,?,?,?,?,?,?,NOW())',['backoffice','internal','schema','AUTO','failed',0,$e->getMessage()]); } catch(Throwable $ignored) {}
  }
}
