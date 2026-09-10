# Oopticien Pro

Application interne de pilotage d’une boutique d’optique, construite pour centraliser le suivi opérationnel autour de Cosium : dossiers clients, prises en charge mutuelles, encaissements, commandes verrier, relances et traçabilité.

> Ce dépôt ne doit contenir ni données patient, ni documents, ni sauvegardes SQL, ni clé de chiffrement, ni identifiants SMTP/IMAP.

## Le besoin métier

Cosium reste le logiciel métier de référence. Oopticien Pro ne le remplace pas : il organise les actions quotidiennes qui gravitent autour des dossiers optiques, rend les priorités visibles et limite les oublis.

L’application couvre notamment le cheminement suivant : création ou import d’un dossier, demande de prise en charge, suivi de réponse mutuelle, facturation, commande verrier, réception, notification au client, puis clôture ou SAV.

## Fonctionnalités

- Fichier clients et dossiers lunettes/lentilles, avec recherche, pagination et documents associés.
- Import CSV et lecture de PDF Cosium, avec OCR local pour les PDF image et contrôle avant création.
- Suivi des PEC mutuelles : demande, réponse, acceptation, dérogation, refus et relances paramétrables.
- Tableau de bord des files de travail : dossiers en cours, à facturer, SAV, lunettes prêtes, clients à prévenir et tâches urgentes.
- Gestion des paiements RO, RC et client, avec reste à encaisser, chèques de caution, tri par date et filtres combinables par mutuelle, payeur, mode et statut.
- Gestion d’avoirs, y compris le partage d’un avoir entre plusieurs membres d’une famille.
- Préparation et suivi des commandes verrier ; le suivi disparaît si l’option est retirée du dossier.
- E-mails transactionnels réels via PHPMailer/SMTP ; WhatsApp manuel ; SMS et téléphone conservés comme évolutions futures.
- Lecture contrôlée des bons de livraison reçus par e-mail, avec rapprochement automatique uniquement lorsque le dossier est certain.
- Assistant conversationnel local : recherche d’un dossier, résumé client, ouverture d’une file de travail et préparation d’une modification à valider explicitement.
- Rôles `admin`, `patron` et `employe`, journal d’activité, CSRF, sessions sécurisées, masquage/chiffrement du NIR et sauvegardes applicatives.

## Technologies

| Domaine | Choix |
| --- | --- |
| Backend | PHP 8.1+ sans framework, architecture modulaire par pages et actions |
| Base de données | MySQL 8 / MariaDB 10.6+, PDO et requêtes préparées |
| Serveur local | Apache via XAMPP ou Laragon sous Windows |
| E-mail | PHPMailer et SMTP |
| Réception d’e-mails | IMAP et traitement planifié |
| PDF Cosium | Poppler (`pdftotext` / `pdftoppm`) et Tesseract OCR français |
| Automatisation | Scripts PHP + Planificateur de tâches Windows |
| Dépendances | Composer |

## Architecture

```text
actions/    Actions POST sécurisées : créations, modifications, suppressions
pages/      Écrans métier de l’application
includes/   Authentification, sécurité, base de données, OCR, messagerie, automatisations
database/   Schéma initial, migrations et jeu de démonstration
cron/       Relances, sauvegardes et automatisations périodiques
server/     Installation Windows, démarrage automatique et supervision
config/     Configuration applicative et exemples de configuration locale
```

Les documents, sauvegardes et clés de chiffrement sont stockés hors de `htdocs`, par défaut dans `C:\xampp\oopticien-pro-storage`.

## Installation locale

### Prérequis

- Windows, XAMPP ou Laragon ;
- PHP 8.1+ ;
- MySQL 8 ou MariaDB 10.6+ ;
- extensions PHP `pdo_mysql`, `openssl`, `fileinfo` et `mbstring` ;
- Composer ;
- Poppler et Tesseract si l’import PDF Cosium est utilisé.

### Démarrage

```powershell
git clone <URL_DU_DEPOT> C:\xampp\htdocs\oopticien-pro
cd C:\xampp\htdocs\oopticien-pro
composer install --no-dev --optimize-autoloader
```

1. Créez une base MySQL vide nommée `oopticien_pro`.
2. Importez `database/install.sql`, puis les migrations dans l’ordre numérique.
3. Copiez et complétez `config/mail.local.example.php` en `config/mail.local.php` si l’envoi d’e-mails est nécessaire.
4. Copiez et complétez `config/inbound_mail.local.example.php` en `config/inbound_mail.local.php` si la lecture de bons de livraison est nécessaire.
5. Démarrez Apache et MySQL, puis ouvrez `http://localhost/oopticien-pro/`.

Le fichier `database/seed.sql` ne doit être utilisé que pour une démonstration : jamais sur une installation contenant des données réelles.

Pour installer les outils PDF sur un serveur Windows :

```powershell
Set-ExecutionPolicy -Scope Process Bypass
& 'C:\xampp\htdocs\oopticien-pro\server\install-pdf-tools.ps1'
```

## Déploiement et mises à jour

Avant toute mise à jour sur le PC de l’opticien :

1. Créer et télécharger une sauvegarde de base depuis l’écran **Sauvegardes**.
2. Sauvegarder séparément le dossier de stockage privé et sa clé de chiffrement.
3. Faire enregistrer les formulaires en cours par les utilisateurs.
4. Déployer les fichiers applicatifs et exécuter uniquement les migrations absentes.
5. Vérifier les écrans **Paramètres**, **Import Cosium**, **Messages** et **Paiements**.

Le guide de mise en service d’un PC serveur qui redémarre automatiquement se trouve dans [server/INSTALLATION_SERVEUR.md](server/INSTALLATION_SERVEUR.md).

## Sécurité et confidentialité

- Ne jamais exposer l’application directement sur Internet en HTTP.
- Restreindre l’accès au réseau local, aux comptes autorisés et aux rôles nécessaires.
- Changer les comptes de démonstration avant mise en production.
- Ne jamais versionner `mail.local.php`, `inbound_mail.local.php`, le stockage privé ou une sauvegarde SQL réelle.
- Tester régulièrement une restauration sur une machine distincte.
- Ne lancer `database/purge_patient_data.sql` que sur une copie explicitement destinée à être anonymisée : jamais en production.

## Documentation

- [Guide d’utilisation pour l’opticien](GUIDE_OPTICIEN.md)
- [Configuration e-mail SMTP](CONFIGURATION_EMAIL.md)
- [Présentation fonctionnelle V2](PRESENTATION_V2.md)
- [Historique des évolutions](CHANGELOG.md)

