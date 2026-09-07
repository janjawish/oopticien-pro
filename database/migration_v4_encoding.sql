-- Oopticien Pro V4 - réparation des libellés UTF-8
-- Cette migration ne modifie pas les données métier importées des patients.

USE oopticien_pro;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

UPDATE users
SET name = 'Emma Conseillère'
WHERE email = 'employe@oopticien.local'
  AND name = 'Emma Conseill?re';

UPDATE settings SET description = 'Délai de relance mutuelle en heures ouvrées'
WHERE setting_key = 'mutual_followup_hours_open';
UPDATE settings SET description = 'Délai de facturation après PEC en jours ouvrés'
WHERE setting_key = 'invoice_followup_days_open';
UPDATE settings SET description = 'Délai de contrôle paiement RO en jours ouvrés'
WHERE setting_key = 'ro_payment_followup_days_open';
UPDATE settings SET description = 'Délai de contrôle paiement RC en jours ouvrés'
WHERE setting_key = 'rc_payment_followup_days_open';
UPDATE settings SET description = 'Première relance client non venu'
WHERE setting_key = 'client_not_come_followup_1_days';
UPDATE settings SET description = 'Deuxième relance client non venu'
WHERE setting_key = 'client_not_come_followup_2_days';
UPDATE settings SET description = 'Troisième relance client non venu'
WHERE setting_key = 'client_not_come_followup_3_days';

UPDATE message_templates
SET name = 'Lunettes prêtes - e-mail',
    subject = 'Vos lunettes sont prêtes',
    body = 'Bonjour {{client_prenom}}, vos lunettes pour le dossier {{dossier_numero}} sont prêtes. Vous pouvez venir les récupérer chez {{boutique_nom}}.',
    content = 'Bonjour {{client_prenom}}, vos lunettes pour le dossier {{dossier_numero}} sont prêtes. Vous pouvez venir les récupérer chez {{boutique_nom}}.'
WHERE event_key = 'glasses_ready' AND channel = 'email';

UPDATE message_templates
SET name = 'Lunettes prêtes - SMS',
    subject = NULL,
    body = 'Bonjour {{client_prenom}}, vos lunettes ({{dossier_numero}}) sont prêtes chez {{boutique_nom}}.',
    content = 'Bonjour {{client_prenom}}, vos lunettes ({{dossier_numero}}) sont prêtes chez {{boutique_nom}}.'
WHERE event_key = 'glasses_ready' AND channel = 'sms';

UPDATE message_templates
SET name = 'Pièces mutuelle manquantes',
    subject = 'Pièces nécessaires pour votre dossier',
    body = 'Bonjour {{client_prenom}}, il manque des pièces pour le dossier {{dossier_numero}} : {{pieces_manquantes}}.',
    content = 'Bonjour {{client_prenom}}, il manque des pièces pour le dossier {{dossier_numero}} : {{pieces_manquantes}}.'
WHERE event_key = 'mutual_missing';

UPDATE message_templates
SET name = 'Relance mutuelle',
    subject = 'Suivi de votre dossier mutuelle',
    body = 'Bonjour {{client_prenom}}, nous effectuons une relance concernant le dossier {{dossier_numero}}.',
    content = 'Bonjour {{client_prenom}}, nous effectuons une relance concernant le dossier {{dossier_numero}}.'
WHERE event_key = 'mutual_followup';

UPDATE message_templates
SET name = 'Rappel rendez-vous',
    subject = NULL,
    body = 'Bonjour {{client_prenom}}, rappel de votre rendez-vous le {{date_rendez_vous}} chez {{boutique_nom}}.',
    content = 'Bonjour {{client_prenom}}, rappel de votre rendez-vous le {{date_rendez_vous}} chez {{boutique_nom}}.'
WHERE event_key = 'appointment_reminder';

UPDATE message_templates
SET name = 'Message libre',
    subject = NULL,
    body = 'Bonjour {{client_prenom}}, {{message_libre}}',
    content = 'Bonjour {{client_prenom}}, {{message_libre}}'
