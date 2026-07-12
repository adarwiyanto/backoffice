<?php
$u=bo_user(); if(($u['role_key']??'')!=='owner'){ echo '<div class="page-title"><div><h1>Pengaturan</h1></div></div><div class="card"><div class="alert danger">Setting Backup Google Drive hanya dapat diakses owner.</div></div>'; return; }
$backupRoot=dirname(__DIR__);require_once $backupRoot.'/core/backup_safe.php';backup_safe_register($backupRoot,'BACKOFFICE backup settings','html');
$svc=null;$loadError='';$msg=(string)($_SESSION['bo_backup_flash']??'');$err=(string)($_SESSION['bo_backup_flash_error']??'');unset($_SESSION['bo_backup_flash'],$_SESSION['bo_backup_flash_error']);
if(session_status()!==PHP_SESSION_ACTIVE)bo_session_start();if(empty($_SESSION['bo_backup_csrf']))$_SESSION['bo_backup_csrf']=bin2hex(random_bytes(24));$csrf=(string)$_SESSION['bo_backup_csrf'];
try{require_once $backupRoot.'/core/BackupAdapter.php';require_once __DIR__.'/backup_ui.php';$svc=bo_backup_service();}
catch(Throwable $e){$loadError=backup_safe_capture($backupRoot,'BACKOFFICE service bootstrap',$e);}
$relativeCallback=bo_url('backup_google_callback.php');$scheme=((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https')?'https':'http';$callback=preg_match('~^https?://~i',$relativeCallback)?$relativeCallback:$scheme.'://'.($_SERVER['HTTP_HOST']??'localhost').'/'.ltrim($relativeCallback,'/');
if($_SERVER['REQUEST_METHOD']==='POST'&&$svc){
 try{if(!hash_equals($csrf,(string)($_POST['backup_csrf']??'')))throw new RuntimeException('CSRF token tidak valid.');$a=(string)($_POST['backup_action']??'');
  if($a==='repair'){$svc->repairInfrastructure();$msg='Struktur backup berhasil diperiksa dan diperbaiki.';}
  elseif($a==='save_config'){$svc->saveConfiguration($_POST);$msg='Konfigurasi backup berhasil disimpan.';}
  elseif($a==='connect'){$state=bin2hex(random_bytes(24));$_SESSION['backup_oauth_state']=$state;header('Location: '.$svc->authorizationUrl($callback,$state));backup_safe_finish();exit;}
  elseif($a==='test'){$r=$svc->testConnection();$msg='Koneksi berhasil ke '.($r['email']??'Google Drive').'.';}
  elseif($a==='disconnect'){$svc->disconnect();$msg='Koneksi Google Drive diputus.';}
  elseif($a==='download_key'){$site=preg_replace('/[^A-Za-z0-9_-]+/','-',(string)$svc->get('site_code',$svc->appKey()));while(ob_get_level()>0)@ob_end_clean();header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="backup-recovery-key-'.$site.'.txt"');echo $svc->recoveryKeyText();backup_safe_finish();exit;}
  elseif($a==='run'){$r=$svc->runBackup((string)($_POST['backup_type']??'daily'),'owner');$msg='Backup berhasil: '.$r['filename'];}
 }catch(Throwable $e){$err=backup_safe_capture($backupRoot,'BACKOFFICE backup action',$e);}
}
?><div class="page-title"><div><h1>Setting Backup Google Drive</h1><div class="muted">Backup otomatis terenkripsi untuk Back Office.</div></div></div><?php if($msg):?><div class="alert success"><?=e($msg)?></div><?php endif;?><?php if($err):?><div class="alert danger"><?=e($err)?></div><?php endif;?><?php
if($svc){$cronCommand=backup_build_cron_command($svc,dirname(__DIR__).'/cron_backup.php');$relativeCron=bo_url('cron_backup.php?key='.rawurlencode((string)$svc->get('cron_secret','')));$cronUrl=preg_match('~^https?://~i',$relativeCron)?$relativeCron:$scheme.'://'.($_SERVER['HTTP_HOST']??'localhost').'/'.ltrim($relativeCron,'/');$connectUrl=bo_url('backup_google_connect.php?token='.rawurlencode($csrf));backup_render_settings($svc,$callback,$cronCommand,$cronUrl,'<input type="hidden" name="backup_csrf" value="'.e($csrf).'">','?p=settings',$connectUrl);}
else{backup_safe_render_error($loadError,$backupRoot);}backup_safe_finish(); ?>
