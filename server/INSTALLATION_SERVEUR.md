# Installation du PC serveur Oopticien Pro

## Configuration recommandée

Pour une boutique, la configuration la plus simple à maintenir est un PC dédié sous Windows 11 Pro, relié au routeur en Ethernet, avec un SSD, 16 Go de mémoire et un petit onduleur. Ce PC ne doit pas servir à la navigation web quotidienne, aux e-mails ou à l’installation de logiciels inutiles.

XAMPP fonctionne pour une installation strictement interne, mais Apache Friends précise qu’il est prévu à l’origine pour le développement. Il faut donc le limiter au réseau privé et le sécuriser avant d’y placer les vraies données clients.

Version Windows retenue : **XAMPP 8.2.12 64 bits**, installé dans `C:\xampp`.

Téléchargement officiel : <https://www.apachefriends.org/fr/download.html>

## Installation

1. Installer XAMPP 8.2.12 64 bits dans `C:\xampp`.
2. Copier le dossier de l’application dans `C:\xampp\htdocs\oopticien-pro`.
3. Importer `database/install.sql`, les migrations puis les données de la boutique.
4. Si la base vient d’un ancien PC, copier également le dossier privé `C:\xampp\oopticien-pro-storage`. Sans lui, les anciens documents et la clé de chiffrement NIR ne suivent pas la base.
5. Ouvrir PowerShell avec **Exécuter en tant qu’administrateur**, puis installer le lecteur PDF Cosium :

```powershell
Set-ExecutionPolicy -Scope Process Bypass
& 'C:\xampp\htdocs\oopticien-pro\server\install-pdf-tools.ps1'
```

6. Vérifier que la page **Import PDF Cosium** affiche « Lecteur PDF prêt ».
7. Vérifier l’application sur `http://127.0.0.1/oopticien-pro/`.
8. Dans le même PowerShell administrateur, lancer :

```powershell
Set-ExecutionPolicy -Scope Process Bypass
& 'C:\xampp\htdocs\oopticien-pro\server\install-autostart.ps1' -KeepAwake
```

Le script :

- installe Apache et MariaDB comme services Windows automatiques ;
- redémarre les services s’ils tombent en panne ;
- vérifie le site après chaque démarrage de Windows ;
- ouvre uniquement le port HTTP aux machines du réseau local privé ;
- empêche la mise en veille et l’hibernation lorsque `-KeepAwake` est utilisé ;
- écrit son journal dans `C:\ProgramData\OopticienPro\server-watchdog.log`.
- lance toutes les 15 minutes les transitions PEC, les relances, les contrôles RO/RC et la lecture des bons de livraison reçus par e-mail.

## Boîte e-mail des bons de livraison

La solution la plus simple est une boîte dédiée accessible en IMAP, par exemple `livraisons@votre-domaine.fr`. Les messages du verrier doivent être redirigés vers cette boîte avec le bon de livraison PDF en pièce jointe.

1. Copier `config/inbound_mail.local.example.php` vers `config/inbound_mail.local.php`.
2. Renseigner le serveur IMAP, l’adresse, le mot de passe et les expéditeurs autorisés.
3. Passer `enabled` à `true`.
4. Dans **Paramètres**, utiliser **Lancer et vérifier maintenant**.

Le lecteur cherche d’abord le numéro Oopticien (`D-01234`), puis la référence de commande verrier et enfin un nom complet unique. S’il hésite entre plusieurs dossiers, il ne modifie rien et classe le bon en **À vérifier**.

Pour Gmail ou Microsoft 365, un mot de passe d’application ou l’activation IMAP par l’administrateur de la messagerie peut être nécessaire. Le mot de passe reste uniquement dans le fichier local du serveur et n’est jamais affiché dans le site.

Le moniteur peut s’éteindre normalement : cela n’arrête pas le serveur.

## Adresse IP du serveur

La meilleure méthode est de créer une **réservation DHCP** dans le routeur de la boutique. Par exemple, réserver `192.168.1.50` à l’adresse réseau du PC serveur.

Les autres postes utiliseront ensuite :

```text
http://192.168.1.50/oopticien-pro/
```

Ne pas ouvrir ce port sur Internet et ne pas créer de redirection de port dans la box.

## Réglages indispensables avant les vraies données

- Mettre un mot de passe fort au compte administrateur MariaDB et créer un utilisateur SQL réservé à l’application.
- Laisser MariaDB accessible uniquement depuis `127.0.0.1` ; le port 3306 ne doit pas être ouvert sur le réseau.
- Laisser phpMyAdmin accessible uniquement depuis le PC serveur.
- Activer BitLocker sur le disque du PC serveur.
- Mettre en place HTTPS sur le réseau local avant l’utilisation quotidienne.
- Faire une sauvegarde automatique quotidienne vers un second support, plus une copie chiffrée hors du PC.
- Tester une restauration de sauvegarde au moins une fois par trimestre.
- Dans le BIOS, activer **Restore on AC Power Loss / Power On** afin que le PC redémarre seul après une coupure électrique.
- Utiliser un onduleur pour éviter les arrêts brutaux et la corruption de la base.

## Après une coupure ou un redémarrage

Windows démarre, puis MariaDB et Apache se lancent sans ouverture de session. Trente secondes après le démarrage, la tâche `Oopticien Pro - Vérification serveur` contrôle le site. Les opticiens peuvent alors rouvrir l’adresse IP habituelle depuis leur poste.

Pour contrôler manuellement :

```powershell
Get-Service Apache2.4, mysql
Get-Content 'C:\ProgramData\OopticienPro\server-watchdog.log' -Tail 20
```
