<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

require_once dirname(__DIR__) . '/includes/ai.php';

$pdo = db();
$passed = 0;
$failed = 0;

function assistant_test(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[OK] $label\n";
    } else {
        $failed++;
        echo "[ÉCHEC] $label\n";
    }
}

$pdo->beginTransaction();
try {
    $userId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $row = $pdo->query(
        "SELECT c.id AS client_id,c.first_name,c.last_name,d.id AS dossier_id
         FROM clients c JOIN dossiers d ON d.client_id=c.id
         WHERE d.folder_status NOT IN ('cloture','annule')
           AND NOT EXISTS (
             SELECT 1 FROM clients duplicate
             WHERE duplicate.id<>c.id AND duplicate.first_name=c.first_name AND duplicate.last_name=c.last_name
           )
           AND (SELECT COUNT(*) FROM dossiers active_d
                WHERE active_d.client_id=c.id AND active_d.folder_status NOT IN ('cloture','annule'))=1
         LIMIT 1"
    )->fetch();
    if (!$userId || !$row) {
        throw new RuntimeException('Aucun dossier de test unique disponible.');
    }

    $_SESSION = [];
    $summary = assistant_handle_command('Résume ' . $row['first_name'] . ' ' . $row['last_name'], $userId);
    assistant_test('Résumé client résolu', ($summary['context_type'] ?? '') === 'client' && str_contains($summary['response'], 'Résumé complet'));
    assistant_test('Résumé sans NIR en clair', !preg_match('/\b[12]\d{12,14}\b/', $summary['response']));

    $duplicateClient = $pdo->query(
        "SELECT c.id,c.last_name FROM clients c
         WHERE EXISTS (SELECT 1 FROM clients other WHERE other.id<>c.id AND other.last_name=c.last_name)
         ORDER BY c.last_name,c.id LIMIT 1"
    )->fetch();
    if ($duplicateClient) {
        $_SESSION = [];
        $ambiguous = assistant_handle_command('Résume ' . $duplicateClient['last_name'], $userId);
        assistant_test('Homonyme détecté', str_contains($ambiguous['response'], 'Plusieurs clients'));
        $chosen = assistant_handle_command((string) $duplicateClient['id'], $userId);
        assistant_test('Choix par numéro client', ($chosen['context_type'] ?? '') === 'client' && (int) ($chosen['context_id'] ?? 0) === (int) $duplicateClient['id']);
    }

    $_SESSION = [];
    $directDossier = assistant_handle_command(
        'Modifie le RO à 120,09 €, le RC à 300 € et le RAC à 50 € pour D-' . $row['dossier_id'],
        $userId
    );
    assistant_test('Nom et prénom obligatoires pour une action', str_contains($directDossier['response'], 'nom ET le prénom'));

    $_SESSION = [];
    $amountChoice = assistant_handle_command(
        'Modifie le RO à 120,09 €, le RC à 300 € et le RAC à 50 € pour ' . $row['first_name'] . ' ' . $row['last_name'],
        $userId
    );
    assistant_test('Choix du dossier obligatoire', empty($amountChoice['redirect_url']) && count($amountChoice['choices'] ?? []) === 1);
    assistant_test('Informations du dossier proposées', str_contains($amountChoice['response'], 'RO ') && str_contains($amountChoice['response'], 'RAC '));
    $amountResult = assistant_handle_command('D-' . $row['dossier_id'], $userId);
    preg_match('/assistant_draft=([a-f0-9]{48})/', (string) ($amountResult['redirect_url'] ?? ''), $tokenMatch);
    $draft = !empty($tokenMatch[1]) ? assistant_load_draft($tokenMatch[1], $userId, (int) $row['dossier_id']) : null;
    assistant_test('Brouillon montants créé', is_array($draft));
    assistant_test('Total recalculé', $draft && abs((float) $draft['payload']['total_amount'] - 470.09) < 0.001);
    assistant_test('Base du dossier mémorisée', $draft && !empty($draft['base_updated_at']));
    assistant_test('Brouillon encore à jour', $draft && assistant_draft_matches_updated_at($draft, $draft['base_updated_at']));
    assistant_test('Modification concurrente détectée', $draft && !assistant_draft_matches_updated_at($draft, '2000-01-01 00:00:00'));
    if ($draft) {
        assistant_mark_draft_applied((int) $draft['id']);
        $appliedStatus = $pdo->query('SELECT status FROM assistant_action_drafts WHERE id=' . (int) $draft['id'])->fetchColumn();
        assistant_test('Brouillon marqué comme validé', $appliedStatus === 'applied');
    }

    $_SESSION = [];
    $savChoice = assistant_handle_command('Passe ' . $row['first_name'] . ' ' . $row['last_name'] . ' en SAV', $userId);
    assistant_test('Choix du dossier avant SAV', empty($savChoice['redirect_url']) && count($savChoice['choices'] ?? []) === 1);
    $savResult = assistant_handle_command('D-' . $row['dossier_id'], $userId);
    preg_match('/assistant_draft=([a-f0-9]{48})/', (string) ($savResult['redirect_url'] ?? ''), $savTokenMatch);
    $savDraft = !empty($savTokenMatch[1]) ? assistant_load_draft($savTokenMatch[1], $userId, (int) $row['dossier_id']) : null;
    assistant_test('Brouillon SAV créé', $savDraft && $savDraft['payload']['folder_status'] === 'sav');

    $navigation = assistant_handle_command('Ouvre les dossiers à facturer', $userId);
    assistant_test('Navigation conversationnelle', str_contains((string) ($navigation['redirect_url'] ?? ''), 'view=to_invoice'));

    $pdo->rollBack();
    echo "Résultat : $passed réussi(s), $failed échec(s).\n";
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[ÉCHEC] ' . $exception->getMessage() . "\n");
    exit(1);
}
