<?php
function bo_company_setting(string $key,string $default=''): string {
 try{
  if(!function_exists('bo_table_exists') || !bo_table_exists('bo_settings')) return $default;
  $v=bo_exec('SELECT setting_value FROM bo_settings WHERE setting_key=? LIMIT 1',[$key])->fetchColumn();
  return $v===false||$v===null?$default:(string)$v;
 }catch(Throwable $e){return $default;}
}
function bo_company_set(string $key,string $value): void {
 bo_exec('INSERT INTO bo_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,$value]);
}
function bo_company_name(): string {return trim(bo_company_setting('company_name','Adena Group'))?:'Adena Group';}
function bo_company_logo_path(): ?string {
 $rel=trim(bo_company_setting('company_logo',''));
 if($rel==='') return null;
 $full=dirname(__DIR__).'/'.ltrim($rel,'/');
 return is_file($full)?$full:null;
}
function bo_company_logo_url(): string {
 $rel=trim(bo_company_setting('company_logo',''));
 return $rel!==''?bo_url($rel):'';
}
function bo_company_logo_data_uri(): string {
 $path=bo_company_logo_path();if(!$path)return '';
 $mime=(string)(mime_content_type($path)?:'image/png');$data=@file_get_contents($path);
 return $data===false?'':'data:'.$mime.';base64,'.base64_encode($data);
}
