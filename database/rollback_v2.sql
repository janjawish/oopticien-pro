-- ATTENTION : rollback DESTRUCTIF des seules structures V2.
-- Faites une sauvegarde avant exécution. Les tables et données V1 sont conservées.
USE oopticien_pro;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ai_logs;
DROP TABLE IF EXISTS mutual_requests;
DROP TABLE IF EXISTS mutual_portals;
DROP TABLE IF EXISTS backups;
DROP TABLE IF EXISTS sensitive_access_logs;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS message_templates;
DROP TABLE IF EXISTS import_field_mapping;
DROP TABLE IF EXISTS pdf_imports;
DROP TABLE IF EXISTS documents;

ALTER TABLE clients DROP COLUMN IF EXISTS social_security_number_encrypted;
ALTER TABLE messages
    DROP COLUMN IF EXISTS subject,
    DROP COLUMN IF EXISTS recipient,
    DROP COLUMN IF EXISTS provider,
    DROP COLUMN IF EXISTS error_message,
    DROP COLUMN IF EXISTS validated_by,
    DROP COLUMN IF EXISTS validated_at;
ALTER TABLE glass_orders
    DROP COLUMN IF EXISTS lens_right_sphere,
    DROP COLUMN IF EXISTS lens_right_cylinder,
    DROP COLUMN IF EXISTS lens_right_axis,
    DROP COLUMN IF EXISTS lens_right_addition,
    DROP COLUMN IF EXISTS lens_left_sphere,
    DROP COLUMN IF EXISTS lens_left_cylinder,
    DROP COLUMN IF EXISTS lens_left_axis,
    DROP COLUMN IF EXISTS lens_left_addition,
    DROP COLUMN IF EXISTS pupillary_distance,
    DROP COLUMN IF EXISTS lens_product,
    DROP COLUMN IF EXISTS lens_index,
    DROP COLUMN IF EXISTS treatment,
    DROP COLUMN IF EXISTS tint,
    DROP COLUMN IF EXISTS frame_reference,
    DROP COLUMN IF EXISTS mounting_comment,
    DROP COLUMN IF EXISTS ophtalmic_url;

DELETE FROM settings WHERE setting_key IN (
    'session_timeout_minutes','login_max_attempts','login_lockout_minutes',
    'force_local_network_only','allowed_local_subnets','encryption_key','app_encryption_key',
    'ai_enabled','ai_provider','ai_model','ai_api_key_encrypted',
    'boutique_name','google_maps_link'
);

SET FOREIGN_KEY_CHECKS = 1;
