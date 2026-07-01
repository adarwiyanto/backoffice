<?php
require_once __DIR__.'/Database.php';
function bo_connection(string $key): ?array { $st=bo_exec('SELECT * FROM bo_system_connections WHERE system_key=? AND is_active=1 LIMIT 1',[$key]); $r=$st->fetch(); return $r ?: null; }
function bo_api_token_from_conn(array $conn): string { return (string)($conn['api_token'] ?? $conn['access_token'] ?? ''); }
function bo_api_request(string $systemKey, string $endpoint, array $query=[]): array {
  $conn=bo_connection($systemKey);
  if(!$conn) return ['ok'=>false,'message'=>'Koneksi '.$systemKey.' belum aktif','data'=>null,'status_code'=>0];
  $url=rtrim($conn['base_url'],'/').'/'.ltrim($endpoint,'/');
  if($query) $url.=(str_contains($url,'?')?'&':'?').http_build_query($query);
  $token=bo_api_token_from_conn($conn);
  $headers=['Accept: application/json']; if($token!=='') $headers[]='Authorization: Bearer '.$token;
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>18,CURLOPT_HTTPHEADER=>$headers]);
  $body=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($body===false) { bo_log_sync($systemKey,'out',$endpoint,'GET','failed',$code,'',$err); return ['ok'=>false,'message'=>$err,'data'=>null,'status_code'=>$code]; }
  $json=json_decode($body,true); if(!is_array($json)) $json=['ok'=>false,'message'=>'Response bukan JSON','raw'=>$body];
  bo_log_sync($systemKey,'out',$endpoint,'GET',($json['ok']??false)?'success':'failed',$code,'',$body);
  $json['status_code']=$code; return $json;
}
function bo_log_sync(string $system,string $direction,string $endpoint,string $method,string $status,int $code,string $request,string $response): void {
  try{ bo_exec('INSERT INTO bo_sync_logs(system_key,direction,endpoint,method,status,status_code,request_payload,response_payload,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())',[$system,$direction,$endpoint,$method,$status,$code,$request,$response]); }catch(Throwable $e){}
}
function bo_health_check(string $system): array {
  $res=bo_api_request($system,'api/backoffice/health.php');
  try{ bo_exec('UPDATE bo_system_connections SET last_health_check_at=NOW(), last_health_status=?, last_health_message=?, updated_at=NOW() WHERE system_key=?',[($res['ok']??false)?'ok':'failed',$res['message']??'', $system]); }catch(Throwable $e){}
  return $res;
}
function bo_remote_json(string $baseUrl,string $path,array $payload=[],string $method='POST',string $token=''): array {
  $url=rtrim(trim($baseUrl),'/').'/'.ltrim($path,'/');
  if($method==='GET' && $payload) $url.=(str_contains($url,'?')?'&':'?').http_build_query($payload);
  $headers=['Accept: application/json','Content-Type: application/json']; if($token!=='') $headers[]='Authorization: Bearer '.$token;
  $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>18,CURLOPT_HTTPHEADER=>$headers]);
  if($method!=='GET'){ curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method); curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
  $body=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  $json=is_string($body)?json_decode($body,true):null; if(!is_array($json)) $json=['ok'=>false,'message'=>$err?:'Respons bukan JSON','raw'=>(string)$body]; $json['_http_code']=$code; return $json;
}
