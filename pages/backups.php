<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
$backups = db()->query(
    'SELECT b.*, u.name AS user_name FROM backups b
     LEFT JOIN users u ON u.id = b.created_by ORDER BY b.created_at DESC LIMIT 100'
)->fetchAll();
$pageTitle = 'Sauvegardes';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy"><h2>Sauvegardes privées</h2><p>Base SQL et documents stockés hors du dossier public. Téléchargement réservé à l’administrateur.</p></div>
    <div class="inline-actions">
        <form method="post" action="<?= e(app_url('actions/backup_create.php')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="database"><button class="btn btn-primary" type="submit">Sauvegarder la base</button></form>
        <form method="post" action="<?= e(app_url('actions/backup_create.php')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="documents"><button class="btn btn-outline" type="submit">Archiver documents + clé</button></form>
    </div>
</section>

<div class="alert alert-warning"><strong>Important :</strong> conservez une copie externe de la base ET de l’archive documentaire contenant la clé. La restauration SQL remplace l’état actuel de la base.</div>

<section class="card table-card">
    <div class="card-header"><div><h2>Historique</h2><p><?= count($backups) ?> sauvegarde(s) affichée(s)</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Fichier</th><th>Taille</th><th>Statut</th><th>Créée par</th><th></th></tr></thead><tbody>
    <?php foreach ($backups as $backup): ?>
        <tr>
            <td><?= e(format_date($backup['created_at'], true)) ?></td>
            <td><?= e(status_label($backup['kind'])) ?></td>
            <td><span class="cell-title"><?= e($backup['file_name']) ?></span><span class="cell-subtitle"><?= e($backup['sha256'] ? substr($backup['sha256'], 0, 16) . '…' : ($backup['error_message'] ?: '—')) ?></span></td>
            <td><?= $backup['size_bytes'] ? e(number_format((int) $backup['size_bytes'] / 1024, 1, ',', ' ') . ' Ko') : '—' ?></td>
            <td><?= status_badge($backup['status']) ?></td>
            <td><?= e($backup['user_name'] ?: 'Tâche planifiée') ?></td>
            <td>
                <?php if (in_array($backup['status'], ['created','restored'], true)): ?>
                    <a class="btn btn-small btn-outline" href="<?= e(app_url('actions/backup_download.php?id=' . $backup['id'])) ?>">Télécharger</a>
                    <?php if ($backup['kind'] === 'database'): ?><a class="btn btn-small btn-warning" href="<?= e(app_url('pages/backup_restore.php?id=' . $backup['id'])) ?>">Restaurer</a><?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$backups): ?><tr><td colspan="7" class="empty-state">Aucune sauvegarde. Créez la première maintenant.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
