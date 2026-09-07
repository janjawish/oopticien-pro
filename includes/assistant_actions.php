<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

function assistant_normalize(string $value): string
{
    $value = mb_strtoupper($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function assistant_text_contains(string $normalizedPrompt, string $value): bool
{
    $needle = assistant_normalize($value);
    return $needle !== '' && str_contains(' ' . $normalizedPrompt . ' ', ' ' . $needle . ' ');
}

function assistant_explicit_dossier_id(string $prompt): ?int
{
    if (preg_match('/\bD[\s-]*0*(\d{1,8})\b/iu', $prompt, $match)
        || preg_match('/\bdossier\s*(?:n(?:um[ée]ro)?\s*)?[#°:]?\s*0*(\d{1,8})\b/iu', $prompt, $match)) {
        return (int) $match[1];
    }
    return null;
}

function assistant_named_client_candidates(string $prompt): array
{
    $normalizedPrompt = assistant_normalize($prompt);
    $clients = db()->query('SELECT id,first_name,last_name,fiche_number,phone FROM clients')->fetchAll();
    $candidates = [];
    foreach ($clients as $client) {
        $first = assistant_normalize((string) $client['first_name']);
        $last = assistant_normalize((string) $client['last_name']);
        $firstLast = trim($first . ' ' . $last);
        $lastFirst = trim($last . ' ' . $first);
        $score = 0;
        if (assistant_text_contains($normalizedPrompt, $firstLast) || assistant_text_contains($normalizedPrompt, $lastFirst)) {
            $score = 120;
        } else {
            $lastTokens = array_values(array_filter(explode(' ', $last), static fn(string $token): bool => strlen($token) >= 2));
            $allLastTokens = $lastTokens !== [];
            foreach ($lastTokens as $token) {
                if (!assistant_text_contains($normalizedPrompt, $token)) {
                    $allLastTokens = false;
                    break;
                }
            }
            if ($allLastTokens) {
                $score = 70;
                $firstToken = explode(' ', $first)[0] ?? '';
                if ($firstToken !== '' && assistant_text_contains($normalizedPrompt, $firstToken)) {
                    $score += 30;
                }
            }
        }
        if ($score > 0) {
            $client['match_score'] = $score;
            $candidates[] = $client;
        }
    }
    usort($candidates, static fn(array $a, array $b): int => $b['match_score'] <=> $a['match_score']);
    if ($candidates === []) {
        return [];
    }
    $best = (int) $candidates[0]['match_score'];
    return array_values(array_filter($candidates, static fn(array $candidate): bool => (int) $candidate['match_score'] === $best));
}

function assistant_client_candidates(string $prompt): array
{
    $explicitDossier = assistant_explicit_dossier_id($prompt);
    if ($explicitDossier) {
        $stmt = db()->prepare(
            'SELECT c.id,c.first_name,c.last_name,c.fiche_number,c.phone
             FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.id=?'
        );
        $stmt->execute([$explicitDossier]);
        $client = $stmt->fetch();
        return $client ? [$client + ['match_score' => 200]] : [];
    }
    if (preg_match('/\bclient\s*[#°:]?\s*(\d+)\b/iu', $prompt, $match)) {
        $stmt = db()->prepare('SELECT id,first_name,last_name,fiche_number,phone FROM clients WHERE id=?');
        $stmt->execute([(int) $match[1]]);
        $client = $stmt->fetch();
        return $client ? [$client + ['match_score' => 200]] : [];
    }
    return assistant_named_client_candidates($prompt);
}

function assistant_client_choices(array $clients): string
{
    $lines = [count($clients) > 1 ? 'Plusieurs clients correspondent. Lequel faut-il utiliser ?' : 'Client correspondant :'];
    foreach (array_slice($clients, 0, 8) as $client) {
        $label = trim($client['first_name'] . ' ' . $client['last_name']);
        $details = array_filter([$client['fiche_number'] ?: null, $client['phone'] ?: null]);
        $lines[] = '- [' . $label . '](' . app_url('pages/client_view.php?id=' . $client['id']) . ')'
            . ($details ? ' · ' . implode(' · ', $details) : '')
            . ' · client #' . $client['id'];
    }
    $exampleClientId = (int) ($clients[0]['id'] ?? 0);
    $lines[] = 'Répondez avec le numéro du dossier (par exemple D-01234) ou « client #' . $exampleClientId . ' ».';
    return implode("\n", $lines);
}

function assistant_resolve_dossier(string $prompt, bool $activeOnly = true): array
{
    $explicitId = assistant_explicit_dossier_id($prompt);
    if ($explicitId) {
        $stmt = db()->prepare(
            'SELECT d.*,c.first_name,c.last_name FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.id=?'
        );
        $stmt->execute([$explicitId]);
        $dossier = $stmt->fetch();
        return ['dossier' => $dossier ?: null, 'clients' => [], 'dossiers' => []];
    }

    $clients = assistant_client_candidates($prompt);
    if (count($clients) !== 1) {
        return ['dossier' => null, 'clients' => $clients, 'dossiers' => []];
    }
    $sql = 'SELECT d.*,c.first_name,c.last_name FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.client_id=?';
    if ($activeOnly) {
        $sql .= " AND d.folder_status NOT IN ('cloture','annule')";
    }
    $sql .= ' ORDER BY d.updated_at DESC,d.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([(int) $clients[0]['id']]);
    $dossiers = $stmt->fetchAll();
    return [
        'dossier' => count($dossiers) === 1 ? $dossiers[0] : null,
        'clients' => $clients,
        'dossiers' => $dossiers,
    ];
}

function assistant_dossier_choices(array $dossiers): string
{
    if ($dossiers === []) {
        return 'Aucun dossier actif ne correspond à ce client.';
    }
    $lines = ['Choisissez le dossier à modifier :'];
    foreach (array_slice($dossiers, 0, 10) as $dossier) {
        $date = $dossier['quote_date'] ?: ($dossier['created_at'] ?? null);
        $lines[] = '- [' . dossier_number((int) $dossier['id']) . '](' . app_url('pages/dossier_view.php?id=' . $dossier['id']) . ')'
            . ' · ' . status_label($dossier['dossier_type'] ?? 'lunettes')
            . ' · ' . status_label($dossier['folder_status'])
            . ' · ' . ($date ? format_date($date) : 'date non renseignée')
            . ' · total ' . format_euros($dossier['total_amount'])
            . ' · RO ' . format_euros($dossier['ro_amount'])
            . ' / RC ' . format_euros($dossier['rc_amount'])
            . ' / RAC ' . format_euros($dossier['rac_amount']);
    }
    $lines[] = 'Cliquez sur « Choisir » dans le petit chat ou répondez avec D-xxxxx. Aucune modification ne sera encore enregistrée.';
    return implode("\n", $lines);
}

function assistant_dossier_choice_data(array $dossiers): array
{
    $choices = [];
    foreach (array_slice($dossiers, 0, 10) as $dossier) {
        $date = $dossier['quote_date'] ?: ($dossier['created_at'] ?? null);
        $choices[] = [
            'value' => dossier_number((int) $dossier['id']),
            'label' => dossier_number((int) $dossier['id']) . ' · ' . status_label($dossier['folder_status']),
            'details' => status_label($dossier['dossier_type'] ?? 'lunettes')
                . ' · ' . ($date ? format_date($date) : 'date non renseignée')
                . ' · ' . format_euros($dossier['total_amount'])
                . ' · RO ' . format_euros($dossier['ro_amount'])
                . ' / RC ' . format_euros($dossier['rc_amount'])
                . ' / RAC ' . format_euros($dossier['rac_amount']),
        ];
    }
    return $choices;
}

function assistant_client_choice_data(array $clients): array
{
    return array_map(static function (array $client): array {
        return [
            'value' => 'client #' . (int) $client['id'],
            'label' => trim($client['first_name'] . ' ' . $client['last_name']),
            'details' => implode(' · ', array_filter([
                $client['fiche_number'] ? 'fiche ' . $client['fiche_number'] : null,
                $client['phone'] ?: null,
            ])) ?: 'Client #' . (int) $client['id'],
        ];
    }, array_slice($clients, 0, 8));
}

function assistant_resolve_action_dossier(string $prompt, string $actionType): array
{
    $pending = $_SESSION['assistant_pending_command'] ?? null;
    $explicitDossierId = assistant_explicit_dossier_id($prompt);
    if (is_array($pending)
        && ($pending['type'] ?? '') === $actionType
        && !empty($pending['client_id'])
        && $explicitDossierId) {
        $allowedDossiers = array_map('intval', (array) ($pending['dossier_ids'] ?? []));
        if (!in_array($explicitDossierId, $allowedDossiers, true)) {
            return [
                'dossier' => null,
                'response' => 'Ce dossier ne fait pas partie des dossiers proposés pour ce client. Choisissez-en un dans la liste.',
                'choices' => (array) ($pending['choices'] ?? []),
            ];
        }
        $stmt = db()->prepare(
            'SELECT d.*,c.first_name,c.last_name FROM dossiers d
             JOIN clients c ON c.id=d.client_id WHERE d.id=? AND d.client_id=?'
        );
        $stmt->execute([$explicitDossierId, (int) $pending['client_id']]);
        return ['dossier' => $stmt->fetch() ?: null, 'response' => null, 'choices' => []];
    }

    $chosenClientId = null;
    if (is_array($pending)
        && ($pending['type'] ?? '') === $actionType
        && preg_match('/\bclient\s*[#°:]?\s*(\d+)\b/iu', $prompt, $clientMatch)) {
        $candidateId = (int) $clientMatch[1];
        if (in_array($candidateId, array_map('intval', (array) ($pending['client_ids'] ?? [])), true)) {
            $chosenClientId = $candidateId;
        }
    }

    if ($chosenClientId) {
        $stmt = db()->prepare('SELECT id,first_name,last_name,fiche_number,phone,120 AS match_score FROM clients WHERE id=?');
        $stmt->execute([$chosenClientId]);
        $clients = array_filter([$stmt->fetch()]);
    } else {
        $clients = assistant_named_client_candidates($prompt);
    }

    $hasFullName = count($clients) === 1 && (int) ($clients[0]['match_score'] ?? 0) >= 100;
    if (!$hasFullName) {
        $_SESSION['assistant_pending_command'] = [
            'prompt' => $prompt,
            'type' => $actionType,
            'client_ids' => array_map(static fn(array $client): int => (int) $client['id'], $clients),
        ];
        if (count($clients) > 1 && (int) ($clients[0]['match_score'] ?? 0) >= 100) {
            return [
                'dossier' => null,
                'response' => assistant_client_choices($clients),
                'choices' => assistant_client_choice_data($clients),
            ];
        }
        return [
            'dossier' => null,
            'response' => 'Indiquez obligatoirement le nom ET le prénom du client avant de modifier un dossier. Exemple : « pour Nadia Dupont ».',
            'choices' => [],
        ];
    }

    $client = $clients[0];
    $stmt = db()->prepare(
        "SELECT d.*,c.first_name,c.last_name FROM dossiers d JOIN clients c ON c.id=d.client_id
         WHERE d.client_id=? AND d.folder_status NOT IN ('cloture','annule')
         ORDER BY d.updated_at DESC,d.id DESC"
    );
    $stmt->execute([(int) $client['id']]);
    $dossiers = $stmt->fetchAll();
    if ($dossiers === []) {
        unset($_SESSION['assistant_pending_command']);
        return [
            'dossier' => null,
            'response' => 'Aucun dossier actif ne correspond à ' . trim($client['first_name'] . ' ' . $client['last_name']) . '.',
            'choices' => [],
        ];
    }
    $choices = assistant_dossier_choice_data($dossiers);
    $_SESSION['assistant_pending_command'] = [
        'prompt' => $prompt,
        'type' => $actionType,
        'client_id' => (int) $client['id'],
        'dossier_ids' => array_map(static fn(array $dossier): int => (int) $dossier['id'], $dossiers),
        'choices' => $choices,
    ];
    return [
        'dossier' => null,
        'response' => 'J’ai trouvé ' . trim($client['first_name'] . ' ' . $client['last_name']) . ".\n\n" . assistant_dossier_choices($dossiers),
        'choices' => $choices,
    ];
}

function assistant_parse_amounts(string $prompt): array
{
    $amounts = [];
    if (preg_match_all('/(?:part\s+)?\b(RO|RC|RAC)\b\s*(?:à|a|de|=|:)?\s*([0-9]+(?:[.,][0-9]{1,2})?)/iu', $prompt, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = strtolower($match[1]) . '_amount';
            $amounts[$key] = round((float) str_replace(',', '.', $match[2]), 2);
        }
    }
    return $amounts;
}

function assistant_status_from_prompt(string $prompt): ?string
{
    $normalized = assistant_normalize($prompt);
    $map = [
        'PEC OK' => 'a_facturer',
        'PEC ACCEPTEE' => 'a_facturer',
        'A FACTURER' => 'a_facturer',
        'DEMANDE PEC' => 'demande_mutuelle',
        'DEMANDE MUTUELLE' => 'demande_mutuelle',
        'SAV' => 'sav',
        'DEROGATION' => 'derogation',
        'DEROG' => 'derogation',
        'EN COURS' => 'en_cours',
        'CLOTURE' => 'cloture',
        'TERMINE' => 'cloture',
        'BLOQUE' => 'bloque',
        'DEVIS' => 'devis',
        'PRET' => 'pret',
        'CLIENT PREVENU' => 'client_prevenu',
        'REMIS' => 'remis',
        'TELETRANSMIS' => 'teletransmis',
        'FACTURE' => 'facture',
    ];
    foreach ($map as $phrase => $status) {
        if (assistant_text_contains($normalized, $phrase)) {
            return $status;
        }
    }
    return null;
}

function assistant_create_dossier_draft(int $dossierId, int $userId, array $payload, string $reason): string
{
    $stmt = db()->prepare('SELECT * FROM dossiers WHERE id=?');
    $stmt->execute([$dossierId]);
    $dossier = $stmt->fetch();
    if (!$dossier) {
        throw new RuntimeException('Dossier introuvable.');
    }
    $allowed = [
        'ro_amount','rc_amount','rac_amount','total_amount','folder_status','mutual_status',
        'pec_sent_at','pec_response_at','next_action','priority',
    ];
    $safePayload = [];
    $original = [];
    foreach ($payload as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $safePayload[$key] = $value;
        $original[$key] = $dossier[$key] ?? null;
    }
    if ($safePayload === []) {
        throw new RuntimeException('Aucune modification exploitable.');
    }
    $safePayload['_assistant_reason'] = mb_substr($reason, 0, 500);
    $token = bin2hex(random_bytes(24));
    $minutes = max(10, min(240, (int) get_setting('assistant_draft_minutes', 60)));
    $expiresAt = (new DateTimeImmutable())->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
    $stmt = db()->prepare(
        'INSERT INTO assistant_action_drafts
         (token_hash,user_id,dossier_id,payload_json,original_json,base_updated_at,expires_at)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        hash('sha256', $token), $userId, $dossierId,
        json_encode($safePayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        json_encode($original, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $dossier['updated_at'], $expiresAt,
    ]);
    log_action('assistant_draft', (int) db()->lastInsertId(), 'creation', 'Action préparée pour ' . dossier_number($dossierId));
    return $token;
}

function assistant_load_draft(string $token, int $userId, ?int $dossierId = null): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT * FROM assistant_action_drafts
         WHERE token_hash=? AND user_id=? AND status="pending" LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token), $userId]);
    $draft = $stmt->fetch();
    if (!$draft || ($dossierId !== null && (int) $draft['dossier_id'] !== $dossierId)) {
        return null;
    }
    if (strtotime($draft['expires_at']) < time()) {
        db()->prepare("UPDATE assistant_action_drafts SET status='expired' WHERE id=?")->execute([$draft['id']]);
        return null;
    }
    $draft['payload'] = json_decode($draft['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $draft['original'] = json_decode($draft['original_json'], true, 512, JSON_THROW_ON_ERROR);
    $draft['token'] = $token;
    return $draft;
}

function assistant_mark_draft_applied(int $draftId): void
{
    $stmt = db()->prepare(
        "UPDATE assistant_action_drafts SET status='applied',applied_at=NOW()
         WHERE id=? AND status='pending'"
    );
    $stmt->execute([$draftId]);
}

function assistant_draft_matches_updated_at(array $draft, mixed $updatedAt): bool
{
    return (string) ($draft['base_updated_at'] ?? '') !== ''
        && (string) ($draft['base_updated_at'] ?? '') === (string) $updatedAt;
}

function assistant_draft_changes(array $draft): array
{
    $labels = [
        'ro_amount' => 'Part RO', 'rc_amount' => 'Part RC', 'rac_amount' => 'RAC', 'total_amount' => 'Total',
        'folder_status' => 'Statut dossier', 'mutual_status' => 'Statut mutuelle',
        'pec_sent_at' => 'PEC envoyée le', 'pec_response_at' => 'Réponse PEC le',
        'next_action' => 'Prochaine action', 'priority' => 'Priorité',
    ];
    $changes = [];
    foreach ($draft['payload'] as $key => $proposed) {
        if (!isset($labels[$key])) {
            continue;
        }
        $before = $draft['original'][$key] ?? null;
        $formatter = static function (string $field, mixed $value): string {
            if (str_ends_with($field, '_amount')) {
                return format_euros((float) $value);
            }
            if (in_array($field, ['folder_status','mutual_status','priority'], true)) {
                return status_label((string) $value);
            }
            if (in_array($field, ['pec_sent_at','pec_response_at'], true)) {
                return $value ? format_date((string) $value, true) : '—';
            }
            return trim((string) $value) !== '' ? (string) $value : '—';
        };
        $changes[] = [
            'field' => $key,
            'label' => $labels[$key],
            'before' => $formatter($key, $before),
            'after' => $formatter($key, $proposed),
        ];
    }
    return $changes;
}

function assistant_client_summary(int $clientId): string
{
    $stmt = db()->prepare('SELECT * FROM clients WHERE id=?');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) {
        return 'Client introuvable.';
    }
    try {
        $maskedNir = mask_nir(client_nir($client));
    } catch (Throwable) {
        $maskedNir = 'Non disponible';
    }
    $stmt = db()->prepare(
        'SELECT d.*,
          (SELECT COUNT(*) FROM tasks t WHERE t.dossier_id=d.id AND t.status IN ("a_faire","en_cours","reportee")) AS open_tasks,
          (SELECT COUNT(*) FROM tasks t WHERE t.dossier_id=d.id AND t.status IN ("a_faire","en_cours","reportee") AND t.due_at<NOW()) AS overdue_tasks,
          (SELECT go.status FROM glass_orders go WHERE go.dossier_id=d.id LIMIT 1) AS order_status
         FROM dossiers d WHERE d.client_id=? ORDER BY d.created_at DESC,d.id DESC'
    );
    $stmt->execute([$clientId]);
    $dossiers = $stmt->fetchAll();

    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(cr.remaining_amount),0)
         FROM credits cr WHERE cr.client_id=? OR EXISTS
         (SELECT 1 FROM credit_beneficiaries cb WHERE cb.credit_id=cr.id AND cb.client_id=?)'
    );
    $stmt->execute([$clientId, $clientId]);
    $availableCredit = (float) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT original_name,document_type,created_at FROM documents WHERE client_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$clientId]);
    $documents = $stmt->fetchAll();

    $name = trim($client['first_name'] . ' ' . $client['last_name']);
    $lines = [
        'Résumé complet de [' . $name . '](' . app_url('pages/client_view.php?id=' . $clientId) . ')',
        '',
        'Identité et contact',
        '- Fiche : ' . ($client['fiche_number'] ?: 'non renseignée'),
        '- Téléphone : ' . ($client['phone'] ?: 'non renseigné'),
        '- E-mail : ' . ($client['email'] ?: 'non renseigné'),
        '- Date de naissance : ' . ($client['birth_date'] ? format_date($client['birth_date']) : 'non renseignée'),
        '- NIR masqué : ' . $maskedNir,
        '- Régime : ' . ($client['social_security_scheme'] ?: 'non renseigné'),
        '- Mutuelle : ' . ($client['mutual_name'] ?: 'non renseignée') . ($client['membership_number'] ? ' · adhérent ' . $client['membership_number'] : ''),
        '- Droits mutuelle : ' . ($client['mutual_valid_from'] ? format_date($client['mutual_valid_from']) : '—') . ' au ' . ($client['mutual_valid_to'] ? format_date($client['mutual_valid_to']) : '—'),
        '- Avoir disponible : ' . format_euros($availableCredit),
    ];
    if (!empty($client['notes'])) {
        $lines[] = '- Notes opticien : ' . preg_replace('/\s+/', ' ', trim((string) $client['notes']));
    }
    $lines[] = '';
    $lines[] = 'Dossiers (' . count($dossiers) . ')';
    if ($dossiers === []) {
        $lines[] = '- Aucun dossier.';
    }
    foreach ($dossiers as $dossier) {
        $paymentStmt = db()->prepare(
            'SELECT payer,expected_amount,paid_amount,status FROM payments WHERE dossier_id=? ORDER BY FIELD(payer,"ro","rc","client"),id'
        );
        $paymentStmt->execute([$dossier['id']]);
        $paymentParts = [];
        foreach ($paymentStmt->fetchAll() as $payment) {
            $paymentParts[] = strtoupper($payment['payer'] === 'client' ? 'RAC' : $payment['payer'])
                . ' ' . format_euros($payment['paid_amount']) . '/' . format_euros($payment['expected_amount'])
                . ' (' . status_label($payment['status']) . ')';
        }
        $lines[] = '- [' . dossier_number((int) $dossier['id']) . '](' . app_url('pages/dossier_view.php?id=' . $dossier['id']) . ')'
            . ' · ' . status_label($dossier['folder_status']) . ' · ' . status_label($dossier['mutual_status'])
            . ' · total ' . format_euros($dossier['total_amount'])
            . ' · RO ' . format_euros($dossier['ro_amount']) . ' / RC ' . format_euros($dossier['rc_amount']) . ' / RAC ' . format_euros($dossier['rac_amount']);
        $lines[] = '  Paiements : ' . ($paymentParts ? implode(', ', $paymentParts) : 'aucun')
            . ' · tâches ' . (int) $dossier['open_tasks']
            . ((int) $dossier['overdue_tasks'] > 0 ? ' dont ' . (int) $dossier['overdue_tasks'] . ' en retard' : '')
            . ($dossier['order_status'] ? ' · verrier ' . status_label($dossier['order_status']) : '');
        $lines[] = '  Prochaine action : ' . ($dossier['next_action'] ?: 'non définie');
        if (!empty($dossier['optician_comment'])) {
            $lines[] = '  Commentaire : ' . preg_replace('/\s+/', ' ', trim((string) $dossier['optician_comment']));
        }
    }
    $lines[] = '';
    $lines[] = 'Documents récents (' . count($documents) . ' affiché(s))';
    if (!$documents) {
        $lines[] = '- Aucun document enregistré.';
    }
    foreach ($documents as $document) {
        $lines[] = '- ' . $document['original_name'] . ' · ' . status_label($document['document_type']) . ' · ' . format_date($document['created_at']);
    }
    return implode("\n", $lines);
}

