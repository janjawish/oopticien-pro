<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
require_post();
verify_csrf();

$boutiqueName = post_string('boutique_name', 160);
$mapsLink = post_string('google_maps_link', 500);
if ($boutiqueName === '') {
    flash('danger', 'Le nom de la boutique est obligatoire.');
    redirect('pages/settings.php');
}
if ($mapsLink !== '' && !filter_var($mapsLink, FILTER_VALIDATE_URL)) {
    flash('danger', 'Le lien Google Maps n’est pas valide.');
    redirect('pages/settings.php');
}

$stmt = db()->prepare(
    'INSERT INTO settings (setting_key,setting_value,description) VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
);
$stmt->execute(['boutique_name', $boutiqueName, 'Nom affiché dans les messages clients']);
$stmt->execute(['google_maps_link', $mapsLink, 'Lien vers la boutique']);
log_action('settings', null, 'mise_a_jour', 'Informations boutique mises à jour');
flash('success', 'Les informations de la boutique ont été enregistrées.');
redirect('pages/settings.php');
