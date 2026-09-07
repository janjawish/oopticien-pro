<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$pageTitle = 'Nouveau client';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Créer une fiche client</h2><p>Renseignez au minimum le prénom et le nom.</p></div></section>
<?php require dirname(__DIR__) . '/includes/client_form.php'; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
