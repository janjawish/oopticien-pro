<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();
$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
$stmt=db()->prepare('SELECT * FROM tasks WHERE id=?');$stmt->execute([$id]);$task=$stmt->fetch();
if(!$task){http_response_code(404);exit('Tâche introuvable.');}
$action=post_string('action');
if($action==='complete'){
    $stmt=db()->prepare("UPDATE tasks SET status='terminee',completed_at=NOW() WHERE id=?");$stmt->execute([$id]);
    log_action('tache',(int)$id,'terminee',$task['title']);flash('success','La tâche est terminée.');
}elseif($action==='postpone'){
    $stmt=db()->prepare("UPDATE tasks SET status='reportee',due_at=DATE_ADD(due_at,INTERVAL 2 DAY) WHERE id=?");$stmt->execute([$id]);
    log_action('tache',(int)$id,'reportee',$task['title'].' reportée de 2 jours');flash('success','La tâche a été reportée de 2 jours.');
}else{
    flash('danger','Action inconnue.');
}
redirect(post_string('return_to')==='dossier'&&$task['dossier_id']?'pages/dossier_view.php?id='.$task['dossier_id']:'pages/relances.php');
