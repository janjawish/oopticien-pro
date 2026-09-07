<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');

$settings = [
    'session_timeout_minutes' => (string) get_setting('session_timeout_minutes', '30'),
    'login_max_attempts' => (string) get_setting('login_max_attempts', '5'),
    'login_lockout_minutes' => (string) get_setting('login_lockout_minutes', '15'),
    'force_local_network_only' => (string) get_setting('force_local_network_only', '0'),
    'allowed_local_subnets' => (string) get_setting('allowed_local_subnets', ''),
];
$legacyNirCount = (int) db()->query(
    "SELECT COUNT(*) FROM clients
     WHERE social_security_number IS NOT NULL AND social_security_number <> ''"
)->fetchColumn();
$recentAttempts = db()->query(
    'SELECT email, ip_address, success, attempted_at
     FROM login_attempts ORDER BY attempted_at DESC LIMIT 20'
)->fetchAll();
$recentSensitive = db()->query(
    'SELECT s.*, u.name AS user_name,
            CONCAT(c.first_name, " ", c.last_name) AS client_name
     FROM sensitive_access_logs s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN clients c ON c.id = s.client_id
     ORDER BY s.created_at DESC LIMIT 20'
)->fetchAll();
$keyReady = configured_encryption_key() !== null;

$pageTitle = 'Sécurité';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy"><h2>Protection de l’accès et des données sensibles</h2><p>Réglages réservés à l’administrateur. Les changements réseau prennent effet à la requête suivante.</p></div>
</section>

<div class="content-grid">
    <section class="card">
        <div class="card-header"><div><h2>Politique d’accès</h2><p>Session, verrouillage et réseau local</p></div></div>
        <form method="post" action="<?= e(app_url('actions/security_save.php')) ?>" data-unsaved-warning>
            <?= csrf_field() ?>
            <div class="form-grid">
                <label class="field"><span>Expiration de session (minutes)</span><input type="number" name="session_timeout_minutes" min="5" max="720" required value="<?= e($settings['session_timeout_minutes']) ?>"></label>
                <label class="field"><span>Tentatives avant verrouillage</span><input type="number" name="login_max_attempts" min="2" max="20" required value="<?= e($settings['login_max_attempts']) ?>"></label>
                <label class="field"><span>Durée du verrouillage (minutes)</span><input type="number" name="login_lockout_minutes" min="1" max="1440" required value="<?= e($settings['login_lockout_minutes']) ?>"></label>
                <label class="field"><span>Limiter au réseau local</span><select name="force_local_network_only"><option value="0" <?= $settings['force_local_network_only'] !== '1' ? 'selected' : '' ?>>Non</option><option value="1" <?= $settings['force_local_network_only'] === '1' ? 'selected' : '' ?>>Oui</option></select></label>
                <label class="field field-full"><span>Sous-réseaux autorisés (CIDR, séparés par des virgules)</span><textarea name="allowed_local_subnets" required><?= e($settings['allowed_local_subnets']) ?></textarea><small>Conservez 127.0.0.1/32 et ::1/128 pour un accès depuis le serveur.</small></label>
            </div>
            <div class="form-footer"><button class="btn btn-primary" type="submit">Enregistrer la politique</button></div>
        </form>
    </section>

    <section class="card">
        <div class="card-header"><div><h2>Chiffrement du NIR</h2><p>AES-256-GCM, clé stockée hors du dossier public</p></div><?= status_badge($keyReady ? 'oui' : 'non') ?></div>
        <p><?= $keyReady ? 'La clé est disponible. Sauvegardez aussi le dossier privé : sans cette clé, les NIR chiffrés ne sont pas récupérables.' : 'La clé ne peut pas être créée. Vérifiez les droits du dossier de stockage privé.' ?></p>
        <p><strong><?= $legacyNirCount ?></strong> NIR encore présent(s) dans l’ancienne colonne en clair.</p>
        <?php if ($legacyNirCount > 0 && $keyReady): ?>
            <form method="post" action="<?= e(app_url('actions/migrate_nir.php')) ?>" data-confirm="Confirmer le chiffrement des NIR existants ? Une sauvegarde est recommandée.">
                <?= csrf_field() ?>
                <input type="hidden" name="confirmation" value="CHIFFRER">
                <button class="btn btn-warning" type="submit">Chiffrer les NIR existants</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="card table-card">
    <div class="card-header"><div><h2>Tentatives de connexion</h2><p>20 événements récents</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>E-mail</th><th>Adresse IP</th><th>Résultat</th></tr></thead><tbody>
    <?php foreach ($recentAttempts as $attempt): ?><tr><td><?= e(format_date($attempt['attempted_at'], true)) ?></td><td><?= e($attempt['email']) ?></td><td><?= e($attempt['ip_address']) ?></td><td><?= status_badge($attempt['success'] ? 'oui' : 'non') ?></td></tr><?php endforeach; ?>
    <?php if (!$recentAttempts): ?><tr><td colspan="4" class="empty-state">Aucune tentative enregistrée.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card table-card">
    <div class="card-header"><div><h2>Accès sensibles</h2><p>Révélations de NIR et téléchargements protégés</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Utilisateur</th><th>Client</th><th>Action</th><th>IP</th></tr></thead><tbody>
    <?php foreach ($recentSensitive as $access): ?><tr><td><?= e(format_date($access['created_at'], true)) ?></td><td><?= e($access['user_name'] ?: 'Système') ?></td><td><?= e($access['client_name'] ?: '—') ?></td><td><?= e(status_label($access['action'])) ?></td><td><?= e($access['ip_address']) ?></td></tr><?php endforeach; ?>
    <?php if (!$recentSensitive): ?><tr><td colspan="5" class="empty-state">Aucun accès sensible enregistré.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
