<?php
require_once __DIR__ . '/../core/Database.php';
function col_exists_bo($table,$col){$st=bo_db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$st->execute([$table,$col]);return (int)$st->fetchColumn()>0;}
$cols=['system_type'=>'VARCHAR(50) NULL','access_scope'=>'VARCHAR(80) NULL','status'=>'VARCHAR(30) NULL','paired_at'=>'DATETIME NULL'];
foreach($cols as $c=>$def){ if(!col_exists_bo('bo_system_connections',$c)){ bo_db()->exec('ALTER TABLE bo_system_connections ADD COLUMN '.$c.' '.$def); }}
$sql=file_get_contents(__DIR__.'/backoffice_schema.sql');
foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if(stripos($q,'CREATE TABLE IF NOT EXISTS bo_pairing_requests')===0) bo_db()->exec($q); }
try { bo_db()->exec("UPDATE bo_pairing_requests SET requested_scope='admin_rw' WHERE requester_type='backoffice' AND requested_scope='superadmin'"); } catch(Throwable $e) {}
try { bo_db()->exec("UPDATE bo_system_connections SET access_scope='admin_rw' WHERE system_type IN ('adena','dapur') AND access_scope='superadmin'"); } catch(Throwable $e) {}
echo 'Migrasi pairing selesai.';
