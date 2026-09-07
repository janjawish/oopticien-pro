<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$pageTitle = 'Guide complet';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="guide-hero">
    <div>
        <p class="eyebrow">Mode d’emploi Oopticien Pro</p>
        <h2>Le cockpit de la boutique, du devis à la remise des lunettes.</h2>
        <p>Cosium reste la source métier et le canal EDI. Oopticien Pro rassemble les actions à faire, les paiements, la commande verrier, les relances et les échanges avec le client.</p>
    </div>
    <div class="guide-flow" aria-label="Flux de travail">
        <span><strong>1</strong> Cosium<small>Dossier métier</small></span>
        <b>→</b>
        <span><strong>2</strong> Verrier<small>Commande EDI</small></span>
        <b>→</b>
        <span><strong>3</strong> Oopticien<small>Suivi & relances</small></span>
    </div>
</section>

<nav class="guide-nav" aria-label="Sommaire du guide">
    <a href="#fonctionnalites">Fonctionnalités</a>
    <a href="#assistant-action">Assistant</a>
    <a href="#quotidien">Parcours quotidien</a>
    <a href="#cosium">Récupérer Cosium</a>
    <a href="#verrier">Commander les verres</a>
    <a href="#messages">Prévenir le client</a>
    <a href="#automatisation">Automatisations</a>
    <a href="#securite">Connexion sécurisée</a>
</nav>

<section id="fonctionnalites" class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Vue d’ensemble</p><h2>Tout ce que fait le site</h2></div></div>
    <div class="feature-grid">
        <article class="feature-card"><span>⌂</span><h3>Tableau de bord</h3><p>Dossiers actifs, attentes mutuelle, paiements à vérifier, lunettes prêtes et urgences du jour.</p></article>
        <article class="feature-card"><span>👤</span><h3>Clients</h3><p>Coordonnées, mutuelle, NIR masqué, dossiers, avoirs et historique réunis dans une fiche.</p></article>
        <article class="feature-card"><span>▣</span><h3>Dossiers</h3><p>Devis, PEC, facture, télétransmission, montants RO/RC/RAC et prochaine action.</p></article>
        <article class="feature-card"><span>◎</span><h3>Commande verrier</h3><p>Référence Ophtalmic, étapes de fabrication, réception, montage, disponibilité et client prévenu.</p></article>
        <article class="feature-card"><span>€</span><h3>Paiements</h3><p>Montants attendus et encaissés par payeur, date, mode et alertes de retard.</p></article>
        <article class="feature-card"><span>✓</span><h3>Relances</h3><p>Tâches automatiques pour mutuelle, facturation, RO, RC et client à prévenir.</p></article>
        <article class="feature-card"><span>✦</span><h3>Assistant d’action</h3><p>Résume un client, ouvre la bonne page et prépare les modifications que l’opticien doit valider.</p></article>
        <article class="feature-card"><span>↻</span><h3>SAV</h3><p>Un dossier en SAV est mis en attente : ses transitions et ses relances sont suspendues jusqu’à sa reprise.</p></article>
        <article class="feature-card"><span>⇧</span><h3>Import CSV</h3><p>Prévisualisation, réutilisation des clients existants et création des paiements depuis un export préparé.</p></article>
        <article class="feature-card"><span>✉</span><h3>Messages</h3><p>E-mail envoyé après vérification, WhatsApp manuel et suivi des clients prévenus quand les lunettes sont prêtes.</p></article>
    </div>
</section>

