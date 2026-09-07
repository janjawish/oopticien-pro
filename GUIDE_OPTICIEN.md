# Oopticien Pro — guide pour l’opticien

État vérifié le 2 août 2026.

## À quoi sert le site

Oopticien Pro est le tableau de suivi de la boutique. Il rassemble les clients, dossiers lunettes, PEC mutuelles, commandes verrier, paiements, avoirs, documents, relances et messages.

Cosium reste la référence métier. Les demandes mutuelle et les commandes verrier sont confirmées dans leurs portails officiels ; Oopticien Pro sert à préparer, contrôler et suivre les actions.

## Parcours quotidien

### Assistant conversationnel

La petite bulle **Assistant**, en bas à droite de tous les écrans, ouvre le chat. Les questions pré-écrites restent disponibles. Exemples :

- `Résume Mme Dupont Moretti` ;
- `Modifie le RO à 120,09 €, le RC à 300 € et le RAC à 50 € pour Nadia Dupont` ;
- `Passe le dossier de Nadia Dupont en SAV` ;
- `Ouvre les dossiers à facturer`.

Le résumé rassemble les coordonnées, la mutuelle, les droits, l’avoir, les dossiers, paiements, tâches, commandes et documents récents. Le NIR reste masqué.

Pour une modification, le nom et le prénom sont obligatoires. L’assistant présente d’abord les dossiers actifs du client avec leur type, leur statut, leur date, le total et le détail RO/RC/RAC. L’opticien choisit le dossier, puis l’assistant l’ouvre avec un aperçu « avant / proposé ». L’opticien contrôle puis clique sur **Enregistrer le dossier**. Sans ce clic, rien ne change. Un brouillon expire au bout de 60 minutes et est refusé si un collègue a modifié le dossier entre-temps.

### 1. Retrouver le client

Dans **Clients**, rechercher par nom, prénom, téléphone, e-mail, numéro de fiche ou NIR. La fiche regroupe ses dossiers, documents, avoirs et coordonnées.

### 2. Contrôler le dossier

Chaque dossier affiche un contrôle :

- ordonnance présente ou à vérifier ;
- cohérence entre total, RO, RC et reste à charge ;
- présence des dates nécessaires ;
- cohérence entre le statut PEC et les dates d’envoi/réponse ;
- présence de la date de facture ou de télétransmission au bon stade.

« Données cohérentes » signifie que les informations enregistrées ne se contredisent pas. Cela ne valide ni l’ordonnance, ni les corrections, ni la conformité médicale : l’opticien garde la validation métier.

### 3. Suivre la mutuelle

Dans **Mutuelles / PEC** :

1. vérifier les pièces ;
2. ouvrir le portail officiel ;
3. envoyer la demande ;
4. noter le statut, la référence et la date d’envoi ;
5. noter la réponse et le montant accepté ;
6. traiter les relances à l’échéance.

Une demande créée par erreur peut être supprimée par un administrateur ou le patron. Le dossier client est conservé et le statut PEC revient à « non envoyée ».

### 4. Commander les verres

Dans le dossier :

1. contrôler OD, OG, addition, écart pupillaire, produit, indice, traitement, teinte et monture ;
2. finaliser les informations dans Cosium ;
3. envoyer la commande par Cosium ou Ophtalmic E-Space ;
4. reporter la référence dans Oopticien Pro ;
5. passer la commande à **Reçue**, puis **Prête**.

Oopticien Pro ne transmet pas automatiquement la commande au verrier. Ce choix évite une commande non contrôlée et reste nécessaire tant que Cosium et le verrier n’ont pas fourni d’interface autorisée.

### 5. Prévenir le client

Quand le dossier ou la commande passe à **Prêt**, une tâche « Prévenir le client » apparaît.

- **E-mail** : envoi réel avec PHPMailer, après relecture.
- **WhatsApp** : ouverture manuelle de la conversation ; l’opticien appuie lui-même sur envoyer.
- **SMS** : affiché comme prévu plus tard, sans envoi.
- **Téléphone** : affiché comme prévu plus tard ; l’appel reste manuel.

Le client n’est marqué comme prévenu qu’après un e-mail réellement accepté par le serveur SMTP ou après confirmation d’un contact manuel.

### 6. Traiter les relances

Dans **Relances**, lancer le générateur puis traiter, terminer ou reporter les tâches :

- vérifier la réponse mutuelle ;
- facturer après PEC acceptée ;
- prévenir le client ;
- rappeler le client non venu.

Le serveur relance automatiquement ce calcul toutes les 15 minutes. Le bouton **Générer les relances** reste disponible pour forcer un contrôle immédiat.

Cycle PEC configuré dans **Paramètres** :

