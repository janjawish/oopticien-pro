-- Oopticien Pro V5 — partage familial des avoirs
USE oopticien_pro;

CREATE TABLE IF NOT EXISTS credit_beneficiaries (
  credit_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (credit_id, client_id),
  CONSTRAINT fk_credit_beneficiaries_credit FOREIGN KEY (credit_id) REFERENCES credits(id) ON DELETE CASCADE,
  CONSTRAINT fk_credit_beneficiaries_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_credit_beneficiaries_client (client_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO credit_beneficiaries (credit_id, client_id)
SELECT id, client_id FROM credits;
