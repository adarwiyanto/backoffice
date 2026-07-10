<?php
require_once __DIR__.'/ApiClient.php';

function bo_norm_email(?string $email): string {
  return strtolower(trim((string)$email));
}

function bo_employee_identity_key(array $row,string $systemKey): string {
  $email=bo_norm_email($row['email'] ?? '');
  if($email!=='') return 'email:'.$email;
  $phone=preg_replace('/\D+/', '', (string)($row['phone'] ?? ''));
  if($phone!=='') return 'phone:'.$phone;
  return 'external:'.$systemKey.':'.(string)($row['employee_id'] ?? $row['id'] ?? $row['username'] ?? md5(json_encode($row)));
}

function bo_sync_employee_row(array $conn,array $row): void {
  $roleKey=strtolower(trim((string)($row['role_key'] ?? $row['role'] ?? '')));
  if($roleKey==='owner') return;
  $systemKey=(string)($conn['system_key'] ?? $conn['system_type'] ?? 'adena');
  $source=(string)($row['source'] ?? $conn['system_type'] ?? 'adena');
  $externalId=(string)($row['employee_id'] ?? $row['id'] ?? $row['username'] ?? '');
  if($externalId==='') $externalId=substr(hash('sha256',json_encode($row)),0,32);
  $name=trim((string)($row['name'] ?? $row['username'] ?? 'Pegawai'));
  $email=trim((string)($row['email'] ?? ''));
  $emailNorm=bo_norm_email($email);
  $phone=trim((string)($row['phone'] ?? ''));
  $identity=bo_employee_identity_key($row,$systemKey);
  $active=isset($row['is_active']) ? ((int)$row['is_active'] ? 1 : 0) : 1;

  bo_exec("INSERT INTO bo_employee_people(canonical_name,email,email_norm,phone,identity_key,is_active,first_seen_at,last_seen_at)
    VALUES(?,?,?,?,?,?,NOW(),NOW())
    ON DUPLICATE KEY UPDATE
      canonical_name=IF(VALUES(canonical_name)<>'',VALUES(canonical_name),canonical_name),
      email=IF(VALUES(email)<>'',VALUES(email),email),
      email_norm=IF(VALUES(email_norm)<>'',VALUES(email_norm),email_norm),
      phone=IF(VALUES(phone)<>'',VALUES(phone),phone),
      is_active=VALUES(is_active),
      last_seen_at=NOW(),
      updated_at=NOW()",[$name,$email,$emailNorm,$phone,$identity,$active]);
  $person=bo_exec('SELECT id FROM bo_employee_people WHERE identity_key=? LIMIT 1',[$identity])->fetch();
  if(!$person) return;

  bo_exec("INSERT INTO bo_employee_assignments(person_id,source_system,system_key,system_name,external_employee_id,username,role_key,role_label,location,is_active,activity_count,raw_json,first_seen_at,last_seen_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
    ON DUPLICATE KEY UPDATE
      person_id=VALUES(person_id),
      system_name=VALUES(system_name),
      username=VALUES(username),
      role_key=VALUES(role_key),
      role_label=VALUES(role_label),
      location=VALUES(location),
      is_active=VALUES(is_active),
      activity_count=VALUES(activity_count),
      raw_json=VALUES(raw_json),
      last_seen_at=NOW(),
      updated_at=NOW()",[
        (int)$person['id'],$source,$systemKey,(string)($conn['system_name'] ?? $systemKey),$externalId,
        (string)($row['username'] ?? ''),(string)($row['role_key'] ?? ''),(string)($row['role'] ?? ''),
        (string)($row['location'] ?? ''),$active,(int)($row['activity_count'] ?? 0),
        json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
      ]);
}