<section id="assistant-action" class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Gain de temps</p><h2>Assistant conversationnel local</h2></div></div>
    <div class="content-grid">
        <article class="card">
            <h3>Exemples de demandes</h3>
            <ul class="guide-list">
                <li><code>Résume Mme Dupont Moretti</code></li>
                <li><code>Modifie le RO à 120,09 €, le RC à 300 € et le RAC à 50 € pour Nadia Dupont</code></li>
                <li><code>Passe le dossier de Nadia Dupont en SAV</code></li>
                <li><code>Ouvre les dossiers à facturer</code></li>
            </ul>
            <a class="btn btn-primary" href="<?= e(app_url('pages/assistant.php')) ?>">Ouvrir l’assistant</a>
        </article>
        <article class="card" style="margin-top:0">
            <h3>L’opticien garde le contrôle</h3>
            <p>La bulle en bas à droite reste accessible sur tous les écrans. Les résumés et recherches restent sur le serveur de la boutique et le NIR demeure masqué.</p>
            <p>Pour modifier un dossier, le nom et le prénom sont obligatoires. L’assistant affiche les dossiers actifs avec leur statut, leur date, le total et les montants RO/RC/RAC, puis l’opticien choisit le bon dossier.</p>
            <p>Une modification produit seulement un brouillon personnel valable 60 minutes.</p>
            <p>Le dossier s’ouvre avec les valeurs proposées et leur comparaison avec les valeurs actuelles. Rien n’est modifié avant le clic sur <strong>Enregistrer le dossier</strong>. Si le dossier a changé entre-temps, la proposition est refusée.</p>
            <p class="form-help">En cas d’homonyme ou de plusieurs dossiers actifs, l’assistant demande une précision.</p>
        </article>
    </div>
</section>

<section id="quotidien" class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Organisation</p><h2>Parcours quotidien conseillé</h2></div></div>
    <div class="steps-card">
        <article><span>01</span><div><h3>Créer ou importer le client</h3><p>Recherchez toujours le client avant de le créer afin d’éviter les doublons.</p></div></article>
        <article><span>02</span><div><h3>Créer le dossier</h3><p>Saisissez les statuts, dates, montants et la prochaine action. Le total est contrôlé automatiquement.</p></div></article>
        <article><span>03</span><div><h3>Traiter la PEC dans Cosium</h3><p>Cosium effectue les opérations réglementées. Reportez ensuite dans Oopticien les dates et le résultat utiles au suivi.</p></div></article>
        <article><span>04</span><div><h3>Commander chez le verrier</h3><p>Lancez la commande EDI dans Cosium ou E-Space, puis copiez la référence dans le dossier Oopticien.</p></div></article>
        <article><span>05</span><div><h3>Mettre à jour la réception</h3><p>Passez la commande à Reçue, Montage, puis Prête. Une tâche « Prévenir le client » sera créée.</p></div></article>
        <article><span>06</span><div><h3>Prévenir et remettre</h3><p>Testez le message, contactez le client, confirmez l’action, puis passez le dossier à Remis ou Clôturé.</p></div></article>
    </div>
</section>

<section id="cosium" class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Reprise de données</p><h2>Comment récupérer les données depuis Cosium</h2></div></div>
    <div class="content-grid">
        <article class="card">
            <h3>La méthode sûre</h3>
            <ol class="guide-list">
                <li>Dans Cosium, ouvrez le menu <strong>?</strong> puis créez une demande de support.</li>
                <li>Demandez un export CSV de votre magasin avec les colonnes nécessaires au cockpit.</li>
                <li>Demandez un transfert par un canal sécurisé : les données clients sont des données personnelles et peuvent inclure des données de santé.</li>
                <li>Conservez Cosium comme référentiel. Importez dans Oopticien uniquement les champs utiles au suivi.</li>
                <li>Alignez le fichier reçu sur le modèle Oopticien, puis utilisez la prévisualisation avant validation.</li>
            </ol>
            <div class="inline-actions">
                <?php if (has_role(['admin','patron'])): ?><a class="btn btn-primary" href="<?= e(app_url('pages/import_excel.php')) ?>">Ouvrir l’import</a><?php endif; ?>
                <a class="btn btn-outline" href="https://www.cosium.com/fr/support/" target="_blank" rel="noopener noreferrer">Support Cosium ↗</a>
            </div>
        </article>
        <article class="card" style="margin-top:0">
            <h3>Texte à envoyer au support Cosium</h3>
            <div class="copy-box">Bonjour,

Nous souhaitons obtenir un export CSV sécurisé des données de notre magasin pour alimenter un outil interne de suivi, sans remplacer Cosium.

Champs souhaités : numéro de fiche, nom, prénom, téléphone, e-mail, statut ordonnance, dates devis/facture/télétransmission, statut PEC, part RO, part RC, RAC, total, paiements et commentaire dossier.

