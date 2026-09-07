<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$userId=filter_input(INPUT_GET,'user_id',FILTER_VALIDATE_INT)?:0;
$clientId=filter_input(INPUT_GET,'client_id',FILTER_VALIDATE_INT)?:0;
$dossierId=filter_input(INPUT_GET,'dossier_id',FILTER_VALIDATE_INT)?:0;
$action=trim((string)($_GET['action']??''));
$conditions=[];$params=[];
if($userId){$conditions[]='h.user_id=?';$params[]=$userId;}
if($dossierId){$conditions[]='h.entity_type="dossier" AND h.entity_id=?';$params[]=$dossierId;}
elseif($clientId){$conditions[]='((h.entity_type="client" AND h.entity_id=?) OR (h.entity_type="dossier" AND h.entity_id IN (SELECT id FROM dossiers WHERE client_id=?)))';$params[]=$clientId;$params[]=$clientId;}
if($action!==''){$conditions[]='h.action LIKE ?';$params[]='%'.$action.'%';}
$sql='SELECT h.*,u.name user_name FROM action_history h LEFT JOIN users u ON u.id=h.user_id';
if($conditions)$sql.=' WHERE '.implode(' AND ',$conditions);
$sql.=' ORDER BY h.created_at DESC LIMIT 500';
$stmt=db()->prepare($sql);$stmt->execute($params);$events=$stmt->fetchAll();
$users=db()->query('SELECT id,name FROM users ORDER BY name')->fetchAll();
$pageTitle='Historique';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Journal des actions</h2><p>Traçabilité des opérations importantes effectuées dans l’application.</p></div></section>
<form class="filters" method="get" style="grid-template-columns:repeat(4,minmax(150px,1fr)) auto">
    <select name="user_id"><option value="">Tous les utilisateurs</option><?php foreach($users as $user): ?><option value="<?= (int)$user['id'] ?>" <?= $userId===(int)$user['id']?'selected':'' ?>><?= e($user['name']) ?></option><?php endforeach; ?></select>
    <input type="number" name="client_id" value="<?= $clientId?:'' ?>" placeholder="ID client">
    <input type="number" name="dossier_id" value="<?= $dossierId?:'' ?>" placeholder="ID dossier">
    <input name="action" value="<?= e($action) ?>" placeholder="Type d’action">
    <button class="btn btn-primary" type="submit">Filtrer</button>
</form>
<section class="card table-card">
    <div class="card-header"><div><h2><?= count($events) ?> événement(s)</h2><p>Résultats limités aux 500 plus récents.</p></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Utilisateur</th><th>Entité</th><th>Action</th><th>Détails</th><th>Adresse IP</th></tr></thead>
            <tbody>
            <?php foreach($events as $event): ?>
                <tr><td><?= e(format_date($event['created_at'],true)) ?></td><td><?= e($event['user_name']?:'Système') ?></td><td><?= e(status_label($event['entity_type'])) ?><?= $event['entity_id']?' #'.(int)$event['entity_id']:'' ?></td><td><?= status_badge($event['action']) ?></td><td><?= e($event['details']?:'—') ?></td><td><?= e($event['ip_address']?:'—') ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$events): ?><tr><td colspan="6" class="empty-state"><strong>Aucun événement trouvé</strong></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
