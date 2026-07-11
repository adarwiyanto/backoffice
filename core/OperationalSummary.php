<?php
require_once __DIR__.'/ApiClient.php';

function bo_ops_payload(array $res): array {
  foreach(['data','summary','payload','result'] as $key){ if(isset($res[$key])&&is_array($res[$key])) return $res[$key]; }
  return $res;
}
function bo_ops_num($v): float { return is_numeric($v)?(float)$v:0.0; }
function bo_ops_get(array $data,array $path,$default=null){ $cur=$data;foreach($path as $key){if(!is_array($cur)||!array_key_exists($key,$cur))return $default;$cur=$cur[$key];}return $cur; }
function bo_ops_first(array $data,array $paths,$default=0){foreach($paths as $path){$v=bo_ops_get($data,is_array($path)?$path:[$path],null);if($v!==null&&$v!=='')return $v;}return $default;}
function bo_ops_unit_name(array $conn,array $data): string {
  $name=(string)bo_ops_first($data,[['system','name'],['store','name'],'store_name','system_name','connection_label'],'');
  return trim($name)!==''?$name:(string)($conn['system_name']??$conn['system_key']??'Koneksi');
}
function bo_ops_fetch_summaries(string $type,string $month): array {
  $rows=[];
  foreach(bo_connections_by_type($type) as $conn){
    $res=bo_api_request_connection($conn,'api/backoffice/dashboard_summary.php',['month'=>$month]);
    $data=!empty($res['ok'])?bo_ops_payload($res):[];
    if($type==='adena' && !empty($res['ok'])){
      $hasEmployees=bo_ops_first($data,['employees_count','employee_count','active_employees'],null);
      if($hasEmployees===null){
        $er=bo_api_request_connection($conn,'api/backoffice/employees.php');
        if(!empty($er['ok'])&&is_array($er['data']??null)){
          $count=0;foreach($er['data'] as $emp){if(!is_array($emp))continue;$role=strtolower((string)($emp['role_key']??$emp['role']??''));if(in_array($role,['owner','superadmin'],true))continue;if(array_key_exists('is_active',$emp)&&(int)$emp['is_active']!==1)continue;$count++;}$data['employees_count']=$count;
        }
      }
    }
    $rows[]=['connection'=>$conn,'ok'=>!empty($res['ok']),'message'=>(string)($res['message']??''),'data'=>$data,'name'=>bo_ops_unit_name($conn,$data)];
  }
  return $rows;
}
function bo_ops_fetch_financials(string $type,string $month,bool $includeRows=true): array {
  $rows=[];
  foreach(bo_connections_by_type($type) as $conn){
    $res=bo_api_request_connection($conn,'api/backoffice/financial_summary.php',['month'=>$month,'include_rows'=>$includeRows?1:0]);
    $data=!empty($res['ok'])?bo_ops_payload($res):[];
    $rows[]=['connection'=>$conn,'ok'=>!empty($res['ok']),'message'=>(string)($res['message']??''),'data'=>$data,'name'=>bo_ops_unit_name($conn,$data)];
  }
  return $rows;
}
function bo_ops_sum(array $rows,callable $fn): float { $n=0.0;foreach($rows as $r){if($r['ok'])$n+=(float)$fn($r['data'],$r);}return $n; }
