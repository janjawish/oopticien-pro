<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
$editId=filter_input(INPUT_GET,'edit_id',FILTER_VALIDATE_INT)?:0;
$editUser=null;
if($editId){$stmt=db()->prepare('SELECT id,name,email,role,is_active FROM users WHERE id=?');$stmt->execute([$editId]);$editUser=$stmt->fetch();}
$users=db()->query('SELECT id,name,email,role,is_active,created_at,last_login_at FROM users ORDER BY is_active DESC,name')->fetchAll();
$pageTitle='Utilisateurs';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Comptes de l’équipe</h2><p>Créez les accès et attribuez les droits adaptés à chaque rôle.</p></div></section>
<div class="content-grid">
    <section class="card table-card">
        <div class="card-header"><div><h2><?= count($users) ?> utilisateur(s)</h2><p>Les comptes désactivés ne peuvent plus se connecter.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Utilisateur</th><th>Rôle</th><th>État</th><th>Dernière connexion</th><th>Créé le</th><th></th></tr></thead>
                <tbody>
                <?php foreach($users as $user): ?>
                    <tr>
                        <td><span class="cell-title"><?= e($user['name']) ?></span><span class="cell-subtitle"><?= e($user['email']) ?></span></td>
                        <td><?= status_badge($user['role']) ?></td>
                        <td><?= $user['is_active']?'<span class="badge badge-success">Actif</span>':'<span class="badge badge-danger">Désactivé</span>' ?></td>
                        <td><?= e(format_date($user['last_login_at'],true)) ?></td>
                        <td><?= e(format_date($user['created_at'])) ?></td>
                        <td><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/users.php?edit_id='.$user['id'])) ?>">Modifier</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <aside class="card" style="margin-top:0">
        <div class="card-header"><div><h2><?= $editUser?'Modifier le compte':'Nouveau compte' ?></h2><p><?= $editUser?'Laissez le mot de passe vide pour le conserver.':'Le mot de passe doit contenir au moins 9 caractères.' ?></p></div></div>
        <form method="post" action="<?= e(app_url('actions/user_save.php')) ?>" data-unsaved-warning>
            <?= csrf_field() ?>
            <?php if($editUser): ?><input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>"><?php endif; ?>
            <label class="field" style="margin-bottom:12px"><span class="required">Nom complet</span><input name="name" maxlength="120" required value="<?= e($editUser['name']??'') ?>"></label>
            <label class="field" style="margin-bottom:12px"><span class="required">E-mail</span><input type="email" name="email" maxlength="190" required value="<?= e($editUser['email']??'') ?>"></label>
            <label class="field" style="margin-bottom:12px"><span><?= $editUser?'Nouveau mot de passe':'Mot de passe' ?></span><input type="password" name="password" minlength="9" <?= $editUser?'':'required' ?> autocomplete="new-password"></label>
            <label class="field" style="margin-bottom:12px"><span>Rôle</span><select name="role"><?php foreach(['admin','patron','employe'] as $role): ?><option value="<?= e($role) ?>" <?= ($editUser['role']??'employe')===$role?'selected':'' ?>><?= e(status_label($role)) ?></option><?php endforeach; ?></select></label>
            <label class="field" style="display:flex;align-items:center;margin-bottom:18px"><input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto" <?= !isset($editUser['is_active'])||$editUser['is_active']?'checked':'' ?>> Compte actif</label>
            <div class="inline-actions">
                <?php if($editUser): ?><a class="btn btn-outline" href="<?= e(app_url('pages/users.php')) ?>">Annuler</a><?php endif; ?>
                <button class="btn btn-primary" type="submit"><?= $editUser?'Enregistrer':'Créer le compte' ?></button>
            </div>
        </form>
    </aside>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