function assistant_prepare_amount_action(string $prompt, int $userId): array
{
    $amounts = assistant_parse_amounts($prompt);
    if ($amounts === []) {
        $_SESSION['assistant_pending_command'] = ['prompt' => $prompt, 'type' => 'amounts'];
        return ['response' => 'Indiquez au moins un montant, par exemple : « RO 120,09 €, RC 300 € et RAC 50 € ».', 'context_type' => null, 'context_id' => null];
    }
    $resolution = assistant_resolve_action_dossier($prompt, 'amounts');
    if (!$resolution['dossier']) {
        return [
            'response' => (string) ($resolution['response'] ?? 'Je ne trouve pas le client ou le dossier.'),
            'choices' => (array) ($resolution['choices'] ?? []),
            'context_type' => null,
            'context_id' => null,
        ];
    }
    $dossier = $resolution['dossier'];
    $newRo = array_key_exists('ro_amount', $amounts) ? $amounts['ro_amount'] : (float) $dossier['ro_amount'];
    $newRc = array_key_exists('rc_amount', $amounts) ? $amounts['rc_amount'] : (float) $dossier['rc_amount'];
    $newRac = array_key_exists('rac_amount', $amounts) ? $amounts['rac_amount'] : (float) $dossier['rac_amount'];
    $payload = $amounts + ['total_amount' => round($newRo + $newRc + $newRac, 2)];
    $token = assistant_create_dossier_draft((int) $dossier['id'], $userId, $payload, $prompt);
    unset($_SESSION['assistant_pending_command']);
    return [
        'response' => 'Les montants ont été préparés pour ' . dossier_number((int) $dossier['id']) . '. Vérifiez puis validez le formulaire.',
        'context_type' => 'dossier',
        'context_id' => (int) $dossier['id'],
        'redirect_url' => app_url('pages/dossier_edit.php?id=' . $dossier['id'] . '&assistant_draft=' . $token),
    ];
}

