<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin','patron']);
$preview=$_SESSION['import_preview']??null;
$imports=db()->query('SELECT i.*,u.name user_name FROM imports i LEFT JOIN users u ON u.id=i.created_by ORDER BY i.created_at DESC LIMIT 10')->fetchAll();
$expected=['NOM','Prénom','ORDO','SECU','MUTUELLE/TP+TM','DEVIS','Part RO','Part RC','RAC','TOTALE','FACTURE','TELETRANS','PAIEMENT SECU','PAIEMENT RC','PAIEMENT RAC','TYPE DE PAIEMENT RAC','COMMENTAIRE','STATUT PEC','ENVOI PEC','RÉPONSE PEC','RÉFÉRENCE PEC'];
$pageTitle='Import Excel / CSV';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Importer les dossiers historiques</h2><p>Une ligne CSV crée un dossier lunettes et réutilise le client s’il existe.</p></div><div class="inline-actions"><a class="btn btn-outline" href="<?= e(app_url('actions/download_import_template.php')) ?>">↓ Télécharger le modèle</a><a class="btn btn-outline" href="<?= e(app_url('pages/guide.php#cosium')) ?>">Guide Cosium</a></div></section>
<div class="info-banner" style="margin-bottom:18px"><span>i</span><div><strong>Import CSV</strong><p>Depuis Excel : Fichier → Enregistrer sous → CSV UTF-8 (séparateur point-virgule). Les fichiers .xlsx nécessitent PhpSpreadsheet et ne sont pas traités sans cette bibliothèque.</p></div></div>

<?php if($preview): ?>
    <section class="card table-card" style="margin-bottom:18px">
        <div class="card-header">
            <div><h2>Prévisualisation · <?= e($preview['file_name']) ?></h2><p><?= count($preview['rows']) ?> ligne(s) prête(s), <?= count($preview['errors']) ?> erreur(s) détectée(s)</p></div>
            <div class="inline-actions">
                <form method="post" action="<?= e(app_url('actions/import_excel_action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="stage" value="cancel"><button class="btn btn-outline" type="submit">Annuler</button></form>
                <form method="post" action="<?= e(app_url('actions/import_excel_action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="stage" value="import"><button class="btn btn-primary" type="submit" <?= !$preview['rows']?'disabled':'' ?>>Valider l’import</button></form>
            </div>
        </div>
        <?php if($preview['errors']): ?><div class="alert alert-warning"><?= e(implode(' · ',array_slice($preview['errors'],0,5))) ?></div><?php endif; ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><?php foreach(array_slice($preview['headers'],0,17) as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach(array_slice($preview['rows'],0,20) as $index=>$row): ?><tr><td><?= $index+1 ?></td><?php foreach(array_slice($preview['headers'],0,17) as $header): ?><td><?= e($row[$header]??'') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if(count($preview['rows'])>20): ?><p class="muted" style="padding:0 20px 18px">Seules les 20 premières lignes sont affichées. Toutes les lignes valides seront importées.</p><?php endif; ?>
    </section>
<?php else: ?>
    <section class="card" style="margin-bottom:18px">
        <form method="post" action="<?= e(app_url('actions/import_excel_action.php')) ?>" enctype="multipart/form-data" data-unsaved-warning>
            <?= csrf_field() ?><input type="hidden" name="stage" value="preview">
            <div class="upload-zone">
                <div style="font-size:2rem;color:var(--blue)">⇧</div>
                <h2>Choisir un fichier CSV</h2>
                <p class="muted">CSV UTF-8, 20 Mo maximum. Toutes les lignes valides du fichier sont analysées.</p>
                <input type="file" name="import_file" accept=".csv,text/csv" required>
                <button class="btn btn-primary" type="submit">Analyser et prévisualiser</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<div class="content-grid">
    <section class="card">
        <div class="card-header"><div><h2>Colonnes attendues</h2><p>L’ordre recommandé du fichier source</p></div></div>
        <div class="code-block"><?= e(implode(' ; ',$expected)) ?></div>
        <p class="form-help" style="margin-top:12px">Les noms de colonnes ne tiennent pas compte des majuscules/minuscules. NOM et Prénom sont obligatoires. Les dates acceptées : JJ/MM/AAAA, JJ-MM-AAAA, AAAA-MM-JJ ou date numérique Excel.</p>
    </section>
    <section class="card">
        <div class="card-header"><div><h2>Règles appliquées</h2><p>Contrôles automatiques</p></div></div>
        <ul class="muted">
            <li>Recherche du client par nom et prénom.</li>
            <li>Création d’un nouveau dossier à chaque ligne.</li>
                        <li>Un nom de mutuelle seul ne classe plus la PEC « en attente ».</li>
                        <li>Les mentions explicites dans DEVIS (« PEC ok », « en attente », « refus », « pièce manquante ») déterminent le statut de la PEC.</li>
            <li>Réimport sans doublon des lignes déjà présentes.</li>
            <li>Création de 3 paiements : RO, RC et client.</li>
            <li>Alerte si TOTALE ≠ RO + RC + RAC.</li>
            <li>Conversion automatique des dates Excel.</li>
        </ul>
    </section>
</div>

<section class="card table-card" style="margin-top:18px">
    <div class="card-header"><div><h2>Derniers imports</h2><p>Rapports des 10 dernières opérations</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Fichier</th><th>Lignes</th><th>Réussies</th><th>Erreurs</th><th>Utilisateur</th></tr></thead><tbody>
    <?php foreach($imports as $import): ?><tr><td><?= e(format_date($import['created_at'],true)) ?></td><td><?= e($import['file_name']) ?></td><td><?= (int)$import['total_rows'] ?></td><td class="text-success"><?= (int)$import['success_rows'] ?></td><td class="<?= $import['error_rows']?'text-danger':'' ?>"><?= (int)$import['error_rows'] ?></td><td><?= e($import['user_name']?:'—') ?></td></tr><?php endforeach; ?>
    <?php if(!$imports): ?><tr><td colspan="6" class="empty-state"><strong>Aucun import effectué</strong></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
