-- Oopticien Pro V1 — schéma MySQL/MariaDB
-- Encodage recommandé : UTF-8 (utf8mb4)

CREATE DATABASE IF NOT EXISTS oopticien_pro
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE oopticien_pro;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','patron','employe') NOT NULL DEFAULT 'employe',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  INDEX idx_users_role_active (role, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiche_number VARCHAR(50) NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  birth_date DATE NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  social_security_number VARCHAR(30) NULL,
  social_security_scheme VARCHAR(100) NULL,
  insured_name VARCHAR(160) NULL,
  reimbursement_rate DECIMAL(5,2) NULL,
  mutual_name VARCHAR(160) NULL,
  membership_number VARCHAR(100) NULL,
  mutual_valid_from DATE NULL,
  mutual_valid_to DATE NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_clients_name (last_name, first_name),
  INDEX idx_clients_phone (phone),
  INDEX idx_clients_nir (social_security_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dossiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  dossier_type ENUM('lunettes','lentilles') NOT NULL DEFAULT 'lunettes',
  prescription_status ENUM('oui','non','attente') NOT NULL DEFAULT 'attente',
  mutual_status ENUM('non_envoyee','envoyee','en_attente','pec_acceptee','pec_refusee','incomplete') NOT NULL DEFAULT 'non_envoyee',
  folder_status ENUM('brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer','facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus','montage','pret','client_prevenu','remis','cloture','bloque','sav','derogation','annule') NOT NULL DEFAULT 'brouillon',
  status_changed_at DATETIME NULL,
  quote_date DATE NULL,
  pec_sent_at DATETIME NULL,
  pec_response_at DATETIME NULL,
  pec_reference VARCHAR(120) NULL,
  invoice_date DATE NULL,
  teletrans_status ENUM('oui','non') NOT NULL DEFAULT 'non',
  teletrans_date DATE NULL,
  ro_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  rc_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  rac_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_warning TINYINT(1) NOT NULL DEFAULT 0,
  optician_comment TEXT NULL,
  next_action VARCHAR(255) NULL,
  priority ENUM('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
  source_origin VARCHAR(50) NULL,
  source_fingerprint CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_dossiers_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dossiers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dossiers_status (folder_status),
  INDEX idx_dossiers_type (dossier_type),
  INDEX idx_dossiers_mutual (mutual_status),
  INDEX idx_dossiers_priority (priority),
  INDEX idx_dossiers_updated (updated_at)
  ,UNIQUE INDEX uq_dossiers_source_fingerprint (source_fingerprint)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  dossier_id INT UNSIGNED NULL,
  label VARCHAR(180) NOT NULL,
  source ENUM('mutuelle','cmu','commercial','autre') NOT NULL DEFAULT 'autre',
  initial_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  used_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  remaining_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_credits_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_credits_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE SET NULL,
  CONSTRAINT fk_credits_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_credits_client (client_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credit_beneficiaries (
  credit_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (credit_id, client_id),
  CONSTRAINT fk_credit_beneficiaries_credit FOREIGN KEY (credit_id) REFERENCES credits(id) ON DELETE CASCADE,
  CONSTRAINT fk_credit_beneficiaries_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_credit_beneficiaries_client (client_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dossier_id INT UNSIGNED NOT NULL,
  payer ENUM('ro','rc','client') NOT NULL,
  expected_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_date DATE NULL,
  payment_method ENUM('cb','cheque','especes','virement','autre') NULL,
  status ENUM('attendu','partiel','encaisse','retard','cheque_caution','non_applicable') NOT NULL DEFAULT 'attendu',
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_payments_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_payments_status_payer (status, payer),
  INDEX idx_payments_dossier (dossier_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS glass_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dossier_id INT UNSIGNED NOT NULL UNIQUE,
  supplier_name VARCHAR(120) NOT NULL DEFAULT 'Ophtalmic',
  order_reference VARCHAR(120) NULL,
  status ENUM('a_preparer','envoyee','confirmee','en_fabrication','expediee','recue','montage','prete','incident','annulee') NOT NULL DEFAULT 'a_preparer',
  sent_at DATETIME NULL,
  confirmed_at DATETIME NULL,
  expected_at DATETIME NULL,
  received_at DATETIME NULL,
  mounted_at DATETIME NULL,
  ready_at DATETIME NULL,
  client_notified_at DATETIME NULL,
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_glass_orders_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
  INDEX idx_glass_orders_status (status),
  INDEX idx_glass_orders_ready (ready_at, client_notified_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dossier_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  title VARCHAR(220) NOT NULL,
  description TEXT NULL,
  task_type ENUM('mutuelle','facturation','paiement_ro','paiement_rc','paiement_rac','commande','montage','client_a_prevenir','client_non_venu','incoherence','autre') NOT NULL DEFAULT 'autre',
  due_at DATETIME NOT NULL,
  priority ENUM('basse','normale','haute','critique') NOT NULL DEFAULT 'normale',
  status ENUM('a_faire','en_cours','terminee','reportee','annulee') NOT NULL DEFAULT 'a_faire',
  assigned_to INT UNSIGNED NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tasks_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tasks_status_due (status, due_at),
  INDEX idx_tasks_priority (priority)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  dossier_id INT UNSIGNED NULL,
  channel ENUM('appel','sms','email','whatsapp','interne') NOT NULL DEFAULT 'interne',
  template_name VARCHAR(160) NULL,
  content TEXT NOT NULL,
  status ENUM('a_preparer','envoye','echec','non_envoye','fait_manuellement') NOT NULL DEFAULT 'a_preparer',
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_messages_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE SET NULL,
  CONSTRAINT fk_messages_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_messages_client (client_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(120) NOT NULL UNIQUE,
  setting_value TEXT NOT NULL,
  description VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS imports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(255) NOT NULL,
  import_type ENUM('excel','csv','manuel') NOT NULL DEFAULT 'csv',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  success_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows INT UNSIGNED NOT NULL DEFAULT 0,
  report TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_imports_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_imports_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS action_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id INT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_history_entity (entity_type, entity_id),
  INDEX idx_history_user_created (user_id, created_at),
  INDEX idx_history_action (action)
) ENGINE=InnoDB;
