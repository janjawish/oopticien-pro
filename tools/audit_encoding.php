<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$columns = $pdo->query(
    "SELECT table_name,column_name
     FROM information_schema.columns
     WHERE table_schema=DATABASE()
       AND data_type IN ('char','varchar','text','mediumtext','longtext')
     ORDER BY table_name,ordinal_position"
)->fetchAll();

$problems = [];
foreach ($columns as $column) {
    $tableName = str_replace('`', '``', (string) $column['table_name']);
    $columnName = str_replace('`', '``', (string) $column['column_name']);
    $sql = sprintf(
        "SELECT COUNT(*) FROM `%s`
         WHERE LOCATE(0x3F, CAST(`%s` AS BINARY)) > 0
            OR LOCATE(0xC383, CAST(`%s` AS BINARY)) > 0
            OR LOCATE(0xC382, CAST(`%s` AS BINARY)) > 0
            OR LOCATE(0xC3A2E282AC, CAST(`%s` AS BINARY)) > 0
            OR LOCATE(0xEFBFBD, CAST(`%s` AS BINARY)) > 0",
        $tableName,
        $columnName,
        $columnName,
        $columnName,
        $columnName,
        $columnName
    );
    $count = (int) $pdo->query($sql)->fetchColumn();
    if ($count > 0) {
        $problems[] = $tableName . '.' . $columnName . '=' . $count;
    }
}

if ($problems) {
    fwrite(STDERR, implode(PHP_EOL, $problems) . PHP_EOL);
    exit(1);
}

echo "ENCODING_OK\n";
