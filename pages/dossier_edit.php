<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/assistant_actions.php';
redirect_if_not_logged_in();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM dossiers WHERE id = ?');
$stmt->execute([$id]);
$folder = $stmt->fetch();
if (!$folder) {
    http_response_code(404);
    exit('Dossier introuvable.');
}
$assistantDraft = null;
$assistantDraftToken = trim((string) ($_GET['assistant_draft'] ?? ''));
if ($assistantDraftToken !== '') {
    $assistantDraft = assistant_load_draft($assistantDraftToken, (int) current_user()['id'], (int) $id);
    if (!$assistantDraft) {
        flash('warning', 'Cette proposition de l’assistant est invalide ou a expiré. Aucune donnée n’a été modifiée.');
        redirect('pages/dossier_edit.php?id=' . $id);
    }
    foreach ($assistantDraft['payload'] as $field => $value) {
        if (array_key_exists($field, $folder)) {
            $folder[$field] = $value;
        }
    }
    $assistantDraftChanges = assistant_draft_changes($assistantDraft);
}
$stmt = db()->prepare('SELECT * FROM glass_orders WHERE dossier_id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch() ?: [];
$clients = db()->query('SELECT id, first_name, last_name, phone FROM clients ORDER BY last_name, first_name')->fetchAll();
$pageTitle = 'Modifier le dossier';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2><?= e(dossier_number((int) $folder['id'])) ?></h2><p>Mettre à jour le suivi opérationnel.</p></div></section>
<?php if ($assistantDraft): ?>
<section class="card assistant-draft-banner">
    <div class="card-header"><div><h2>✦ Proposition de l’assistant</h2><p>Le formulaire est prérempli, mais rien n’est encore enregistré.</p></div><span class="badge badge-warning">À valider</span></div>
    <p><strong>Demande :</strong> <?= e((string)($assistantDraft['payload']['_assistant_reason'] ?? 'Modification préparée')) ?></p>
    <div class="table-wrap"><table class="assistant-draft-table"><thead><tr><th>Champ</th><th>Valeur actuelle</th><th>Valeur proposée</th></tr></thead><tbody>
    <?php foreach ($assistantDraftChanges as $change): ?><tr><td><?= e($change['label']) ?></td><td><?= e($change['before']) ?></td><td><strong><?= e($change['after']) ?></strong></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="form-help">Contrôlez le client et toutes les valeurs. Seul le bouton « Enregistrer les modifications » appliquera l’action. Proposition valable jusqu’au <?= e(format_date($assistantDraft['expires_at'],true)) ?>.</p>
</section>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/dossier_form.php'; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
