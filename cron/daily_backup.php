<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/backup.php';

try {
    $metadata = create_database_backup_file();
    $id = register_backup_result($metadata, null);
    echo 'Sauvegarde quotidienne créée #' . $id . ': ' . $metadata['file_name'] . PHP_EOL;
} catch (Throwable $exception) {
    try {
        register_backup_failure('database', $exception->getMessage(), null);
    } catch (Throwable) {
    }
    fwrite(STDERR, 'Échec sauvegarde : ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

