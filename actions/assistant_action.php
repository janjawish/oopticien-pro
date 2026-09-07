<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ai.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

if ((string) get_setting('ai_enabled', '1') !== '1') {
    flash('warning', 'L’assistant est désactivé.');
    redirect('pages/assistant.php');
}

$prompt = trim(post_string('prompt', 1500));
$preset = post_string('preset');
$dossierId = filter_input(INPUT_POST, 'dossier_id', FILTER_VALIDATE_INT) ?: null;
$allowedPresets = ['urgent','followups','payments','to_invoice','warnings','folder','ready_message','today'];

try {
    if ($prompt !== '') {
        $result = assistant_handle_command($prompt, (int) current_user()['id']);
        $presetKey = 'action_chat';
        $provider = 'local-actions';
    } else {
        if (!in_array($preset, $allowedPresets, true)) {
            $preset = 'today';
        }
        $prompt = $preset . ($dossierId ? ' dossier #' . $dossierId : '');
        $response = call_ai_provider($prompt, []) ?? run_local_assistant($preset, $dossierId);
        $result = [
            'response' => $response,
            'context_type' => $dossierId ? 'dossier' : null,
            'context_id' => $dossierId,
        ];
        $presetKey = $preset;
        $provider = (string) get_setting('ai_provider', 'local');
    }

    $response = (string) ($result['response'] ?? 'Action préparée.');
    $stmt = db()->prepare(
        'INSERT INTO ai_logs
         (user_id,preset_key,prompt,provider,input_summary,output_text,response,context_type,context_id,status)
         VALUES (?,?,?,?,?,?,?,?,?,"success")'
    );
    $stmt->execute([
        current_user()['id'], $presetKey, $prompt, $provider, 'Traitement local — aucune donnée transmise',
        $response, $response, $result['context_type'] ?? null, $result['context_id'] ?? null,
    ]);
    $logId = (int) db()->lastInsertId();
    log_action('ai_log', $logId, 'assistant_query', mb_substr($prompt, 0, 300));

    if (!empty($result['redirect_url']) && str_starts_with((string) $result['redirect_url'], app_url(''))) {
        header('Location: ' . $result['redirect_url']);
        exit;
    }
    redirect('pages/assistant.php?result=' . $logId);
} catch (Throwable $exception) {
    try {
        $stmt = db()->prepare(
            'INSERT INTO ai_logs (user_id,preset_key,prompt,provider,status,error_message)
             VALUES (?,"action_chat",?,"local-actions","failed",?)'
        );
        $stmt->execute([current_user()['id'], $prompt, mb_substr($exception->getMessage(), 0, 2000)]);
    } catch (Throwable) {
    }
    flash('danger', 'L’assistant n’a pas pu préparer cette demande. Aucune donnée n’a été modifiée.');
    redirect('pages/assistant.php');
}