WHERE event_key = 'free_text';

UPDATE message_templates
SET name = 'Dossier incomplet',
    subject = 'Votre dossier doit être complété',
    body = 'Bonjour {{prenom}}, des éléments manquent encore dans votre dossier. Merci de contacter {{nom_boutique}}.',
    content = 'Bonjour {{prenom}}, des éléments manquent encore dans votre dossier. Merci de contacter {{nom_boutique}}.'
WHERE event_key = 'folder_incomplete';

UPDATE message_templates
SET name = 'Mutuelle en attente',
    subject = 'Votre dossier mutuelle est en cours',
    body = 'Bonjour {{prenom}}, votre demande mutuelle est toujours en attente. Nous assurons son suivi.',
    content = 'Bonjour {{prenom}}, votre demande mutuelle est toujours en attente. Nous assurons son suivi.'
WHERE event_key = 'mutual_waiting';

UPDATE message_templates
SET name = 'Relance client non venu',
    subject = NULL,
    body = 'Bonjour {{prenom}}, vos lunettes vous attendent chez {{nom_boutique}}. {{lien_google_maps}}',
    content = 'Bonjour {{prenom}}, vos lunettes vous attendent chez {{nom_boutique}}. {{lien_google_maps}}'
WHERE event_key = 'client_not_come';

UPDATE message_templates
SET name = 'Paiement RAC à régulariser',
    subject = 'Règlement de votre reste à charge',
    body = 'Bonjour {{prenom}}, merci de contacter {{nom_boutique}} au sujet du règlement de votre dossier.',
    content = 'Bonjour {{prenom}}, merci de contacter {{nom_boutique}} au sujet du règlement de votre dossier.'
WHERE event_key = 'rac_payment';

UPDATE message_templates
SET name = 'Rendez-vous ou passage boutique',
    subject = NULL,
    body = 'Bonjour {{prenom}}, rappel de votre passage prévu le {{date}} chez {{nom_boutique}}.',
    content = 'Bonjour {{prenom}}, rappel de votre passage prévu le {{date}} chez {{nom_boutique}}.'
WHERE event_key = 'appointment';

UPDATE messages
SET subject = REPLACE(subject, 'Vos lunettes sont pr?tes', 'Vos lunettes sont prêtes')
WHERE subject LIKE '%Vos lunettes sont pr?tes%';

UPDATE messages
SET template_name = REPLACE(template_name, 'Lunettes pr?tes', 'Lunettes prêtes')
WHERE template_name LIKE '%Lunettes pr?tes%';

CREATE TEMPORARY TABLE portal_encoding_repairs (
    bad_name VARCHAR(160) PRIMARY KEY,
    good_name VARCHAR(160) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO portal_encoding_repairs (bad_name, good_name) VALUES
    ('iSant?', 'iSanté'),
    ('Mutuelle G?n?rale', 'Mutuelle Générale'),
    ('Sant?clair', 'Santéclair'),
    ('S?v?ane', 'Sévéane'),
    ('SP Sant?', 'SP Santé'),
    ('A?sio', 'Aésio'),
    ('G?n?ration', 'Génération'),
    ('H?lium', 'Hélium'),
    ('SPSant?', 'SPSanté');

UPDATE mutual_portals good
JOIN portal_encoding_repairs repair ON repair.good_name = good.name
JOIN mutual_portals bad ON bad.name = repair.bad_name
SET good.url = COALESCE(good.url, bad.url),
    good.portal_url = COALESCE(good.portal_url, bad.portal_url),
    good.is_active = GREATEST(good.is_active, bad.is_active);

UPDATE mutual_requests request
JOIN mutual_portals bad ON bad.id = request.portal_id
JOIN portal_encoding_repairs repair ON repair.bad_name = bad.name
JOIN mutual_portals good ON good.name = repair.good_name
SET request.portal_id = good.id;

DELETE bad
FROM mutual_portals bad
JOIN portal_encoding_repairs repair ON repair.bad_name = bad.name;

DROP TEMPORARY TABLE portal_encoding_repairs;

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;
