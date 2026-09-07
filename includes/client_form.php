<?php
$client = $client ?? [];
$isEdit = !empty($client['id']);
?>
<form class="card" method="post" action="<?= e(app_url('actions/client_save.php')) ?>" data-unsaved-warning>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>"><?php endif; ?>
    <section class="form-section">
        <h3>Identité</h3>
        <div class="form-grid form-grid-3">
            <label class="field"><span>Numéro de fiche</span><input name="fiche_number" maxlength="50" value="<?= e($client['fiche_number'] ?? '') ?>" placeholder="F-1004"></label>
            <label class="field"><span class="required">Prénom</span><input name="first_name" maxlength="100" required value="<?= e($client['first_name'] ?? '') ?>"></label>
            <label class="field"><span class="required">Nom</span><input name="last_name" maxlength="100" required value="<?= e($client['last_name'] ?? '') ?>"></label>
            <label class="field"><span>Date de naissance</span><input type="date" name="birth_date" value="<?= e($client['birth_date'] ?? '') ?>"></label>
        </div>
    </section>
    <section class="form-section">
        <h3>Coordonnées</h3>
        <div class="form-grid">
            <label class="field"><span>Téléphone</span><input type="tel" name="phone" maxlength="30" value="<?= e($client['phone'] ?? '') ?>" placeholder="06 00 00 00 00"></label>
            <label class="field"><span>E-mail</span><input type="email" name="email" maxlength="190" value="<?= e($client['email'] ?? '') ?>"></label>
            <label class="field field-full"><span>Adresse</span><textarea name="address"><?= e($client['address'] ?? '') ?></textarea></label>
        </div>
    </section>
    <section class="form-section">
        <h3>Assurance maladie et mutuelle</h3>
        <div class="form-grid">
            <label class="field">
                <span>Numéro de sécurité sociale (NIR)</span>
                <input name="social_security_number" maxlength="30" inputmode="numeric" value="" placeholder="<?= $isEdit ? e(mask_nir(client_nir($client))) : '' ?>" autocomplete="off">
                <small><?= $isEdit ? 'Laissez vide pour conserver le NIR actuel.' : 'Le numéro sera chiffré et masqué par défaut.' ?></small>
            </label>
            <label class="field"><span>Régime de sécurité sociale</span><input name="social_security_scheme" maxlength="100" value="<?= e($client['social_security_scheme'] ?? '') ?>"></label>
            <label class="field"><span>Assuré</span><input name="insured_name" maxlength="160" value="<?= e($client['insured_name'] ?? '') ?>"></label>
            <label class="field"><span>Taux de remboursement</span><input type="number" min="0" max="100" step="0.01" name="reimbursement_rate" value="<?= e((string)($client['reimbursement_rate'] ?? '')) ?>"></label>
            <label class="field"><span>Mutuelle</span><input name="mutual_name" maxlength="160" value="<?= e($client['mutual_name'] ?? '') ?>"></label>
            <label class="field"><span>Numéro d’adhérent</span><input name="membership_number" maxlength="100" value="<?= e($client['membership_number'] ?? '') ?>"></label>
            <label class="field"><span>Droits mutuelle du</span><input type="date" name="mutual_valid_from" value="<?= e($client['mutual_valid_from'] ?? '') ?>"></label>
            <label class="field"><span>Droits mutuelle au</span><input type="date" name="mutual_valid_to" value="<?= e($client['mutual_valid_to'] ?? '') ?>"></label>
        </div>
    </section>
    <section class="form-section">
        <h3>Notes internes</h3>
        <label class="field"><span>Commentaire</span><textarea name="notes" placeholder="Informations utiles pour l’équipe…"><?= e($client['notes'] ?? '') ?></textarea></label>
    </section>
    <div class="form-footer">
        <a class="btn btn-outline" href="<?= e($isEdit ? app_url('pages/client_view.php?id=' . $client['id']) : app_url('pages/clients.php')) ?>">Annuler</a>
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Enregistrer les modifications' : 'Créer le client' ?></button>
    </div>
</form>
