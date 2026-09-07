<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow_automation.php';
require_once __DIR__ . '/pdf_import.php';

function inbound_mail_config(): array
{
    $config = app_config()['inbound_mail'] ?? [];
    return is_array($config) ? $config : [];
}

function inbound_mail_status(): array
{
    $config = inbound_mail_config();
    $missing = [];
    if (!extension_loaded('imap')) {
        $missing[] = 'extension PHP IMAP';
    }
    foreach (['mailbox','username','password'] as $key) {
        if (trim((string) ($config[$key] ?? '')) === '') {
            $missing[] = $key;
        }
    }
    return [
        'enabled' => (bool) ($config['enabled'] ?? false),
        'ready' => (bool) ($config['enabled'] ?? false) && $missing === [],
        'missing' => $missing,
        'mailbox' => (string) ($config['mailbox'] ?? ''),
        'username' => (string) ($config['username'] ?? ''),
    ];
}

function inbound_decode_header(?string $value): string
{
    if (!$value) {
        return '';
    }
    $decoded = '';
    foreach (imap_mime_header_decode($value) ?: [] as $part) {
        $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
        $text = (string) ($part->text ?? '');
        if ($charset !== 'DEFAULT' && $charset !== 'UTF-8') {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            $text = $converted !== false ? $converted : $text;
        }
        $decoded .= $text;
    }
    return trim($decoded ?: $value);
}

function inbound_normalize_text(string $value): string
{
    $value = mb_strtoupper($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
    return ' ' . trim(preg_replace('/\s+/', ' ', $value) ?? $value) . ' ';
}

function inbound_sender_address(object $header): string
{
    $from = $header->from[0] ?? null;
    if (!$from) {
        return '';
    }
    return strtolower(trim((string) ($from->mailbox ?? '') . '@' . (string) ($from->host ?? ''), '@'));
}

function inbound_sender_is_allowed(string $sender, array $allowlist): bool
{
    if ($allowlist === []) {
        return true;
    }
    foreach ($allowlist as $allowed) {
        $allowed = strtolower(trim((string) $allowed));
        if ($allowed === '') {
            continue;
        }
        if ($sender === $allowed || (str_starts_with($allowed, '@') && str_ends_with($sender, $allowed))) {
            return true;
        }
    }
    return false;
}

function inbound_subject_is_delivery_note(string $subject, array $keywords): bool
{
    if ($keywords === []) {
        return true;
    }
    $subject = inbound_normalize_text($subject);
    foreach ($keywords as $keyword) {
        if (str_contains($subject, trim(inbound_normalize_text((string) $keyword)))) {
            return true;
        }
    }
    return false;
}

function inbound_part_filename(object $part): string
{
    foreach (['dparameters','parameters'] as $property) {
        foreach (($part->{$property} ?? []) as $parameter) {
            $attribute = strtolower((string) ($parameter->attribute ?? ''));
            if (in_array($attribute, ['filename','name'], true)) {
                return inbound_decode_header((string) ($parameter->value ?? ''));
            }
        }
    }
    return '';
}

function inbound_pdf_parts(object $structure, string $prefix = ''): array
{
    $results = [];
    $parts = $structure->parts ?? null;
    if (is_array($parts) && $parts !== []) {
        foreach ($parts as $index => $part) {
            $number = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);
            $results = array_merge($results, inbound_pdf_parts($part, $number));
        }
        return $results;
    }

    $filename = inbound_part_filename($structure);
    $subtype = strtoupper((string) ($structure->subtype ?? ''));
    if ($subtype === 'PDF' || str_ends_with(strtolower($filename), '.pdf')) {
        $results[] = [
            'number' => $prefix === '' ? '1' : $prefix,
            'encoding' => (int) ($structure->encoding ?? 0),
            'filename' => $filename !== '' ? $filename : 'bon-de-livraison.pdf',
        ];
    }
    return $results;
}

function inbound_decode_body(string $body, int $encoding): string
{
    return match ($encoding) {
        3 => base64_decode($body, true) ?: '',
        4 => quoted_printable_decode($body),
        default => $body,
    };
}

/**
 * Retourne un dossier seulement si l'identification est unique. L'ordre des
 * méthodes privilégie le numéro Oopticien, puis la référence verrier, puis le
 * nom complet du client.
 */
