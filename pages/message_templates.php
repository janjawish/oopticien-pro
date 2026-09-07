<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin','patron']);
$templates=db()->query('SELECT * FROM message_templates ORDER BY event_key,channel')->fetchAll();
$pageTitle='Modèles de message';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Modèles et variables</h2><p>Variables disponibles : {{client_prenom}}, {{client_nom}}, {{dossier_numero}}, {{boutique_nom}}, {{date_rendez_vous}}, {{pieces_manquantes}}, {{message_libre}}.</p></div></section>
<form class="card" method="post" action="<?= e(app_url('actions/message_template_save.php')) ?>" data-testid="new-template-form" data-unsaved-warning>
<?= csrf_field() ?><div class="card-header"><div><h2>Nouveau modèle</h2><p>Un événement et un canal ne peuvent avoir qu’un seul modèle.</p></div></div>
<div class="form-grid form-grid-3"><label class="field"><span>Nom</span><input name="name" required></label><label class="field"><span>Canal</span><select name="channel"><?php foreach(['email','sms','whatsapp','appel','interne'] as $channel):?><option value="<?= e($channel) ?>"><?= e(status_label($channel)) ?></option><?php endforeach;?></select></label><label class="field"><span>Clé événement</span><input name="event_key" pattern="[a-z0-9_]+" placeholder="ex. controle_annuel" required></label><label class="field field-full"><span>Objet</span><input name="subject"></label><label class="field field-full"><span>Corps</span><textarea name="body" required></textarea></label><label class="check-row"><input type="checkbox" name="is_active" value="1" checked><span>Modèle actif</span></label></div><div class="form-footer"><button class="btn btn-primary" type="submit">Créer le modèle</button></div>
</form>
<?php foreach($templates as $template):?>
<form class="card" method="post" action="<?= e(app_url('actions/message_template_save.php')) ?>" data-unsaved-warning>
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$template['id'] ?>"><input type="hidden" name="event_key" value="<?= e($template['event_key']) ?>">
<div class="form-grid form-grid-3"><label class="field"><span>Nom</span><input name="name" value="<?= e($template['name']) ?>" required></label><label class="field"><span>Canal</span><select name="channel"><?php foreach(['email','sms','whatsapp','appel','interne'] as $channel):?><option value="<?= e($channel) ?>" <?= $template['channel']===$channel?'selected':'' ?>><?= e(status_label($channel)) ?></option><?php endforeach;?></select></label><label class="field"><span>Événement</span><input value="<?= e($template['event_key']) ?>" disabled></label><label class="field field-full"><span>Objet</span><input name="subject" value="<?= e($template['subject']??'') ?>"></label><label class="field field-full"><span>Corps</span><textarea name="body" required><?= e($template['content']?:$template['body']) ?></textarea></label><label class="check-row"><input type="checkbox" name="is_active" value="1" <?= $template['is_active']?'checked':'' ?>><span>Modèle actif</span></label></div>
<div class="form-footer"><button class="btn btn-primary" type="submit">Enregistrer ce modèle</button></div>
</form><?php endforeach;?>
<?php require dirname(__DIR__) . '/includes/footer.php';?>
