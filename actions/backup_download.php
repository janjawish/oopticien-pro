<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/storage.php';
require_role('admin');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM backups WHERE id = ? AND status IN ("created","restored")');
$stmt->execute([$id]);
$backup = $stmt->fetch();
if (!$backup) {
    http_response_code(404);
    exit('Sauvegarde introuvable.');
}
$path = safe_private_file('backups', $backup['file_name']);
if (!hash_equals((string) $backup['sha256'], hash_file('sha256', $path))) {
    http_response_code(409);
    exit('Le contrôle d’intégrité de cette sauvegarde a échoué.');
}
log_sensitive_access(null, 'backup_download', 'Sauvegarde #' . $backup['id']);
$mime = $backup['kind'] === 'database' ? 'application/sql' : 'application/zip';
stream_private_download($path, $mime, $backup['file_name']);
