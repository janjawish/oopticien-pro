<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin', 'patron']);
require_post();
verify_csrf();

function csv_header_key(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s*\([^)]*\)\s*$/u', '', $value) ?? $value;
    return mb_strtoupper($value, 'UTF-8');
}

function csv_amount(mixed $value): float
{
    $clean = preg_replace('/[^\d,.\-]/u', '', (string) $value) ?? '';
    if (str_contains($clean, ',') && str_contains($clean, '.')) {
        $clean = str_replace('.', '', $clean);
    }
    $clean = str_replace(',', '.', $clean);
    return is_numeric($clean) ? round((float) $clean, 2) : 0.0;
}

function csv_truthy(mixed $value): bool
{
    $normalized = csv_header_key((string) $value);
    return $normalized === '1'
        || preg_match('/^(?:OUI|YES|OK|FAIT|ENCAISS[ÉE]?)(?:\b| )/u', $normalized) === 1;
}

function csv_payment_method(mixed $value): ?string
{
    $value = csv_header_key((string) $value);
    return match (true) {
        str_contains($value, 'CB'), str_contains($value, 'CARTE') => 'cb',
        str_contains($value, 'CHEQ'), str_contains($value, 'CHÈQ') => 'cheque',
        str_contains($value, 'ESPE'), str_contains($value, 'ESPÈ') => 'especes',
        str_contains($value, 'VIREMENT') => 'virement',
        $value !== '' => 'autre',
        default => null,
    };
}

