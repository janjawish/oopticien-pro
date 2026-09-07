<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$clients = db()->query('SELECT id, first_name, last_name, phone FROM clients ORDER BY last_name, first_name')->fetchAll();
if (!$clients) {
    flash('warning', 'Créez d’abord un client avant d’ouvrir un dossier.');
    redirect('pages/client_add.php');
}
$pageTitle = 'Nouveau dossier';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Créer un dossier lunettes</h2><p>Centralisez le devis, la PEC, les montants et la commande verrier.</p></div></section>
<?php require dirname(__DIR__) . '/includes/dossier_form.php'; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
