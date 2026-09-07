<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db.php';
app_config();

$csvPath = $argv[1] ?? dirname(__DIR__) . '/outputs/oopticien-suivie-2026-08-01/suivie_statuts_mis_a_jour.csv';
$apply = in_array('--apply', $argv, true);
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV introuvable : {$csvPath}\n");
    exit(1);
}

function sync_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? trim($value);
    return mb_strtoupper(preg_replace('/\s+/u', ' ', $value) ?? $value, 'UTF-8');
}

function sync_name(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{5}$/', $value) && (int) $value >= 40000 && (int) $value <= 80000) {
        $timestamp = ((int) $value - 25569) * 86400;
        $value = gmdate('d/m/Y', $timestamp);
    }
    $value = mb_strtolower($value, 'UTF-8');
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function sync_amount(mixed $value): float
{
    $clean = str_replace([' ', ','], ['', '.'], trim((string) $value));
    return is_numeric($clean) ? round((float) $clean, 2) : 0.0;
}

$handle = fopen($csvPath, 'rb');
$headers = array_map('sync_header', fgetcsv($handle, 0, ';') ?: []);
$rows = [];
while (($values = fgetcsv($handle, 0, ';')) !== false) {
    $values = array_pad($values, count($headers), '');
    $row = array_combine($headers, array_slice($values, 0, count($headers)));
    if ($row && trim((string) ($row['NOM'] ?? '')) !== '' && trim((string) ($row['PRÉNOM'] ?? '')) !== '') {
        $rows[] = $row;
    }
}
fclose($handle);

$pdo = db();
$dbRows = $pdo->query(
    "SELECT d.id,d.client_id,d.folder_status,d.teletrans_status,
            d.ro_amount,d.rc_amount,d.rac_amount,d.total_amount,
            c.first_name,c.last_name
     FROM dossiers d JOIN clients c ON c.id=d.client_id
     WHERE d.source_origin='csv'
     ORDER BY d.id"
)->fetchAll();

$synthetic = [];
$genuine = [];
foreach ($dbRows as $dbRow) {
    if (preg_match('/^(2024|2025|2026)$/', trim((string) $dbRow['last_name']))
        && trim((string) $dbRow['last_name']) === trim((string) $dbRow['first_name'])) {
        $synthetic[] = $dbRow;
    } else {
        $genuine[] = $dbRow;
    }
}

$unmatchedSources = [];
$unusedDatabase = [];
$amountDifferences = 0;
$changes = [];
$matches = [];
$statusCounts = [];
$typeCounts = [];
$allowedStatuses = ['brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer','facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus','montage','pret','client_prevenu','remis','cloture','bloque','sav','annule'];
$available = [];
foreach ($genuine as $dbRow) $available[(int) $dbRow['id']] = $dbRow;

foreach ($rows as $index => $row) {
    $candidates = [];
    foreach ($available as $id => $dbRow) {
        if (sync_name((string) $row['NOM']) !== sync_name((string) $dbRow['last_name'])
            || sync_name((string) $row['PRÉNOM']) !== sync_name((string) $dbRow['first_name'])) continue;
        $score = abs(sync_amount($row['PART RO'] ?? 0) - (float) $dbRow['ro_amount'])
            + abs(sync_amount($row['PART RC'] ?? 0) - (float) $dbRow['rc_amount'])
            + abs(sync_amount($row['RAC'] ?? 0) - (float) $dbRow['rac_amount'])
            + abs(sync_amount($row['TOTALE'] ?? 0) - (float) $dbRow['total_amount']);
        $candidates[] = ['id' => $id, 'score' => $score, 'db' => $dbRow];
    }
    usort($candidates, static fn(array $a, array $b): int => ($a['score'] <=> $b['score']) ?: ($a['id'] <=> $b['id']));
    if (!$candidates) {
        $unmatchedSources[] = ['source_line' => $row['LIGNE SOURCE'] ?? '', 'name' => trim((string) $row['NOM'] . ' ' . (string) $row['PRÉNOM'])];
        continue;
    }
    $match = $candidates[0];
    $dbRow = $match['db'];
    unset($available[$match['id']]);
    if ($match['score'] > 0.04) $amountDifferences++;
    $matches[] = ['row' => $row, 'db' => $dbRow];

    $status = in_array(($row['STATUT DOSSIER'] ?? ''), $allowedStatuses, true)
        ? (string) $row['STATUT DOSSIER'] : (string) $dbRow['folder_status'];
    $type = ($row['TYPE DOSSIER'] ?? '') === 'lentilles' ? 'lentilles' : 'lunettes';
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
    if ($status !== $dbRow['folder_status']) $changes[] = [(int) $dbRow['id'], $dbRow['folder_status'], $status];
}

