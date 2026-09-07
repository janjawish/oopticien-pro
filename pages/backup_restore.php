<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM backups WHERE id = ? AND kind = "database" AND status IN ("created","restored")');
$stmt->execute([$id]);
$backup = $stmt->fetch();
if (!$backup) {
    http_response_code(404);
    exit('Sauvegarde introuvable.');
}
$phrase = 'RESTAURER ' . $backup['file_name'];
$pageTitle = 'Restaurer une sauvegarde';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card narrow-card">
    <div class="card-header"><div><h2>Confirmation forte</h2><p>Cette opération remplace toutes les tables avec l’état de la sauvegarde.</p></div></div>
    <div class="alert alert-danger">Une sauvegarde automatique de sécurité sera créée juste avant la restauration.</div>
    <dl class="meta-list"><div class="meta-row"><dt>Fichier</dt><dd><?= e($backup['file_name']) ?></dd></div><div class="meta-row"><dt>Date</dt><dd><?= e(format_date($backup['created_at'], true)) ?></dd></div></dl>
    <form method="post" action="<?= e(app_url('actions/backup_restore.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $backup['id'] ?>">
        <label class="field"><span>Recopiez exactement : <strong><?= e($phrase) ?></strong></span><input name="confirmation" autocomplete="off" required></label>
        <div class="form-footer"><a class="btn btn-outline" href="<?= e(app_url('pages/backups.php')) ?>">Annuler</a><button class="btn btn-danger" type="submit">Restaurer la base</button></div>
    </form>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