function assistant_prepare_status_action(string $prompt, int $userId, string $status): array
{
    $resolution = assistant_resolve_action_dossier($prompt, 'status');
    if (!$resolution['dossier']) {
        return [
            'response' => (string) ($resolution['response'] ?? 'Je ne trouve pas le client ou le dossier à modifier.'),
            'choices' => (array) ($resolution['choices'] ?? []),
            'context_type' => null,
            'context_id' => null,
        ];
    }
    $dossier = $resolution['dossier'];
    $payload = ['folder_status' => $status];
    if ($status === 'demande_mutuelle') {
        $payload += ['mutual_status' => 'envoyee', 'pec_sent_at' => date('Y-m-d H:i:s'), 'next_action' => 'Attendre puis vérifier la réponse mutuelle'];
    } elseif ($status === 'a_facturer') {
        $payload += ['mutual_status' => 'pec_acceptee', 'pec_response_at' => date('Y-m-d H:i:s'), 'next_action' => 'Facturer le dossier'];
    } elseif ($status === 'sav') {
        $payload['next_action'] = 'Dossier SAV en attente de résolution';
    }
    $token = assistant_create_dossier_draft((int) $dossier['id'], $userId, $payload, $prompt);
    unset($_SESSION['assistant_pending_command']);
    return [
        'response' => 'Le changement de statut vers « ' . status_label($status) . ' » est prêt. Vérifiez puis validez.',
        'context_type' => 'dossier',
        'context_id' => (int) $dossier['id'],
        'redirect_url' => app_url('pages/dossier_edit.php?id=' . $dossier['id'] . '&assistant_draft=' . $token),
    ];
}

