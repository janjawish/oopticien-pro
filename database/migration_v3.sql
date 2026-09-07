-- Oopticien Pro V3 - import complet, OCR Cosium et dédoublonnage
-- À exécuter une seule fois sur la base existante.

USE oopticien_pro;
SET NAMES utf8mb4;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS birth_date DATE NULL AFTER last_name,
    ADD COLUMN IF NOT EXISTS insured_name VARCHAR(160) NULL AFTER social_security_scheme,
    ADD COLUMN IF NOT EXISTS reimbursement_rate DECIMAL(5,2) NULL AFTER insured_name,
    ADD COLUMN IF NOT EXISTS mutual_valid_from DATE NULL AFTER membership_number,
    ADD COLUMN IF NOT EXISTS mutual_valid_to DATE NULL AFTER mutual_valid_from;

ALTER TABLE dossiers
    ADD COLUMN IF NOT EXISTS source_origin VARCHAR(50) NULL AFTER priority,
    ADD COLUMN IF NOT EXISTS source_fingerprint CHAR(64) NULL AFTER source_origin;

-- MariaDB accepte IF NOT EXISTS pour les index. Si l'index existe déjà,
-- cette instruction ne modifie rien.
ALTER TABLE dossiers
    ADD UNIQUE INDEX IF NOT EXISTS uq_dossiers_source_fingerprint (source_fingerprint);

-- Répare la règle erronée de l'ancien import :
-- une mutuelle renseignée ne prouve pas qu'une PEC a été envoyée.
UPDATE dossiers d
LEFT JOIN mutual_requests mr
    ON mr.dossier_id = d.id
   AND (mr.sent_at IS NOT NULL OR mr.status IN ('envoyee','en_attente','acceptee','partielle'))
SET
    d.mutual_status = 'non_envoyee',
    d.folder_status = CASE
        WHEN d.folder_status = 'demande_mutuelle' AND d.quote_date IS NOT NULL THEN 'devis'
        WHEN d.folder_status = 'demande_mutuelle' THEN 'brouillon'
        ELSE d.folder_status
    END,
    d.next_action = CASE
        WHEN d.folder_status = 'demande_mutuelle' THEN 'Préparer la demande mutuelle'
        ELSE d.next_action
    END
WHERE d.mutual_status = 'en_attente'
  AND d.pec_sent_at IS NULL
  AND mr.id IS NULL;
