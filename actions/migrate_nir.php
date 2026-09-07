<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
require_post();
verify_csrf();

if (post_string('confirmation') !== 'CHIFFRER' || configured_encryption_key() === null) {
    flash('danger', 'Migration refusée : confirmation ou clé de chiffrement manquante.');
    redirect('pages/security.php');
}

$pdo = db();
$count = 0;
try {
    $pdo->beginTransaction();
    $rows = $pdo->query(
        "SELECT id, social_security_number FROM clients
         WHERE social_security_number IS NOT NULL AND social_security_number <> '' FOR UPDATE"
    )->fetchAll();
    $update = $pdo->prepare(
        'UPDATE clients SET social_security_number_encrypted = ?, social_security_number = NULL WHERE id = ?'
    );
    foreach ($rows as $row) {
        $nir = normalize_nir((string) $row['social_security_number']);
        if ($nir === '') {
            continue;
        }
        $update->execute([encrypt_sensitive_value($nir), $row['id']]);
        $count++;
    }
    $pdo->commit();
    log_sensitive_access(null, 'nir_migration', $count . ' NIR chiffré(s)');
    log_action('client', null, 'nir_migration', $count . ' NIR chiffré(s)');
    flash('success', $count . ' NIR ont été chiffrés ; l’ancienne valeur en clair a été effacée.');
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', 'La migration des NIR a échoué et a été annulée.');
}
redirect('pages/security.php');

