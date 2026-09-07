-- Oopticien Pro V2 - migration non destructive
-- A executer APRES database/install.sql sur la base oopticien_pro.
-- Compatible MySQL 8 / MariaDB 10.6+ (XAMPP recent).

USE oopticien_pro;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS social_security_number_encrypted TEXT NULL AFTER social_security_number;

ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS subject VARCHAR(190) NULL AFTER template_name,
    ADD COLUMN IF NOT EXISTS recipient VARCHAR(190) NULL AFTER subject,
    ADD COLUMN IF NOT EXISTS provider ENUM('none','phpmailer','sms_placeholder','whatsapp','manual') NOT NULL DEFAULT 'none' AFTER recipient,
    ADD COLUMN IF NOT EXISTS error_message TEXT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS validated_by INT UNSIGNED NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS validated_at DATETIME NULL AFTER validated_by;

ALTER TABLE glass_orders
    ADD COLUMN IF NOT EXISTS lens_right_sphere DECIMAL(6,2) NULL AFTER order_reference,
    ADD COLUMN IF NOT EXISTS lens_right_cylinder DECIMAL(6,2) NULL AFTER lens_right_sphere,
    ADD COLUMN IF NOT EXISTS lens_right_axis SMALLINT UNSIGNED NULL AFTER lens_right_cylinder,
    ADD COLUMN IF NOT EXISTS lens_right_addition DECIMAL(5,2) NULL AFTER lens_right_axis,
    ADD COLUMN IF NOT EXISTS lens_left_sphere DECIMAL(6,2) NULL AFTER lens_right_addition,
    ADD COLUMN IF NOT EXISTS lens_left_cylinder DECIMAL(6,2) NULL AFTER lens_left_sphere,
    ADD COLUMN IF NOT EXISTS lens_left_axis SMALLINT UNSIGNED NULL AFTER lens_left_cylinder,
    ADD COLUMN IF NOT EXISTS lens_left_addition DECIMAL(5,2) NULL AFTER lens_left_axis,
    ADD COLUMN IF NOT EXISTS pupillary_distance VARCHAR(40) NULL AFTER lens_left_addition,
    ADD COLUMN IF NOT EXISTS lens_product VARCHAR(190) NULL AFTER pupillary_distance,
    ADD COLUMN IF NOT EXISTS lens_index VARCHAR(40) NULL AFTER lens_product,
    ADD COLUMN IF NOT EXISTS treatment VARCHAR(190) NULL AFTER lens_index,
    ADD COLUMN IF NOT EXISTS tint VARCHAR(120) NULL AFTER treatment,
    ADD COLUMN IF NOT EXISTS frame_reference VARCHAR(120) NULL AFTER tint,
    ADD COLUMN IF NOT EXISTS mounting_comment TEXT NULL AFTER frame_reference,
    ADD COLUMN IF NOT EXISTS ophtalmic_url VARCHAR(500) NULL AFTER mounting_comment;

CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NULL,
    dossier_id INT UNSIGNED NULL,
    category ENUM('ordonnance','mutuelle','identite','facture','devis','cosium','autre') NOT NULL DEFAULT 'autre',
    document_type ENUM('pdf_cosium','ordonnance','pec_mutuelle','devis','facture','ophtalmic','paiement','autre') NOT NULL DEFAULT 'autre',
    original_name VARCHAR(255) NOT NULL,
    original_file_name VARCHAR(255) NULL,
    stored_name VARCHAR(255) NOT NULL,
    stored_file_name VARCHAR(255) NULL,
    file_path VARCHAR(500) NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    file_size INT UNSIGNED NULL,
    description TEXT NULL,
    is_sensitive TINYINT(1) NOT NULL DEFAULT 1,
    sha256 CHAR(64) NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_documents_client (client_id, deleted_at),
    INDEX idx_documents_dossier (dossier_id, deleted_at),
    CONSTRAINT fk_documents_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pdf_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    original_file_name VARCHAR(255) NULL,
    stored_name VARCHAR(255) NOT NULL,
    stored_file_path VARCHAR(500) NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    status ENUM('uploaded','parsed','waiting_validation','validated','failed','cancelled','review','rejected') NOT NULL DEFAULT 'uploaded',
    extracted_text MEDIUMTEXT NULL,
    parsed_data LONGTEXT NULL,
    extracted_json JSON NULL,
    error_message TEXT NULL,
    client_id INT UNSIGNED NULL,
    dossier_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    validated_by INT UNSIGNED NULL,
    validated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pdf_imports_status (status, created_at),
    CONSTRAINT fk_pdf_import_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_pdf_import_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE SET NULL,
    CONSTRAINT fk_pdf_import_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pdf_import_validated_by FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_field_mapping (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pdf_import_id INT UNSIGNED NOT NULL,
    import_id INT UNSIGNED NULL,
    target_field VARCHAR(100) NOT NULL,
    field_name VARCHAR(100) NULL,
    source_value TEXT NULL,
    extracted_value TEXT NULL,
    corrected_value TEXT NULL,
    mapped_entity ENUM('client','dossier','mutuelle','prescription','ignore') NOT NULL DEFAULT 'client',
    mapped_field VARCHAR(100) NULL,
    confidence DECIMAL(5,2) NULL,
    confidence_score DECIMAL(5,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_import_field (pdf_import_id, target_field),
    CONSTRAINT fk_mapping_import FOREIGN KEY (pdf_import_id) REFERENCES pdf_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    channel ENUM('email','sms','whatsapp','appel','interne','manual') NOT NULL DEFAULT 'email',
    event_key VARCHAR(80) NOT NULL,
    subject VARCHAR(190) NULL,
    body TEXT NOT NULL,
    content TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_template_event_channel (event_key, channel),
    CONSTRAINT fk_templates_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_ip (email, ip_address, attempted_at),
    INDEX idx_login_attempts_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sensitive_access_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    client_id INT UNSIGNED NULL,
    entity_type VARCHAR(80) NOT NULL DEFAULT 'client',
    entity_id INT UNSIGNED NULL,
    sensitive_field VARCHAR(100) NOT NULL DEFAULT 'nir',
    reason VARCHAR(500) NULL,
    action VARCHAR(80) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    details VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sensitive_client (client_id, created_at),
    CONSTRAINT fk_sensitive_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sensitive_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NULL,
    backup_type ENUM('manual','automatic') NOT NULL DEFAULT 'manual',
    kind ENUM('database','documents','full') NOT NULL DEFAULT 'database',
    status ENUM('running','success','created','failed','restored') NOT NULL DEFAULT 'running',
    size_bytes BIGINT UNSIGNED NULL,
    size BIGINT UNSIGNED NULL,
    sha256 CHAR(64) NULL,
    error_message TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at DATETIME NULL,
    INDEX idx_backups_created (created_at),
    CONSTRAINT fk_backups_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mutual_portals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    portal_url VARCHAR(500) NULL,
    url VARCHAR(500) NULL,
    instructions TEXT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mutual_portal_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mutual_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dossier_id INT UNSIGNED NOT NULL,
    portal_id INT UNSIGNED NULL,
    status ENUM('a_preparer','prete','envoyee','en_attente','acceptee','partielle','refusee','expiree','annulee','a_completer') NOT NULL DEFAULT 'a_preparer',
    external_reference VARCHAR(160) NULL,
    reference VARCHAR(160) NULL,
    requested_amount DECIMAL(10,2) NULL,
    ro_amount DECIMAL(10,2) NULL,
    rc_amount DECIMAL(10,2) NULL,
    rac_amount DECIMAL(10,2) NULL,
    approved_amount DECIMAL(10,2) NULL,
    accepted_amount DECIMAL(10,2) NULL,
    sent_at DATETIME NULL,
    response_due_at DATETIME NULL,
    response_received_at DATETIME NULL,
    response_at DATETIME NULL,
    valid_until DATE NULL,
    refusal_reason TEXT NULL,
    document_id INT UNSIGNED NULL,
    has_identity TINYINT(1) NOT NULL DEFAULT 0,
    has_prescription TINYINT(1) NOT NULL DEFAULT 0,
    has_rights_certificate TINYINT(1) NOT NULL DEFAULT 0,
    has_quote TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    comment TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mutual_status_due (status, response_due_at),
    UNIQUE KEY uq_mutual_dossier (dossier_id),
    CONSTRAINT fk_mutual_request_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    CONSTRAINT fk_mutual_request_portal FOREIGN KEY (portal_id) REFERENCES mutual_portals(id) ON DELETE SET NULL,
    CONSTRAINT fk_mutual_request_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    preset_key VARCHAR(80) NOT NULL,
    prompt TEXT NULL,
    provider VARCHAR(80) NOT NULL DEFAULT 'local',
    input_summary VARCHAR(500) NULL,
    output_text MEDIUMTEXT NULL,
    response MEDIUMTEXT NULL,
    context_type VARCHAR(80) NULL,
    context_id INT UNSIGNED NULL,
    status ENUM('success','failed','blocked') NOT NULL DEFAULT 'success',
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_logs_created (created_at),
    CONSTRAINT fk_ai_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('session_timeout_minutes', '30'),
    ('login_max_attempts', '5'),
    ('login_lockout_minutes', '15'),
    ('force_local_network_only', '0'),
    ('allowed_local_subnets', '127.0.0.1/32,::1/128,192.168.0.0/16,10.0.0.0/8,172.16.0.0/12'),
    ('encryption_key', ''),
    ('app_encryption_key', ''),
    ('ai_enabled', '1'),
    ('ai_provider', 'local'),
    ('ai_model', 'rules-v2'),
    ('ai_api_key_encrypted', ''),
    ('boutique_name', 'Oopticien Pro'),
    ('google_maps_link', '')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO message_templates (name, channel, event_key, subject, body) VALUES
    ('Lunettes prêtes - e-mail', 'email', 'glasses_ready', 'Vos lunettes sont prêtes',
     'Bonjour {{client_prenom}}, vos lunettes pour le dossier {{dossier_numero}} sont prêtes. Vous pouvez venir les récupérer chez {{boutique_nom}}.'),
    ('Lunettes prêtes - SMS', 'sms', 'glasses_ready', NULL,
     'Bonjour {{client_prenom}}, vos lunettes ({{dossier_numero}}) sont prêtes chez {{boutique_nom}}.'),
    ('Pièces mutuelle manquantes', 'email', 'mutual_missing', 'Pièces nécessaires pour votre dossier',
     'Bonjour {{client_prenom}}, il manque des pièces pour le dossier {{dossier_numero}} : {{pieces_manquantes}}.'),
    ('Relance mutuelle', 'email', 'mutual_followup', 'Suivi de votre dossier mutuelle',
     'Bonjour {{client_prenom}}, nous effectuons une relance concernant le dossier {{dossier_numero}}.'),
    ('Rappel rendez-vous', 'sms', 'appointment_reminder', NULL,
     'Bonjour {{client_prenom}}, rappel de votre rendez-vous le {{date_rendez_vous}} chez {{boutique_nom}}.'),
    ('Message libre', 'appel', 'free_text', NULL,
     'Bonjour {{client_prenom}}, {{message_libre}}')
ON DUPLICATE KEY UPDATE name = VALUES(name), subject = VALUES(subject), body = VALUES(body), is_active = 1;

UPDATE message_templates SET content = body WHERE content IS NULL;

INSERT INTO message_templates (name, channel, event_key, subject, body, content) VALUES
    ('Dossier incomplet', 'email', 'folder_incomplete', 'Votre dossier doit être complété',
     'Bonjour {{prenom}}, des éléments manquent encore dans votre dossier. Merci de contacter {{nom_boutique}}.',
     'Bonjour {{prenom}}, des éléments manquent encore dans votre dossier. Merci de contacter {{nom_boutique}}.'),
    ('Mutuelle en attente', 'email', 'mutual_waiting', 'Votre dossier mutuelle est en cours',
     'Bonjour {{prenom}}, votre demande mutuelle est toujours en attente. Nous assurons son suivi.',
     'Bonjour {{prenom}}, votre demande mutuelle est toujours en attente. Nous assurons son suivi.'),
    ('Relance client non venu', 'sms', 'client_not_come', NULL,
     'Bonjour {{prenom}}, vos lunettes vous attendent chez {{nom_boutique}}. {{lien_google_maps}}',
     'Bonjour {{prenom}}, vos lunettes vous attendent chez {{nom_boutique}}. {{lien_google_maps}}'),
    ('Paiement RAC à régulariser', 'email', 'rac_payment', 'Règlement de votre reste à charge',
     'Bonjour {{prenom}}, merci de contacter {{nom_boutique}} au sujet du règlement de votre dossier.',
     'Bonjour {{prenom}}, merci de contacter {{nom_boutique}} au sujet du règlement de votre dossier.'),
    ('Rendez-vous ou passage boutique', 'sms', 'appointment', NULL,
     'Bonjour {{prenom}}, rappel de votre passage prévu le {{date}} chez {{nom_boutique}}.',
     'Bonjour {{prenom}}, rappel de votre passage prévu le {{date}} chez {{nom_boutique}}.')
ON DUPLICATE KEY UPDATE name=VALUES(name),subject=VALUES(subject),body=VALUES(body),content=VALUES(content),is_active=1;

INSERT INTO mutual_portals (name, portal_url) VALUES
    ('Almerys', NULL), ('Carte Blanche', NULL), ('Harmonie Mutuelle', NULL),
    ('iSanté', NULL), ('Kalixia', NULL), ('MGEN', NULL), ('Mutuelle Générale', NULL),
    ('Santéclair', NULL), ('Sévéane', NULL), ('SP Santé', NULL),
    ('Viamedis', NULL), ('Aésio', NULL), ('Malakoff Humanis', NULL),
    ('SwissLife', NULL), ('Autre organisme', NULL)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO mutual_portals (name, portal_url, url) VALUES
    ('Oxantis', NULL, NULL), ('Viamedis', NULL, NULL), ('Korelio', NULL, NULL),
    ('iSanté', NULL, NULL), ('Génération', NULL, NULL), ('TP Plus', NULL, NULL),
    ('APGIS', NULL, NULL), ('Hélium', NULL, NULL), ('AmeliPro', NULL, NULL),
    ('SPSanté', NULL, NULL), ('Groupe Mutualiste RATP', NULL, NULL),
    ('CGRM', NULL, NULL), ('Ociane', NULL, NULL), ('Carte Blanche', NULL, NULL),
    ('Actil', NULL, NULL)
ON DUPLICATE KEY UPDATE name = VALUES(name);

SET FOREIGN_KEY_CHECKS = 1;
