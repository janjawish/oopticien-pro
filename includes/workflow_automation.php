<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

function automation_setting_write(string $key, string $value, string $description = 'État du moteur automatique'): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key,setting_value,description) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),description=VALUES(description)'
    );
    $stmt->execute([$key, $value, $description]);
}

function automation_latest_datetime(?string ...$values): ?string
{
    $latest = null;
    foreach ($values as $value) {
        if (!$value) {
            continue;
        }
        if ($latest === null || strtotime($value) > strtotime($latest)) {
            $latest = $value;
        }
    }
    return $latest;
}

/**
 * Valide une PEC de manière atomique et place immédiatement le dossier dans
 * « À facturer ». La fonction est partagée par le délai automatique et la
 * lecture des bons de livraison.
 */
function automation_accept_pec(int $dossierId, string $acceptedAt, string $source): bool
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT id,client_id,folder_status,mutual_status FROM dossiers WHERE id=? FOR UPDATE');
        $stmt->execute([$dossierId]);
        $dossier = $stmt->fetch();
        if (!$dossier || in_array($dossier['folder_status'], ['sav','cloture','annule'], true)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }
        if (!in_array($dossier['mutual_status'], ['envoyee','en_attente','pec_acceptee'], true)
            && !in_array($dossier['folder_status'], ['demande_mutuelle','pec_acceptee'], true)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE dossiers
             SET mutual_status='pec_acceptee',folder_status='a_facturer',
                 pec_response_at=COALESCE(pec_response_at,?),status_changed_at=?,
                 next_action='Facturer le dossier'
             WHERE id=?"
        );
        $stmt->execute([$acceptedAt, $acceptedAt, $dossierId]);

        try {
            $stmt = $pdo->prepare(
                "UPDATE mutual_requests
                 SET status='acceptee',response_received_at=COALESCE(response_received_at,?),
                     response_at=COALESCE(response_at,?)
                 WHERE dossier_id=? AND status IN ('envoyee','en_attente')"
            );
            $stmt->execute([$acceptedAt, $acceptedAt, $dossierId]);
        } catch (Throwable) {
            // Une base V1 sans table mutual_requests reste exploitable.
        }

        $stmt = $pdo->prepare(
            "UPDATE tasks SET status='terminee',completed_at=?
             WHERE dossier_id=? AND task_type='mutuelle'
               AND status IN ('a_faire','en_cours','reportee')"
        );
        $stmt->execute([$acceptedAt, $dossierId]);

        $invoiceDue = add_business_days($acceptedAt, (int) get_setting('invoice_followup_days_open', 3));
        create_task_if_not_exists(
            $dossierId,
            (int) $dossier['client_id'],
            'Facturer le dossier',
            'facturation',
            $invoiceDue,
            'haute'
        );

        log_action('dossier', $dossierId, 'pec_acceptee_automatique', $source);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Exécute les transitions de workflow et les contrôles de paiement.
 */
