<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$priority=trim((string)($_GET['priority']??''));
$status=trim((string)($_GET['status']??''));
$type=trim((string)($_GET['type']??''));
$urgent=isset($_GET['urgent'])&&$_GET['urgent']==='1';
$conditions=[];$params=[];
$conditions[]="(d.id IS NULL OR d.folder_status<>'sav')";
if($priority!==''){$conditions[]='t.priority=?';$params[]=$priority;}
if($status!==''){$conditions[]='t.status=?';$params[]=$status;} else {$conditions[]="t.status IN ('a_faire','en_cours','reportee')";}
if($type!==''){$conditions[]='t.task_type=?';$params[]=$type;}
if($urgent){$conditions[]="(t.priority='critique' OR t.due_at<=NOW())";}
$sql='SELECT t.*,c.first_name,c.last_name,d.folder_status FROM tasks t LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN dossiers d ON d.id=t.dossier_id';
if($conditions)$sql.=' WHERE '.implode(' AND ',$conditions);
$sql.=" ORDER BY FIELD(t.priority,'critique','haute','normale','basse'),t.due_at LIMIT 300";
$stmt=db()->prepare($sql);$stmt->execute($params);$tasks=$stmt->fetchAll();
$counts=db()->query("SELECT
 SUM(t.status IN ('a_faire','en_cours','reportee') AND (d.id IS NULL OR d.folder_status<>'sav')) open_count,
 SUM(t.status IN ('a_faire','en_cours','reportee') AND t.due_at<NOW() AND (d.id IS NULL OR d.folder_status<>'sav')) overdue_count,
 SUM(t.status IN ('a_faire','en_cours','reportee') AND t.priority='critique' AND (d.id IS NULL OR d.folder_status<>'sav')) critical_count,
 SUM(t.status='terminee' AND DATE(t.completed_at)=CURDATE()) done_today
 FROM tasks t LEFT JOIN dossiers d ON d.id=t.dossier_id")->fetch();
$types=['mutuelle','facturation','paiement_ro','paiement_rc','paiement_rac','commande','montage','client_a_prevenir','client_non_venu','incoherence','autre'];
$pageTitle='Relances';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>File d’actions</h2><p>Traitez les relances par priorité et échéance.</p></div><?php if(has_role(['admin','patron'])): ?><form method="post" action="<?= e(app_url('cron/generate_reminders.php')) ?>" data-confirm="Générer maintenant les relances automatiques ?"><?= csrf_field() ?><button class="btn btn-outline" type="submit">↻ Générer les relances</button></form><?php endif; ?></section>
<div class="stats-grid" style="margin-bottom:18px">
    <article class="stat-card"><div class="stat-top"><span class="stat-icon">✓</span>À traiter</div><div class="stat-value"><?= (int)($counts['open_count']??0) ?></div><p class="stat-note">Tâches ouvertes</p></article>
    <article class="stat-card accent-red"><div class="stat-top"><span class="stat-icon">!</span>En retard</div><div class="stat-value"><?= (int)($counts['overdue_count']??0) ?></div><p class="stat-note">Échéance dépassée</p></article>
    <article class="stat-card accent-red"><div class="stat-top"><span class="stat-icon">!!</span>Critiques</div><div class="stat-value"><?= (int)($counts['critical_count']??0) ?></div><p class="stat-note">À voir en premier</p></article>
    <article class="stat-card accent-green"><div class="stat-top"><span class="stat-icon">✓</span>Faites aujourd’hui</div><div class="stat-value"><?= (int)($counts['done_today']??0) ?></div><p class="stat-note">Actions terminées</p></article>
</div>
<form class="filters" method="get" style="grid-template-columns:repeat(3,minmax(160px,1fr)) auto">
    <select name="priority"><option value="">Toutes priorités</option><?php foreach(['basse','normale','haute','critique'] as $item): ?><option value="<?= e($item) ?>" <?= $priority===$item?'selected':'' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select>
    <select name="status"><option value="">Tâches ouvertes</option><?php foreach(['a_faire','en_cours','terminee','reportee','annulee'] as $item): ?><option value="<?= e($item) ?>" <?= $status===$item?'selected':'' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select>
    <select name="type"><option value="">Tous les types</option><?php foreach($types as $item): ?><option value="<?= e($item) ?>" <?= $type===$item?'selected':'' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select>
    <button class="btn btn-primary" type="submit">Filtrer</button>
</form>
<section class="card">
    <div class="task-list">
        <?php foreach($tasks as $task): ?>
            <article class="task-item <?= strtotime($task['due_at'])<time() && !in_array($task['status'],['terminee','annulee'],true)?'row-overdue':'' ?>">
                <span class="task-line <?= $task['priority']==='critique'?'critical':($task['priority']==='haute'?'high':'') ?>"></span>
                <div>
                    <p><?= e($task['title']) ?> <?= status_badge($task['priority']) ?> <?= status_badge($task['status']) ?></p>
                    <small><?= e(trim(($task['first_name']??'').' '.($task['last_name']??'')) ?: 'Sans client') ?> · échéance <?= e(format_date($task['due_at'],true)) ?> · <?= e(status_label($task['task_type'])) ?></small>
                </div>
                <div class="inline-actions">
                    <?php if($task['dossier_id']): ?><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/dossier_view.php?id='.$task['dossier_id'])) ?>">Dossier</a><?php endif; ?>
                    <?php if(in_array($task['status'],['a_faire','en_cours','reportee'],true)): ?>
                        <form method="post" action="<?= e(app_url('actions/task_update.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$task['id'] ?>"><input type="hidden" name="action" value="postpone"><button class="btn btn-small btn-outline" type="submit">Reporter +2 j</button></form>
                        <form method="post" action="<?= e(app_url('actions/task_update.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$task['id'] ?>"><input type="hidden" name="action" value="complete"><button class="btn btn-small btn-success" type="submit">Marquer faite</button></form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if(!$tasks): ?><div class="empty-state"><strong>Aucune tâche dans cette vue</strong>Les relances générées apparaîtront ici.</div><?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
