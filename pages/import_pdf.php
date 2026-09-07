<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/storage.php';
require_once dirname(__DIR__) . '/includes/pdf_import.php';
require_role(['admin','patron']);
$pdfDiagnostics = pdf_import_diagnostics();
$pdfStorageReady = false;
try {
    $pdfStorageReady = is_writable(private_storage_path('pdf-imports'));
} catch (Throwable) {
    $pdfStorageReady = false;
}
$imports = db()->query(
    'SELECT p.*,u.name AS user_name FROM pdf_imports p LEFT JOIN users u ON u.id=p.created_by ORDER BY p.created_at DESC LIMIT 50'
)->fetchAll();
$pageTitle='Import PDF Cosium';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Reprendre un dossier Cosium</h2><p>Le document est lu localement puis présenté à l’opticien pour vérification.</p></div></section>
<?php if (!$pdfDiagnostics['ready'] || !$pdfStorageReady): ?>
<div class="alert alert-danger"><strong>Lecteur PDF incomplet sur ce PC.</strong> <?= e(implode(' · ', array_merge($pdfDiagnostics['missing'], $pdfStorageReady ? [] : ['stockage privé non accessible']))) ?>. Lancez <code>server/install-pdf-tools.ps1</code> en administrateur puis redémarrez Apache.</div>
<?php else: ?>
<div class="alert alert-success"><strong>Lecteur PDF prêt.</strong> Poppler et Tesseract sont détectés<?= $pdfDiagnostics['french_ready'] ? ', avec la langue française' : '' ?>.</div>
<?php if (!$pdfDiagnostics['french_ready']): ?><div class="alert alert-warning">La langue française de Tesseract n’est pas installée. L’OCR utilisera l’anglais et sera moins fiable.</div><?php endif; ?>
<?php endif; ?>
<div class="alert alert-info">Le lecteur reconnaît le modèle Cosium fourni, y compris lorsqu’il est enregistré comme une image. Contrôlez toujours les champs et les corrections avant de valider.</div>
<section class="card">
    <div class="card-header"><div><h2>Déposer un PDF</h2><p>10 Mo maximum · PDF uniquement</p></div></div>
    <form method="post" enctype="multipart/form-data" action="<?= e(app_url('actions/import_pdf_action.php')) ?>" data-unsaved-warning>
        <?= csrf_field() ?>
        <label class="field"><span>Export PDF Cosium</span><input type="file" name="pdf" accept=".pdf,application/pdf" required></label>
        <div class="form-footer"><button class="btn btn-primary" type="submit">Analyser puis vérifier</button></div>
    </form>
</section>
<section class="card table-card"><div class="card-header"><div><h2>Imports PDF récents</h2><p><?= count($imports) ?> import(s)</p></div></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Fichier</th><th>Statut</th><th>Utilisateur</th><th></th></tr></thead><tbody>
<?php foreach($imports as $import): ?><tr><td><?= e(format_date($import['created_at'],true)) ?></td><td><?= e($import['original_file_name']?:$import['original_name']) ?></td><td><?= status_badge($import['status']) ?></td><td><?= e($import['user_name']?:'—') ?></td><td><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/import_preview.php?id='.$import['id'])) ?>">Ouvrir</a></td></tr><?php endforeach; ?>
<?php if(!$imports): ?><tr><td colspan="5" class="empty-state">Aucun PDF importé.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
