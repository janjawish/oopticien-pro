<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/reminder_engine.php';
require_once dirname(__DIR__) . '/includes/inbound_mail.php';
require_role(['admin','patron']);
require_post();
verify_csrf();

try {
    $reminders = run_reminder_generation();
    $mail = process_inbound_delivery_mail();
    flash(
        'success',
        'Automatisation terminée : '
        . (int) ($reminders['workflow']['pec_accepted'] ?? 0) . ' PEC acceptée(s), '
        . (int) ($reminders['created'] ?? 0) . ' relance(s), '
        . (int) ($mail['accepted'] ?? 0) . ' bon(s) de livraison traité(s).'
    );
} catch (Throwable $exception) {
    flash('danger', 'L’automatisation a rencontré une erreur : ' . $exception->getMessage());
}
redirect('pages/settings.php');
