<?php
declare(strict_types=1);

return [
    // Passer à true uniquement après avoir testé les identifiants IMAP.
    'enabled' => false,

    // Exemples :
    // Gmail     : {imap.gmail.com:993/imap/ssl}INBOX
    // Microsoft : {outlook.office365.com:993/imap/ssl}INBOX
    // OVH       : {ssl0.ovh.net:993/imap/ssl}INBOX
    'mailbox' => '{imap.votre-hebergeur.fr:993/imap/ssl}INBOX',
    'username' => 'livraisons@votre-domaine.fr',
    'password' => 'MOT_DE_PASSE_OU_MOT_DE_PASSE_APPLICATION',

    // Il est fortement conseillé de limiter les expéditeurs autorisés.
    // Une adresse exacte ou un domaine commençant par @ sont acceptés.
    'sender_allowlist' => [
        'livraison@verrier.example',
        '@verrier.example',
    ],
    'subject_keywords' => ['bon de livraison', 'livraison', 'BL'],
    'lookback_days' => 14,
    'max_messages_per_run' => 30,
    'max_attachment_mb' => 10,
    'mark_seen' => true,
];
