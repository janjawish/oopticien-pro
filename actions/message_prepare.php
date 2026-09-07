<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/messaging.php';
redirect_if_not_logged_in();require_post();verify_csrf();
$dossierId=filter_input(INPUT_POST,'dossier_id',FILTER_VALIDATE_INT);$channel=post_string('channel');$templateId=filter_input(INPUT_POST,'template_id',FILTER_VALIDATE_INT)?:null;
if(!$dossierId||!in_array($channel,['appel','sms','email','whatsapp'],true)){flash('danger','Dossier ou canal invalide.');redirect('pages/dossiers.php');}
$stmt=db()->prepare('SELECT d.*,c.first_name,c.last_name,c.phone,c.email,go.ready_at FROM dossiers d JOIN clients c ON c.id=d.client_id LEFT JOIN glass_orders go ON go.dossier_id=d.id WHERE d.id=?');
$stmt->execute([$dossierId]);$folder=$stmt->fetch();if(!$folder){http_response_code(404);exit('Dossier introuvable.');}
$template=null;if($templateId){$stmt=db()->prepare('SELECT * FROM message_templates WHERE id=? AND is_active=1');$stmt->execute([$templateId]);$template=$stmt->fetch();}
if(!$template){$stmt=db()->prepare('SELECT * FROM message_templates WHERE event_key="glasses_ready" AND channel=? AND is_active=1 LIMIT 1');$stmt->execute([$channel]);$template=$stmt->fetch();}
$ready=in_array($folder['folder_status'],['pret','client_prevenu','remis','cloture'],true)||!empty($folder['ready_at']);
if(($template['event_key']??'glasses_ready')==='glasses_ready'&&!$ready){flash('warning','Le dossier doit être prêt avant de préparer ce message.');redirect('pages/dossier_view.php?id='.$dossierId);}
$recipient=$channel==='email'?trim((string)$folder['email']):trim((string)$folder['phone']);
if($recipient===''||($channel==='email'&&!filter_var($recipient,FILTER_VALIDATE_EMAIL))){flash('danger','Coordonnée client absente ou invalide pour ce canal.');redirect('pages/dossier_view.php?id='.$dossierId);}
$context=message_context($folder);$rawContent=post_string('content',5000);
$content=$rawContent!==''?$rawContent:render_message_template((string)($template['content']??$template['body']??''),$context);
$subject=render_message_template(post_string('subject',190)?:((string)($template['subject']??'')),$context);
if($content===''){flash('danger','Le message est vide.');redirect('pages/dossier_view.php?id='.$dossierId);}
$stmt=db()->prepare('INSERT INTO messages (client_id,dossier_id,channel,template_name,subject,recipient,provider,content,status,created_by) VALUES (?,?,?,?,?,?,"none",?,"a_preparer",?)');
$stmt->execute([$folder['client_id'],$dossierId,$channel,$template['name']??'Message libre',$subject?:null,$recipient,$content,current_user()['id']]);
$messageId=(int)db()->lastInsertId();log_action('message',$messageId,'message_prepare','Préparation avec validation requise');
redirect('pages/message_preview.php?id='.$messageId);