Merci de nous indiquer la fonction d’export disponible, le format fourni et le canal sécurisé de transmission.</div>
            <p class="form-help">Le site public Cosium ne documente pas d’API publique ni de format CSV client standard. Il ne faut donc pas automatiser une extraction ou réutiliser des identifiants sans validation de leur support.</p>
        </article>
    </div>
    <section class="card table-card" style="margin-top:18px">
        <div class="card-header"><div><h3>Correspondance minimale</h3><p>Colonnes à préparer avant import</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Dans Oopticien</th><th>Donnée attendue</th><th>Traitement</th></tr></thead><tbody>
            <tr><td>NOM / Prénom</td><td>Identité client</td><td>Recherche du client existant</td></tr>
            <tr><td>ORDO</td><td>Oui / Non</td><td>Statut ordonnance</td></tr>
            <tr><td>SECU</td><td>NIR ou régime</td><td>Masqué dans l’interface</td></tr>
            <tr><td>MUTUELLE/TP+TM</td><td>Nom ou suivi PEC</td><td>Dossier en attente mutuelle</td></tr>
            <tr><td>Part RO / Part RC / RAC / TOTALE</td><td>Montants</td><td>Contrôle de cohérence</td></tr>
            <tr><td>PAIEMENT SECU / RC / RAC</td><td>Date d’encaissement</td><td>Création des paiements</td></tr>
        </tbody></table></div>
    </section>
</section>

<section id="verrier" class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Ophtalmic</p><h2>Comment faire la commande verrier</h2></div></div>
    <div class="workflow-panel">
        <article><span>1</span><h3>Finaliser dans Cosium</h3><p>Validez le devis et le dossier lunettes, puis générez le job avec les mesures, la monture et la prescription.</p></article>
        <article><span>2</span><h3>Lancer la commande EDI</h3><p>Utilisez l’interface fabricant de Cosium. Si nécessaire, utilisez votre compte sécurisé Ophtalmic E-Space.</p></article>
        <article><span>3</span><h3>Contrôler avant validation</h3><p>Vérifiez références, quantités, correction, traitements, diamètre, livraison et prix. Une commande reçue par Ophtalmic devient définitive.</p></article>
        <article><span>4</span><h3>Reporter le suivi</h3><p>Dans Oopticien, cochez « Suivre une commande », saisissez la référence et faites progresser son statut.</p></article>
    </div>
    <div class="info-banner" style="margin-top:16px"><span>!</span><div><strong>Oopticien Pro ne transmet pas la commande</strong><p>Le bouton ouvre uniquement le portail officiel. La validation reste volontairement dans Cosium/Ophtalmic pour éviter une commande médicale ou commerciale erronée.</p></div></div>
    <div class="inline-actions" style="margin-top:14px">
        <a class="btn btn-primary" href="<?= e(app_config()['suppliers']['ophtalmic_portal_url']) ?>" target="_blank" rel="noopener noreferrer">Ouvrir Ophtalmic E-Space ↗</a>
        <a class="btn btn-outline" href="https://www.cosium.com/fr/formation-edi/" target="_blank" rel="noopener noreferrer">Formation EDI Cosium ↗</a>
    </div>
</section>

<section id="automatisation" class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Suivi automatique</p><h2>PEC, paiements, bons de livraison et SAV</h2></div></div>
    <div class="steps-card">
        <article><span>01</span><div><h3>Demande mutuelle</h3><p>Le passage du dossier en « Demande mutuelle » mémorise l’heure de départ. Après 48 heures ouvrées par défaut, une relance devient due.</p></div></article>
        <article><span>02</span><div><h3>PEC acceptée</h3><p>Après 5 jours ouvrés par défaut sans refus, le dossier passe automatiquement en « À facturer ».</p></div></article>
        <article><span>03</span><div><h3>Contrôles RO et RC</h3><p>Les délais partent de la facture ou de la télétransmission. À l’échéance, le paiement passe en retard et apparaît dans les relances.</p></div></article>
        <article><span>04</span><div><h3>Bon reçu par e-mail</h3><p>Le PDF est rapproché par numéro de dossier, référence verrier ou nom complet unique. En cas de doute, aucun statut n’est modifié.</p></div></article>
        <article><span>05</span><div><h3>SAV</h3><p>Le statut SAV suspend les transitions et les relances du dossier jusqu’à sa reprise manuelle.</p></div></article>
    </div>
    <?php if (has_role(['admin','patron'])): ?><div class="inline-actions" style="margin-top:16px"><a class="btn btn-primary" href="<?= e(app_url('pages/settings.php')) ?>">Configurer les délais</a></div><?php endif; ?>
