-- Oopticien Pro V2
-- Purge des donnees patient avant un import reel.
--
-- ATTENTION :
--   1. Faites une sauvegarde de la base avant d'executer ce fichier.
--   2. Cette operation est irreversible apres COMMIT.
--   3. Les comptes, reglages, modeles de messages, portails mutuelles,
--      tentatives de connexion et sauvegardes enregistrees sont conserves.
--   4. Les fichiers physiques presents dans le stockage prive
--      (documents, PDF Cosium et anciennes sauvegardes) ne sont pas effaces
--      par SQL. Voyez GUIDE_OPTICIEN.md avant une mise en production.

USE oopticien_pro;

SET NAMES utf8mb4;
SET @previous_sql_safe_updates = @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

-- Etat AVANT purge : controlez ces nombres avant de continuer.
SELECT
    (SELECT COUNT(*) FROM clients) AS clients_avant,
    (SELECT COUNT(*) FROM dossiers) AS dossiers_avant,
    (SELECT COUNT(*) FROM documents) AS documents_avant,
    (SELECT COUNT(*) FROM pdf_imports) AS pdf_cosium_avant,
    (SELECT COUNT(*) FROM users) AS utilisateurs_conserves;

START TRANSACTION;

-- Donnees d'import et traces pouvant contenir des donnees patient.
DELETE FROM import_field_mapping;
DELETE FROM sensitive_access_logs;
DELETE FROM ai_logs;
DELETE FROM assistant_action_drafts;

-- Documents references en base. Les fichiers sur disque restent a traiter
-- separement si des documents de demonstration ont ete televerses.
DELETE FROM documents;
DELETE FROM pdf_imports;

-- Donnees metier rattachees aux patients et aux dossiers.
DELETE FROM mutual_requests;
DELETE FROM messages;
DELETE FROM tasks;
DELETE FROM glass_orders;
DELETE FROM payments;
DELETE FROM credits;

-- Historique patient uniquement. Les actions de connexion, comptes,
-- reglages et sauvegardes sont conservees.
DELETE FROM action_history
WHERE entity_type IN (
    'client',
    'dossier',
    'avoir',
    'paiement',
    'glass_order',
    'tache',
    'message',
    'document',
    'pdf_import',
    'mutual_request',
    'ai_log'
);

DELETE FROM dossiers;
DELETE FROM clients;
DELETE FROM imports;

COMMIT;

SET SQL_SAFE_UPDATES = @previous_sql_safe_updates;

-- Etat APRES purge : tous les compteurs patient doivent etre a zero.
-- Les utilisateurs et la configuration doivent toujours etre presents.
SELECT
    (SELECT COUNT(*) FROM clients) AS clients_apres,
    (SELECT COUNT(*) FROM dossiers) AS dossiers_apres,
    (SELECT COUNT(*) FROM credits) AS avoirs_apres,
    (SELECT COUNT(*) FROM payments) AS paiements_apres,
    (SELECT COUNT(*) FROM messages) AS messages_apres,
    (SELECT COUNT(*) FROM users) AS utilisateurs_conserves,
    (SELECT COUNT(*) FROM settings) AS reglages_conserves,
    (SELECT COUNT(*) FROM message_templates) AS modeles_conserves,
    (SELECT COUNT(*) FROM mutual_portals) AS portails_conserves;
