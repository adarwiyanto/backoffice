<?php
require_once __DIR__.'/Database.php'; require_once __DIR__.'/Helpers.php'; require_once __DIR__.'/BackupGoogle.php';
function bo_backup_get_setting(string $key,$default=null){ try{$st=bo_exec('SELECT setting_value FROM bo_settings WHERE setting_key=?',[$key]);$v=$st->fetchColumn();return $v===false?$default:$v;}catch(Throwable $e){return $default;} }
function bo_backup_set_setting(string $key,string $value): void { bo_exec('INSERT INTO bo_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,$value]); }
function bo_backup_service(): GoogleDriveBackupService {
 static $s=null; if($s) return $s; bo_db()->exec('CREATE TABLE IF NOT EXISTS bo_settings(setting_key VARCHAR(120) PRIMARY KEY,setting_value MEDIUMTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); $cfg=bo_config();
 return $s=new GoogleDriveBackupService(['pdo'=>bo_db(),'db'=>$cfg['db'],'app_key'=>'BACKOFFICE','app_name'=>$cfg['app']['name']??'Adena Back Office','root_path'=>dirname(__DIR__),'private_path'=>dirname(__DIR__).'/storage/private_backup','jobs_table'=>'bo_cloud_backup_jobs','timezone'=>$cfg['app']['timezone']??'Asia/Jakarta','get_setting'=>fn($k,$d=null)=>bo_backup_get_setting($k,$d),'set_setting'=>fn($k,$v)=>bo_backup_set_setting($k,$v)]);
}
