<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) {
    http_response_code(404);
    exit('Client introuvable.');
}
$pageTitle = 'Modifier le client';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2><?= e($client['first_name'] . ' ' . mb_strtoupper($client['last_name'])) ?></h2><p>Mettre à jour les informations de la fiche.</p></div></section>
<?php require dirname(__DIR__) . '/includes/client_form.php'; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
