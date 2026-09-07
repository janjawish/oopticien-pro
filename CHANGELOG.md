# Changelog

## V3.0.0 — 2026-07-30

### Ajouté

- envoi SMTP réel avec PHPMailer ;
- OCR local Poppler/Tesseract pour le modèle PDF Cosium fourni ;
- champs Cosium structurés et validation avant création ;
- contrôle de cohérence visible sur les dossiers ;
- suppression d’un dossier ou d’une demande mutuelle ;
- pagination de la liste des dossiers.

### Corrigé

- réparation des accents dans les modèles de messages, paramètres, comptes et noms de portails mutuelle ;
- suppression de la limite de 1 000 lignes à l’import CSV ;
- empreinte anti-doublon au réimport ;
- prise en charge des en-têtes annotés du fichier réel ;
- statut PEC fondé sur les preuves d’envoi/réponse et les mentions de la colonne DEVIS ;
- un nom de mutuelle seul ne classe plus le dossier « en attente » ;
- synchronisation entre demande mutuelle et dossier ;
- retrait du mode simulation et des messages techniques visibles.

## V2.0.0 — 2026-07-27

### Ajouté

- migration SQL V2 et rollback documenté ;
- tables `documents`, `pdf_imports`, `import_field_mapping`, `message_templates`, `login_attempts`, `sensitive_access_logs`, `backups`, `mutual_portals`, `mutual_requests`, `ai_logs` ;
- stockage privé et téléchargement protégé ;
- chiffrement AES-256-GCM du NIR et migration des valeurs V1 ;
- import PDF Cosium minimal avec détection de doublons et validation humaine ;
- documents liés aux clients/dossiers et suppression logique ;
- centre de messages, modèles, prévisualisation, simulation et action manuelle ;
- suivi mutuelle, checklist, justificatif et relance 48 h ;
- fiche et tableau des commandes Ophtalmic ;
- boutons reçue/prête et tâche « prévenir client » ;
- statistiques direction filtrables ;
- assistant local avec sept questions rapides ;
- recherche globale et aide V2 ;
- sauvegardes SQL, archive ZIP et restauration avec confirmation ;
- cron de sauvegarde quotidienne ;
- navigation, dashboard et responsive enrichis ;
- suite de 20 tests métier.

### Modifié

- authentification avec délai de session configurable et verrouillage ;
- formulaire client pour ne plus réafficher le NIR en clair ;
- commande verrier enrichie des corrections, produit et montage ;
- messages enrichis du destinataire, fournisseur, erreur et validateur ;
- historique des actions sensibles ;
- paramètres boutique, Maps et assistant.

### Limites connues

- extraction PDF sans OCR et dépendante du PDF Cosium ;
- SMS placeholder uniquement ;
- e-mail via relais PHP local, sans paquet PHPMailer embarqué ;
- fournisseur IA externe prévu mais non branché ;
- URL des portails mutuelles à configurer ;
- aucune automatisation de Cosium, Ophtalmic ou des portails mutuelles.