</section>

<section id="messages" class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Relation client</p><h2>Prévenir le client quand les lunettes sont prêtes</h2></div></div>
    <div class="content-grid">
        <article class="card">
            <h3>E-mail</h3>
            <ol class="guide-list">
                <li>Ouvrez un dossier dont le statut est <strong>Prêt</strong>, ou dont la commande possède une date « Prête le ».</li>
                <li>Choisissez <strong>E-mail</strong> et relisez le texte et l’adresse.</li>
                <li>Prévisualisez le message puis confirmez l’envoi.</li>
                <li>L’envoi est réalisé par PHPMailer et enregistré dans l’historique.</li>
            </ol>
        </article>
        <article class="card" style="margin-top:0">
            <h3>WhatsApp, SMS et téléphone</h3>
            <p><strong>WhatsApp manuel</strong> ouvre gratuitement la conversation avec le texte déjà préparé. L’opticien vérifie puis appuie lui-même sur Envoyer. Cela ne nécessite pas l’API WhatsApp Business.</p>
            <p>Un envoi WhatsApp entièrement automatique passe par la plateforme professionnelle de Meta et peut être facturé selon la catégorie et le contexte du message. Les SMS et appels automatiques sont conservés dans l’interface pour une mise en place ultérieure.</p>
            <p class="form-help">La configuration e-mail se trouve dans <code>config/mail.local.php</code>.</p>
        </article>
    </div>
</section>

<section id="securite" class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Alerte navigateur</p><h2>« Connexion non sécurisée » : pourquoi ?</h2></div></div>
    <section class="card">
        <p>Cette alerte vient du navigateur lorsque l’application est ouverte en <code>http://</code>. Comme le dossier contient un champ nommé « mode de paiement », le navigateur croit pouvoir proposer une carte bancaire enregistrée et désactive cet autoremplissage sur une connexion non chiffrée.</p>
        <div class="feature-grid compact">
            <article class="feature-card"><span>✓</span><h3>Ce que cela ne bloque pas</h3><p>Les paiements RO/RC/client continuent de fonctionner. Oopticien ne demande aucun numéro de carte bancaire.</p></article>
            <article class="feature-card"><span>⌂</span><h3>Solution immédiate</h3><p>Restez sur le réseau privé, désactivez l’autoremplissage pour ces formulaires et n’exposez pas le site sur Internet.</p></article>
            <article class="feature-card"><span>🔒</span><h3>Solution recommandée</h3><p>Installer un certificat HTTPS local de confiance sur le serveur et les postes de la boutique.</p></article>
        </div>
    </section>
</section>

<section class="guide-section">
    <div class="section-heading"><div><p class="eyebrow">Droits</p><h2>Qui peut faire quoi ?</h2></div></div>
    <section class="card table-card">
        <div class="table-wrap"><table><thead><tr><th>Fonction</th><th>Employé</th><th>Patron</th><th>Admin</th></tr></thead><tbody>
            <tr><td>Clients, dossiers, paiements, avoirs</td><td>Oui</td><td>Oui</td><td>Oui</td></tr>
            <tr><td>Relances et messages</td><td>Oui</td><td>Oui</td><td>Oui</td></tr>
            <tr><td>Afficher le NIR complet</td><td>Non</td><td>Oui</td><td>Oui</td></tr>
            <tr><td>Importer depuis CSV</td><td>Non</td><td>Oui</td><td>Oui</td></tr>
            <tr><td>Utilisateurs, paramètres, exports</td><td>Non</td><td>Non</td><td>Oui</td></tr>
        </tbody></table></div>
    </section>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