1. le passage en **Demande mutuelle** démarre le compteur et mémorise l’heure exacte ;
2. après 48 heures ouvrées par défaut, la demande passe en attente de réponse et une relance est due ;
3. après 5 jours ouvrés par défaut sans refus, le dossier passe en **PEC acceptée**, puis immédiatement en **À facturer** ;
4. le délai de facturation crée ensuite une tâche dédiée ;
5. les contrôles RO et RC partent de la télétransmission ou de la facture et passent le paiement en retard à l’échéance.

Le statut **SAV** suspend toutes ces transitions et masque les tâches du dossier dans la file active. À la sortie du SAV, le nouveau changement de statut redémarre le compteur.

### 6 bis. Bons de livraison reçus par e-mail

Une boîte dédiée IMAP peut être lue automatiquement. Pour chaque PDF, Oopticien cherche dans cet ordre : numéro de dossier, référence de commande verrier, puis nom complet unique. Une correspondance certaine valide la PEC et place le dossier dans **À facturer**. Une correspondance absente ou ambiguë ne modifie rien et reste **À vérifier** dans **Paramètres**.

### 7. Suivre les paiements et les avoirs

Le dossier distingue RO, RC et client. Le solde d’un avoir est calculé automatiquement :

`avoir restant = montant initial − montant utilisé`

## Import du tableau Excel

Enregistrer le tableau en **CSV UTF-8**, puis ouvrir **Import Excel**.

Le fichier réel fourni donne :

- 2 520 lignes exploitables ;
- 2 lignes écartées car le nom ou le prénom manque ;
- 4 doublons exacts ignorés ;
- 2 516 dossiers uniques à créer.

Il n’y a plus de limite à 1 000 lignes. La limite technique est 20 Mo.

Le même fichier peut être réimporté : les empreintes de ligne empêchent la création de doublons.

### Comment le statut PEC est décidé

Le site ne considère plus qu’un nom de mutuelle signifie « en attente ».

L’ordre des preuves est :

1. statut, date d’envoi, date de réponse ou référence PEC dans des colonnes dédiées ;
2. mention claire dans la colonne **DEVIS**, par exemple :

   - `PEC ok` → acceptée ;
   - `PEC en attente` → en attente ;
   - `refus` → refusée ;
   - `pièce manquante` ou `attente de PJ` → incomplète ;

3. aucune preuve d’envoi → non envoyée.

Après lecture du fichier fourni, le classement de test obtenu est :

| Statut | Nombre |
|---|---:|
| PEC acceptée | 1 869 |
| Non envoyée | 616 |
| Incomplète | 21 |
| En attente | 6 |
| Refusée | 4 |

Certaines mentions anciennes contiennent `PEC ok` sans date complète. Le statut peut être repris, mais le contrôle du dossier demandera à l’opticien de confirmer la date manquante.

## Import du PDF Cosium

Le PDF Cosium fourni est un document image. Le lecteur OCR récupère localement :

- numéro de fiche ;
- identité et date de naissance ;
- assuré ;
- caisse ;
- taux de remboursement ;
- mutuelle et numéro d’adhérent ;
- dates de droits ;
- date du dossier ;
- corrections visibles.

Une page de vérification est toujours affichée avant la création. Les corrections sont enregistrées comme « à contrôler » et ne déclenchent jamais une commande automatique.

## Suppression

Un administrateur ou le patron peut :

- supprimer un dossier depuis sa fiche, en conservant le client ;
- supprimer uniquement une demande mutuelle, en conservant le dossier ;
- supprimer les données patients avec le script dédié avant un nouvel import complet.

Les suppressions du site demandent une confirmation et sont journalisées.

## État des besoins exprimés

| Besoin | État actuel |
|---|---|
| Rappel après envoi à la mutuelle | Disponible sous forme de tâche interne à l’échéance |
| Rappel de facturation après PEC | Disponible sous forme de tâche interne |
| Message vocal pour les horaires | Prévu plus tard ; nécessite l’opérateur ou le standard |
| Message quand les lunettes sont prêtes | E-mail réel, WhatsApp manuel, appel manuel |
| Rappels client | Délais configurables dans les paramètres |
| Envoyer automatiquement les mesures au verrier | Non ; préparation et suivi disponibles, validation dans le portail officiel |
| Base clients | Disponible |
| Calcul de l’avoir restant | Disponible |

## Avant l’utilisation réelle

- configurer l’e-mail SMTP ;
- changer les mots de passe de démonstration ;
- sauvegarder la base, les documents et la clé NIR ;
- utiliser HTTPS ;
- contrôler un échantillon de dossiers importés ;
- définir qui peut supprimer des dossiers ;
- ne pas importer `seed.sql` dans la base réelle.
