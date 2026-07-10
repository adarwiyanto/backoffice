<?php
bo_bootstrap_schema();
$me=bo_user(); $role=$me['role_key'] ?? 'viewer';
if(!in_array($role,['owner','admin'],true)){ echo '<div class="alert danger">Akses ditolak.</div>'; return; }
$msg=''; $err='';
function bo_can_manage_target_role(string $actor,string $target): bool {
  if($actor==='owner') return in_array($target,['owner','admin'],true);
  return $target==='admin';
}
function bo_audit(string $action,string $targetType,string $targetId,string $desc,array $payload=[]): void {
  try { bo_exec('INSERT INTO bo_audit_logs(user_id,action,target_type,target_id,description,payload_json,created_at) VALUES(?,?,?,?,?,?,NOW())',[(int)(bo_user()['id']??0),$action,$targetType,$targetId,$desc,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); } catch(Throwable $e) {}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  $act=$_POST['action'] ?? '';
  try {
    if($act==='create'){
      $username=trim((string)($_POST['username'] ?? ''));
      $name=trim((string)($_POST['name'] ?? ''));
      $email=trim((string)($_POST['email'] ?? ''));
      $newRole=(string)($_POST['role_key'] ?? 'admin');
      $pass=(string)($_POST['password'] ?? '');
      if($username==='' || $name==='' || strlen($pass)<8) throw new RuntimeException('Username, nama, dan password minimal 8 karakter wajib diisi.');
      if(!bo_can_manage_target_role($role,$newRole)) throw new RuntimeException('Role tidak diizinkan.');
      bo_exec('INSERT INTO bo_users(username,name,email,password_hash,role_key,is_active,must_change_password,created_at) VALUES(?,?,?,?,?,1,1,NOW())',[$username,$name,$email,password_hash($pass,PASSWORD_DEFAULT),$newRole]);
      bo_audit('bo_user_create','bo_user',$username,'User BO dibuat manual',['role'=>$newRole]);
      $msg='User berhasil dibuat. Password awal hanya tampil saat dibuat dan user wajib mengganti saat login pertama.';
    }
    if($act==='reset'){
      $id=(int)($_POST['id'] ?? 0); $pass=(string)($_POST['password'] ?? '');
      $target=bo_exec('SELECT * FROM bo_users WHERE id=? LIMIT 1',[$id])->fetch();
      if(!$target) throw new RuntimeException('User tidak ditemukan.');
      if(strlen($pass)<8) throw new RuntimeException('Password minimal 8 karakter.');
      if(!bo_can_manage_target_role($role,(string)$target['role_key'])) throw new RuntimeException('Tidak boleh reset role ini.');
      bo_exec('UPDATE bo_users SET password_hash=?,must_change_password=1,updated_at=NOW() WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$id]);
      try { bo_exec('DELETE FROM bo_remember_tokens WHERE user_id=?',[$id]); } catch(Throwable $e) {}
      bo_audit('bo_user_reset_password','bo_user',(string)$id,'Password BO direset');
      $msg='Password awal baru disimpan. User wajib mengganti saat login berikutnya.';
    }
    if($act==='toggle'){
      $id=(int)($_POST['id'] ?? 0); $target=bo_exec('SELECT * FROM bo_users WHERE id=? LIMIT 1',[$id])->fetch();
      if(!$target) throw new RuntimeException('User tidak ditemukan.');
      if((int)$target['id']===(int)$me['id']) throw new RuntimeException('Tidak bisa menonaktifkan akun sendiri.');
      if(!bo_can_manage_target_role($role,(string)$target['role_key'])) throw new RuntimeException('Tidak boleh mengubah role ini.');
      $active=(int)$target['is_active'] ? 0 : 1;
      bo_exec('UPDATE bo_users SET is_active=?,updated_at=NOW() WHERE id=?',[$active,$id]);
      bo_audit('bo_user_toggle','bo_user',(string)$id,$active?'User BO diaktifkan':'User BO dinonaktifkan');
      $msg=$active?'User diaktifkan.':'User dinonaktifkan.';
    }
  } catch(Throwable $e){ $err=$e->getMessage(); }
}
$users=bo_exec('SELECT * FROM bo_users ORDER BY FIELD(role_key,"owner","admin"), name')->fetchAll();
?>
<div class="page-title"><div><h1>Admin User</h1><div class="muted">Tambah user BackOffice manual tanpa email invite.</div></div></div>
<?php if($msg): ?><div class="alert"><?=e($msg)?></div><?php endif; ?>
<?php if($err): ?><div class="alert danger"><?=e($err)?></div><?php endif; ?>
<div class="grid-2">
  <div class="card"><h3>Tambah User</h3><form method="post"><input type="hidden" name="action" value="create">
    <label>Nama</label><input name="name" required>
    <label>Username</label><input name="username" required>
    <label>Email</label><input name="email" type="email">
    <label>Role</label><select name="role_key"><?php if($role==='owner'): ?><option value="owner">Owner</option><?php endif; ?><option value="admin">Admin</option></select>
    <label>Password Awal</label><input name="password" type="password" autocomplete="new-password" required>
    <button class="btn primary" type="submit">Buat User</button>
  </form></div>
  <div class="card"><h3>Aturan</h3><p class="muted">Password awal tidak dikirim email. User wajib mengganti password pada login pertama. Password tersimpan sebagai hash.</p></div>
</div>
<div class="section table-wrap"><table><thead><tr><th>Nama</th><th>Username/Email</th><th>Role</th><th>Status</th><th>Password</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($users as $u): ?><tr>
<td><b><?=e($u['name'])?></b><br><small>ID: <?=e($u['id'])?></small></td>
<td><?=e($u['username'])?><br><small><?=e($u['email'] ?? '')?></small></td>
<td><span class="badge"><?=e($u['role_key'])?></span></td>
<td><span class="badge <?=(int)$u['is_active']?'ok':'danger'?>"><?=(int)$u['is_active']?'Aktif':'Nonaktif'?></span></td>
<td><?=!empty($u['must_change_password'])?'<span class="badge warn">Wajib ganti</span>':'<span class="badge ok">Mandiri</span>'?></td>
<td><form method="post" class="filters" style="margin:0"><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?=e($u['id'])?>"><input name="password" type="password" placeholder="Password awal baru" style="max-width:180px"><button class="btn">Reset</button></form>
<form method="post" style="margin-top:6px"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($u['id'])?>"><button class="btn"><?=(int)$u['is_active']?'Nonaktifkan':'Aktifkan'?></button></form></td>
</tr><?php endforeach; ?></tbody></table></div>