function assistant_navigation(string $prompt): ?array
{
    $normalized = assistant_normalize($prompt);
    $routes = [
        'TABLEAU DE BORD' => 'pages/dashboard.php', 'A FACTURER' => 'pages/dossiers.php?view=to_invoice',
        'RELANCES' => 'pages/relances.php', 'PAIEMENTS' => 'pages/paiements.php', 'SAV' => 'pages/dossiers.php?view=sav',
        'COMMANDES VERRIER' => 'pages/ophtalmic.php', 'VERRIER' => 'pages/ophtalmic.php',
        'CLIENTS' => 'pages/clients.php', 'DOSSIERS' => 'pages/dossiers.php', 'PARAMETRES' => 'pages/settings.php',
        'DOCUMENTS' => 'pages/documents.php', 'MESSAGES' => 'pages/messages.php', 'AVOIRS' => 'pages/avoirs.php',
    ];
    if (str_contains($normalized, 'OUVR') || str_contains($normalized, 'AFFICH') || str_contains($normalized, 'VA SUR')) {
        $dossierId = assistant_explicit_dossier_id($prompt);
        if ($dossierId) {
            $stmt = db()->prepare('SELECT id FROM dossiers WHERE id=?');
            $stmt->execute([$dossierId]);
            if ($stmt->fetchColumn()) {
                return ['response' => 'Ouverture de ' . dossier_number($dossierId) . '.', 'redirect_url' => app_url('pages/dossier_view.php?id=' . $dossierId), 'context_type' => 'dossier', 'context_id' => $dossierId];
            }
        }
        $clients = assistant_client_candidates($prompt);
        if (count($clients) === 1) {
            return ['response' => 'Ouverture de la fiche client.', 'redirect_url' => app_url('pages/client_view.php?id=' . $clients[0]['id']), 'context_type' => 'client', 'context_id' => (int) $clients[0]['id']];
        }
        foreach ($routes as $phrase => $route) {
            if (assistant_text_contains($normalized, $phrase)) {
                return ['response' => 'Ouverture de la page demandée.', 'redirect_url' => app_url($route), 'context_type' => null, 'context_id' => null];
            }
        }
    }
    return null;
}

