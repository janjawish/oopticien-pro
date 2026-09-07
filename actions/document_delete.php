<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('UPDATE documents SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL');
$stmt->execute([$id]);
if ($stmt->rowCount()) {
    log_action('document', (int)$id, 'document_delete', 'Retrait logique du document');
    flash('success', 'Le document a été retiré de l’interface. Le fichier reste conservé pour audit.');
} else {
    flash('danger', 'Document introuvable.');
}
redirect('pages/documents.php');

