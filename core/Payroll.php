<?php
require_once __DIR__.'/OperationalSummary.php';

function bo_payroll_schema_ready(): bool {
  return function_exists('bo_table_exists')
    && bo_table_exists('bo_payroll_unit_settings')
    && bo_table_exists('bo_payroll_employee_settings')
    && bo_table_exists('bo_payroll_admin_kpi')
    && bo_table_exists('bo_payroll_runs')
    && bo_table_exists('bo_payroll_items');
}

function bo_payroll_unit_key(string $type,string $systemKey): string { return strtolower(trim($type)).':'.trim($systemKey); }

function bo_payroll_money($v): float { return round(max(0,(float)$v),2); }

function bo_payroll_upsert_default_units(): void {
  if(!bo_payroll_schema_ready()) return;
  $units=[];
  foreach(bo_connections_by_type('adena') as $c){
    $units[]=['store',(string)($c['system_key']??''),(string)($c['system_name']??$c['system_key']??'Toko')];
  }
  foreach(bo_connections_by_type('dapur') as $c){
    $units[]=['dapur',(string)($c['system_key']??''),(string)($c['system_name']??$c['system_key']??'Dapur')];
  }
  $units[]=['backoffice','backoffice','Admin Back Office'];
  foreach($units as [$type,$key,$name]){
    if($key==='') continue;
    bo_exec("INSERT INTO bo_payroll_unit_settings(unit_type,system_key,unit_name,pool_percentage,is_active,created_at)
      VALUES(?,?,?,0,1,NOW()) ON DUPLICATE KEY UPDATE unit_name=VALUES(unit_name),updated_at=NOW()",[$type,$key,$name]);
  }
}

function bo_payroll_unit_settings(): array {
  bo_payroll_upsert_default_units();
  $rows=bo_exec("SELECT * FROM bo_payroll_unit_settings ORDER BY FIELD(unit_type,'store','dapur','backoffice'),unit_name")->fetchAll()?:[];
  $out=[];foreach($rows as $r)$out[bo_payroll_unit_key((string)$r['unit_type'],(string)$r['system_key'])]=$r;return $out;
}

function bo_payroll_employee_settings(): array {
  $rows=bo_exec('SELECT * FROM bo_payroll_employee_settings')->fetchAll()?:[];$out=[];
  foreach($rows as $r){
    if((int)($r['assignment_id']??0)>0)$out['assignment:'.(int)$r['assignment_id']]=$r;
    elseif((int)($r['bo_user_id']??0)>0)$out['admin:'.(int)$r['bo_user_id']]=$r;
  }
  return $out;
}

function bo_payroll_admin_kpi_map(string $month): array {
  $rows=bo_exec('SELECT * FROM bo_payroll_admin_kpi WHERE payroll_month=?',[$month])->fetchAll()?:[];$out=[];
  foreach($rows as $r)$out[(int)$r['bo_user_id']]=$r;return $out;
}

function bo_payroll_assignment_rows(): array {
  $sql="SELECT a.id assignment_id,a.person_id,a.source_system,a.system_key,a.system_name,a.external_employee_id,a.username,a.role_key,a.role_label,a.location,a.is_active assignment_active,
      p.canonical_name,p.email,p.phone,p.is_active person_active,p.manually_disabled
    FROM bo_employee_assignments a JOIN bo_employee_people p ON p.id=a.person_id
    WHERE a.is_active=1 AND p.is_active=1 AND p.manually_disabled=0
      AND LOWER(TRIM(COALESCE(a.role_key,''))) NOT IN ('owner','superadmin')
    ORDER BY a.source_system,a.system_name,p.canonical_name";
  return bo_exec($sql)->fetchAll()?:[];
}

function bo_payroll_admin_rows(): array {
  if(!bo_table_exists('bo_users')) return [];
  return bo_exec("SELECT id,name,username,email,role_key,is_active FROM bo_users WHERE is_active=1 AND LOWER(TRIM(role_key))='admin' ORDER BY name")->fetchAll()?:[];
}

function bo_payroll_fetch_kpi_maps(string $month): array {
  $maps=['store'=>[],'dapur'=>[],'errors'=>[]];
  foreach(bo_connections_by_type('adena') as $conn){
    $key=(string)($conn['system_key']??'');
    $res=bo_api_request_connection($conn,'api/backoffice/kpi_store.php',['month'=>$month]);
    if(empty($res['ok'])){$maps['errors'][]=(string)($conn['system_name']??$key).': '.($res['message']??'KPI toko gagal');continue;}
    $p=bo_ops_payload($res);
    foreach((array)($p['employees']??[]) as $r){if(!is_array($r))continue;$eid=(string)($r['employee_id']??'');if($eid!=='')$maps['store'][$key][$eid]=$r;}
  }
  foreach(bo_connections_by_type('dapur') as $conn){
    $key=(string)($conn['system_key']??'');
    $res=bo_api_request_connection($conn,'api/backoffice/kpi_dapur.php',['month'=>$month]);
    if(empty($res['ok'])){$maps['errors'][]=(string)($conn['system_name']??$key).': '.($res['message']??'KPI dapur gagal');continue;}
    $p=bo_ops_payload($res);
    foreach((array)($p['employees']??[]) as $r){if(!is_array($r))continue;$eid=(string)($r['employee_id']??'');if($eid!=='')$maps['dapur'][$key][$eid]=$r;}
  }
  return $maps;
}

function bo_payroll_financial_map(string $month): array {
  $out=['store'=>[],'dapur'=>[],'store_total'=>0.0,'dapur_external_total'=>0.0,'errors'=>[]];
  foreach(bo_ops_fetch_financials('adena',$month,false) as $u){
    $key=(string)($u['connection']['system_key']??'');
    if(!$u['ok']){$out['errors'][]=$u['name'].': '.$u['message'];continue;}
    $rev=bo_payroll_money($u['data']['sales_revenue']??0);$out['store'][$key]=['name'=>$u['name'],'revenue'=>$rev,'data'=>$u['data']];$out['store_total']+=$rev;
  }
  foreach(bo_ops_fetch_financials('dapur',$month,false) as $u){
    $key=(string)($u['connection']['system_key']??'');
    if(!$u['ok']){$out['errors'][]=$u['name'].': '.$u['message'];continue;}
    $external=bo_payroll_money($u['data']['sales_revenue']??0);
    $internal=bo_payroll_money($u['data']['internal_distribution_value']??0);
    $basis=$external+$internal;
    $out['dapur'][$key]=['name'=>$u['name'],'revenue'=>$basis,'external_revenue'=>$external,'internal_distribution'=>$internal,'data'=>$u['data']];
    $out['dapur_external_total']+=$external;
  }
  $out['backoffice_revenue']=$out['store_total']+$out['dapur_external_total'];
  return $out;
}

function bo_payroll_default_target(string $role): float {
  $role=strtolower(trim($role));
  $st=bo_exec("SELECT target_value FROM bo_payroll_kpi_targets WHERE unit_type='dapur' AND system_key='*' AND role_key=? LIMIT 1",[$role]);
  $v=$st->fetchColumn();return $v!==false?(float)$v:100.0;
}

function bo_payroll_target(string $unitType,string $systemKey,string $role): float {
  if($unitType!=='dapur') return 100.0;
  $role=strtolower(trim($role));
  $st=bo_exec("SELECT target_value FROM bo_payroll_kpi_targets WHERE unit_type='dapur' AND system_key=? AND role_key=? LIMIT 1",[$systemKey,$role]);
  $v=$st->fetchColumn();if($v!==false)return max(0.01,(float)$v);
  return max(0.01,bo_payroll_default_target($role));
}

function bo_payroll_compute(string $month): array {
  bo_payroll_upsert_default_units();
  $unitSettings=bo_payroll_unit_settings();$employeeSettings=bo_payroll_employee_settings();$adminKpis=bo_payroll_admin_kpi_map($month);
  $financial=bo_payroll_financial_map($month);$kpis=bo_payroll_fetch_kpi_maps($month);
  $items=[];$groups=[];
  foreach(bo_payroll_assignment_rows() as $a){
    $unitType=((string)$a['source_system']==='dapur')?'dapur':'store';$systemKey=(string)$a['system_key'];$unitKey=bo_payroll_unit_key($unitType,$systemKey);
    $us=$unitSettings[$unitKey]??['pool_percentage'=>0,'is_active'=>1,'unit_name'=>$a['system_name']];if(empty($us['is_active']))continue;
    $es=$employeeSettings['assignment:'.(int)$a['assignment_id']]??[];if(isset($es['is_eligible'])&&(int)$es['is_eligible']!==1)continue;
    $rev=(float)($financial[$unitType][$systemKey]['revenue']??0);$poolPct=(float)($us['pool_percentage']??0);$pool=$rev*$poolPct/100;
    $externalId=(string)$a['external_employee_id'];$kpiRaw=0.0;$target=100.0;$kpiScore=0.0;$kpiSource='Belum tersedia';
    if($unitType==='store'){
      $kr=$kpis['store'][$systemKey][$externalId]??null;
      if($kr){$kpiRaw=(float)($kr['final_score']??0);$target=100.0;$kpiScore=max(0,min(120,$kpiRaw));$kpiSource=(string)($kr['status']??'KPI Toko');}
    }else{
      $kr=$kpis['dapur'][$systemKey][$externalId]??null;$target=bo_payroll_target('dapur',$systemKey,(string)$a['role_key']);
      if($kr){$kpiRaw=(float)($kr['total_points']??0);$kpiScore=max(0,min(120,($kpiRaw/$target)*100));$kpiSource='Poin Dapur';}
    }
    $row=['source_type'=>'assignment','assignment_id'=>(int)$a['assignment_id'],'bo_user_id'=>null,'person_id'=>(int)$a['person_id'],'employee_name'=>(string)$a['canonical_name'],'role_key'=>(string)$a['role_key'],'unit_type'=>$unitType,'system_key'=>$systemKey,'unit_name'=>(string)($us['unit_name']??$a['system_name']),'revenue_base'=>$rev,'pool_percentage'=>$poolPct,'pool_amount'=>$pool,'kpi_raw'=>$kpiRaw,'kpi_target'=>$target,'kpi_score'=>$kpiScore,'kpi_source'=>$kpiSource,'base_salary'=>bo_payroll_money($es['base_salary']??0),'allowance'=>bo_payroll_money($es['allowance']??0),'overtime'=>bo_payroll_money($es['overtime']??0),'deduction'=>bo_payroll_money($es['deduction']??0),'adjustment'=>(float)($es['adjustment']??0),'notes'=>(string)($es['notes']??'')];
    $idx=count($items);$items[]=$row;$groups[$unitKey][]=$idx;
  }
  $adminSetting=$unitSettings[bo_payroll_unit_key('backoffice','backoffice')]??['pool_percentage'=>0,'is_active'=>1,'unit_name'=>'Admin Back Office'];
  if(!empty($adminSetting['is_active']))foreach(bo_payroll_admin_rows() as $a){
    $es=$employeeSettings['admin:'.(int)$a['id']]??[];if(isset($es['is_eligible'])&&(int)$es['is_eligible']!==1)continue;
    $k=$adminKpis[(int)$a['id']]??[];$score=max(0,min(120,(float)($k['score']??0)));$rev=(float)$financial['backoffice_revenue'];$pct=(float)($adminSetting['pool_percentage']??0);
    $row=['source_type'=>'backoffice_user','assignment_id'=>null,'bo_user_id'=>(int)$a['id'],'person_id'=>null,'employee_name'=>(string)$a['name'],'role_key'=>(string)$a['role_key'],'unit_type'=>'backoffice','system_key'=>'backoffice','unit_name'=>(string)($adminSetting['unit_name']??'Admin Back Office'),'revenue_base'=>$rev,'pool_percentage'=>$pct,'pool_amount'=>$rev*$pct/100,'kpi_raw'=>$score,'kpi_target'=>100.0,'kpi_score'=>$score,'kpi_source'=>'KPI Admin Manual','base_salary'=>bo_payroll_money($es['base_salary']??0),'allowance'=>bo_payroll_money($es['allowance']??0),'overtime'=>bo_payroll_money($es['overtime']??0),'deduction'=>bo_payroll_money($es['deduction']??0),'adjustment'=>(float)($es['adjustment']??0),'notes'=>(string)($es['notes']??'')];
    $idx=count($items);$items[]=$row;$groups['backoffice:backoffice'][]=$idx;
  }
  foreach($groups as $unitKey=>$indexes){
    $totalWeight=0.0;foreach($indexes as $i)$totalWeight+=max(0,(float)$items[$i]['kpi_score']);
    foreach($indexes as $i){$weight=max(0,(float)$items[$i]['kpi_score']);$bonus=$totalWeight>0?((float)$items[$i]['pool_amount']*$weight/$totalWeight):0.0;$items[$i]['kpi_weight']=$totalWeight>0?$weight/$totalWeight:0.0;$items[$i]['incentive']=round($bonus,2);$items[$i]['take_home']=round($items[$i]['base_salary']+$items[$i]['allowance']+$items[$i]['overtime']+$items[$i]['adjustment']+$items[$i]['incentive']-$items[$i]['deduction'],2);}
  }
  $tot=['base_salary'=>0.0,'incentive'=>0.0,'allowance'=>0.0,'overtime'=>0.0,'deduction'=>0.0,'adjustment'=>0.0,'take_home'=>0.0];
  foreach($items as $it)foreach(array_keys($tot) as $k)$tot[$k]+=(float)($it[$k]??0);
  return ['month'=>$month,'items'=>$items,'totals'=>$tot,'financial'=>$financial,'kpi_errors'=>$kpis['errors'],'financial_errors'=>$financial['errors']];
}

function bo_payroll_save_run(string $month,int $userId,bool $finalize=false): int {
  $calc=bo_payroll_compute($month);
  if($finalize && (!empty($calc['financial_errors']) || !empty($calc['kpi_errors']))) throw new RuntimeException('Finalisasi dibatalkan karena masih ada sumber omset/KPI yang gagal dibaca. Perbaiki koneksi lalu hitung ulang.');
  if($finalize && empty($calc['items'])) throw new RuntimeException('Finalisasi dibatalkan karena belum ada pegawai eligible dalam payroll.');
  $existing=bo_exec('SELECT * FROM bo_payroll_runs WHERE payroll_month=? LIMIT 1',[$month])->fetch();
  if($existing && in_array((string)$existing['status'],['final','paid'],true)) throw new RuntimeException('Payroll bulan ini sudah dikunci.');
  bo_db()->beginTransaction();
  try{
    if($existing){$runId=(int)$existing['id'];bo_exec("UPDATE bo_payroll_runs SET status='draft',total_base_salary=?,total_incentive=?,total_allowance=?,total_overtime=?,total_deduction=?,total_adjustment=?,total_take_home=?,snapshot_json=?,updated_by=?,updated_at=NOW() WHERE id=?",[$calc['totals']['base_salary'],$calc['totals']['incentive'],$calc['totals']['allowance'],$calc['totals']['overtime'],$calc['totals']['deduction'],$calc['totals']['adjustment'],$calc['totals']['take_home'],json_encode(['financial'=>$calc['financial'],'kpi_errors'=>$calc['kpi_errors'],'financial_errors'=>$calc['financial_errors']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId,$runId]);bo_exec('DELETE FROM bo_payroll_items WHERE run_id=?',[$runId]);}
    else{bo_exec("INSERT INTO bo_payroll_runs(payroll_month,status,total_base_salary,total_incentive,total_allowance,total_overtime,total_deduction,total_adjustment,total_take_home,snapshot_json,created_by,updated_by,created_at) VALUES(?,'draft',?,?,?,?,?,?,?,?,?,?,NOW())",[$month,$calc['totals']['base_salary'],$calc['totals']['incentive'],$calc['totals']['allowance'],$calc['totals']['overtime'],$calc['totals']['deduction'],$calc['totals']['adjustment'],$calc['totals']['take_home'],json_encode(['financial'=>$calc['financial'],'kpi_errors'=>$calc['kpi_errors'],'financial_errors'=>$calc['financial_errors']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId,$userId]);$runId=(int)bo_db()->lastInsertId();}
    foreach($calc['items'] as $it){
      bo_exec("INSERT INTO bo_payroll_items(run_id,source_type,assignment_id,bo_user_id,person_id,employee_name_snapshot,role_key_snapshot,unit_type,system_key,unit_name_snapshot,revenue_base,pool_percentage,pool_amount,kpi_raw,kpi_target,kpi_score,kpi_weight,kpi_source,incentive_amount,base_salary,allowance,overtime,deduction,adjustment,take_home,notes,snapshot_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",[
        $runId,$it['source_type'],$it['assignment_id'],$it['bo_user_id'],$it['person_id'],$it['employee_name'],$it['role_key'],$it['unit_type'],$it['system_key'],$it['unit_name'],$it['revenue_base'],$it['pool_percentage'],$it['pool_amount'],$it['kpi_raw'],$it['kpi_target'],$it['kpi_score'],$it['kpi_weight'],$it['kpi_source'],$it['incentive'],$it['base_salary'],$it['allowance'],$it['overtime'],$it['deduction'],$it['adjustment'],$it['take_home'],$it['notes'],json_encode($it,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
      ]);
    }
    if($finalize)bo_exec("UPDATE bo_payroll_runs SET status='final',finalized_by=?,finalized_at=NOW(),updated_by=?,updated_at=NOW() WHERE id=?",[$userId,$userId,$runId]);
    bo_db()->commit();return $runId;
  }catch(Throwable $e){if(bo_db()->inTransaction())bo_db()->rollBack();throw $e;}
}

function bo_payroll_run(string $month): ?array {
  $r=bo_exec('SELECT * FROM bo_payroll_runs WHERE payroll_month=? LIMIT 1',[$month])->fetch();if(!$r)return null;
  $r['items']=bo_exec("SELECT * FROM bo_payroll_items WHERE run_id=? ORDER BY FIELD(unit_type,'store','dapur','backoffice'),unit_name_snapshot,employee_name_snapshot",[(int)$r['id']])->fetchAll()?:[];return $r;
}
