# Configuration de l’e-mail

Oopticien Pro envoie les e-mails avec PHPMailer par une connexion SMTP authentifiée. PHPMailer est déjà installé.

## Fichier à modifier

Sur l’installation XAMPP :

`C:\xampp\htdocs\oopticien-pro\config\mail.local.php`

Dans le dossier de travail :

`C:\Users\a\Desktop\oopticien\outil\oopticien-pro\config\mail.local.php`

Remplir les valeurs fournies par l’hébergeur de l’adresse e-mail de la boutique :

```php
return [
    'host' => 'smtp.votre-hebergeur.fr',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'boutique@votre-domaine.fr',
    'password' => 'MOT_DE_PASSE_SMTP',
    'from_email' => 'boutique@votre-domaine.fr',
    'from_name' => 'Nom de la boutique',
    'reply_to' => 'boutique@votre-domaine.fr',
    'timeout' => 15,
];
```

Réglages habituels :

- port `587` avec `tls` ;
- port `465` avec `ssl` ;
- `none` uniquement si le fournisseur l’impose sur un réseau de confiance.

Le mot de passe demandé est le mot de passe SMTP ou le mot de passe d’application fourni par le service de messagerie. Ne pas utiliser un mot de passe personnel sans vérifier la procédure du fournisseur.

## Vérification

1. Enregistrer `mail.local.php`.
2. Ouvrir **Administration → Paramètres**.
3. Vérifier que la rubrique E-mail affiche **Configuré**.
4. Créer ou utiliser une fiche de test appartenant à la boutique.
5. Renseigner une adresse e-mail interne.
6. Mettre le dossier ou la commande à **Prêt**.
7. Ouvrir **Prévenir le client**, relire le texte puis envoyer.
8. Vérifier la boîte de réception et l’historique du message.

Si l’envoi échoue, le motif est affiché et le client n’est pas marqué comme prévenu.

## Protection

- ne jamais joindre `mail.local.php` à un e-mail ;
- ne pas le copier dans un dépôt public ;
- limiter sa lecture au compte qui exécute Apache ;
- changer immédiatement le mot de passe SMTP s’il a été divulgué.
