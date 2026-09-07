<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';

function sql_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function sql_dump_value(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if ((string) $value === '') {
        return "''";
    }
    return 'CONVERT(0x' . bin2hex((string) $value) . ' USING utf8mb4)';
}

/**
 * Produit un SQL contrôlé : une instruction par ligne, ce qui rend la restauration
 * indépendante de mysqldump et sûre vis-à-vis des retours à la ligne contenus dans les données.
 */
function create_database_backup_file(): array
{
    $pdo = db();
    $fileName = 'oopticien-db-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
    $path = private_storage_path('backups') . DIRECTORY_SEPARATOR . $fileName;
    $handle = fopen($path, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Impossible de créer le fichier de sauvegarde.');
    }

    try {
        fwrite($handle, "-- Oopticien Pro V2 database backup\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $table = (string) $table;
            $create = $pdo->query('SHOW CREATE TABLE ' . sql_identifier($table))->fetch(PDO::FETCH_NUM);
            if (!$create || !isset($create[1])) {
                continue;
            }
            fwrite($handle, 'DROP TABLE IF EXISTS ' . sql_identifier($table) . ";\n");
            $createSql = preg_replace('/\s+/', ' ', (string) $create[1]) ?: (string) $create[1];
            fwrite($handle, rtrim($createSql, ';') . ";\n");

            $statement = $pdo->query('SELECT * FROM ' . sql_identifier($table));
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $columns = implode(',', array_map('sql_identifier', array_keys($row)));
                $values = implode(',', array_map('sql_dump_value', array_values($row)));
                fwrite($handle, 'INSERT INTO ' . sql_identifier($table) . ' (' . $columns . ') VALUES (' . $values . ");\n");
            }
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($handle);
    }
    @chmod($path, 0640);
    return backup_file_metadata($fileName, $path, 'database');
}

function create_documents_backup_file(): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('L’extension PHP ZIP n’est pas disponible.');
    }
    $fileName = 'oopticien-documents-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
    $path = private_storage_path('backups') . DIRECTORY_SEPARATOR . $fileName;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Impossible de créer l’archive des documents.');
    }
    $root = realpath(private_storage_path());
    $backupDirectory = realpath(private_storage_path('backups'));
    if ($root !== false) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $realPath = $file->getRealPath();
            if ($realPath === false || ($backupDirectory !== false && str_starts_with($realPath, $backupDirectory . DIRECTORY_SEPARATOR))) {
                continue;
            }
            $relative = ltrim(substr($realPath, strlen($root)), DIRECTORY_SEPARATOR);
            $zip->addFile($realPath, str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        }
    }
    $zip->close();
    @chmod($path, 0640);
    return backup_file_metadata($fileName, $path, 'documents');
}

function backup_file_metadata(string $fileName, string $path, string $kind): array
{
    return [
        'file_name' => $fileName,
        'path' => $path,
        'kind' => $kind,
        'size_bytes' => filesize($path) ?: 0,
        'sha256' => hash_file('sha256', $path),
    ];
}

function register_backup_result(array $metadata, ?int $userId): int
{
    $stmt = db()->prepare(
        'INSERT INTO backups (file_name,file_path,backup_type,kind,status,size_bytes,size,sha256,created_by)
         VALUES (?, ?, ?, ?, "created", ?, ?, ?, ?)'
    );
    $stmt->execute([
        $metadata['file_name'],'backups/'.$metadata['file_name'],$userId===null?'automatic':'manual',
        $metadata['kind'],$metadata['size_bytes'],$metadata['size_bytes'],$metadata['sha256'],$userId,
    ]);
    return (int) db()->lastInsertId();
}

function register_backup_failure(string $kind, string $message, ?int $userId): void
{
    $stmt = db()->prepare(
        'INSERT INTO backups (file_name,file_path,backup_type,kind,status,error_message,created_by)
         VALUES (?, ?, ?, ?, "failed", ?, ?)'
    );
    $failedName='échec-' . date('Ymd-His');
    $stmt->execute([$failedName,'backups/'.$failedName,$userId===null?'automatic':'manual',$kind,mb_substr($message, 0, 2000),$userId]);
}

function restore_database_backup(string $path): int
{
    if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'sql') {
        throw new RuntimeException('Sauvegarde SQL invalide.');
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Impossible de lire la sauvegarde.');
    }
    $count = 0;
    try {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }
            if (!str_ends_with($line, ';')) {
                throw new RuntimeException('Format de sauvegarde non reconnu.');
            }
            // Compatibilité avec les sauvegardes V2.0 initiales où une chaîne vide
            // pouvait être sérialisée sous la forme ambiguë "0x".
            if (str_starts_with($line, 'INSERT INTO ')) {
                $line = preg_replace('/(?<=\(|,)0x(?=,|\))/', "''", $line) ?? $line;
                $line = preg_replace(
                    '/(?<=\(|,)0x([0-9a-fA-F]+)(?=,|\))/',
                    'CONVERT(0x$1 USING utf8mb4)',
                    $line
                ) ?? $line;
            }
            db()->exec($line);
            $count++;
        }
    } finally {
        fclose($handle);
    }
    return $count;
}