function bo_sync_employees(?array $onlyConn=null): array {
  bo_bootstrap_schema();
  $connections=$onlyConn ? [$onlyConn] : array_merge(bo_connections_by_type('adena'), bo_connections_by_type('dapur'));
  $summary=['ok'=>true,'systems'=>0,'received'=>0,'saved'=>0,'errors'=>[]];
  foreach($connections as $conn){
    $summary['systems']++;
    $key=(string)($conn['system_key'] ?? $conn['system_type'] ?? 'unknown');
    $res=bo_api_request_connection($conn,'api/backoffice/employees.php');
    if(empty($res['ok'])){
      $summary['ok']=false; $summary['errors'][]=$key.': '.($res['message'] ?? 'API pegawai gagal');
      continue;
    }
    $rows=$res['data'] ?? [];
    if(!is_array($rows)) $rows=[];
    $summary['received']+=count($rows);
    foreach($rows as $row){
      if(is_array($row)){
        $roleKey=strtolower(trim((string)($row['role_key'] ?? $row['role'] ?? '')));
        if($roleKey==='owner') continue;
        bo_sync_employee_row($conn,$row); $summary['saved']++;
      }
    }
    try { bo_exec('UPDATE bo_system_connections SET last_sync_at=NOW(),last_sync_status=?,last_sync_message=? WHERE system_key=?',['ok','Pegawai: '.count($rows).' row',$key]); } catch(Throwable $e) {}
  }
  return $summary;
}

function bo_employee_rows(string $source='all',string $status='active'): array {
  bo_bootstrap_schema();
  $conditions=["LOWER(TRIM(COALESCE(a.role_key,'')))<>'owner'"]; $params=[];
  if($source!=='all'){ $conditions[]='a.source_system=?'; $params[]=$source; }
  if($status==='active') $conditions[]='p.manually_disabled=0';
  elseif($status==='inactive') $conditions[]='p.manually_disabled=1';
  $where='WHERE '.implode(' AND ',$conditions);
  $sql="SELECT p.*, GROUP_CONCAT(DISTINCT a.source_system ORDER BY a.source_system SEPARATOR ', ') sources,
      GROUP_CONCAT(DISTINCT CONCAT(COALESCE(NULLIF(a.role_label,''),a.role_key,'Pegawai'),' - ',COALESCE(NULLIF(a.system_name,''),a.system_key)) ORDER BY a.system_name SEPARATOR ' | ') roles_locations,
      GROUP_CONCAT(DISTINCT COALESCE(NULLIF(a.location,''),a.system_name,a.system_key) ORDER BY a.system_name SEPARATOR ', ') locations,
      MAX(a.last_seen_at) assignment_seen_at,
      SUM(CASE WHEN p.manually_disabled=0 THEN a.activity_count ELSE 0 END) activity_count,
      CASE WHEN p.manually_disabled=1 THEN 0 ELSE MAX(a.is_active) END assignment_active
    FROM bo_employee_people p
    JOIN bo_employee_assignments a ON a.person_id=p.id
    {$where}
    GROUP BY p.id
    ORDER BY p.canonical_name ASC";
  return bo_exec($sql,$params)->fetchAll() ?: [];
}

function bo_backup_dataset_endpoint(string $dataset): string {
  return 'api/backoffice/export.php?dataset='.rawurlencode($dataset);
}

