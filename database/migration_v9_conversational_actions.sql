-- V9 : assistant conversationnel d'action avec validation humaine obligatoire.
CREATE TABLE IF NOT EXISTS assistant_action_drafts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  dossier_id INT UNSIGNED NOT NULL,
  action_type ENUM('update_dossier') NOT NULL DEFAULT 'update_dossier',
  payload_json LONGTEXT NOT NULL,
  original_json LONGTEXT NOT NULL,
  base_updated_at DATETIME NOT NULL,
  status ENUM('pending','applied','cancelled','expired') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  applied_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_assistant_draft_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_assistant_draft_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
  UNIQUE KEY uq_assistant_draft_token (token_hash),
  INDEX idx_assistant_draft_user_status (user_id,status,expires_at),
  INDEX idx_assistant_draft_dossier (dossier_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key,setting_value,description) VALUES
  ('assistant_draft_minutes','60','Durée de validité des actions préparées par l’assistant')
ON DUPLICATE KEY UPDATE description=VALUES(description);
