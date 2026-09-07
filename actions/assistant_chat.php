<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ai.php';

header('Content-Type: application/json; charset=utf-8');

function assistant_chat_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    assistant_chat_json(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}
if (!current_user()) {
    assistant_chat_json(['ok' => false, 'error' => 'Votre session a expiré. Rechargez la page.'], 401);
}
$csrf = (string) ($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    assistant_chat_json(['ok' => false, 'error' => 'Votre session a expiré. Rechargez la page.'], 419);
}
if ((string) get_setting('ai_enabled', '1') !== '1') {
    assistant_chat_json(['ok' => false, 'error' => 'L’assistant est désactivé.'], 403);
}

$prompt = trim(post_string('prompt', 1500));
if ($prompt === '') {
    assistant_chat_json(['ok' => false, 'error' => 'Écrivez une demande.'], 422);
}

try {
    $result = assistant_handle_command($prompt, (int) current_user()['id']);
    $response = (string) ($result['response'] ?? 'Demande traitée.');
    $stmt = db()->prepare(
        'INSERT INTO ai_logs
         (user_id,preset_key,prompt,provider,input_summary,output_text,response,context_type,context_id,status)
         VALUES (?,"action_chat",?,"local-actions",?,?,?,?,?,"success")'
    );
    $stmt->execute([
        current_user()['id'], $prompt, 'Traitement local — aucune donnée transmise',
        $response, $response, $result['context_type'] ?? null, $result['context_id'] ?? null,
    ]);
    $logId = (int) db()->lastInsertId();
    log_action('ai_log', $logId, 'assistant_query', mb_substr($prompt, 0, 300));

    $redirectUrl = (string) ($result['redirect_url'] ?? '');
    if ($redirectUrl !== '' && !str_starts_with($redirectUrl, app_url(''))) {
        $redirectUrl = '';
    }
    assistant_chat_json([
        'ok' => true,
        'response_html' => render_assistant_response($response),
        'choices' => array_values((array) ($result['choices'] ?? [])),
        'redirect_url' => $redirectUrl,
    ]);
} catch (Throwable $exception) {
    try {
        $stmt = db()->prepare(
            'INSERT INTO ai_logs (user_id,preset_key,prompt,provider,status,error_message)
             VALUES (?,"action_chat",?,"local-actions","failed",?)'
        );
        $stmt->execute([current_user()['id'], $prompt, mb_substr($exception->getMessage(), 0, 2000)]);
    } catch (Throwable) {
    }
    assistant_chat_json([
        'ok' => false,
        'error' => 'L’assistant n’a pas pu préparer cette demande. Aucune donnée n’a été modifiée.',
    ], 500);
}
