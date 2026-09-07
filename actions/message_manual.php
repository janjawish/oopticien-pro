<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/messaging.php';
redirect_if_not_logged_in();require_post();verify_csrf();$messageId=filter_input(INPUT_POST,'message_id',FILTER_VALIDATE_INT);
$stmt=db()->prepare('SELECT * FROM messages WHERE id=?');$stmt->execute([$messageId]);$message=$stmt->fetch();
if(!$message||!in_array($message['status'],['a_preparer','non_envoye','echec'],true)){flash('danger','Message invalide.');redirect('pages/messages.php');}
$pdo=db();$pdo->beginTransaction();
try{$stmt=$pdo->prepare('UPDATE messages SET provider="manual",status="fait_manuellement",error_message=NULL,sent_at=NOW(),validated_by=?,validated_at=NOW() WHERE id=?');$stmt->execute([current_user()['id'],$messageId]);if($message['dossier_id'])mark_client_notified($pdo,(int)$message['dossier_id']);$pdo->commit();log_action('message',(int)$messageId,'message_envoye','Contact effectué manuellement');flash('success','Le contact a été marqué comme effectué manuellement.');}catch(Throwable){if($pdo->inTransaction())$pdo->rollBack();flash('danger','Impossible de journaliser le contact.');}
redirect('pages/message_preview.php?id='.$messageId);

