<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';redirect_if_not_logged_in();$pageTitle='Aide';require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Procédures de la boutique</h2><p>Oopticien Pro pilote le suivi ; Cosium et les portails partenaires restent les outils de saisie et d’envoi officiels.</p></div></section>
<div class="help-grid">
<article class="card"><h2>1. Créer un client</h2><p>Clients → Nouveau client. Renseignez identité et coordonnées. Le NIR est chiffré et masqué ; laissez le champ vide lors d’une modification pour le conserver.</p><a href="<?= e(app_url('pages/client_add.php')) ?>">Créer un client →</a></article>
<article class="card"><h2>2. Créer un dossier</h2><p>Depuis la fiche client, créez un dossier, contrôlez RO + RC + RAC, puis définissez la prochaine action.</p><a href="<?= e(app_url('pages/dossiers.php')) ?>">Voir les dossiers →</a></article>
<article class="card"><h2>3. Importer Excel / CSV</h2><p>Réservé admin/patron. Téléchargez le modèle, conservez les en-têtes, importez puis relisez le rapport.</p><a href="<?= e(app_url('pages/import_excel.php')) ?>">Import Excel →</a></article>
<article class="card"><h2>4. Importer un PDF Cosium</h2><p>Déposez le PDF (10 Mo max), vérifiez les champs proposés et choisissez un client existant ou une création. Rien n’est créé avant validation.</p><a href="<?= e(app_url('pages/import_pdf.php')) ?>">Import PDF →</a></article>
<article class="card"><h2>5. Suivre une mutuelle</h2><p>Créez le suivi, cochez les pièces, ouvrez manuellement le portail, saisissez référence et dates. Une tâche de relance est prévue à 48 h.</p><a href="<?= e(app_url('pages/mutuelles.php')) ?>">Mutuelles →</a></article>
<article class="card"><h2>6. Suivre Ophtalmic</h2><p>Préparez les corrections et le produit, ouvrez E-Space, passez la commande manuellement puis reportez son numéro. « Lunettes prêtes » crée une tâche client.</p><a href="<?= e(app_url('pages/ophtalmic.php')) ?>">Commandes verrier →</a></article>
<article class="card"><h2>7. Traiter les relances</h2><p>Exécutez le générateur, ouvrez chaque dossier, effectuez l’action, puis terminez ou reportez la tâche.</p><a href="<?= e(app_url('pages/relances.php')) ?>">Relances →</a></article>
<article class="card"><h2>8. Sauvegarder</h2><p>Admin : créez une sauvegarde SQL et une archive documents + clé. Copiez-les sur un support externe. Testez la restauration hors production.</p><?php if(has_role('admin')):?><a href="<?= e(app_url('pages/backups.php')) ?>">Sauvegardes →</a><?php endif;?></article>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php';?>
