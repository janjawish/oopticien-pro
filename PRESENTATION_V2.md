# Présentation complète — Oopticien Pro V2

## Positionnement

Oopticien Pro V2 est le cockpit interne de la boutique. Il rassemble les actions quotidiennes dispersées entre Cosium, les portails mutuelles, Ophtalmic, les e-mails et les tableaux de suivi.

Il ne remplace aucun de ces services. Il indique ce qui doit être fait, prépare les informations, ouvre les portails officiels et conserve une trace des décisions humaines.

## Profils

### Employé

- clients et dossiers ;
- documents ;
- mutuelles ;
- commandes verrier ;
- paiements, avoirs et relances ;
- messages avec validation ;
- assistant local et recherche.

### Patron

Toutes les fonctions employé, plus :

- statistiques ;
- imports Excel et PDF ;
- modèles de messages.

### Administrateur

Toutes les fonctions, plus :

- utilisateurs et paramètres ;
- sécurité et migration des NIR ;
- sauvegardes et restauration.

## Navigation

| Zone | Pages principales | Finalité |
|---|---|---|
| Pilotage | Tableau de bord, Relances | Prioriser la journée |
| Clients | Clients, Dossiers | Centraliser le suivi |
| Opérations | Mutuelles, Ophtalmic, Documents, Messages | Exécuter les étapes |
| Finances | Paiements, Avoirs | Suivre les montants |
| Outils | Assistant, Recherche, Historique, Aide | Retrouver et décider |
| Direction | Statistiques, Imports | Contrôler l’activité |
| Administration | Utilisateurs, Paramètres, Sécurité, Sauvegardes | Exploiter l’application |

## Parcours client

1. Créer le client manuellement, importer un CSV ou déposer une fiche PDF Cosium.
2. Contrôler le doublon et valider les données.
3. Créer le dossier lunettes.
4. Suivre ordonnance, mutuelle, devis et montants.
5. Préparer la commande verrier.
6. Reporter manuellement la commande dans Ophtalmic.
7. Marquer les verres reçus puis les lunettes prêtes.
8. Prévisualiser et valider le message client.
9. Suivre le retrait, les paiements et les relances.
10. Conserver documents et historique.

## Sécurité

- sessions sécurisées et expirables ;
- rôles admin/patron/employé ;
- CSRF sur les écritures ;
- requêtes PDO préparées ;
- verrouillage après échecs de connexion ;
- restriction CIDR optionnelle ;
- NIR chiffré AES-256-GCM et masqué ;
- révélation NIR réservée et journalisée ;
- fichiers privés téléchargés via PHP ;
- contrôles SHA-256 ;
- suppression documentaire logique ;
- confirmations sur les actions sensibles.

## Ce qui reste humain

- validation d’un import PDF ;
- choix de créer ou mettre à jour un client ;
- envoi du message ;
- connexion et saisie dans un portail mutuelle ;
- validation de la commande Ophtalmic ;
- décision proposée par l’assistant ;
- restauration d’une sauvegarde.

## Indicateurs direction

- dossiers créés, actifs, clôturés et bloqués ;
- RO, RC et RAC attendus ;
- retards de paiement ;
- RAC non encaissés ;
- délais mutuelle, facturation et retrait ;
- incohérences de montants ;
- tâches en retard par type ;
- dossiers créés par employé.

## Résultat de recette

La recette V2 a été exécutée sur une base isolée issue du schéma V1 :

- migration SQL réussie ;
- écrans V2 sans erreur serveur ;
- trois rôles connectés ;
- restrictions de rôle vérifiées ;
- 20 tests métier réussis sur 20 ;
- parcours « lunettes prêtes » contrôlé avec e-mail SMTP, WhatsApp manuel et suivi des contacts ; l’envoi réel dépend de la configuration de la boutique.
