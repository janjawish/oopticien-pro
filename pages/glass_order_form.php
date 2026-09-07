<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT);
if (!$dossierId) {
    flash('warning', 'Sélectionnez d’abord un dossier.');
    redirect('pages/dossiers.php');
}
redirect('pages/dossier_edit.php?id=' . $dossierId);