function assistant_handle_command(string $prompt, int $userId): array
{
    $prompt = trim(mb_substr($prompt, 0, 1500));
    if ($prompt === '') {
        return ['response' => 'Écrivez une demande.', 'context_type' => null, 'context_id' => null];
    }
    $pending = $_SESSION['assistant_pending_command'] ?? null;
    $isClientReference = (bool) preg_match('/\bclient\s*[#°:]?\s*\d+\b/iu', $prompt);
    $isBareClientId = is_array($pending)
        && ($pending['type'] ?? '') === 'summary'
        && preg_match('/^\s*#?(\d+)\s*$/', $prompt, $bareClientMatch);
    if ($isBareClientId) {
        $prompt = 'client #' . $bareClientMatch[1];
        $isClientReference = true;
    }
    $isActionFollowUp = is_array($pending) && in_array(($pending['type'] ?? ''), ['amounts','status'], true);
    $startsAnotherRequest = (bool) preg_match('/\b(?:r[ée]sum|synth[èe]se|ouvre|affiche|va\s+sur)\b/iu', $prompt);
    if (($isActionFollowUp && !$startsAnotherRequest)
        || (is_array($pending)
            && (assistant_explicit_dossier_id($prompt)
                || $isClientReference
                || (($pending['type'] ?? '') === 'amounts' && assistant_parse_amounts($prompt) !== [])))) {
        $prompt = (string) ($pending['prompt'] ?? '') . ' ' . $prompt;
    }
    $normalized = assistant_normalize($prompt);

    $isAmountAction = (str_contains($normalized, 'RO') || str_contains($normalized, 'RC') || str_contains($normalized, 'RAC'))
        && (str_contains($normalized, 'MODIF') || str_contains($normalized, 'CHANGE') || str_contains($normalized, 'MET') || assistant_parse_amounts($prompt) !== []);
    if ($isAmountAction) {
        return assistant_prepare_amount_action($prompt, $userId);
    }

    $status = assistant_status_from_prompt($prompt);
    if ($status && (str_contains($normalized, 'MET') || str_contains($normalized, 'PASSE') || str_contains($normalized, 'CHANGE') || str_contains($normalized, 'STATUT'))) {
        return assistant_prepare_status_action($prompt, $userId, $status);
    }

    if (preg_match('/\b(?:r[ée]sum|synth[èe]se)/iu', $prompt)) {
        $dossierId = assistant_explicit_dossier_id($prompt);
        if ($dossierId) {
            unset($_SESSION['assistant_pending_command']);
            return ['response' => assistant_folder_summary($dossierId), 'context_type' => 'dossier', 'context_id' => $dossierId];
        }
        $clients = assistant_client_candidates($prompt);
        if (count($clients) === 1) {
            unset($_SESSION['assistant_pending_command']);
            return ['response' => assistant_client_summary((int) $clients[0]['id']), 'context_type' => 'client', 'context_id' => (int) $clients[0]['id']];
        }
        $_SESSION['assistant_pending_command'] = ['prompt' => $prompt, 'type' => 'summary'];
        return [
            'response' => $clients ? assistant_client_choices($clients) : 'Je ne trouve pas ce client. Indiquez son nom complet, son numéro de fiche ou un dossier D-xxxxx.',
            'context_type' => null,
            'context_id' => null,
        ];
    }

    $navigation = assistant_navigation($prompt);
    if ($navigation) {
        unset($_SESSION['assistant_pending_command']);
        return $navigation;
    }

    return [
        'response' => "Je peux notamment :\n- résumer un client : « Résume Mme Dupont Moretti » ;\n- préparer des montants : « Mets RO 120 €, RC 300 € et RAC 50 € sur D-01234 » ;\n- préparer un statut : « Passe D-01234 en SAV » ;\n- ouvrir une fiche ou une page : « Ouvre les dossiers à facturer ».\n\nAucune modification n’est enregistrée sans validation dans le formulaire.",
        'context_type' => null,
        'context_id' => null,
    ];
}