function match_delivery_note_to_dossier(string $text): array
{
    $candidateReasons = [];

    if (preg_match_all('/\bD[\s-]*0*(\d{1,8})\b/iu', $text, $matches)) {
        foreach (array_unique(array_map('intval', $matches[1])) as $id) {
            $stmt = db()->prepare(
                "SELECT id FROM dossiers
                 WHERE id=? AND folder_status='demande_mutuelle'
                   AND mutual_status IN ('envoyee','en_attente')"
            );
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) {
                $candidateReasons[$id][] = 'numéro de dossier';
            }
        }
    }

    $normalizedText = inbound_normalize_text($text);
    $orders = db()->query(
        "SELECT d.id,go.order_reference
         FROM glass_orders go JOIN dossiers d ON d.id=go.dossier_id
         WHERE d.folder_status='demande_mutuelle'
           AND d.mutual_status IN ('envoyee','en_attente')
           AND go.order_reference IS NOT NULL AND go.order_reference<>''"
    )->fetchAll();
    foreach ($orders as $order) {
        $reference = trim(inbound_normalize_text((string) $order['order_reference']));
        if (strlen(str_replace(' ', '', $reference)) >= 4 && str_contains($normalizedText, ' ' . $reference . ' ')) {
            $candidateReasons[(int) $order['id']][] = 'référence verrier';
        }
    }

    if ($candidateReasons === []) {
        $clients = db()->query(
            "SELECT d.id,c.first_name,c.last_name
             FROM dossiers d JOIN clients c ON c.id=d.client_id
             WHERE d.folder_status='demande_mutuelle'
               AND d.mutual_status IN ('envoyee','en_attente')"
        )->fetchAll();
        foreach ($clients as $client) {
            $firstLast = trim(inbound_normalize_text($client['first_name'] . ' ' . $client['last_name']));
            $lastFirst = trim(inbound_normalize_text($client['last_name'] . ' ' . $client['first_name']));
            if (($firstLast !== '' && str_contains($normalizedText, ' ' . $firstLast . ' '))
                || ($lastFirst !== '' && str_contains($normalizedText, ' ' . $lastFirst . ' '))) {
                $candidateReasons[(int) $client['id']][] = 'nom complet unique';
            }
        }
    }

    if (count($candidateReasons) !== 1) {
        return [
            'dossier_id' => null,
            'reason' => $candidateReasons === [] ? 'Aucun dossier en attente reconnu' : 'Plusieurs dossiers possibles',
            'candidate_count' => count($candidateReasons),
        ];
    }
    $dossierId = (int) array_key_first($candidateReasons);
    return [
        'dossier_id' => $dossierId,
        'reason' => implode(' + ', array_unique($candidateReasons[$dossierId])),
        'candidate_count' => 1,
    ];
}

function inbound_delivery_note_exists(string $uid, string $sha256): bool
{
    $stmt = db()->prepare('SELECT id FROM inbound_delivery_notes WHERE message_uid=? AND attachment_sha256=? LIMIT 1');
    $stmt->execute([$uid, $sha256]);
    return (bool) $stmt->fetchColumn();
}

function inbound_delivery_note_record(array $data): void
{
    $stmt = db()->prepare(
        'INSERT INTO inbound_delivery_notes
         (message_uid,message_id,sender,subject,attachment_name,attachment_sha256,dossier_id,
          processing_status,match_reason,error_message,received_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $data['message_uid'], $data['message_id'] ?: null, $data['sender'] ?: null,
        $data['subject'] ?: null, $data['attachment_name'] ?: null, $data['attachment_sha256'],
        $data['dossier_id'] ?: null, $data['processing_status'], $data['match_reason'] ?: null,
        $data['error_message'] ?: null, $data['received_at'] ?: null,
    ]);
}

