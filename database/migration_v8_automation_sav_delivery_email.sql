-- V8 : automatisations horodatées, statut SAV et lecture des bons de livraison par e-mail.
ALTER TABLE dossiers
  MODIFY COLUMN folder_status ENUM(
    'brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer',
    'facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus',
    'montage','pret','client_prevenu','remis','cloture','bloque','sav','annule'
  ) NOT NULL DEFAULT 'brouillon';

ALTER TABLE dossiers
  ADD COLUMN IF NOT EXISTS status_changed_at DATETIME NULL AFTER folder_status;

-- Les anciens dossiers ne doivent pas être basculés dès l'installation de la migration.
UPDATE dossiers
SET status_changed_at = NOW()
WHERE status_changed_at IS NULL;

CREATE TABLE IF NOT EXISTS inbound_delivery_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_uid VARCHAR(190) NOT NULL,
  message_id VARCHAR(255) NULL,
  sender VARCHAR(255) NULL,
  subject VARCHAR(500) NULL,
  attachment_name VARCHAR(255) NULL,
  attachment_sha256 CHAR(64) NOT NULL,
  dossier_id INT UNSIGNED NULL,
  processing_status ENUM('traite','a_verifier','ignore','erreur') NOT NULL DEFAULT 'a_verifier',
  match_reason VARCHAR(255) NULL,
  error_message TEXT NULL,
  received_at DATETIME NULL,
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inbound_delivery_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE SET NULL,
  UNIQUE KEY uq_inbound_delivery_attachment (message_uid, attachment_sha256),
  INDEX idx_inbound_delivery_status (processing_status, processed_at),
  INDEX idx_inbound_delivery_dossier (dossier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, description) VALUES
  ('pec_auto_accept_days_open', '5', 'Passage automatique en PEC acceptée après la demande, en jours ouvrés'),
  ('automation_interval_minutes', '15', 'Fréquence conseillée du moteur automatique en minutes'),
  ('automation_last_run_at', '', 'Dernière exécution du moteur automatique'),
  ('automation_last_summary', '', 'Résumé de la dernière exécution automatique'),
  ('inbound_mail_last_run_at', '', 'Dernière lecture de la boîte des bons de livraison'),
  ('inbound_mail_last_error', '', 'Dernière erreur de lecture de la boîte des bons de livraison')
ON DUPLICATE KEY UPDATE description = VALUES(description);
