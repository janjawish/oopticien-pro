<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$stmt=db()->prepare('SELECT d.*,CONCAT(c.first_name," ",c.last_name) AS client_name FROM documents d LEFT JOIN clients c ON c.id=d.client_id WHERE d.id=? AND d.deleted_at IS NULL');
$stmt->execute([$id]);$document=$stmt->fetch();if(!$document){http_response_code(404);exit('Document introuvable.');}
log_action('document',(int)$document['id'],'document_view','Consultation de la fiche document');
log_sensitive_access($document['client_id']?(int)$document['client_id']:null,'document_view','Document #'.$document['id']);
$pageTitle='Document';require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2><?= e($document['original_file_name']?:$document['original_name']) ?></h2><p>Document privé · consultation journalisée</p></div><a class="btn btn-primary" href="<?= e(app_url('actions/document_download.php?id='.$document['id'])) ?>">Télécharger</a></section>
<section class="card"><dl class="meta-list"><div class="meta-row"><dt>Type</dt><dd><?= e(status_label($document['document_type']?:$document['category'])) ?></dd></div><div class="meta-row"><dt>Client</dt><dd><?= e($document['client_name']?:'—') ?></dd></div><div class="meta-row"><dt>Dossier</dt><dd><?= $document['dossier_id']?e(dossier_number((int)$document['dossier_id'])):'—' ?></dd></div><div class="meta-row"><dt>Taille</dt><dd><?= e(number_format((int)($document['file_size']?:$document['size_bytes'])/1024,1,',',' ')) ?> Ko</dd></div><div class="meta-row"><dt>Description</dt><dd><?= nl2br(e($document['description']?:'—')) ?></dd></div><div class="meta-row"><dt>Déposé le</dt><dd><?= e(format_date($document['created_at'],true)) ?></dd></div></dl></section>
<?php require dirname(__DIR__) . '/includes/footer.php';?>
