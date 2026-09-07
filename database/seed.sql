-- Oopticien Pro V1 — données fictives de démonstration
-- À importer après database/install.sql

USE oopticien_pro;

INSERT INTO users (id, name, email, password_hash, role, is_active) VALUES
(1, 'Alice Admin', 'admin@oopticien.local', '$2y$10$Ze14MEWStis9ZHfp3s8avOkWtcRXQHlF4UGUI4OKOsperCKw38fpm', 'admin', 1),
(2, 'Paul Directeur', 'patron@oopticien.local', '$2y$10$OiUUv1oNoCWyWwEo6vup0OL7ubPXrZ8nLztO7H.tHkwEirEs2/oEi', 'patron', 1),
(3, 'Emma Conseillère', 'employe@oopticien.local', '$2y$10$jv1UakOI5u8CBBlBclLus.21ZPfQT3ncU87osiSjoRlSP4e1NgX7u', 'employe', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = VALUES(role), is_active = 1;

INSERT INTO settings (setting_key, setting_value, description) VALUES
('mutual_followup_hours_open', '48', 'Délai de relance mutuelle en heures ouvrées'),
('pec_auto_accept_days_open', '5', 'Passage automatique en PEC acceptée après la demande, en jours ouvrés'),
('invoice_followup_days_open', '3', 'Délai de facturation après PEC en jours ouvrés'),
('ro_payment_followup_days_open', '7', 'Délai de contrôle paiement RO en jours ouvrés'),
('rc_payment_followup_days_open', '10', 'Délai de contrôle paiement RC en jours ouvrés'),
('client_not_come_followup_1_days', '3', 'Première relance client non venu'),
('client_not_come_followup_2_days', '7', 'Deuxième relance client non venu'),
('client_not_come_followup_3_days', '14', 'Troisième relance client non venu'),
('automation_interval_minutes', '15', 'Fréquence conseillée du moteur automatique en minutes'),
('automation_last_run_at', '', 'Dernière exécution du moteur automatique'),
('automation_last_summary', '', 'Résumé de la dernière exécution automatique'),
('inbound_mail_last_run_at', '', 'Dernière lecture de la boîte des bons de livraison'),
('inbound_mail_last_error', '', 'Dernière erreur de lecture de la boîte des bons de livraison'),
('assistant_draft_minutes', '60', 'Durée de validité des actions préparées par l’assistant')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description);

INSERT INTO clients
(id, fiche_number, first_name, last_name, phone, email, address, social_security_number, social_security_scheme, mutual_name, membership_number, notes, created_by)
VALUES
(1, 'F-1001', 'Camille', 'Martin', '06 12 34 56 70', 'camille.martin@example.test', '12 rue des Lilas, 75012 Paris', '293047512345678', 'Régime général', 'Harmonie Démo', 'HD-84721', 'Préfère être appelée en fin de journée.', 2),
(2, 'F-1002', 'Lucas', 'Bernard', '06 23 45 67 81', 'lucas.bernard@example.test', '8 avenue Verte, 92100 Boulogne', '185119912345612', 'Régime général', 'Santé Exemple', 'SE-20148', NULL, 3),
(3, 'F-1003', 'Inès', 'Robert', '06 34 56 78 92', 'ines.robert@example.test', '4 place du Marché, 94000 Créteil', '298086612345623', 'Régime général', 'Mutuelle Fictive', 'MF-99740', 'Cliente disponible le samedi matin.', 3)
ON DUPLICATE KEY UPDATE phone = VALUES(phone), mutual_name = VALUES(mutual_name);

INSERT INTO dossiers
(id, client_id, prescription_status, mutual_status, folder_status, quote_date, pec_sent_at, pec_response_at, pec_reference, invoice_date, teletrans_status, teletrans_date, ro_amount, rc_amount, rac_amount, total_amount, total_warning, optician_comment, next_action, priority, created_by)
VALUES
(1, 1, 'oui', 'pec_acceptee', 'cloture', DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 29 DAY), DATE_SUB(NOW(), INTERVAL 27 DAY), 'PEC-DEMO-1001', DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'oui', DATE_SUB(CURDATE(), INTERVAL 24 DAY), 120.00, 280.00, 50.00, 450.00, 0, 'Dossier fictif soldé et remis.', 'Aucune', 'normale', 2),
(2, 2, 'oui', 'en_attente', 'demande_mutuelle', DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, NULL, NULL, 'non', NULL, 90.00, 310.00, 80.00, 480.00, 0, 'Réponse mutuelle en attente.', 'Relancer la mutuelle', 'haute', 3),
(3, 3, 'oui', 'pec_acceptee', 'pret', DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY), 'PEC-DEMO-1003', DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'oui', DATE_SUB(CURDATE(), INTERVAL 9 DAY), 100.00, 250.00, 95.00, 445.00, 0, 'Lunettes prêtes en boutique.', 'Prévenir la cliente', 'urgente', 3)
ON DUPLICATE KEY UPDATE folder_status = VALUES(folder_status), optician_comment = VALUES(optician_comment);

