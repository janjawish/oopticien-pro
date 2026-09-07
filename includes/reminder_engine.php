<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow_automation.php';

function run_reminder_generation(): array
{
    $workflow = run_workflow_automation();
    $created = 0;
    $folders = db()->query(
        "SELECT d.*,go.ready_at,go.client_notified_at
         FROM dossiers d
         LEFT JOIN glass_orders go ON go.dossier_id=d.id
         WHERE d.folder_status NOT IN ('cloture','annule','sav')"
    )->fetchAll();

    foreach ($folders as $folder) {
        $dossierId = (int) $folder['id'];
        $clientId = (int) $folder['client_id'];
        if ($folder['ready_at'] && !$folder['client_notified_at']) {
            $created += (int) create_task_if_not_exists(
                $dossierId,
                $clientId,
                'Prévenir le client : lunettes prêtes',
                'client_a_prevenir',
                $folder['ready_at'],
                'haute'
            );
        }
        if ($folder['client_notified_at'] && !in_array($folder['folder_status'], ['remis','cloture'], true)) {
            foreach ([1,2,3] as $level) {
                $days = (int) get_setting('client_not_come_followup_' . $level . '_days', [1=>3,2=>7,3=>14][$level]);
                $due = (new DateTime($folder['client_notified_at']))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
                $created += (int) create_task_if_not_exists(
                    $dossierId,
                    $clientId,
                    'Relance client non venu J+' . $days,
                    'client_non_venu',
                    $due,
                    $level === 3 ? 'critique' : 'haute'
                );
            }
        }
        if ((int) $folder['total_warning'] === 1) {
            $created += (int) create_task_if_not_exists(
                $dossierId,
                $clientId,
                'Incohérence montant dossier',
                'incoherence',
                date('Y-m-d H:i:s'),
                'critique'
            );
        }
    }
    $result = ['created' => $created, 'workflow' => $workflow];
    log_action('cron', null, 'generation_relances', json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
    return $result;
}
