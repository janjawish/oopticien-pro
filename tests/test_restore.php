<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/backup.php';

$metadata = create_database_backup_file();
db()->exec(
    "INSERT INTO settings (setting_key,setting_value,description)
     VALUES ('restore_test_sentinel','1','Test temporaire')
     ON DUPLICATE KEY UPDATE setting_value='1'"
);
$statements = restore_database_backup($metadata['path']);
$sentinel = (int) db()->query(
    "SELECT COUNT(*) FROM settings WHERE setting_key='restore_test_sentinel'"
)->fetchColumn();

if ($sentinel !== 0) {
    fwrite(STDERR, "ECHEC : la restauration n’a pas supprimé la sentinelle.\n");
    exit(1);
}
echo 'Restauration SQL réussie : ' . $statements . " instructions exécutées.\n";

