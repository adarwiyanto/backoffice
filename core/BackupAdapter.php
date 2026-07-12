<?php
require_once __DIR__.'/Database.php';
require_once __DIR__.'/Helpers.php';
function bo_backup_get_setting($key, $default = null) {
    try {
        $st = bo_exec('SELECT setting_value FROM bo_settings WHERE setting_key=?', array($key));
        $value = $st->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) { return $default; }
}
function bo_backup_set_setting($key, $value) {
    bo_exec('INSERT INTO bo_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', array($key,$value));
}
function bo_backup_service() {
    static $service = null;
    if ($service !== null) return $service;
    if (!class_exists('GoogleDriveBackupService', false)) require_once __DIR__.'/BackupGoogle.php';
    $cfg = bo_config();
    $getter = function ($key, $default = null) { return bo_backup_get_setting($key, $default); };
    $setter = function ($key, $value) { bo_backup_set_setting($key, $value); };
    $service = new GoogleDriveBackupService(array(
        'pdo'=>bo_db(), 'db'=>$cfg['db'], 'app_key'=>'BACKOFFICE',
        'app_name'=>isset($cfg['app']['name']) ? $cfg['app']['name'] : 'Adena Back Office',
        'root_path'=>dirname(__DIR__), 'private_path'=>dirname(__DIR__).'/storage/private_backup',
        'jobs_table'=>'bo_cloud_backup_jobs',
        'timezone'=>isset($cfg['app']['timezone']) ? $cfg['app']['timezone'] : 'Asia/Jakarta',
        'get_setting'=>$getter, 'set_setting'=>$setter
    ));
    return $service;
}
