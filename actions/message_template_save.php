<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin','patron']);require_post();verify_csrf();
$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:null;$name=post_string('name',120);$channel=post_string('channel');$body=post_string('body',5000);$eventKey=post_string('event_key',80);
if($name===''||$body===''||!in_array($channel,['email','sms','whatsapp','appel','interne'],true)||!preg_match('/^[a-z0-9_]+$/',$eventKey)){flash('danger','Modèle invalide.');redirect('pages/message_templates.php');}
try{
if($id){$stmt=db()->prepare('UPDATE message_templates SET name=?,channel=?,event_key=?,subject=?,body=?,content=?,is_active=? WHERE id=?');$stmt->execute([$name,$channel,$eventKey,post_nullable('subject'),$body,$body,isset($_POST['is_active'])?1:0,$id]);}
else{$stmt=db()->prepare('INSERT INTO message_templates (name,channel,event_key,subject,body,content,is_active,created_by) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([$name,$channel,$eventKey,post_nullable('subject'),$body,$body,isset($_POST['is_active'])?1:0,current_user()['id']]);$id=(int)db()->lastInsertId();}
}catch(PDOException){flash('danger','Un modèle existe déjà pour cet événement et ce canal.');redirect('pages/message_templates.php');}
log_action('message_template',$id,'mise_a_jour','Modèle '.$name);
flash('success','Le modèle a été enregistré.');redirect('pages/message_templates.php');
