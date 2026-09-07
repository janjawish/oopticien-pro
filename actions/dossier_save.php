<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/assistant_actions.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

function mysql_datetime_from_post(string $key): ?string
{
    $value = post_nullable($key);
    return $value ? str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '') : null;
}

function allowed_value(string $key, array $allowed, string $default): string
{
    $value = post_string($key);
    return in_array($value, $allowed, true) ? $value : $default;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
$assistantDraftToken = trim(post_string('assistant_draft_token', 48));
$assistantDraft = null;
if ($assistantDraftToken !== '') {
    if (!$id) {
        flash('danger', 'Une action de l’assistant ne peut viser qu’un dossier existant.');
        redirect('pages/dossiers.php');
    }
    $assistantDraft = assistant_load_draft($assistantDraftToken, (int) current_user()['id'], (int) $id);
    if (!$assistantDraft) {
        flash('warning', 'Cette proposition a expiré ou ne vous appartient pas. Aucune donnée n’a été modifiée.');
        redirect('pages/dossier_edit.php?id=' . $id);
    }
}
$clientId = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
$existsStmt = db()->prepare('SELECT id FROM clients WHERE id = ?');
$existsStmt->execute([$clientId]);
if (!$clientId || !$existsStmt->fetchColumn()) {
    flash('danger', 'Sélectionnez un client valide.');
    redirect($id ? 'pages/dossier_edit.php?id=' . $id : 'pages/dossier_add.php');
}

$ro = post_decimal('ro_amount');
$rc = post_decimal('rc_amount');
$rac = post_decimal('rac_amount');
$total = post_decimal('total_amount');
$warning = has_total_warning($ro, $rc, $rac, $total) ? 1 : 0;

$folderStatus = allowed_value('folder_status', ['brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer','facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus','montage','pret','client_prevenu','remis','cloture','bloque','sav','derogation','annule'], 'brouillon');
$mutualStatus = allowed_value('mutual_status', ['non_envoyee','envoyee','en_attente','pec_acceptee','pec_refusee','incomplete'], 'non_envoyee');
$priority = allowed_value('priority', ['basse','normale','haute','urgente'], 'normale');
$prescription = allowed_value('prescription_status', ['oui','non','attente'], 'attente');
$teletrans = allowed_value('teletrans_status', ['oui','non'], 'non');
$dossierType = allowed_value('dossier_type', ['lunettes','lentilles'], 'lunettes');
$invoiceDate = post_nullable('invoice_date');
$nextAction = post_nullable('next_action');
$pecSentAt = mysql_datetime_from_post('pec_sent_at');
$pecResponseAt = mysql_datetime_from_post('pec_response_at');

if ($folderStatus === 'demande_mutuelle') {
    if ($mutualStatus === 'non_envoyee') {
        $mutualStatus = 'envoyee';
    }
    $pecSentAt ??= date('Y-m-d H:i:s');
    $nextAction = 'Attendre puis vérifier la réponse mutuelle';
}
if ($folderStatus === 'sav') {
    $nextAction = 'Dossier SAV en attente de résolution';
}

if ($mutualStatus === 'pec_acceptee' && $invoiceDate === null
    && in_array($folderStatus, ['brouillon','devis','demande_mutuelle','pec_acceptee'], true)) {
    $folderStatus = 'a_facturer';
    $pecResponseAt ??= date('Y-m-d H:i:s');
    $nextAction = 'Facturer le dossier';
}

$previousFolderStatus = null;
$previousUpdatedAt = null;
if ($id) {
    $previousStmt = db()->prepare('SELECT folder_status,updated_at FROM dossiers WHERE id=?');
    $previousStmt->execute([$id]);
    $previousDossier = $previousStmt->fetch();
    $previousFolderStatus = $previousDossier['folder_status'] ?? null;
    $previousUpdatedAt = $previousDossier['updated_at'] ?? null;
}
if ($assistantDraft && !assistant_draft_matches_updated_at($assistantDraft, $previousUpdatedAt)) {
    flash('warning', 'Le dossier a changé depuis la proposition de l’assistant. Relancez la demande pour éviter d’écraser une modification récente.');
    redirect('pages/dossier_edit.php?id=' . $id);
}
$statusChangedAt = !$id || $previousFolderStatus !== $folderStatus ? date('Y-m-d H:i:s') : null;

$values = [
    $clientId, $dossierType, $prescription, $mutualStatus, $folderStatus, post_nullable('quote_date'),
    $pecSentAt, $pecResponseAt,
    post_nullable('pec_reference'), $invoiceDate, $teletrans, post_nullable('teletrans_date'),
    $ro, $rc, $rac, $total, $warning, post_nullable('optician_comment'),
    $nextAction, $priority,
];

$pdo = db();
$removedOrder = null;
try {
    $pdo->beginTransaction();
    if ($id) {
        $stmt = $pdo->prepare(
            'UPDATE dossiers SET client_id=?, dossier_type=?, prescription_status=?, mutual_status=?, folder_status=?,
             status_changed_at=COALESCE(?,status_changed_at), quote_date=?,
             pec_sent_at=?, pec_response_at=?, pec_reference=?, invoice_date=?, teletrans_status=?, teletrans_date=?,
             ro_amount=?, rc_amount=?, rac_amount=?, total_amount=?, total_warning=?, optician_comment=?, next_action=?, priority=?
             WHERE id=?'
        );
        $updateValues = $values;
        array_splice($updateValues, 5, 0, [$statusChangedAt]);
        $stmt->execute([...$updateValues, $id]);
        $action = 'mise_a_jour';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO dossiers (client_id, dossier_type, prescription_status, mutual_status, folder_status, status_changed_at, quote_date,
             pec_sent_at, pec_response_at, pec_reference, invoice_date, teletrans_status, teletrans_date,
             ro_amount, rc_amount, rac_amount, total_amount, total_warning, optician_comment, next_action, priority, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertValues = $values;
        array_splice($insertValues, 5, 0, [$statusChangedAt]);
        $stmt->execute([...$insertValues, current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        $action = 'creation';
    }

    foreach (['ro' => $ro, 'rc' => $rc, 'client' => $rac] as $payer => $amount) {
        $stmt = $pdo->prepare('SELECT id FROM payments WHERE dossier_id=? AND payer=? ORDER BY id LIMIT 1');
        $stmt->execute([$id, $payer]);
        $paymentId = $stmt->fetchColumn();
        if ($paymentId) {
            $stmt = $pdo->prepare('UPDATE payments SET expected_amount=? WHERE id=?');
            $stmt->execute([$amount, $paymentId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO payments (dossier_id, payer, expected_amount, status, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$id, $payer, $amount, $amount > 0 ? 'attendu' : 'non_applicable', current_user()['id']]);
        }
    }

    if (isset($_POST['with_order'])) {
        $orderStatus = allowed_value('order_status', ['a_preparer','envoyee','confirmee','en_fabrication','expediee','recue','montage','prete','incident','annulee'], 'a_preparer');
        foreach (['lens_right_axis', 'lens_left_axis'] as $axisField) {
            $axisValue = post_nullable($axisField);
            if ($axisValue !== null && ((int) $axisValue < 0 || (int) $axisValue > 180)) {
                throw new RuntimeException('Axe optique invalide.');
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO glass_orders (
                dossier_id, supplier_name, order_reference, status,
                lens_right_sphere, lens_right_cylinder, lens_right_axis, lens_right_addition,
                lens_left_sphere, lens_left_cylinder, lens_left_axis, lens_left_addition, pupillary_distance,
                lens_product, lens_index, treatment, tint, frame_reference, mounting_comment, ophtalmic_url,
                sent_at, expected_at, received_at, ready_at, client_notified_at, comment
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE supplier_name=VALUES(supplier_name), order_reference=VALUES(order_reference),
             lens_right_sphere=VALUES(lens_right_sphere), lens_right_cylinder=VALUES(lens_right_cylinder), lens_right_axis=VALUES(lens_right_axis), lens_right_addition=VALUES(lens_right_addition),
             lens_left_sphere=VALUES(lens_left_sphere), lens_left_cylinder=VALUES(lens_left_cylinder), lens_left_axis=VALUES(lens_left_axis), lens_left_addition=VALUES(lens_left_addition),
             pupillary_distance=VALUES(pupillary_distance), lens_product=VALUES(lens_product), lens_index=VALUES(lens_index),
             treatment=VALUES(treatment), tint=VALUES(tint), frame_reference=VALUES(frame_reference), mounting_comment=VALUES(mounting_comment),
             ophtalmic_url=VALUES(ophtalmic_url),
             status=VALUES(status), sent_at=VALUES(sent_at), expected_at=VALUES(expected_at), received_at=VALUES(received_at),
             ready_at=VALUES(ready_at), client_notified_at=VALUES(client_notified_at), comment=VALUES(comment)'
        );
        $stmt->execute([
            $id, post_string('supplier_name', 120) ?: 'Ophtalmic', post_nullable('order_reference'), $orderStatus,
            post_nullable('lens_right_sphere'), post_nullable('lens_right_cylinder'), post_nullable('lens_right_axis'), post_nullable('lens_right_addition'),
            post_nullable('lens_left_sphere'), post_nullable('lens_left_cylinder'), post_nullable('lens_left_axis'), post_nullable('lens_left_addition'),
            post_nullable('pupillary_distance'), post_nullable('lens_product'), post_nullable('lens_index'),
            post_nullable('treatment'), post_nullable('tint'), post_nullable('frame_reference'), post_nullable('mounting_comment'),
            app_config()['suppliers']['ophtalmic_portal_url'] ?? null,
            mysql_datetime_from_post('order_sent_at'), mysql_datetime_from_post('order_expected_at'),
            mysql_datetime_from_post('order_received_at'), mysql_datetime_from_post('order_ready_at'),
            mysql_datetime_from_post('client_notified_at'), post_nullable('order_comment'),
        ]);
    } elseif ($id) {
        $stmt = $pdo->prepare('SELECT id,order_reference,status FROM glass_orders WHERE dossier_id=?');
        $stmt->execute([$id]);
        $removedOrder = $stmt->fetch() ?: null;
        if ($removedOrder) {
            $stmt = $pdo->prepare('DELETE FROM glass_orders WHERE dossier_id=?');
            $stmt->execute([$id]);
            $stmt = $pdo->prepare(
                "DELETE FROM tasks
                 WHERE dossier_id=? AND task_type='client_a_prevenir'
                   AND status IN ('a_faire','en_cours','reportee')"
            );
            $stmt->execute([$id]);
        }
    }

    $pdo->commit();
    if ($assistantDraft) {
        assistant_mark_draft_applied((int) $assistantDraft['id']);
        log_action('assistant_draft', (int) $assistantDraft['id'], 'validation', 'Action validée sur ' . dossier_number((int) $id));
    }
    if ($folderStatus === 'sav' && $previousFolderStatus !== 'sav') {
        $stmt = db()->prepare(
            "UPDATE tasks SET status='annulee'
             WHERE dossier_id=? AND status IN ('a_faire','en_cours','reportee')"
        );
        $stmt->execute([$id]);
    }
    if ($folderStatus === 'demande_mutuelle' && $pecSentAt) {
        create_task_if_not_exists(
            $id,
            (int) $clientId,
            'Vérifier la réponse mutuelle',
            'mutuelle',
            add_business_hours($statusChangedAt ?: $pecSentAt, (int) get_setting('mutual_followup_hours_open', 48)),
            'haute'
        );
    }
    if ($folderStatus === 'a_facturer' && $pecResponseAt) {
        create_task_if_not_exists(
            $id,
            (int) $clientId,
            'Facturer le dossier',
            'facturation',
            add_business_days($statusChangedAt ?: $pecResponseAt, (int) get_setting('invoice_followup_days_open', 3)),
            'haute'
        );
    }
    log_action('dossier', $id, $action, $warning ? 'Dossier enregistré avec incohérence de montant' : 'Dossier enregistré');
    if ($removedOrder) {
        log_action(
            'glass_order',
            (int) $removedOrder['id'],
            'suppression',
            'Suivi verrier retiré du dossier '.dossier_number((int) $id)
        );
    }
    if ($warning) {
        create_task_if_not_exists($id, (int) $clientId, 'Incohérence montant dossier', 'incoherence', date('Y-m-d H:i:s'), 'critique');
        flash('warning', 'Dossier enregistré. Attention : le total déclaré ne correspond pas à RO + RC + RAC.');
    } elseif ($removedOrder) {
        flash('success', 'Le dossier a été mis à jour et le suivi verrier a été retiré.');
    } else {
        flash('success', $action === 'creation' ? 'Le dossier a été créé.' : 'Le dossier a été mis à jour.');
    }
    redirect('pages/dossier_view.php?id=' . $id);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', 'Impossible d’enregistrer le dossier. Vérifiez les valeurs saisies.');
    redirect($id ? 'pages/dossier_edit.php?id=' . $id : 'pages/dossier_add.php?client_id=' . $clientId);
}
