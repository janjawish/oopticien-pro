<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/backup.php';
require_role('admin');
require_post();
verify_csrf();

$kind = post_string('kind') === 'documents' ? 'documents' : 'database';
try {
    $metadata = $kind === 'documents' ? create_documents_backup_file() : create_database_backup_file();
    $id = register_backup_result($metadata, (int) current_user()['id']);
    log_action('backup', $id, 'backup_created', 'Sauvegarde ' . $kind);
    flash('success', 'La sauvegarde a été créée et vérifiée.');
} catch (Throwable $exception) {
    register_backup_failure($kind, $exception->getMessage(), (int) current_user()['id']);
    flash('danger', 'La sauvegarde a échoué : ' . $exception->getMessage());
}
redirect('pages/backups.php');

