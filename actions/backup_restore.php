<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/backup.php';
require_role('admin');
require_post();
verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM backups WHERE id = ? AND kind = "database" AND status IN ("created","restored")');
$stmt->execute([$id]);
$backup = $stmt->fetch();
if (!$backup || !hash_equals('RESTAURER ' . $backup['file_name'], post_string('confirmation', 400))) {
    flash('danger', 'Restauration refusée : la confirmation ne correspond pas.');
    redirect('pages/backups.php');
}

try {
    $safety = create_database_backup_file();
    $path = safe_private_file('backups', $backup['file_name']);
    if (!hash_equals((string) $backup['sha256'], hash_file('sha256', $path))) {
        throw new RuntimeException('Le contrôle d’intégrité a échoué.');
    }
    $statements = restore_database_backup($path);
    $restoredId = register_backup_result([
        'file_name' => $backup['file_name'],
        'kind' => 'database',
        'size_bytes' => filesize($path) ?: (int) $backup['size_bytes'],
        'sha256' => hash_file('sha256', $path),
    ], (int) current_user()['id']);
    db()->prepare('UPDATE backups SET status="restored",restored_at=NOW() WHERE id=?')->execute([$restoredId]);
    register_backup_result($safety, (int) current_user()['id']);
    log_sensitive_access(null, 'backup_restore', 'Sauvegarde restaurée, ' . $statements . ' instructions');
    flash('success', 'La base a été restaurée. Une sauvegarde de sécurité préalable a été conservée.');
} catch (Throwable $exception) {
    flash('danger', 'La restauration a échoué : ' . $exception->getMessage());
}
redirect('pages/backups.php');