function bo_backup_dataset(array $conn,string $dataset,string $mode='incremental'): array {
  bo_bootstrap_schema();
  $key=(string)($conn['system_key'] ?? $conn['system_type'] ?? 'unknown');
  bo_exec('INSERT INTO bo_backup_runs(system_key,dataset,mode,status,started_at) VALUES(?,?,?,?,NOW())',[$key,$dataset,$mode,'running']);
  $runId=(int)bo_db()->lastInsertId();
  $res=bo_api_request_connection($conn,bo_backup_dataset_endpoint($dataset),['mode'=>$mode]);
  $saved=0; $rows=[];
  if(!empty($res['ok']) && is_array($res['data'] ?? null)) $rows=$res['data'];
  if(!empty($res['ok'])){
    foreach($rows as $row){
      if(!is_array($row)) continue;
      $externalId=(string)($row['id'] ?? $row['external_id'] ?? substr(hash('sha256',json_encode($row)),0,32));
      $payload=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $hash=hash('sha256',$payload);
      $updated=trim((string)($row['updated_at'] ?? $row['created_at'] ?? ''));
      $updatedAt=$updated!=='' ? date('Y-m-d H:i:s',strtotime($updated)) : null;
      bo_exec("INSERT INTO bo_backup_records(system_key,dataset,external_id,external_updated_at,payload_json,payload_hash,first_seen_at,last_seen_at)
        VALUES(?,?,?,?,?,?,NOW(),NOW())
        ON DUPLICATE KEY UPDATE external_updated_at=VALUES(external_updated_at),payload_json=VALUES(payload_json),payload_hash=VALUES(payload_hash),last_seen_at=NOW(),updated_at=NOW()",[$key,$dataset,$externalId,$updatedAt,$payload,$hash]);
      $saved++;
    }
  }
  $status=!empty($res['ok'])?'success':'failed';
  $message=(string)($res['message'] ?? ($status==='success'?'OK':'API backup gagal'));
  bo_exec('UPDATE bo_backup_runs SET status=?,rows_received=?,rows_saved=?,message=?,finished_at=NOW() WHERE id=?',[$status,count($rows),$saved,$message,$runId]);
  try { bo_exec('UPDATE bo_system_connections SET last_sync_at=NOW(),last_sync_status=?,last_sync_message=? WHERE system_key=?',[$status,$dataset.': '.$message,$key]); } catch(Throwable $e) {}
  return ['ok'=>$status==='success','dataset'=>$dataset,'received'=>count($rows),'saved'=>$saved,'message'=>$message,'status_code'=>$res['status_code'] ?? 0];
}

function bo_backup_all(array $datasets=['employees','products','sales','sale_payments','stock_ledger','stock_transfers','stock_transfer_items']): array {
  $out=['ok'=>true,'results'=>[],'errors'=>[]];
  foreach(bo_connections_by_type('adena') as $conn){
    foreach($datasets as $dataset){
      if($dataset==='employees'){ $r=bo_sync_employees($conn); $ok=!empty($r['ok']); $item=['dataset'=>'employees','saved'=>$r['saved'] ?? 0,'message'=>$ok?'OK':implode('; ',$r['errors'] ?? [])]; }
      else { $item=bo_backup_dataset($conn,$dataset); $ok=!empty($item['ok']); }
      $item['system_key']=$conn['system_key'] ?? 'adena';
      $out['results'][]=$item;
      if(!$ok){ $out['ok']=false; $out['errors'][]=($item['system_key'] ?? '').' '.$dataset.': '.($item['message'] ?? 'gagal'); }
    }
  }
  return $out;
}

function bo_run_api_test(array $conn,string $testKey): array {
  $map=[
    'health'=>'api/backoffice/health.php',
    'auth'=>'api/backoffice/health.php?dry_run=1',
    'employees'=>'api/backoffice/employees.php?dry_run=1',
    'products'=>'api/backoffice/export.php?dataset=products&dry_run=1&limit=1',
    'sales'=>'api/backoffice/export.php?dataset=sales&dry_run=1&limit=1',
    'stock'=>'api/backoffice/export.php?dataset=stock_ledger&dry_run=1&limit=1',
    'transfer'=>'api/backoffice/export.php?dataset=stock_transfers&dry_run=1&limit=1',
    'transaction'=>'api/backoffice/test_transaction.php?dry_run=1',
  ];
  $endpoint=$map[$testKey] ?? $map['health'];
  $res=bo_api_request_connection($conn,$endpoint);
  $key=(string)($conn['system_key'] ?? 'unknown');
  $ok=!empty($res['ok']);
  bo_exec('INSERT INTO bo_api_test_runs(system_key,test_key,endpoint,status,status_code,message,response_payload,created_at) VALUES(?,?,?,?,?,?,?,NOW())',[
    $key,$testKey,$endpoint,$ok?'success':'failed',(int)($res['status_code'] ?? 0),(string)($res['message'] ?? ($ok?'OK':'Gagal')),
    bo_redact_secret(json_encode($res,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
  ]);
  return ['ok'=>$ok,'test_key'=>$testKey,'endpoint'=>$endpoint,'status_code'=>$res['status_code'] ?? 0,'message'=>$res['message'] ?? ($ok?'OK':'Gagal')];
}
