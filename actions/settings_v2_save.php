<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';require_role('admin');require_post();verify_csrf();
$provider=post_string('ai_provider');if(!in_array($provider,['local','generic'],true))$provider='local';
$values=[
 'boutique_name'=>post_string('boutique_name',160)?:'Oopticien Pro',
 'google_maps_link'=>post_string('google_maps_link',500),
 'ai_enabled'=>isset($_POST['ai_enabled'])?'1':'0',
 'ai_provider'=>$provider,
 'ai_model'=>post_string('ai_model',120)?:'rules-v2',
];
$maps=$values['google_maps_link'];if($maps!==''&&!filter_var($maps,FILTER_VALIDATE_URL)){flash('danger','Le lien Google Maps n’est pas valide.');redirect('pages/settings.php');}
$apiKey=post_string('ai_api_key',500);
if($apiKey!==''){$values['ai_api_key_encrypted']=encrypt_sensitive_value($apiKey);}
$stmt=db()->prepare('INSERT INTO settings (setting_key,setting_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
foreach($values as $key=>$value)$stmt->execute([$key,$value,'Réglage V2']);
log_action('settings',null,'mise_a_jour','Réglages boutique et assistant');flash('success','Les réglages ont été enregistrés.');redirect('pages/settings.php');
