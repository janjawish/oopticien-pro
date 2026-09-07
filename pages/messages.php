<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$status=trim((string)($_GET['status']??''));
$where='1=1';$params=[];
if(in_array($status,['a_preparer','envoye','echec','non_envoye','fait_manuellement'],true)){$where='m.status=?';$params[]=$status;}
$stmt=db()->prepare(
    'SELECT m.*,CONCAT(c.first_name," ",c.last_name) AS client_name,u.name AS user_name
     FROM messages m JOIN clients c ON c.id=m.client_id LEFT JOIN users u ON u.id=m.created_by
     WHERE '.$where.' ORDER BY m.created_at DESC LIMIT 200'
);$stmt->execute($params);$messages=$stmt->fetchAll();
$pageTitle='Messages';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Messages clients</h2><p>E-mails envoyés, WhatsApp préparés et historique des contacts. SMS et téléphone seront activés plus tard.</p></div><div class="inline-actions"><?php if(has_role(['admin','patron'])):?><a class="btn btn-outline" href="<?= e(app_url('pages/message_templates.php')) ?>">Modèles</a><?php endif;?><a class="btn btn-primary" href="<?= e(app_url('pages/dossiers.php')) ?>">Choisir un dossier</a></div></section>
<nav class="filter-tabs"><a href="?">Tous</a><?php foreach(['a_preparer','envoye','non_envoye','fait_manuellement','echec'] as $item):?><a class="<?= $status===$item?'active':'' ?>" href="?status=<?= e($item) ?>"><?= e(status_label($item)) ?></a><?php endforeach;?></nav>
<section class="card table-card"><div class="table-wrap"><table><thead><tr><th>Date</th><th>Client</th><th>Canal</th><th>Destinataire</th><th>Objet / aperçu</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach($messages as $message):?><tr><td><?= e(format_date($message['created_at'],true)) ?></td><td><?= e($message['client_name']) ?><?= $message['dossier_id']?'<span class="cell-subtitle">'.e(dossier_number((int)$message['dossier_id'])).'</span>':'' ?></td><td><?= e(status_label($message['channel'])) ?></td><td><?= e($message['recipient']?:'—') ?></td><td><span class="cell-title"><?= e($message['subject']?:$message['template_name']?:'Message') ?></span><span class="cell-subtitle"><?= e(mb_substr(preg_replace('/\s+/',' ',$message['content'])??'',0,90)) ?></span></td><td><?= status_badge($message['status']) ?></td><td><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/message_preview.php?id='.$message['id'])) ?>">Ouvrir</a></td></tr><?php endforeach;?>
<?php if(!$messages):?><tr><td colspan="7" class="empty-state">Aucun message pour ce filtre.</td></tr><?php endif;?></tbody></table></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php';?>
