-- V6 : type de dossier, workflow de facturation et chèque de caution.
ALTER TABLE dossiers
  ADD COLUMN IF NOT EXISTS dossier_type ENUM('lunettes','lentilles') NOT NULL DEFAULT 'lunettes' AFTER client_id;

ALTER TABLE dossiers
  ADD INDEX IF NOT EXISTS idx_dossiers_type (dossier_type);

ALTER TABLE payments
  MODIFY COLUMN status ENUM('attendu','partiel','encaisse','retard','cheque_caution','non_applicable') NOT NULL DEFAULT 'attendu';

UPDATE dossiers
SET folder_status = 'a_facturer', next_action = 'Facturer le dossier'
WHERE mutual_status = 'pec_acceptee'
  AND invoice_date IS NULL
  AND folder_status IN ('brouillon','devis','demande_mutuelle','pec_acceptee');