function process_inbound_delivery_mail(): array
{
    $status = inbound_mail_status();
    $stats = ['emails' => 0, 'attachments' => 0, 'accepted' => 0, 'review' => 0, 'errors' => 0, 'duplicates' => 0];
    $now = date('Y-m-d H:i:s');
    automation_setting_write('inbound_mail_last_run_at', $now, 'Dernière lecture de la boîte des bons de livraison');
    if (!$status['enabled']) {
        return $stats + ['skipped' => 'Lecture IMAP désactivée'];
    }
    if (!$status['ready']) {
        $message = 'Configuration incomplète : ' . implode(', ', $status['missing']);
        automation_setting_write('inbound_mail_last_error', $message, 'Dernière erreur de lecture de la boîte des bons de livraison');
        return $stats + ['error' => $message];
    }

    $config = inbound_mail_config();
    $stream = @imap_open(
        (string) $config['mailbox'],
        (string) $config['username'],
        (string) $config['password'],
        0,
        1,
        ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
    );
    if ($stream === false) {
        $message = imap_last_error() ?: 'Connexion IMAP impossible';
        automation_setting_write('inbound_mail_last_error', $message, 'Dernière erreur de lecture de la boîte des bons de livraison');
        return $stats + ['error' => $message];
    }

    try {
        $lookbackDays = max(1, min(90, (int) ($config['lookback_days'] ?? 14)));
        $criteria = 'UNSEEN SINCE "' . date('d-M-Y', strtotime('-' . $lookbackDays . ' days')) . '"';
        $messages = imap_search($stream, $criteria, SE_FREE, 'UTF-8') ?: [];
        rsort($messages, SORT_NUMERIC);
        $messages = array_slice($messages, 0, max(1, min(100, (int) ($config['max_messages_per_run'] ?? 30))));
        $allowlist = array_values(array_filter((array) ($config['sender_allowlist'] ?? []), 'is_string'));
        $keywords = array_values(array_filter((array) ($config['subject_keywords'] ?? ['bon de livraison','livraison']), 'is_string'));
        $maxBytes = max(1, (int) ($config['max_attachment_mb'] ?? 10)) * 1024 * 1024;

        foreach ($messages as $messageNumber) {
            $header = imap_headerinfo($stream, $messageNumber);
            if (!$header) {
                continue;
            }
            $sender = inbound_sender_address($header);
            $subject = inbound_decode_header((string) ($header->subject ?? ''));
            if (!inbound_sender_is_allowed($sender, $allowlist)
                || !inbound_subject_is_delivery_note($subject, $keywords)) {
                continue;
            }
            $structure = imap_fetchstructure($stream, $messageNumber);
            if (!$structure) {
                continue;
            }
            $pdfParts = inbound_pdf_parts($structure);
            if ($pdfParts === []) {
                continue;
            }
            $stats['emails']++;
            $uid = (string) (imap_uid($stream, $messageNumber) ?: $messageNumber);
            $messageId = trim((string) ($header->message_id ?? ''));
            $receivedAt = !empty($header->udate) ? date('Y-m-d H:i:s', (int) $header->udate) : null;

            foreach ($pdfParts as $part) {
                $body = imap_fetchbody($stream, $messageNumber, $part['number'], FT_PEEK) ?: '';
                $bytes = inbound_decode_body($body, (int) $part['encoding']);
                if ($bytes === '' || strlen($bytes) > $maxBytes) {
                    $stats['errors']++;
                    continue;
                }
                $sha256 = hash('sha256', $bytes);
                if (inbound_delivery_note_exists($uid, $sha256)) {
                    $stats['duplicates']++;
                    continue;
                }
                $stats['attachments']++;
                $record = [
                    'message_uid' => $uid,
                    'message_id' => $messageId,
                    'sender' => $sender,
                    'subject' => $subject,
                    'attachment_name' => $part['filename'],
                    'attachment_sha256' => $sha256,
                    'dossier_id' => null,
                    'processing_status' => 'a_verifier',
                    'match_reason' => '',
                    'error_message' => '',
                    'received_at' => $receivedAt,
                ];
                $temporaryPath = tempnam(sys_get_temp_dir(), 'oopt-bl-');
                try {
                    if ($temporaryPath === false || file_put_contents($temporaryPath, $bytes, LOCK_EX) === false) {
                        throw new RuntimeException('Impossible de préparer la pièce jointe PDF.');
                    }
                    $text = extract_pdf_text_minimal($temporaryPath);
                    $match = match_delivery_note_to_dossier($text);
                    $record['dossier_id'] = $match['dossier_id'];
                    $record['match_reason'] = $match['reason'];
                    if ($match['dossier_id'] && automation_accept_pec((int) $match['dossier_id'], $now, 'Bon de livraison reçu par e-mail : ' . $subject)) {
                        $record['processing_status'] = 'traite';
                        $stats['accepted']++;
                    } else {
                        $stats['review']++;
                    }
                } catch (Throwable $exception) {
                    $record['processing_status'] = 'erreur';
                    $record['error_message'] = mb_substr($exception->getMessage(), 0, 2000);
                    $stats['errors']++;
                } finally {
                    if ($temporaryPath !== false && is_file($temporaryPath)) {
                        @unlink($temporaryPath);
                    }
                }
                inbound_delivery_note_record($record);
            }
            if ((bool) ($config['mark_seen'] ?? true)) {
                imap_setflag_full($stream, (string) $messageNumber, '\\Seen');
            }
        }
        automation_setting_write('inbound_mail_last_error', '', 'Dernière erreur de lecture de la boîte des bons de livraison');
    } finally {
        imap_close($stream);
    }
    log_action('automation', null, 'inbound_mail_execute', json_encode($stats, JSON_UNESCAPED_UNICODE) ?: '');
    return $stats;
}