INSERT INTO payments
(id, dossier_id, payer, expected_amount, paid_amount, payment_date, payment_method, status, comment, created_by)
VALUES
(1, 1, 'ro', 120.00, 120.00, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'virement', 'encaisse', 'Paiement fictif.', 2),
(2, 1, 'rc', 280.00, 280.00, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'virement', 'encaisse', 'Paiement fictif.', 2),
(3, 1, 'client', 50.00, 50.00, DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'cb', 'encaisse', 'Paiement fictif.', 2),
(4, 2, 'ro', 90.00, 0.00, NULL, NULL, 'attendu', NULL, 3),
(5, 2, 'rc', 310.00, 0.00, NULL, NULL, 'attendu', NULL, 3),
(6, 2, 'client', 80.00, 0.00, NULL, NULL, 'attendu', NULL, 3),
(7, 3, 'ro', 100.00, 0.00, NULL, NULL, 'attendu', NULL, 3),
(8, 3, 'rc', 250.00, 250.00, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'virement', 'encaisse', NULL, 3),
(9, 3, 'client', 95.00, 95.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'cb', 'encaisse', NULL, 3)
ON DUPLICATE KEY UPDATE status = VALUES(status), paid_amount = VALUES(paid_amount);

INSERT INTO glass_orders
(id, dossier_id, supplier_name, order_reference, status, sent_at, confirmed_at, expected_at, received_at, mounted_at, ready_at, client_notified_at, comment)
VALUES
(1, 1, 'Ophtalmic', 'OPH-DEMO-01', 'prete', DATE_SUB(NOW(), INTERVAL 23 DAY), DATE_SUB(NOW(), INTERVAL 22 DAY), DATE_SUB(NOW(), INTERVAL 18 DAY), DATE_SUB(NOW(), INTERVAL 18 DAY), DATE_SUB(NOW(), INTERVAL 17 DAY), DATE_SUB(NOW(), INTERVAL 17 DAY), DATE_SUB(NOW(), INTERVAL 17 DAY), 'Commande fictive terminée.'),
(2, 3, 'Ophtalmic', 'OPH-DEMO-03', 'prete', DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, 'Lunettes disponibles en boutique.')
ON DUPLICATE KEY UPDATE status = VALUES(status), ready_at = VALUES(ready_at);

INSERT INTO credits
(id, client_id, dossier_id, label, source, initial_amount, used_amount, remaining_amount, comment, created_by)
VALUES
(1, 1, 1, 'Avantage monture démo', 'commercial', 80.00, 50.00, 30.00, 'Avoir fictif.', 2),
(2, 3, NULL, 'Droit mutuelle restant', 'mutuelle', 150.00, 0.00, 150.00, 'Montant de démonstration.', 3)
ON DUPLICATE KEY UPDATE remaining_amount = VALUES(remaining_amount);

INSERT INTO tasks
(id, dossier_id, client_id, title, description, task_type, due_at, priority, status, assigned_to)
VALUES
(1, 2, 2, 'Vérifier la réponse mutuelle', 'PEC envoyée, réponse en attente.', 'mutuelle', DATE_SUB(NOW(), INTERVAL 1 DAY), 'haute', 'a_faire', 3),
(2, 3, 3, 'Prévenir le client : lunettes prêtes', 'Lunettes reçues et montées.', 'client_a_prevenir', NOW(), 'haute', 'a_faire', 3),
(3, 3, 3, 'Vérifier paiement RO', 'Télétransmission effectuée.', 'paiement_ro', DATE_ADD(NOW(), INTERVAL 1 DAY), 'haute', 'a_faire', 2)
ON DUPLICATE KEY UPDATE due_at = VALUES(due_at), status = VALUES(status);

INSERT INTO action_history (user_id, entity_type, entity_id, action, details, ip_address) VALUES
(2, 'client', 1, 'creation', 'Création de la fiche fictive F-1001', '127.0.0.1'),
(3, 'dossier', 2, 'creation', 'Création du dossier de démonstration', '127.0.0.1'),
(3, 'dossier', 3, 'mise_a_jour', 'Commande reçue, dossier prêt', '127.0.0.1');
