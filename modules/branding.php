<?php
$u=bo_user();
if(($u['role_key']??'')!=='owner'){
 echo '<div class="page-title"><div><h1>Branding Perusahaan</h1></div></div><div class="card"><div class="alert danger">Branding Perusahaan hanya dapat diakses owner.</div></div>';
 return;
}

$root=dirname(__DIR__);
require_once $root.'/core/Company.php';
if(session_status()!==PHP_SESSION_ACTIVE) bo_session_start();
if(empty($_SESSION['bo_branding_csrf'])) $_SESSION['bo_branding_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['bo_branding_csrf'];
$msg=(string)($_SESSION['bo_branding_flash']??'');
$err=(string)($_SESSION['bo_branding_flash_error']??'');
unset($_SESSION['bo_branding_flash'],$_SESSION['bo_branding_flash_error']);

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['company_branding_action'])){
 try{
  if(!hash_equals($csrf,(string)($_POST['branding_csrf']??''))) throw new RuntimeException('CSRF token tidak valid.');
  $action=(string)$_POST['company_branding_action'];
  $companyName=trim((string)($_POST['company_name']??''));
  if($companyName==='') $companyName='Adena Group';
  bo_company_set('company_name',$companyName);

  if($action==='remove_logo'){
   $dir=$root.'/storage/branding';
   foreach(glob($dir.'/company-logo.*')?:[] as $old) @unlink($old);
   bo_company_set('company_logo','');
   $_SESSION['bo_branding_flash']='Logo perusahaan berhasil dihapus.';
  } else {
   if(isset($_FILES['company_logo']) && is_array($_FILES['company_logo']) && ($_FILES['company_logo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
    if(($_FILES['company_logo']['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) throw new RuntimeException('Upload logo gagal.');
    $tmp=(string)$_FILES['company_logo']['tmp_name'];
    $mime=(string)(mime_content_type($tmp)?:'');
    $ext=match($mime){'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp',default=>''};
    if($ext==='') throw new RuntimeException('Logo harus PNG, JPG, atau WEBP.');
    if((int)($_FILES['company_logo']['size']??0)>2*1024*1024) throw new RuntimeException('Ukuran logo maksimal 2 MB.');
    $dir=$root.'/storage/branding';
    if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Folder branding tidak dapat dibuat.');
    foreach(glob($dir.'/company-logo.*')?:[] as $old) @unlink($old);
    $dest=$dir.'/company-logo.'.$ext;
    if(!move_uploaded_file($tmp,$dest)) throw new RuntimeException('Logo gagal disimpan.');
    bo_company_set('company_logo','storage/branding/company-logo.'.$ext);
   }
   $_SESSION['bo_branding_flash']='Branding perusahaan berhasil disimpan.';
  }
  header('Location: ?p=branding');
  exit;
 }catch(Throwable $e){
  $_SESSION['bo_branding_flash_error']=$e->getMessage();
  header('Location: ?p=branding');
  exit;
 }
}

$brandLogo=bo_company_logo_data_uri();
?>
<div class="page-title"><div><h1>Branding Perusahaan</h1><div class="muted">Atur identitas perusahaan yang digunakan pada Slip Gaji A4.</div></div></div>
<?php if($msg):?><div class="alert success"><?=e($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert danger"><?=e($err)?></div><?php endif;?>
<div class="card branding-card">
 <form method="post" enctype="multipart/form-data">
  <input type="hidden" name="branding_csrf" value="<?=e($csrf)?>">
  <input type="hidden" name="company_branding_action" value="save">
  <div class="grid-2 branding-grid">
   <div>
    <label>Nama perusahaan</label>
    <input name="company_name" value="<?=e(bo_company_name())?>" required>
    <div class="muted branding-help">Nama ini akan tampil pada dokumen Slip Gaji.</div>
   </div>
   <div>
    <label>Logo perusahaan</label>
    <input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp">
    <div class="muted branding-help">PNG, JPG, atau WEBP. Maksimal 2 MB.</div>
   </div>
  </div>
  <div class="branding-preview-wrap">
   <div class="branding-preview-title">Preview logo aktif</div>
   <div class="branding-preview">
    <?php if($brandLogo!==''): ?>
     <img src="<?=e($brandLogo)?>" alt="Logo perusahaan">
    <?php else: ?>
     <div class="branding-empty">Belum ada logo. Slip gaji akan menggunakan nama perusahaan tanpa ikon rusak.</div>
    <?php endif; ?>
   </div>
  </div>
  <div class="branding-actions">
   <button class="btn primary" type="submit">Simpan Branding</button>
  </div>
 </form>
 <?php if($brandLogo!==''): ?>
 <form method="post" class="branding-remove-form" data-confirm="Hapus logo perusahaan saat ini?">
  <input type="hidden" name="branding_csrf" value="<?=e($csrf)?>">
  <input type="hidden" name="company_name" value="<?=e(bo_company_name())?>">
  <input type="hidden" name="company_branding_action" value="remove_logo">
  <button class="btn" type="submit">Hapus Logo</button>
 </form>
 <?php endif; ?>
</div>
