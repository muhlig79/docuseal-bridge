<?php
/**
 * Plugin Name: Elementor DocuSeal Bridge
 * Description: Send Elementor Pro, Contact Form 7 and Gravity Forms submissions to a configurable DocuSeal instance.
 * Version: 0.8.0-beta.1
 * Requires PHP: 7.4
 * Text Domain: elementor-docuseal-bridge
 */
if (!defined('ABSPATH')) { exit; }
final class EDB_Bridge {
 const OPT='edb_settings';
 public static function init() {
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('admin_init',[__CLASS__,'ensure_sequence_table']);
  add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
  add_action('wp_ajax_edb_catalog',[__CLASS__,'catalog']);
  add_action('wp_ajax_edb_template',[__CLASS__,'template']);
  add_action('admin_post_edb_save',[__CLASS__,'save']);
  add_action('admin_post_edb_test',[__CLASS__,'test']);
  add_action('elementor_pro/forms/validation',[__CLASS__,'validate'],10,2);
  add_action('elementor_pro/forms/new_record',[__CLASS__,'submit'],10,2);
  add_action('wpcf7_before_send_mail',[__CLASS__,'cf7_submit'],10,3);
  add_action('gform_after_submission',[__CLASS__,'gf_submit'],10,2);
 }
 // Database-backed yearly sequence; no reuse of reserved numbers.
 public static function ensure_sequence_table(){
  if(get_option('edb_sequence_schema')==='1')return;
  global $wpdb;
  $table=$wpdb->prefix.'edb_contract_sequences';
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  dbDelta("CREATE TABLE {$table} (
    year smallint(4) unsigned NOT NULL,
    last_number bigint(20) unsigned NOT NULL,
    PRIMARY KEY  (year)
  ) {$wpdb->get_charset_collate()};");
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table)update_option('edb_sequence_schema','1',false);
 }
 private static function next_contract_id(){
  global $wpdb;
  $table=$wpdb->prefix.'edb_contract_sequences';
  if(get_option('edb_sequence_schema')!=='1')self::ensure_sequence_table();
  if(get_option('edb_sequence_schema')!=='1')return new WP_Error('edb_sequence','Vertragsnummern-Tabelle nicht verfügbar.');
  $year=(int)wp_date('Y');
  // LAST_INSERT_ID is connection-scoped; atomic also for concurrent submissions.
  $ok=$wpdb->query($wpdb->prepare("INSERT INTO {$table} (year,last_number) VALUES (%d,LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_number=LAST_INSERT_ID(last_number+1)",$year));
  if($ok===false)return new WP_Error('edb_sequence','Vertragsnummer konnte nicht vergeben werden.');
  $num=(int)$wpdb->get_var('SELECT LAST_INSERT_ID()');
  if($num<1||$num>999999)return new WP_Error('edb_sequence','Vertragsnummer außerhalb des Nummernkreises.');
  return sprintf('SH-%04d-%06d',$year,$num);
 }
 private static function defaults(){return ['url'=>'','api_key'=>'','routes'=>'[]'];}
 private static function config(){return wp_parse_args(get_option(self::OPT,[]),self::defaults());}
 private static function routes(){ $r=json_decode(self::config()['routes'],true);return is_array($r)?$r:[]; }
 private static function route($name,$provider='elementor'){foreach(self::routes() as $r){if(!empty($r['form_name']) && $r['form_name']===$name && ($r['provider']??'elementor')===$provider && !empty($r['template_id']))return $r;}return null;}
 public static function menu(){add_options_page('Elementor DocuSeal Bridge','DocuSeal Bridge','manage_options','edb-bridge',[__CLASS__,'page_v3']);}
 public static function page(){if(!current_user_can('manage_options'))return;$c=self::config();?>
 <div class="wrap"><h1>Elementor DocuSeal Bridge</h1><p>Elementor Pro → DocuSeal. Jede Zuordnung verwendet eine Vorlage und einen Unterzeichner.</p>
 <?php if(isset($_GET['edb_notice']))echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['edb_notice']))).'</p></div>'; ?>
 <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('edb_save');?><input type="hidden" name="action" value="edb_save">
 <table class="form-table"><tr><th><label for="edb_url">DocuSeal URL</label></th><td><input class="regular-text" id="edb_url" name="url" type="url" placeholder="https://docs.example.org" value="<?php echo esc_attr($c['url']); ?>" required><p class="description">Basisadresse ohne /api. Nur HTTPS.</p></td></tr>
 <tr><th><label for="edb_key">API-Key</label></th><td><input class="regular-text" id="edb_key" name="api_key" type="password" value="" autocomplete="new-password" placeholder="Leer lassen, um bestehenden Schlüssel beizubehalten"><p class="description">In WordPress-Optionen gespeichert; nur Administratoren können diese Seite öffnen.</p></td></tr>
 <tr><th><label for="edb_routes">Formular-Zuordnungen (JSON)</label></th><td><textarea id="edb_routes" name="routes" rows="16" cols="100" class="large-text code" spellcheck="false"><?php echo esc_textarea($c['routes']); ?></textarea><p class="description">Eine Zuordnung pro Elementor-Formularname. Feldnamen müssen exakt mit DocuSeal übereinstimmen.</p></td></tr></table>
 <?php submit_button('Einstellungen speichern'); ?></form>
 <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('edb_test');?><input type="hidden" name="action" value="edb_test"><?php submit_button('Verbindung testen (nur lesend)','secondary');?></form>
 <h2>Beispiel für die Zuordnungen</h2><pre><?php echo esc_html(wp_json_encode([['form_name'=>'Mitgliedschaft','template_id'=>123,'role'=>'First Party','email_field'=>'email','name_field'=>'full_name','send_email'=>true,'fields'=>['Vorname'=>'first_name','Nachname'=>'last_name','Tarif'=>'membership_type']]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></pre>
 <p><strong>Hinweis:</strong> Diese Version versendet Signaturanfragen, verwaltet aber weder Vertragsabschluss noch Zahlungen. Formulare zunächst mit einer Testvorlage erproben.</p></div><?php }
 private static function redirect($message){wp_safe_redirect(add_query_arg(['page'=>'edb-bridge','edb_notice'=>$message],admin_url('options-general.php')));exit;}
 public static function save(){if(!current_user_can('manage_options'))wp_die('Forbidden',403);check_admin_referer('edb_save');$old=self::config();$url=esc_url_raw(trim((string)wp_unslash($_POST['url']??'')));if(!preg_match('~^https://[^/]+~i',$url))self::redirect('Fehler: HTTPS-URL erforderlich.');$url=rtrim($url,'/');$routes=wp_unslash($_POST['routes']??'[]');$parsed=json_decode($routes,true);if(!is_array($parsed)||json_last_error()!==JSON_ERROR_NONE)self::redirect('Fehler: ungültiges JSON.');foreach($parsed as $r){if(!is_array($r)||empty($r['form_name'])||empty($r['template_id'])||!is_numeric($r['template_id'])||empty($r['email_field'])||!is_array($r['fields']??[]))self::redirect('Fehler: ungültige Zuordnung.');foreach((array)($r['pipelines']??[]) as $target=>$pipeline){if(!is_string($target)||!is_array($pipeline)||count($pipeline)>50)self::redirect('Fehler: ungültige Berechnungskette.');foreach((array)($pipeline['steps']??[]) as $step){if(!is_array($step)||!in_array(($step['op']??''),['add','subtract','multiply','divide','percent','increase','decrease','round','ceil','floor','min','max','abs','power','mod'],true))self::redirect('Fehler: ungültiger Rechenschritt.');}}foreach((array)($r['rules']??[]) as $rule){if(!is_array($rule)||!in_array(($rule['type']??''),['condition','add','subtract','multiply','divide'],true)||empty($rule['target']))self::redirect('Fehler: ungültige Berechnungsregel.');}} $key=trim((string)wp_unslash($_POST['api_key']??''));update_option(self::OPT,['url'=>$url,'api_key'=>$key!==''?$key:$old['api_key'],'routes'=>wp_json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],false);self::redirect('Einstellungen gespeichert.');}
 private static function request($method,$path,$body=null){$c=self::config();if(empty($c['url'])||empty($c['api_key']))return new WP_Error('edb_config','API-Verbindung nicht konfiguriert.');$host=wp_parse_url($c['url'],PHP_URL_HOST);if(!$host || wp_parse_url($c['url'],PHP_URL_SCHEME)!=='https')return new WP_Error('edb_url','Ungültige HTTPS-URL.');$args=['method'=>$method,'timeout'=>15,'redirection'=>0,'reject_unsafe_urls'=>true,'headers'=>['X-Auth-Token'=>$c['api_key'],'Accept'=>'application/json']];if($body!==null){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body);}return wp_safe_remote_request(rtrim($c['url'],'/').'/api'.$path,$args);}
 public static function test(){if(!current_user_can('manage_options'))wp_die('Forbidden',403);check_admin_referer('edb_test');$r=self::request('GET','/templates');if(is_wp_error($r))self::redirect('Verbindungsfehler: '.$r->get_error_message());$code=wp_remote_retrieve_response_code($r);self::redirect($code>=200&&$code<300?'Verbindung erfolgreich.':'API antwortet mit HTTP '.$code.'.');}
 // Reserved server-side metadata: never accept these values from submitted form fields.
 private static function metadata(){
  $ip=isset($_SERVER['REMOTE_ADDR'])?trim((string)$_SERVER['REMOTE_ADDR']):'';
  if(!filter_var($ip,FILTER_VALIDATE_IP))$ip='';
  $agent=isset($_SERVER['HTTP_USER_AGENT'])?sanitize_text_field(wp_unslash((string)$_SERVER['HTTP_USER_AGENT'])):'';
  return [
   '__edb_meta_ip'=>$ip,
   '__edb_meta_timestamp'=>(string)time(),
   '__edb_meta_browser'=>substr($agent,0,255),
  ];
 }
 private static function data($record){$data=[];foreach((array)$record->get('fields') as $id=>$field){$v=$field['value']??'';$data[$id]=is_array($v)?implode(', ',array_map('sanitize_text_field',$v)):sanitize_text_field((string)$v);}return array_merge($data,self::metadata());}
 // Evaluate administrator-configured rules on the server; browser-supplied prices are ignored for rule targets.
 private static function numeric($value){
  if(!is_scalar($value))return null;
  $v=trim((string)$value);
  if(!preg_match('/^-?[0-9]+(?:[.,][0-9]+)?$/D',$v))return null;
  return (float)str_replace(',','.',$v);
 }
 private static function computed($r,$data){
  $out=[];
  foreach((array)($r['pipelines']??[]) as $target=>$pipeline){if(!is_string($target)||!is_array($pipeline)||count($pipeline)>50)self::redirect('Fehler: ungültige Berechnungskette.');foreach((array)($pipeline['steps']??[]) as $step){if(!is_array($step)||!in_array(($step['op']??''),['add','subtract','multiply','divide','percent','increase','decrease','round','ceil','floor','min','max','abs','power','mod'],true))self::redirect('Fehler: ungültiger Rechenschritt.');}}foreach((array)($r['rules']??[]) as $rule){
   if(!is_array($rule))continue;
   $target=(string)($rule['target']??'');$type=(string)($rule['type']??'');
   if($target==='')continue;
   if($type==='condition'){
    $source=(string)($rule['source']??'');
    $actual=(string)($out[$source]??$data[$source]??'');$expected=(string)($rule['equals']??'');
    if($source!=='' && $actual===$expected)$out[$target]=(string)($rule['value']??'');
   }elseif(in_array($type,['add','subtract','multiply','divide'],true)){
    $a=(string)($rule['a']??'');$b=(string)($rule['b']??'');
    $left=self::numeric($out[$a]??$data[$a]??null);
    $right=self::numeric($out[$b]??$data[$b]??null);
    if($left===null||$right===null)continue;
    if($type==='divide' && $right==0)continue;
    switch($type){
     case 'add':$result=$left+$right;break;
     case 'subtract':$result=$left-$right;break;
     case 'divide':$result=$left/$right;break;
     default:$result=$left*$right;
    }
    if(is_finite($result))$out[$target]=number_format($result,2,'.','');
   }
  }
  return $out;
 }
 // Evaluate multi-step pipelines using only server-side values and administrator-defined operands.
 private static function pipelines($r,$data,$seed){
  $pipelines=(array)($r['pipelines']??[]);$resolved=$seed;$pending=$pipelines;
  for($pass=0;$pass<count($pipelines);$pass++){
   $progress=false;
   foreach($pending as $target=>$pipe){
    if(!is_array($pipe)||empty($pipe['steps'])||count($pipe['steps'])>50){unset($pending[$target]);continue;}
    $previous=null;$valid=true;
    foreach($pipe['steps'] as $step){
     if(!is_array($step)){$valid=false;break;}
     $op=(string)($step['op']??'');
     $get=function($spec)use($data,$resolved,$previous){
      if(!is_array($spec))return null;
      $type=(string)($spec['type']??'');$key=(string)($spec['value']??'');
      if($type==='constant')return self::numeric($key);
      if($type==='previous')return $previous;
      if($type==='field')return array_key_exists($key,$data)?self::numeric($data[$key]):null;
      if($type==='result')return array_key_exists($key,$resolved)?self::numeric($resolved[$key]):null;
      return null;
     };
     $a=$get($step['a']??[]);$b=$get($step['b']??[]);
     if($a===null||(!in_array($op,['ceil','floor','abs'],true)&&$b===null)){$valid=false;break;}
     switch($op){
      case 'add':$v=$a+$b;break;case 'subtract':$v=$a-$b;break;
      case 'multiply':$v=$a*$b;break;case 'divide':$v=$b==0?NAN:$a/$b;break;
      case 'percent':$v=$a*$b/100;break;case 'increase':$v=$a*(1+$b/100);break;
      case 'decrease':$v=$a*(1-$b/100);break;
      case 'round':$v=round($a,max(0,min(8,(int)$b)));break;
      case 'ceil':$v=ceil($a);break;case 'floor':$v=floor($a);break;
      case 'min':$v=min($a,$b);break;case 'max':$v=max($a,$b);break;
      case 'abs':$v=abs($a);break;case 'power':$v=pow($a,$b);break;
      case 'mod':$v=$b==0?NAN:fmod($a,$b);break;
      default:$valid=false;break 2;
     }
     if(!is_finite($v)){$valid=false;break;}$previous=$v;
    }
    if($valid&&$previous!==null){$resolved[$target]=number_format($previous,2,'.','');unset($pending[$target]);$progress=true;}
   }
   if(!$progress)break;
  }
  return array_diff_key($resolved,$seed);
 }
 private static function payload($r,$data,$contract_id=null){$email=sanitize_email($data[$r['email_field']]??'');if(!is_email($email))return new WP_Error('edb_email','Ungültige E-Mail-Adresse.');$submitter=['role'=>sanitize_text_field($r['role']??'First Party'),'email'=>$email,'require_phone_2fa'=>false,'require_email_2fa'=>false];if(!empty($r['name_field'])&&!empty($data[$r['name_field']]))$submitter['name']=$data[$r['name_field']];$values=[];foreach($r['fields'] as $docuseal=>$elementor){if(is_string($elementor)&&array_key_exists($elementor,$data))$values[(string)$docuseal]=$data[$elementor];}$legacy=self::computed($r,$data);foreach($legacy as $target=>$value)$values[$target]=$value;foreach(self::pipelines($r,$data,$values) as $target=>$value)$values[$target]=$value;if($contract_id!==null && array_key_exists('Vertrags-ID',(array)($r['fields']??[])) && ($r['fields']['Vertrags-ID']??'')==='__edb_contract_id'){$values['Vertrags-ID']=$contract_id;}if($values)$submitter['values']=$values;return ['template_id'=>(int)$r['template_id'],'send_email'=>!array_key_exists('send_email',$r)||!empty($r['send_email']),'submitters'=>[$submitter]];}
 public static function validate($record,$ajax_handler){$name=(string)$record->get_form_settings('form_name');$r=self::route($name);if(!$r)return;$p=self::payload($r,self::data($record));if(is_wp_error($p))$ajax_handler->add_error_message('Bitte eine gültige E-Mail-Adresse angeben.');}
 public static function submit($record,$ajax_handler){
  $name=(string)$record->get_form_settings('form_name');$r=self::route($name,'elementor');if(!$r)return;
  $result=self::dispatch($r,self::data($record),'elementor:'.$name);
  if(is_wp_error($result))$ajax_handler->add_error_message($result->get_error_message());
 }
 public static function cf7_submit($contact_form,$abort,$submission){
  if($abort || !$submission || !is_object($contact_form))return $abort;
  $r=self::route((string)$contact_form->id(),'cf7');if(!$r)return $abort;
  $data=[];
  foreach((array)$submission->get_posted_data() as $key=>$value){
   if(strpos((string)$key,'_wpcf7')===0)continue;
   $data[(string)$key]=self::clean_value($value);
  }
  $data=array_merge($data,self::metadata());
  $result=self::dispatch($r,$data,'cf7:'.$contact_form->id());
  if(is_wp_error($result)){error_log('[EDB] CF7 dispatch failed: '.$result->get_error_code());return true;}
  return $abort;
 }
 public static function gf_submit($entry,$form){
  $r=self::route((string)($form['id']??''),'gravity');if(!$r)return;
  $data=[];
  foreach((array)($form['fields']??[]) as $field){
   $id=(string)$field->id;
   $value=function_exists('rgar')?rgar($entry,$id):($entry[$id]??'');
   if(($value===''||$value===null)&&!empty($field->inputs)){
    $parts=[];foreach($field->inputs as $input){$v=function_exists('rgar')?rgar($entry,(string)$input['id']):($entry[(string)$input['id']]??'');if($v!==''&&$v!==null)$parts[]=self::clean_value($v);}
    $value=implode(' ',array_filter($parts,'strlen'));
   }
   $data[$id]=self::clean_value($value);
  }
  $data=array_merge($data,self::metadata());
  $result=self::dispatch($r,$data,'gravity:'.($form['id']??''));
  if(is_wp_error($result))error_log('[EDB] Gravity Forms dispatch failed: '.$result->get_error_code());
 }
 private static function clean_value($value){
  if(is_array($value))return implode(', ',array_map([__CLASS__,'clean_value'],$value));
  return sanitize_text_field((string)$value);
 }
 private static function dispatch($r,$data,$source){
  $preflight=self::payload($r,$data);
  if(is_wp_error($preflight))return $preflight;
  // A stable ID for retries within the duplicate-submission window.
  // Never log personal data or bank details.
  $fingerprint=hash_hmac('sha256',wp_json_encode([$source,$preflight]),wp_salt('auth'));
  $lock='edb_'.substr($fingerprint,0,36);
  $state=get_transient($lock);
  if(is_array($state)&&($state['status']??'')==='sent')return true;
  if(is_array($state)&&!empty($state['contract_id']))$contract_id=$state['contract_id'];
  else{
   $contract_id=self::next_contract_id();
   if(is_wp_error($contract_id)){return $contract_id;}
  }
  set_transient($lock,['contract_id'=>$contract_id,'status'=>'pending'],DAY_IN_SECONDS);
  $data['__edb_contract_id']=$contract_id;
  $p=self::payload($r,$data,$contract_id);
  // An additional per-contract lock reduces accidental concurrent duplicate sends.
  $sending_key='edb_sending_'.md5($contract_id);
  if(get_transient($sending_key))return new WP_Error('edb_pending','Signaturanfrage wird bereits verarbeitet.');
  set_transient($sending_key,1,MINUTE_IN_SECONDS);
  $response=self::request('POST','/submissions',$p);
  delete_transient($sending_key);
  if(is_wp_error($response)){
   error_log('[EDB] Submission transport failure: '.$response->get_error_code());
   return new WP_Error('edb_transport','Signaturanfrage konnte nicht versendet werden.');
  }
  $code=wp_remote_retrieve_response_code($response);
  if($code<200||$code>=300){error_log('[EDB] DocuSeal returned HTTP '.(int)$code);return new WP_Error('edb_http','Signaturanfrage konnte nicht erstellt werden.');}
  set_transient($lock,['contract_id'=>$contract_id,'status'=>'sent'],DAY_IN_SECONDS);
  return true;
 }
 private static function is_list($a){return array_keys($a)===range(0,count($a)-1);}
 public static function assets($hook){
  if($hook!=='settings_page_edb-bridge')return;
  wp_enqueue_script('edb-admin',plugins_url('assets/admin.js',__FILE__),[], '0.8.0-beta.1',true);
  wp_localize_script('edb-admin','EDB',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('edb_catalog')]);
 }
 private static function scan_forms(){
  $out=[];
  $posts=get_posts(['post_type'=>'any','post_status'=>['publish','draft','private'],'posts_per_page'=>300,'fields'=>'ids','suppress_filters'=>true]);
  foreach($posts as $id){
   $raw=get_post_meta($id,'_elementor_data',true);
   if(!$raw)continue;
   $tree=is_string($raw)?json_decode($raw,true):$raw;
   if(!is_array($tree))continue;
   self::walk($tree,$out,$id);
  }
  return array_values($out);
 }
 private static function walk($nodes,&$out,$post_id){
  foreach($nodes as $node){
   if(!is_array($node))continue;
   if(($node['widgetType']??'')==='form'){
    $settings=$node['settings']??[];$name=(string)($settings['form_name']??'');
    if($name!==''){
     $fields=[];
     foreach(($settings['form_fields']??[]) as $f){
      if(!is_array($f))continue;
      $fid=(string)($f['custom_id']??'');
      if($fid==='' || in_array(strtolower((string)($f['field_type']??'')),['step','html','recaptcha','recaptcha_v3','honeypot'],true))continue;
      $fields[]=['id'=>$fid,'label'=>(string)($f['field_label']??$fid),'type'=>(string)($f['field_type']??'text')];
     }
     $key=$name;
     if(!isset($out[$key]))$out[$key]=['provider'=>'elementor','name'=>$name,'post_id'=>$post_id,'fields'=>$fields];
    }
   }
   if(!empty($node['elements'])&&is_array($node['elements']))self::walk($node['elements'],$out,$post_id);
  }
 }
 private static function scan_cf7_forms(){
  if(!class_exists('WPCF7_ContactForm'))return [];
  $out=[];
  foreach(WPCF7_ContactForm::find(['posts_per_page'=>-1]) as $form){
   $fields=[];
   if(method_exists($form,'scan_form_tags'))foreach($form->scan_form_tags() as $tag){
    $name=(string)($tag->name??'');if(!$name)continue;
    $fields[]=['id'=>$name,'label'=>$name,'type'=>(string)($tag->basetype??'text')];
   }
   $out[]=['provider'=>'cf7','name'=>(string)$form->id(),'title'=>$form->title(),'fields'=>$fields];
  }
  return $out;
 }
 private static function scan_gravity_forms(){
  if(!class_exists('GFAPI'))return [];
  $out=[];
  foreach((array)GFAPI::get_forms() as $form){
   $fields=[];
   foreach((array)($form['fields']??[]) as $field){
    $fields[]=['id'=>(string)$field->id,'label'=>(string)$field->label,'type'=>(string)$field->type];
   }
   $out[]=['provider'=>'gravity','name'=>(string)$form['id'],'title'=>(string)$form['title'],'fields'=>$fields];
  }
  return $out;
 }
 public static function catalog(){
  if(!current_user_can('manage_options')||!check_ajax_referer('edb_catalog','nonce',false))wp_send_json_error('Forbidden',403);
  wp_send_json_success(['forms'=>array_merge(self::scan_forms(),self::scan_cf7_forms(),self::scan_gravity_forms())]);
 }
 public static function template(){
  if(!current_user_can('manage_options')||!check_ajax_referer('edb_catalog','nonce',false))wp_send_json_error('Forbidden',403);
  $id=absint($_POST['id']??0);
  $path=$id?'/templates/'.$id:'/templates';
  $r=self::request('GET',$path);
  if(is_wp_error($r))wp_send_json_error($r->get_error_message());
  $code=wp_remote_retrieve_response_code($r);
  if($code<200||$code>=300)wp_send_json_error('DocuSeal HTTP '.$code);
  $data=json_decode(wp_remote_retrieve_body($r),true);
  if(!is_array($data))wp_send_json_error('Invalid JSON');
  if($id){
   $fields=[];
   foreach(($data['fields']??[]) as $f){
    if(!is_array($f)||empty($f['name'])||in_array($f['type']??'',['signature','initials'],true))continue;
    $fields[]=['name'=>$f['name'],'type'=>$f['type']??'text','required'=>!empty($f['required'])];
   }
   $roles=[];foreach(($data['submitters']??[]) as $r)if(!empty($r['name']))$roles[]=$r['name'];
   wp_send_json_success(['fields'=>$fields,'roles'=>$roles]);
  }
  $list=self::is_list($data)?$data:($data['data']??$data['templates']??[]);
  $result=[];foreach($list as $t)if(isset($t['id'],$t['name']))$result[]=['id'=>(int)$t['id'],'name'=>(string)$t['name']];
  wp_send_json_success(['templates'=>$result]);
 }
 public static function page_v3(){
  if(!current_user_can('manage_options'))return;
  $c=self::config();
  echo '<div class="wrap"><h1>Elementor DocuSeal Bridge</h1><p>Visuelle Zuordnung: Elementor Pro / Contact Form 7 / Gravity Forms → DocuSeal-Vorlage → Feld-Mapping und serverseitige Regeln.</p>';
  if(isset($_GET['edb_notice']))echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['edb_notice']))).'</p></div>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('edb_save');
  echo '<input type="hidden" name="action" value="edb_save"><table class="form-table"><tr><th>DocuSeal URL</th><td><input class="regular-text" name="url" type="url" required value="'.esc_attr($c['url']).'"></td></tr><tr><th>API-Key</th><td><input class="regular-text" name="api_key" type="password" autocomplete="new-password" placeholder="Leer = beibehalten"></td></tr></table>';
  echo '<h2>Zuordnungen</h2><p><button type="button" class="button" id="edb-load">Formulare und Vorlagen laden</button> <button type="button" class="button" id="edb-add">+ Zuordnung hinzufügen</button> <span id="edb-status" aria-live="polite"></span></p>';
  echo '<div id="edb-routes"></div><input type="hidden" id="edb-json" name="routes" value="'.esc_attr($c['routes']).'">';
  submit_button('Einstellungen und Zuordnungen speichern');echo '</form>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('edb_test');echo '<input type="hidden" name="action" value="edb_test">';submit_button('Verbindung testen','secondary');echo '</form>';
  echo '<p><strong>Hinweis:</strong> Die Feldnamen stammen aus der DocuSeal-Vorlage; Felder mit Signaturtyp werden nicht vorbefüllt. Elementor-Formulare werden aus gespeicherten Seiten geladen; Contact Form 7 und Gravity Forms über ihre jeweiligen APIs. Nach Änderungen in Elementor neu laden.</p></div>';
 }

}
EDB_Bridge::init();
