<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$requests=db()->query(
    'SELECT mr.*,mp.name AS portal_name,COALESCE(mp.url,mp.portal_url) AS portal_url,d.client_id,
            CONCAT(c.first_name," ",c.last_name) AS client_name
     FROM mutual_requests mr JOIN dossiers d ON d.id=mr.dossier_id
     JOIN clients c ON c.id=d.client_id LEFT JOIN mutual_portals mp ON mp.id=mr.portal_id
     ORDER BY FIELD(mr.status,"en_attente","envoyee","a_completer","a_preparer","prete","refusee","acceptee"),mr.response_due_at'
)->fetchAll();
$portals=db()->query('SELECT *,COALESCE(url,portal_url) AS active_url FROM mutual_portals WHERE is_active=1 ORDER BY name')->fetchAll();
$available=db()->query(
    'SELECT d.id,CONCAT(c.last_name," ",c.first_name) AS client_name FROM dossiers d
     JOIN clients c ON c.id=d.client_id LEFT JOIN mutual_requests mr ON mr.dossier_id=d.id
     WHERE mr.id IS NULL AND d.folder_status NOT IN ("cloture","annule") ORDER BY d.created_at DESC LIMIT 200'
)->fetchAll();
$pageTitle='Mutuelles';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Demandes de prise en charge</h2><p>Préparation, checklist, références et relance à 48 h. Aucun mot de passe n’est stocké et aucun portail n’est automatisé.</p></div></section>
<?php if($available):?><section class="card"><div class="card-header"><div><h2>Démarrer un suivi</h2><p>Le dossier reste traité manuellement sur le portail de l’organisme.</p></div></div><form method="post" action="<?= e(app_url('actions/mutual_request_save.php')) ?>" data-unsaved-warning><?= csrf_field() ?><input type="hidden" name="create" value="1"><div class="form-grid"><label class="field"><span>Dossier</span><select name="dossier_id" required><option value="">Sélectionner</option><?php foreach($available as $folder):?><option value="<?= (int)$folder['id'] ?>"><?= e(dossier_number((int)$folder['id']).' · '.$folder['client_name']) ?></option><?php endforeach;?></select></label><label class="field"><span>Portail</span><select name="portal_id"><option value="">— À choisir —</option><?php foreach($portals as $portal):?><option value="<?= (int)$portal['id'] ?>"><?= e($portal['name']) ?></option><?php endforeach;?></select></label></div><div class="form-footer"><button class="btn btn-primary" type="submit">Créer le suivi</button></div></form></section><?php endif;?>
<section class="card table-card"><div class="card-header"><div><h2>Suivis en cours</h2><p><?= count($requests) ?> demande(s)</p></div></div><div class="table-wrap"><table><thead><tr><th>Dossier</th><th>Organisme</th><th>Checklist</th><th>Référence</th><th>Montants</th><th>Échéance</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach($requests as $request):$checkCount=(int)$request['has_identity']+(int)$request['has_prescription']+(int)$request['has_rights_certificate']+(int)$request['has_quote'];?><tr class="<?= $request['response_due_at']&&strtotime($request['response_due_at'])<time()&&in_array($request['status'],['envoyee','en_attente'],true)?'row-warning':'' ?>"><td><a class="cell-title" href="<?= e(app_url('pages/dossier_view.php?id='.$request['dossier_id'])) ?>"><?= e(dossier_number((int)$request['dossier_id'])) ?></a><span class="cell-subtitle"><?= e($request['client_name']) ?></span></td><td><?= e($request['portal_name']?:'—') ?><?php if($request['portal_url']):?><a class="cell-subtitle" target="_blank" rel="noopener noreferrer" href="<?= e($request['portal_url']) ?>">Ouvrir le portail ↗</a><?php endif;?></td><td><?= $checkCount ?>/4 pièces</td><td><?= e($request['reference']?:$request['external_reference']?:'—') ?></td><td><?= e(format_euros((float)$request['ro_amount']+(float)$request['rc_amount']+(float)$request['rac_amount'])) ?><span class="cell-subtitle">Accordé : <?= e(format_euros($request['accepted_amount']?:$request['approved_amount'])) ?></span></td><td><?= e(format_date($request['response_due_at'],true)) ?></td><td><?= status_badge($request['status']) ?></td><td><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/mutuelle_request.php?id='.$request['id'])) ?>">Ouvrir</a></td></tr><?php endforeach;?>
<?php if(!$requests):?><tr><td colspan="8" class="empty-state">Aucune demande suivie.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div class="card-header"><div><h2>Portails connus</h2><p>Liens configurables, sans identifiants enregistrés</p></div></div><div class="portal-grid"><?php foreach($portals as $portal):?><div class="portal-card"><strong><?= e($portal['name']) ?></strong><?php if($portal['active_url']):?><a target="_blank" rel="noopener noreferrer" href="<?= e($portal['active_url']) ?>">Ouvrir ↗</a><?php else:?><small>URL à configurer</small><?php endif;?></div><?php endforeach;?></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php';?>