function run_workflow_automation(?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    $nowSql = $now->format('Y-m-d H:i:s');
    $stats = [
        'mutual_followups' => 0,
        'pec_accepted' => 0,
        'invoice_tasks' => 0,
        'payment_tasks' => 0,
        'payments_late' => 0,
        'tasks_closed' => 0,
    ];

    $followupHours = (int) get_setting('mutual_followup_hours_open', 48);
    $autoAcceptDays = (int) get_setting('pec_auto_accept_days_open', 5);
    $pending = db()->query(
        "SELECT d.id,d.client_id,d.folder_status,d.mutual_status,d.pec_sent_at,d.status_changed_at,
                mr.id AS request_id,mr.status AS request_status,mr.sent_at AS request_sent_at
         FROM dossiers d
         LEFT JOIN mutual_requests mr ON mr.dossier_id=d.id
         WHERE d.folder_status NOT IN ('sav','cloture','annule','bloque')
           AND d.mutual_status IN ('envoyee','en_attente')"
    )->fetchAll();

    foreach ($pending as $dossier) {
        $base = automation_latest_datetime(
            $dossier['pec_sent_at'] ?? null,
            $dossier['request_sent_at'] ?? null,
            $dossier['status_changed_at'] ?? null
        );
        if (!$base) {
            continue;
        }

        $followupAt = add_business_hours($base, $followupHours);
        if (strtotime($nowSql) >= strtotime($followupAt)) {
            $changed = false;
            if (($dossier['mutual_status'] ?? '') === 'envoyee') {
                $stmt = db()->prepare(
                    "UPDATE dossiers SET mutual_status='en_attente',folder_status='demande_mutuelle',
                     next_action='Vérifier la réponse mutuelle' WHERE id=? AND folder_status<>'sav'"
                );
                $stmt->execute([(int) $dossier['id']]);
                $changed = $stmt->rowCount() > 0;
            }
            if (!empty($dossier['request_id']) && ($dossier['request_status'] ?? '') === 'envoyee') {
                $stmt = db()->prepare("UPDATE mutual_requests SET status='en_attente' WHERE id=? AND status='envoyee'");
                $stmt->execute([(int) $dossier['request_id']]);
                $changed = $changed || $stmt->rowCount() > 0;
            }
            if ($changed) {
                $stats['mutual_followups']++;
            }
            create_task_if_not_exists(
                (int) $dossier['id'],
                (int) $dossier['client_id'],
                'Vérifier la réponse mutuelle',
                'mutuelle',
                $followupAt,
                'haute'
            );
        }

        $acceptAt = add_business_days($base, $autoAcceptDays);
        if (strtotime($nowSql) >= strtotime($acceptAt)
            && automation_accept_pec((int) $dossier['id'], $nowSql, 'Délai automatique de ' . $autoAcceptDays . ' jours ouvrés')) {
            $stats['pec_accepted']++;
        }
    }

    $invoiceFolders = db()->query(
        "SELECT id,client_id,pec_response_at,status_changed_at
         FROM dossiers
         WHERE folder_status='a_facturer' AND invoice_date IS NULL"
    )->fetchAll();
    foreach ($invoiceFolders as $dossier) {
        $base = automation_latest_datetime($dossier['pec_response_at'] ?? null, $dossier['status_changed_at'] ?? null) ?: $nowSql;
        $due = add_business_days($base, (int) get_setting('invoice_followup_days_open', 3));
        $stats['invoice_tasks'] += (int) create_task_if_not_exists(
            (int) $dossier['id'],
            (int) $dossier['client_id'],
            'Facturer le dossier',
            'facturation',
            $due,
            'haute'
        );
    }

    $payments = db()->query(
        "SELECT p.id,p.dossier_id,p.payer,p.status,p.expected_amount,p.paid_amount,
                d.client_id,d.invoice_date,d.teletrans_date,d.pec_response_at,d.status_changed_at
         FROM payments p
         JOIN dossiers d ON d.id=p.dossier_id
         WHERE p.payer IN ('ro','rc')
           AND p.status IN ('attendu','partiel','retard')
           AND p.expected_amount>p.paid_amount
           AND d.folder_status NOT IN ('sav','cloture','annule')"
    )->fetchAll();
    foreach ($payments as $payment) {
        $payer = $payment['payer'];
        $paymentBase = $payer === 'ro'
            ? ($payment['teletrans_date'] ?: null)
            : ($payment['teletrans_date'] ?: ($payment['invoice_date'] ?: ($payment['pec_response_at'] ?: null)));
        if (!$paymentBase) {
            continue;
        }
        $base = automation_latest_datetime($paymentBase, $payment['status_changed_at'] ?? null);
        $days = (int) get_setting($payer === 'ro' ? 'ro_payment_followup_days_open' : 'rc_payment_followup_days_open', $payer === 'ro' ? 7 : 10);
        $due = add_business_days($base, $days);
        $title = $payer === 'ro' ? 'Vérifier paiement RO' : 'Vérifier paiement RC';
        $type = $payer === 'ro' ? 'paiement_ro' : 'paiement_rc';
        $stats['payment_tasks'] += (int) create_task_if_not_exists(
            (int) $payment['dossier_id'],
            (int) $payment['client_id'],
            $title,
            $type,
            $due,
            'haute'
        );
        if (strtotime($nowSql) >= strtotime($due) && $payment['status'] !== 'retard') {
            $stmt = db()->prepare("UPDATE payments SET status='retard' WHERE id=? AND status IN ('attendu','partiel')");
            $stmt->execute([(int) $payment['id']]);
            $stats['payments_late'] += $stmt->rowCount();
        }
    }

    $stmt = db()->prepare(
        "UPDATE tasks t
         LEFT JOIN dossiers d ON d.id=t.dossier_id
         LEFT JOIN payments p ON p.dossier_id=t.dossier_id
             AND ((t.task_type='paiement_ro' AND p.payer='ro') OR (t.task_type='paiement_rc' AND p.payer='rc'))
         SET t.status='terminee',t.completed_at=?
         WHERE t.status IN ('a_faire','en_cours','reportee')
           AND (
             (t.task_type='facturation' AND (d.invoice_date IS NOT NULL OR d.folder_status IN ('facture','teletransmis','cloture','annule')))
             OR (t.task_type IN ('paiement_ro','paiement_rc') AND p.status IN ('encaisse','non_applicable'))
           )"
    );
    $stmt->execute([$nowSql]);
    $stats['tasks_closed'] = $stmt->rowCount();

    automation_setting_write('automation_last_run_at', $nowSql, 'Dernière exécution du moteur automatique');
    automation_setting_write('automation_last_summary', json_encode($stats, JSON_UNESCAPED_UNICODE) ?: '{}', 'Résumé de la dernière exécution automatique');
    log_action('automation', null, 'workflow_execute', json_encode($stats, JSON_UNESCAPED_UNICODE) ?: '');
    return $stats;
}
