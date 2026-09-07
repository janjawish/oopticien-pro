<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

require_once dirname(__DIR__) . '/includes/workflow_automation.php';
require_once dirname(__DIR__) . '/includes/inbound_mail.php';

$pdo = db();
$pdo->beginTransaction();
$checks = [];

try {
    $pdo->exec("INSERT INTO clients (first_name,last_name) VALUES ('TESTAUTO','PEC')");
    $clientId = (int) $pdo->lastInsertId();
    $insertDossier = $pdo->prepare(
        "INSERT INTO dossiers
         (client_id,mutual_status,folder_status,status_changed_at,pec_sent_at,teletrans_status,teletrans_date,
          ro_amount,rc_amount,total_amount,next_action)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );

    $insertDossier->execute([$clientId,'envoyee','demande_mutuelle','2026-07-24 09:00:00','2026-07-24 09:00:00','non',null,0,0,0,'Attendre']);
    $acceptId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO mutual_requests (dossier_id,status,sent_at) VALUES (?,'envoyee','2026-07-24 09:00:00')")->execute([$acceptId]);

    $insertDossier->execute([$clientId,'envoyee','demande_mutuelle','2026-07-29 09:00:00','2026-07-29 09:00:00','non',null,0,0,0,'Attendre']);
    $followupId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO mutual_requests (dossier_id,status,sent_at) VALUES (?,'envoyee','2026-07-29 09:00:00')")->execute([$followupId]);

    $insertDossier->execute([$clientId,'envoyee','sav','2026-07-20 09:00:00','2026-07-20 09:00:00','oui','2026-07-20',100,100,200,'SAV']);
    $savId = (int) $pdo->lastInsertId();

    $insertDossier->execute([$clientId,'pec_acceptee','teletransmis','2026-07-20 09:00:00','2026-07-18 09:00:00','oui','2026-07-20',100,0,100,'Paiement']);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payments (dossier_id,payer,expected_amount,paid_amount,status) VALUES (?,'ro',100,0,'attendu')")->execute([$paymentId]);

    $stats = run_workflow_automation(new DateTimeImmutable('2026-08-03 12:00:00'));

    $statusStmt = $pdo->prepare('SELECT folder_status,mutual_status FROM dossiers WHERE id=?');
    $statusStmt->execute([$acceptId]);
    $accepted = $statusStmt->fetch();
    $checks['PEC après 5 jours'] = $accepted['folder_status'] === 'a_facturer' && $accepted['mutual_status'] === 'pec_acceptee';

    $statusStmt->execute([$followupId]);
    $followed = $statusStmt->fetch();
    $checks['Suivi après 48 h'] = $followed['folder_status'] === 'demande_mutuelle' && $followed['mutual_status'] === 'en_attente';

    $statusStmt->execute([$savId]);
    $sav = $statusStmt->fetch();
    $checks['SAV suspendu'] = $sav['folder_status'] === 'sav' && $sav['mutual_status'] === 'envoyee';

    $paymentStatus = $pdo->query('SELECT status FROM payments WHERE dossier_id=' . $paymentId . " AND payer='ro'")->fetchColumn();
    $checks['Paiement RO en retard'] = $paymentStatus === 'retard';

    $match = match_delivery_note_to_dossier('BON DE LIVRAISON — dossier D-' . str_pad((string) $followupId, 5, '0', STR_PAD_LEFT));
    $checks['Lecture numéro dossier du bon'] = (int) ($match['dossier_id'] ?? 0) === $followupId;
    $checks['Compteurs moteur'] = $stats['pec_accepted'] >= 1 && $stats['mutual_followups'] >= 1 && $stats['payments_late'] >= 1;

    foreach ($checks as $label => $passed) {
        echo ($passed ? '[OK] ' : '[ÉCHEC] ') . $label . "\n";
    }
    $failed = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
    $pdo->rollBack();
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[ÉCHEC] ' . $exception->getMessage() . "\n");
    exit(1);
}
