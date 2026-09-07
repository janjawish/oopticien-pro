<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin', 'patron']);
require_post();
verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM pdf_imports WHERE id=? FOR UPDATE');
    $stmt->execute([$id]);
    $import = $stmt->fetch();
    if (!$import || $import['status'] === 'validated') {
        throw new RuntimeException('Import introuvable ou déjà validé.');
    }

    $data = json_decode((string) ($import['extracted_json'] ?: $import['parsed_data']), true) ?: [];
    $clientId = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT) ?: null;
    $firstName = post_string('first_name', 100);
    $lastName = post_string('last_name', 100);
    if (!$clientId && ($firstName === '' || $lastName === '')) {
        throw new RuntimeException('Le prénom et le nom sont obligatoires.');
    }
    $email = post_nullable('email');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Adresse e-mail invalide.');
    }

    $nir = normalize_nir(post_string('social_security_number', 30));
    $encryptedNir = $nir !== ''
        ? encrypt_sensitive_value($nir)
        : ($data['social_security_number_encrypted'] ?? null);
    $clientValues = [
        post_string('fiche_number', 50),
        $firstName,
        $lastName,
        post_nullable('birth_date'),
        post_string('phone', 30),
        $email ?: '',
        post_string('address', 1000),
        post_string('social_security_scheme', 100),
        post_string('insured_name', 160),
        post_nullable('reimbursement_rate'),
        post_string('mutual_name', 160),
        post_string('membership_number', 100),
        post_nullable('mutual_valid_from'),
        post_nullable('mutual_valid_to'),
        post_string('notes', 2000),
    ];

    if ($clientId) {
        $check = $pdo->prepare('SELECT id FROM clients WHERE id=? FOR UPDATE');
        $check->execute([$clientId]);
        if (!$check->fetch()) {
            throw new RuntimeException('Client de destination introuvable.');
        }
        $updateClient = $pdo->prepare(
            'UPDATE clients SET
             fiche_number=COALESCE(NULLIF(?,""),fiche_number),
             first_name=COALESCE(NULLIF(?,""),first_name),
             last_name=COALESCE(NULLIF(?,""),last_name),
             birth_date=COALESCE(?,birth_date),
             phone=COALESCE(NULLIF(?,""),phone),
             email=COALESCE(NULLIF(?,""),email),
             address=COALESCE(NULLIF(?,""),address),
             social_security_scheme=COALESCE(NULLIF(?,""),social_security_scheme),
             insured_name=COALESCE(NULLIF(?,""),insured_name),
             reimbursement_rate=COALESCE(?,reimbursement_rate),
             mutual_name=COALESCE(NULLIF(?,""),mutual_name),
             membership_number=COALESCE(NULLIF(?,""),membership_number),
             mutual_valid_from=COALESCE(?,mutual_valid_from),
             mutual_valid_to=COALESCE(?,mutual_valid_to),
             notes=COALESCE(NULLIF(?,""),notes),
             social_security_number=IF(? IS NULL,social_security_number,NULL),
             social_security_number_encrypted=COALESCE(?,social_security_number_encrypted)
             WHERE id=?'
        );
        $updateClient->execute([...$clientValues, $encryptedNir, $encryptedNir, $clientId]);
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO clients (
                fiche_number,first_name,last_name,birth_date,phone,email,address,
                social_security_number_encrypted,social_security_scheme,insured_name,reimbursement_rate,
                mutual_name,membership_number,mutual_valid_from,mutual_valid_to,notes,created_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $clientValues[0] ?: null, $clientValues[1], $clientValues[2], $clientValues[3],
            $clientValues[4] ?: null, $clientValues[5] ?: null, $clientValues[6] ?: null,
            $encryptedNir, $clientValues[7] ?: null, $clientValues[8] ?: null, $clientValues[9],
            $clientValues[10] ?: null, $clientValues[11] ?: null, $clientValues[12], $clientValues[13],
            $clientValues[14] ?: null, current_user()['id'],
        ]);
        $clientId = (int) $pdo->lastInsertId();
    }

    $dossierId = null;
    if (isset($_POST['create_dossier'])) {
        $dossierDate = post_nullable('dossier_date');
        $corrections = post_string('corrections', 2000);
        $folderStatus = $dossierDate ? 'devis' : 'brouillon';
        $insert = $pdo->prepare(
            'INSERT INTO dossiers (
                client_id,prescription_status,mutual_status,folder_status,quote_date,
                optician_comment,next_action,source_origin,created_by
             ) VALUES (?,"attente","non_envoyee",?,?,?,?, "cosium_pdf",?)'
        );
        $insert->execute([
            $clientId,
            $folderStatus,
            $dossierDate,
            $corrections !== '' ? "Corrections lues dans Cosium — à vérifier :\n" . $corrections : null,
            'Vérifier le dossier importé depuis Cosium',
            current_user()['id'],
        ]);
        $dossierId = (int) $pdo->lastInsertId();
    }

    $fields = [
        'fiche_number','first_name','last_name','birth_date','email','phone','address',
        'social_security_scheme','insured_name','reimbursement_rate','mutual_name',
        'membership_number','mutual_valid_from','mutual_valid_to','notes',
    ];
    $map = $pdo->prepare(
        'INSERT INTO import_field_mapping (
            pdf_import_id,import_id,target_field,field_name,source_value,extracted_value,
            corrected_value,mapped_entity,mapped_field
         ) VALUES (?,?,?,?,?,?,?,"client",?)
         ON DUPLICATE KEY UPDATE corrected_value=VALUES(corrected_value),mapped_field=VALUES(mapped_field)'
    );
    foreach ($fields as $field) {
        $source = mb_substr((string) ($data[$field] ?? ''), 0, 1000);
        $corrected = mb_substr(post_string($field, 1000), 0, 1000);
        $map->execute([$id, $id, $field, $field, $source, $source, $corrected, $field]);
    }

    $update = $pdo->prepare(
        'UPDATE pdf_imports SET status="validated",client_id=?,dossier_id=?,validated_by=?,validated_at=NOW() WHERE id=?'
    );
    $update->execute([$clientId, $dossierId, current_user()['id'], $id]);
    $pdo->commit();
    log_action('pdf_import', (int) $id, 'pdf_import_validated', 'Import validé vers client #' . $clientId);
    flash('success', 'Les informations Cosium ont été enregistrées après votre validation.');
    redirect($dossierId ? 'pages/dossier_view.php?id=' . $dossierId : 'pages/client_view.php?id=' . $clientId);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', 'Validation impossible : ' . $exception->getMessage());
    redirect('pages/import_preview.php?id=' . $id);
}
