<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ai.php';
redirect_if_not_logged_in();

$stmt = db()->prepare(
    'SELECT * FROM ai_logs WHERE user_id=? AND preset_key="action_chat" AND status="success"
     ORDER BY id DESC LIMIT 16'
);
$stmt->execute([current_user()['id']]);
$conversation = array_reverse($stmt->fetchAll());
$presets = [
    'today' => 'Priorités du jour', 'to_invoice' => 'Dossiers à facturer', 'urgent' => 'Dossiers urgents',
    'followups' => 'Relances à faire', 'payments' => 'Paiements à contrôler', 'warnings' => 'Montants incohérents',
];
$pageTitle = 'Assistant d’action';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy"><h2>Assistant d’action</h2><p>Demandez une synthèse ou préparez une modification en langage naturel.</p></div>
    <span class="badge badge-success">Traitement local</span>
</section>

<div class="assistant-layout">
    <section class="card assistant-chat-card">
        <div class="card-header"><div><h2>Conversation</h2><p>Aucune donnée n’est modifiée sans votre validation.</p></div></div>
        <div class="assistant-conversation" aria-live="polite">
            <?php if (!$conversation): ?>
                <div class="assistant-welcome">
                    <span>✦</span><div><strong>Que puis-je préparer ?</strong><p>Essayez « Résume Nadia Dupont » ou « Modifie le RO, le RC et le RAC pour Nadia Dupont ».</p></div>
                </div>
            <?php endif; ?>
            <?php foreach ($conversation as $message): ?>
                <article class="chat-message chat-user"><div><small>Vous · <?= e(format_date($message['created_at'],true)) ?></small><p><?= nl2br(e($message['prompt'])) ?></p></div></article>
                <article class="chat-message chat-assistant"><span>✦</span><div><small>Assistant</small><div class="answer-text"><?= render_assistant_response((string)($message['response'] ?: $message['output_text'])) ?></div></div></article>
            <?php endforeach; ?>
        </div>
        <form class="assistant-composer" method="post" action="<?= e(app_url('actions/assistant_action.php')) ?>">
            <?= csrf_field() ?>
            <label class="field field-full"><span class="sr-only">Votre demande</span><textarea name="prompt" maxlength="1500" required autofocus placeholder="Ex. Résume Mme Dupont Moretti…"></textarea></label>
            <button class="btn btn-primary" type="submit">Envoyer →</button>
        </form>
    </section>

    <aside class="assistant-sidebar">
        <section class="card">
            <div class="card-header"><div><h2>Exemples</h2><p>Commandes disponibles</p></div></div>
            <?php foreach ([
                'Résume Nadia Dupont',
                'Modifie le RO, le RC et le RAC pour Nadia Dupont',
                'Passe le dossier de Nadia Dupont en SAV',
                'Ouvre les dossiers à facturer',
            ] as $example): ?>
                <form method="post" action="<?= e(app_url('actions/assistant_action.php')) ?>" class="assistant-example-form"><?= csrf_field() ?><input type="hidden" name="prompt" value="<?= e($example) ?>"><button type="submit"><?= e($example) ?></button></form>
            <?php endforeach; ?>
        </section>
        <section class="card">
            <div class="card-header"><div><h2>Analyses rapides</h2></div></div>
            <?php foreach ($presets as $key => $label): ?>
                <form method="post" action="<?= e(app_url('actions/assistant_action.php')) ?>" class="assistant-example-form"><?= csrf_field() ?><input type="hidden" name="preset" value="<?= e($key) ?>"><button type="submit"><?= e($label) ?></button></form>
            <?php endforeach; ?>
        </section>
        <section class="card assistant-safety"><strong>Validation obligatoire</strong><p>L’assistant prépare et préremplit. L’opticien contrôle le client, le dossier et les valeurs avant d’enregistrer.</p></section>
    </aside>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