function csv_first_value(array $row, array $headers): string
{
    foreach ($headers as $header) {
        $value = trim((string) ($row[csv_header_key($header)] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function csv_pec_status(
    array $row,
    ?string $sentAt,
    ?string $responseAt,
    string $reference,
    string $businessEvidence = ''
): string
{
    $explicit = csv_header_key(csv_first_value($row, ['STATUT PEC', 'PEC STATUT', 'STATUT MUTUELLE']));
    if ($explicit !== '') {
        if (str_contains($explicit, 'NON ENVOY') || str_contains($explicit, 'A PREPAR')) {
            return 'non_envoyee';
        }
        if (str_contains($explicit, 'ACCEPT') || str_contains($explicit, 'ACCORD')) {
            return 'pec_acceptee';
        }
        if (str_contains($explicit, 'REFUS')) {
            return 'pec_refusee';
        }
        if (str_contains($explicit, 'INCOMPL') || str_contains($explicit, 'MANQU')) {
            return 'incomplete';
        }
        if (str_contains($explicit, 'ATTENTE')) {
            return 'en_attente';
        }
        if (str_contains($explicit, 'ENVOY')) {
            return 'envoyee';
        }
    }
    if ($responseAt) {
        return 'pec_acceptee';
    }
    if ($sentAt || $reference !== '') {
        return 'en_attente';
    }

    $evidence = csv_header_key($businessEvidence);
    if ($evidence !== '') {
        if (
            preg_match('/\bPEC\b.*\b(?:OK|ACCEPT|ACCORD)/u', $evidence) === 1
            || preg_match('/\b(?:OK|ACCEPT|ACCORD)\b.*\bPEC\b/u', $evidence) === 1
        ) {
            return 'pec_acceptee';
        }
        if (str_contains($evidence, 'REFUS')) {
            return 'pec_refusee';
        }
        if (
            preg_match('/\bPEC\b.*(?:INCOMPL|MANQU|PJ|PIECE)/u', $evidence) === 1
            || str_contains($evidence, 'ATT DE PJ')
        ) {
            return 'incomplete';
        }
        if (
            preg_match('/\bPEC\b.*(?:ATT|EN COURS|ENVOY)/u', $evidence) === 1
            || preg_match('/(?:ATT|EN COURS|ENVOY).*\bPEC\b/u', $evidence) === 1
        ) {
            return 'en_attente';
        }
    }

    return 'non_envoyee';
}

function csv_source_fingerprint(array $row): string
{
    foreach (['STATUT DOSSIER','TYPE DOSSIER','COULEUR SOURCE','LIGNE SOURCE','ANNEE DOSSIER'] as $derivedColumn) {
        unset($row[$derivedColumn]);
    }
    ksort($row);
    $normalized = [];
    foreach ($row as $key => $value) {
        $normalized[$key] = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value);
    }
    return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($normalized));
}

$stage = post_string('stage');
if ($stage === 'cancel') {
    unset($_SESSION['import_preview']);
    flash('success', 'La prévisualisation a été annulée.');
    redirect('pages/import_excel.php');
}

if ($stage === 'preview') {
    $file = $_FILES['import_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'Le fichier n’a pas pu être envoyé.');
        redirect('pages/import_excel.php');
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        flash('danger', 'Le fichier dépasse la limite de sécurité de 20 Mo.');
        redirect('pages/import_excel.php');
    }
    if (mb_strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        flash('danger', 'Exportez le fichier Excel au format CSV UTF-8 avant de l’importer.');
        redirect('pages/import_excel.php');
    }

    $handle = fopen($file['tmp_name'], 'rb');
    if (!$handle) {
        flash('danger', 'Impossible de lire le fichier.');
        redirect('pages/import_excel.php');
    }
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        flash('danger', 'Le fichier est vide.');
        redirect('pages/import_excel.php');
    }
    $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    rewind($handle);
    $rawHeaders = fgetcsv($handle, 0, $delimiter) ?: [];
    $headers = array_map('csv_header_key', $rawHeaders);
    $missing = array_diff(['NOM', 'PRÉNOM'], $headers);
    if ($missing) {
        fclose($handle);
        flash('danger', 'Colonnes obligatoires manquantes : ' . implode(', ', $missing));
        redirect('pages/import_excel.php');
    }

    $rows = [];
    $errors = [];
    $line = 1;
    while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line++;
        if (count(array_filter($values, static fn($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }
        $values = array_pad($values, count($headers), '');
        $row = array_combine($headers, array_slice($values, 0, count($headers)));
        if (!$row) {
            $errors[] = 'Ligne ' . $line . ' illisible';
            continue;
        }
        if (trim((string) ($row['NOM'] ?? '')) === '' || trim((string) ($row['PRÉNOM'] ?? '')) === '') {
            $errors[] = 'Ligne ' . $line . ' : nom ou prénom absent';
            continue;
        }
        $rows[] = $row;
    }
    fclose($handle);

    $_SESSION['import_preview'] = [
        'file_name' => basename($file['name']),
        'headers' => $headers,
        'rows' => $rows,
        'errors' => $errors,
    ];
    flash('success', 'Prévisualisation prête pour ' . count($rows) . ' ligne(s). Contrôlez les données avant validation.');
    redirect('pages/import_excel.php');
}

if ($stage !== 'import' || empty($_SESSION['import_preview'])) {
    flash('danger', 'Aucune prévisualisation à importer.');
    redirect('pages/import_excel.php');
}

@set_time_limit(0);
$preview = $_SESSION['import_preview'];
$pdo = db();
$success = 0;
$updated = 0;
$skipped = 0;
$errors = [];
$createdClients = 0;

foreach ($preview['rows'] as $index => $row) {
    try {
        $pdo->beginTransaction();
        $lastName = trim((string) ($row['NOM'] ?? ''));
        $firstName = trim((string) ($row['PRÉNOM'] ?? ''));
        $stmt = $pdo->prepare(
            'SELECT id FROM clients WHERE LOWER(last_name)=LOWER(?) AND LOWER(first_name)=LOWER(?) ORDER BY id LIMIT 1'
        );
        $stmt->execute([$lastName, $firstName]);
        $clientId = $stmt->fetchColumn();
        if (!$clientId) {
            $secu = trim((string) ($row['SECU'] ?? ''));
            $nir = normalize_nir($secu);
            $encryptedNir = strlen($nir) >= 13 ? encrypt_sensitive_value($nir) : null;
            $stmt = $pdo->prepare(
                'INSERT INTO clients (
                    first_name,last_name,social_security_number,social_security_number_encrypted,
                    social_security_scheme,mutual_name,created_by
                 ) VALUES (?,?,NULL,?,?,?,?)'
            );
            $stmt->execute([
                $firstName,
                $lastName,
                $encryptedNir,
                strlen($nir) >= 13 ? null : ($secu ?: null),
                trim((string) ($row['MUTUELLE/TP+TM'] ?? '')) ?: null,
                current_user()['id'],
            ]);
            $clientId = (int) $pdo->lastInsertId();
            $createdClients++;
        } else {
            $clientId = (int) $clientId;
        }

        $ro = csv_amount($row['PART RO'] ?? 0);
        $rc = csv_amount($row['PART RC'] ?? 0);
        $rac = csv_amount($row['RAC'] ?? 0);
        $total = csv_amount($row['TOTALE'] ?? 0);
        $warning = has_total_warning($ro, $rc, $rac, $total) ? 1 : 0;
        $devisValue = trim((string) ($row['DEVIS'] ?? ''));
        $quoteDate = excel_date_to_sql($devisValue);
        $comment = trim((string) ($row['COMMENTAIRE'] ?? ''));
        $invoiceValue = trim((string) ($row['FACTURE'] ?? ''));
        $invoiceDate = excel_date_to_sql($invoiceValue);
        $invoiceEvidence = csv_header_key(trim($invoiceValue . ' ' . $comment));
        $isInvoiced = $invoiceDate !== null
            || csv_truthy($invoiceValue)
            || str_contains($invoiceEvidence, 'FACTUR');
        if ($isInvoiced && !$invoiceDate) {
            $invoiceDate = excel_date_to_sql($comment);
        }
        $teletransValue = trim((string) ($row['TELETRANS'] ?? ''));
        $teleDate = excel_date_to_sql($teletransValue);
        $isTeletransmitted = $teleDate !== null || csv_truthy($teletransValue);
        $pecSentAt = excel_date_to_sql(csv_first_value($row, ['ENVOI PEC', 'DATE ENVOI PEC', 'PEC ENVOYÉE LE']));
        $pecResponseAt = excel_date_to_sql(csv_first_value($row, ['RÉPONSE PEC', 'DATE RÉPONSE PEC']));
        $pecReference = csv_first_value($row, ['RÉFÉRENCE PEC', 'REFERENCE PEC', 'N° PEC']);
        $mutualValue = trim((string) ($row['MUTUELLE/TP+TM'] ?? ''));
        $mutualStatus = csv_pec_status(
            $row,
            $pecSentAt,
            $pecResponseAt,
            $pecReference,
            trim($devisValue . ' ' . $comment)
        );
        if ($mutualStatus === 'pec_acceptee' && !$pecResponseAt && $quoteDate) {
            $pecResponseAt = $quoteDate;
        } elseif (in_array($mutualStatus, ['envoyee', 'en_attente', 'incomplete'], true) && !$pecSentAt && $quoteDate) {
            $pecSentAt = $quoteDate;
        }
        $prescriptionStatus = csv_truthy($row['ORDO'] ?? '') ? 'oui' : 'attente';
        $fingerprint = csv_source_fingerprint($row);
        $folderStatus = match (true) {
            $isTeletransmitted => 'teletransmis',
            $isInvoiced => 'facture',
            $mutualStatus === 'pec_acceptee' => 'a_facturer',
            in_array($mutualStatus, ['envoyee', 'en_attente', 'pec_refusee', 'incomplete'], true) => 'demande_mutuelle',
            $quoteDate !== null => 'devis',
            default => 'brouillon',
        };
        $explicitFolderStatus = trim((string) ($row['STATUT DOSSIER'] ?? ''));
        $allowedFolderStatuses = ['brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer','facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus','montage','pret','client_prevenu','remis','cloture','bloque','sav','derogation','annule'];
        if (in_array($explicitFolderStatus, $allowedFolderStatuses, true)) {
            $folderStatus = $explicitFolderStatus;
        }
        $dossierType = in_array(($row['TYPE DOSSIER'] ?? ''), ['lunettes','lentilles'], true)
            ? (string) $row['TYPE DOSSIER'] : 'lunettes';
        $nextAction = match (true) {
            $folderStatus === 'bloque' => 'Traiter le dossier problématique',
            $folderStatus === 'cloture' => null,
            $folderStatus === 'a_facturer' => 'Facturer le dossier',
            $isTeletransmitted => null,
            $isInvoiced => 'Télétransmettre la facture',
            $mutualStatus === 'pec_acceptee' => 'Facturer le dossier',
            in_array($mutualStatus, ['envoyee', 'en_attente'], true) => 'Vérifier la réponse mutuelle',
            $mutualStatus === 'incomplete' => 'Compléter la demande mutuelle',
            $mutualStatus === 'pec_refusee' => 'Traiter le refus mutuelle',
            $mutualValue !== '' => 'Préparer la demande mutuelle',
            default => null,
        };
        $paymentInputs = [
            ['ro', $ro, $row['PAIEMENT SECU'] ?? null, null],
            ['rc', $rc, $row['PAIEMENT RC'] ?? null, null],
            ['client', $rac, $row['PAIEMENT RAC'] ?? null, $row['TYPE DE PAIEMENT RAC'] ?? null],
        ];

        $stmt = $pdo->prepare('SELECT id FROM dossiers WHERE source_fingerprint=? LIMIT 1');
        $stmt->execute([$fingerprint]);
        $existingDossierId = $stmt->fetchColumn();
        if (!$existingDossierId) {
            $stmt = $pdo->prepare(
                'SELECT d.id FROM dossiers d
                 WHERE d.client_id=? AND d.source_fingerprint IS NULL
                   AND ro_amount=? AND rc_amount=? AND rac_amount=? AND total_amount=?
                   AND COALESCE(optician_comment,"")=?
                   AND EXISTS (
                       SELECT 1 FROM action_history h
                       WHERE h.entity_type="dossier" AND h.entity_id=d.id AND h.action="import_csv"
                   )
                 ORDER BY d.id LIMIT 1'
            );
            $stmt->execute([
                $clientId, $ro, $rc, $rac, $total, $comment,
            ]);
            $existingDossierId = $stmt->fetchColumn();
            if ($existingDossierId) {
                $pdo->prepare(
                    'UPDATE dossiers SET
                     dossier_type=?,prescription_status=?,mutual_status=?,folder_status=?,quote_date=?,
                     pec_sent_at=?,pec_response_at=?,pec_reference=?,invoice_date=?,
                     teletrans_status=?,teletrans_date=?,total_warning=?,next_action=?,
                     priority=?,source_origin="csv",source_fingerprint=?
                     WHERE id=?'
                )->execute([
                    $dossierType, $prescriptionStatus, $mutualStatus, $folderStatus, $quoteDate,
                    $pecSentAt ? $pecSentAt . ' 00:00:00' : null,
                    $pecResponseAt ? $pecResponseAt . ' 00:00:00' : null,
                    $pecReference ?: null, $invoiceDate,
                    $isTeletransmitted ? 'oui' : 'non',
                    $teleDate, $warning, $nextAction, $warning ? 'urgente' : 'normale',
                    $fingerprint, $existingDossierId,
                ]);
                $pdo->commit();
                $updated++;
                continue;
            }
        }
        if ($existingDossierId) {
            $mutations = 0;
            $stmt = $pdo->prepare(
                'UPDATE dossiers SET
                 dossier_type=?,prescription_status=?,mutual_status=?,folder_status=?,quote_date=?,
                 pec_sent_at=?,pec_response_at=?,pec_reference=?,invoice_date=?,
                 teletrans_status=?,teletrans_date=?,total_warning=?,next_action=?,
                 priority=?
                 WHERE id=?'
            );
            $stmt->execute([
                $dossierType, $prescriptionStatus, $mutualStatus, $folderStatus, $quoteDate,
                $pecSentAt ? $pecSentAt . ' 00:00:00' : null,
                $pecResponseAt ? $pecResponseAt . ' 00:00:00' : null,
                $pecReference ?: null, $invoiceDate,
                $isTeletransmitted ? 'oui' : 'non',
                $teleDate, $warning, $nextAction, $warning ? 'urgente' : 'normale',
                $existingDossierId,
            ]);
            $mutations += $stmt->rowCount();

            $paymentUpdateStmt = $pdo->prepare(
                'UPDATE payments SET
                 expected_amount=?,paid_amount=?,payment_date=?,payment_method=?,status=?
                 WHERE dossier_id=? AND payer=?'
            );
            foreach ($paymentInputs as [$payer, $amount, $paidValue, $methodValue]) {
                $date = excel_date_to_sql($paidValue);
                $isPaid = $date !== null || csv_truthy($paidValue);
                $paymentUpdateStmt->execute([
                    $amount,
                    $isPaid ? $amount : 0,
                    $date,
                    $payer === 'client' ? csv_payment_method($methodValue) : ($isPaid ? 'virement' : null),
                    $amount <= 0 ? 'non_applicable' : ($isPaid ? 'encaisse' : 'attendu'),
                    $existingDossierId,
                    $payer,
                ]);
                $mutations += $paymentUpdateStmt->rowCount();
            }
            $pdo->commit();
            if ($mutations > 0) {
                $updated++;
            } else {
                $skipped++;
            }
            continue;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO dossiers (
                client_id,dossier_type,prescription_status,mutual_status,folder_status,quote_date,
                pec_sent_at,pec_response_at,pec_reference,invoice_date,teletrans_status,teletrans_date,
                ro_amount,rc_amount,rac_amount,total_amount,total_warning,optician_comment,next_action,
                priority,source_origin,source_fingerprint,created_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"csv",?,?)'
        );
        $stmt->execute([
            $clientId, $dossierType, $prescriptionStatus, $mutualStatus, $folderStatus, $quoteDate,
            $pecSentAt ? $pecSentAt . ' 00:00:00' : null,
            $pecResponseAt ? $pecResponseAt . ' 00:00:00' : null,
            $pecReference ?: null, $invoiceDate,
            $isTeletransmitted ? 'oui' : 'non',
            $teleDate, $ro, $rc, $rac, $total, $warning, $comment ?: null, $nextAction,
            $warning ? 'urgente' : 'normale', $fingerprint, current_user()['id'],
        ]);
        $dossierId = (int) $pdo->lastInsertId();

        $paymentStmt = $pdo->prepare(
            'INSERT INTO payments (
                dossier_id,payer,expected_amount,paid_amount,payment_date,payment_method,status,created_by
             ) VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($paymentInputs as [$payer, $amount, $paidValue, $methodValue]) {
            $date = excel_date_to_sql($paidValue);
            $isPaid = $date !== null || csv_truthy($paidValue);
            $paid = $isPaid ? $amount : 0;
            $status = $amount <= 0 ? 'non_applicable' : ($isPaid ? 'encaisse' : 'attendu');
            $paymentStmt->execute([
                $dossierId, $payer, $amount, $paid, $date,
                $payer === 'client' ? csv_payment_method($methodValue) : ($date ? 'virement' : null),
                $status, current_user()['id'],
            ]);
        }
        $pdo->commit();
        log_action('dossier', $dossierId, 'import_csv', 'Dossier créé depuis ' . $preview['file_name']);
        if ($warning) {
            create_task_if_not_exists(
                $dossierId,
                $clientId,
                'Incohérence montant dossier',
                'incoherence',
                date('Y-m-d H:i:s'),
                'critique'
            );
        }
        $success++;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Ligne ' . ($index + 2) . ' : ' . mb_substr($exception->getMessage(), 0, 180);
    }
}

$report = $updated . " ancien(s) dossier(s) complété(s).\n"
    . $skipped . " ligne(s) déjà présentes ignorées.\n" . implode("\n", $errors);
$stmt = $pdo->prepare(
    'INSERT INTO imports (file_name,import_type,total_rows,success_rows,error_rows,report,created_by)
     VALUES (?,?,?,?,?,?,?)'
);
$stmt->execute([
    $preview['file_name'], 'csv', count($preview['rows']), $success, count($errors), trim($report), current_user()['id'],
]);
log_action(
    'import',
    (int) $pdo->lastInsertId(),
    'import_csv',
    $success . ' dossiers créés, ' . $updated . ' complétés, ' . $skipped . ' déjà présents, '
    . $createdClients . ' clients créés'
);
unset($_SESSION['import_preview']);
$message = $success . ' dossier(s) créé(s), ' . $updated . ' complété(s), ' . $skipped . ' déjà présent(s), '
    . $createdClients . ' nouveau(x) client(s), ' . count($errors) . ' erreur(s).';
flash($errors ? 'warning' : 'success', $message);
redirect('pages/import_excel.php');