$unusedDatabase = array_map(static fn(array $row): array => [
    'dossier_id' => (int) $row['id'],
    'name' => trim((string) $row['last_name'] . ' ' . (string) $row['first_name']),
], array_values($available));

if (count($unmatchedSources) > 10 || count($unusedDatabase) > 10) {
    fwrite(STDERR, "Correspondance refusée, trop d'écarts :\n" . json_encode(['sources' => $unmatchedSources, 'database' => $unusedDatabase], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(3);
}

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'source_rows' => count($rows),
    'matched_rows' => count($matches),
    'unmatched_sources' => $unmatchedSources,
    'unused_database' => $unusedDatabase,
    'amount_differences' => $amountDifferences,
    'status_changes' => count($changes),
    'synthetic_dossiers' => array_map(static fn(array $row): int => (int) $row['id'], $synthetic),
    'statuses' => $statusCounts,
    'types' => $typeCounts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if (!$apply) exit(0);

try {
    $pdo->beginTransaction();
    $update = $pdo->prepare('UPDATE dossiers SET dossier_type=?,folder_status=?,priority=?,next_action=? WHERE id=?');
    foreach ($matches as $match) {
        $row = $match['row'];
        $dbRow = $match['db'];
        $status = in_array(($row['STATUT DOSSIER'] ?? ''), $allowedStatuses, true)
            ? (string) $row['STATUT DOSSIER'] : (string) $dbRow['folder_status'];
        $type = ($row['TYPE DOSSIER'] ?? '') === 'lentilles' ? 'lentilles' : 'lunettes';
        $priority = $status === 'bloque' ? 'urgente' : 'normale';
        $nextAction = match ($status) {
            'a_facturer' => 'Facturer le dossier',
            'en_cours' => 'Poursuivre le traitement du dossier',
            'bloque' => 'Traiter le dossier problématique',
            'facture' => $dbRow['teletrans_status'] === 'oui' ? null : 'Télétransmettre la facture',
            'cloture', 'teletransmis' => null,
            default => null,
        };
        $update->execute([$type, $status, $priority, $nextAction, $dbRow['id']]);
    }

    $pdo->exec(
        "UPDATE tasks t JOIN dossiers d ON d.id=t.dossier_id
         SET t.status='annulee'
         WHERE d.folder_status IN ('cloture','annule')
           AND t.status IN ('a_faire','en_cours','reportee')"
    );
    $pdo->exec(
        "INSERT INTO tasks (dossier_id,client_id,title,task_type,due_at,priority,status)
         SELECT d.id,d.client_id,'Facturer le dossier','facturation',NOW(),'haute','a_faire'
         FROM dossiers d
         WHERE d.folder_status='a_facturer'
           AND NOT EXISTS (
               SELECT 1 FROM tasks t
               WHERE t.dossier_id=d.id AND t.task_type='facturation'
                 AND t.status IN ('a_faire','en_cours','reportee')
           )"
    );

    $deleteDossier = $pdo->prepare('DELETE FROM dossiers WHERE id=?');
    $deleteClient = $pdo->prepare('DELETE FROM clients WHERE id=? AND NOT EXISTS (SELECT 1 FROM dossiers WHERE client_id=clients.id)');
    foreach ($synthetic as $row) {
        $deleteDossier->execute([$row['id']]);
        $deleteClient->execute([$row['client_id']]);
    }

    $history = $pdo->prepare('INSERT INTO action_history (user_id,entity_type,entity_id,action,details,ip_address) VALUES (NULL,"database",NULL,"import_csv",?,"CLI")');
    $history->execute(['Synchronisation XLSX : ' . count($matches) . ' dossiers validés, ' . count($changes) . ' statuts modifiés']);
    $pdo->commit();
    echo "Synchronisation appliquée avec succès.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Synchronisation annulée : ' . $exception->getMessage() . "\n");
    exit(4);
}
