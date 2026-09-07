<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
$firstName = post_string('first_name', 100);
$lastName = post_string('last_name', 100);
$email = post_nullable('email');
$nir = normalize_nir(post_string('social_security_number', 30));

if ($firstName === '' || $lastName === '') {
    flash('danger', 'Le prénom et le nom sont obligatoires.');
    redirect($id ? 'pages/client_edit.php?id=' . $id : 'pages/client_add.php');
}
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('danger', 'L’adresse e-mail n’est pas valide.');
    redirect($id ? 'pages/client_edit.php?id=' . $id : 'pages/client_add.php');
}
if ($nir !== '' && (strlen($nir) < 13 || strlen($nir) > 15)) {
    flash('danger', 'Le NIR doit contenir entre 13 et 15 chiffres.');
    redirect($id ? 'pages/client_edit.php?id=' . $id : 'pages/client_add.php');
}

$legacyNir = null;
$encryptedNir = null;
if ($nir !== '') {
    try {
        $encryptedNir = encrypt_sensitive_value($nir);
    } catch (Throwable) {
        flash('danger', 'Le NIR n’a pas pu être chiffré. Vérifiez la configuration du stockage privé.');
        redirect($id ? 'pages/client_edit.php?id=' . $id : 'pages/client_add.php');
    }
}

$values = [
    post_nullable('fiche_number'), $firstName, $lastName, post_nullable('birth_date'), post_nullable('phone'), $email,
    post_nullable('address'), post_nullable('social_security_scheme'),
    post_nullable('insured_name'), post_nullable('reimbursement_rate'),
    post_nullable('mutual_name'), post_nullable('membership_number'),
    post_nullable('mutual_valid_from'), post_nullable('mutual_valid_to'), post_nullable('notes'),
];

try {
    if ($id) {
        $stmt = db()->prepare(
            'UPDATE clients SET fiche_number=?, first_name=?, last_name=?, birth_date=?, phone=?, email=?, address=?,
             social_security_scheme=?, insured_name=?, reimbursement_rate=?, mutual_name=?, membership_number=?,
             mutual_valid_from=?, mutual_valid_to=?, notes=?,
             social_security_number = CASE WHEN ? IS NULL THEN social_security_number ELSE NULL END,
             social_security_number_encrypted = COALESCE(?, social_security_number_encrypted)
             WHERE id=?'
        );
        $stmt->execute([...$values, $encryptedNir, $encryptedNir, $id]);
        log_action('client', $id, 'mise_a_jour', 'Mise à jour de la fiche client');
        flash('success', 'La fiche client a été mise à jour.');
    } else {
        $stmt = db()->prepare(
            'INSERT INTO clients (fiche_number, first_name, last_name, birth_date, phone, email, address,
             social_security_number, social_security_number_encrypted, social_security_scheme,
             insured_name, reimbursement_rate, mutual_name, membership_number,
             mutual_valid_from, mutual_valid_to, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $values[6],
            $legacyNir, $encryptedNir, $values[7], $values[8], $values[9], $values[10], $values[11],
            $values[12], $values[13], $values[14],
            current_user()['id'],
        ]);
        $id = (int) db()->lastInsertId();
        log_action('client', $id, 'creation', 'Création de la fiche client');
        flash('success', 'Le client a été créé.');
    }
    redirect('pages/client_view.php?id=' . $id);
} catch (PDOException $exception) {
    $message = $exception->getCode() === '23000'
        ? 'Ce numéro de fiche est déjà utilisé.'
        : 'Impossible d’enregistrer le client.';
    flash('danger', $message);
    redirect($id ? 'pages/client_edit.php?id=' . $id : 'pages/client_add.php');
}
