<?php
declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config/config.php';
        date_default_timezone_set($config['timezone'] ?? 'Europe/Paris');
    }
    return $config;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) (app_config()['base_url'] ?? ''), '/');
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : app_url($path)));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function has_role(array|string $roles): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], (array) $roles, true);
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Méthode non autorisée.');
    }
}

function post_string(string $key, int $maxLength = 0): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return $maxLength > 0 ? mb_substr($value, 0, $maxLength) : $value;
}

function post_nullable(string $key): ?string
{
    $value = post_string($key);
    return $value === '' ? null : $value;
}

function post_decimal(string $key): float
{
    $value = str_replace([' ', ','], ['', '.'], post_string($key));
    return is_numeric($value) ? round((float) $value, 2) : 0.0;
}

function format_euros(mixed $amount): string
{
    return number_format((float) $amount, 2, ',', ' ') . ' €';
}

function format_date(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTime($value))->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    } catch (Throwable) {
        return '—';
    }
}

function calculate_total(float $ro, float $rc, float $rac): float
{
    return round($ro + $rc + $rac, 2);
}

function has_total_warning(float $ro, float $rc, float $rac, float $total): bool
{
    return abs(calculate_total($ro, $rc, $rac) - $total) > 0.01;
}

function dossier_control(array $dossier): array
{
    $issues = [];
    if (($dossier['prescription_status'] ?? 'attente') === 'attente') {
        $issues[] = 'ordonnance à vérifier';
    }
    if (empty($dossier['quote_date']) && !in_array($dossier['folder_status'] ?? '', ['brouillon', 'annule'], true)) {
        $issues[] = 'date du devis absente';
    }
    if ((int) ($dossier['total_warning'] ?? 0) === 1) {
        $issues[] = 'montants incohérents';
    }
    if (in_array($dossier['mutual_status'] ?? '', ['envoyee', 'en_attente'], true) && empty($dossier['pec_sent_at'])) {
        $issues[] = 'PEC indiquée envoyée sans date d’envoi';
    }
    if (($dossier['mutual_status'] ?? '') === 'pec_acceptee' && empty($dossier['pec_response_at'])) {
        $issues[] = 'date de réponse PEC absente';
    }
    if (in_array($dossier['folder_status'] ?? '', ['facture', 'teletransmis', 'commande_a_faire', 'commande_envoyee', 'verres_recus', 'montage', 'pret', 'client_prevenu', 'remis', 'cloture'], true)
        && empty($dossier['invoice_date'])) {
        $issues[] = 'date de facture absente';
    }
    if (($dossier['teletrans_status'] ?? 'non') === 'oui' && empty($dossier['teletrans_date'])) {
        $issues[] = 'date de télétransmission absente';
    }

    return [
        'ready' => $issues === [],
        'issues' => $issues,
        'label' => $issues === [] ? 'Données cohérentes' : 'À compléter',
    ];
}

function normalize_nir(string $nir): string
{
    return preg_replace('/\D+/', '', $nir) ?? '';
}

function mask_nir(?string $nir): string
{
    $digits = normalize_nir((string) $nir);
    if ($digits === '') {
        return 'Non renseigné';
    }
    $first = substr($digits, 0, 1) ?: '*';
    $year = str_pad(substr($digits, 1, 2), 2, '*');
    return $first . ' ' . $year . ' ** ** *** *** **';
}

function excel_date_to_sql(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $timestamp = ((int) $value - 25569) * 86400;
        return gmdate('Y-m-d', $timestamp);
    }
    foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, trim((string) $value));
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }
    if (preg_match('/\b(\d{1,2}[\/-]\d{1,2}[\/-]\d{4})\b/', (string) $value, $match)) {
        foreach (['d/m/Y', 'd-m-Y'] as $format) {
            $date = DateTime::createFromFormat('!' . $format, $match[1]);
            if ($date instanceof DateTime) {
                return $date->format('Y-m-d');
            }
        }
    }
    return null;
}

function get_setting(string $key, mixed $default = null): mixed
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $cache[$key] = ($value === false ? $default : $value);
    } catch (Throwable) {
        return $default;
    }
}

function log_action(string $entityType, ?int $entityId, string $action, string $details = ''): void
{
    $user = current_user();
    $stmt = db()->prepare(
        'INSERT INTO action_history (user_id, entity_type, entity_id, action, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user['id'] ?? null,
        $entityType,
        $entityId,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? 'CLI',
    ]);
}

function add_business_days(string $date, int $days): string
{
    $result = new DateTime($date);
    $added = 0;
    while ($added < $days) {
        $result->modify('+1 day');
        if ((int) $result->format('N') < 6) {
            $added++;
        }
    }
    return $result->format('Y-m-d H:i:s');
}

function add_business_hours(string $date, int $hours): string
{
    $result = new DateTime($date);
    $added = 0;
    while ($added < $hours) {
        $result->modify('+1 hour');
        if ((int) $result->format('N') < 6) {
            $added++;
        }
    }
    return $result->format('Y-m-d H:i:s');
}

function create_task_if_not_exists(
    ?int $dossierId,
    ?int $clientId,
    string $title,
    string $type,
    string $dueAt,
    string $priority = 'haute'
): bool {
    $stmt = db()->prepare(
        "SELECT id FROM tasks
         WHERE title = ? AND dossier_id <=> ? AND client_id <=> ?
           AND status IN ('a_faire','en_cours','reportee') LIMIT 1"
    );
    $stmt->execute([$title, $dossierId, $clientId]);
    if ($stmt->fetch()) {
        return false;
    }

    $stmt = db()->prepare(
        'INSERT INTO tasks (dossier_id, client_id, title, task_type, due_at, priority, status)
         VALUES (?, ?, ?, ?, ?, ?, "a_faire")'
    );
    $stmt->execute([$dossierId, $clientId, $title, $type, $dueAt, $priority]);
    return true;
}

function status_label(string $status): string
{
    $labels = [
        'non_envoyee' => 'Non envoyée', 'envoyee' => 'Envoyée', 'en_attente' => 'En attente',
        'pec_acceptee' => 'PEC acceptée', 'pec_refusee' => 'PEC refusée', 'incomplete' => 'Incomplète',
        'brouillon' => 'Brouillon', 'devis' => 'Devis', 'demande_mutuelle' => 'Demande mutuelle',
        'a_facturer' => 'À facturer', 'facture' => 'Facturé', 'teletransmis' => 'Télétransmis',
        'commande_a_faire' => 'Commande à faire', 'commande_envoyee' => 'Commande envoyée',
        'verres_recus' => 'Verres reçus', 'montage' => 'Montage', 'pret' => 'Prêt',
        'client_prevenu' => 'Client prévenu', 'remis' => 'Remis', 'cloture' => 'Clôturé', 'sav' => 'SAV', 'derogation' => 'Dérogation',
        'bloque' => 'Bloqué', 'annule' => 'Annulé', 'oui' => 'Oui', 'non' => 'Non',
        'attendu' => 'Attendu', 'partiel' => 'Partiel', 'encaisse' => 'Encaissé',
        'retard' => 'En retard', 'cheque_caution' => 'Chèque de caution', 'non_applicable' => 'Non applicable',
        'lunettes' => 'Lunettes', 'lentilles' => 'Lentilles',
        'basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute', 'urgente' => 'Urgente',
        'critique' => 'Critique', 'a_faire' => 'À faire', 'en_cours' => 'En cours',
        'terminee' => 'Terminée', 'reportee' => 'Reportée', 'annulee' => 'Annulée',
        'a_preparer' => 'À préparer', 'confirmee' => 'Confirmée', 'en_fabrication' => 'En fabrication',
        'expediee' => 'Expédiée', 'recue' => 'Reçue', 'prete' => 'Prête', 'incident' => 'Incident',
        'ro' => 'RO', 'rc' => 'RC', 'client' => 'Client',
        'mutuelle' => 'Mutuelle', 'cmu' => 'CMU', 'commercial' => 'Commercial', 'autre' => 'Autre',
        'admin' => 'Administrateur', 'patron' => 'Patron', 'employe' => 'Employé',
        'cb' => 'Carte bancaire', 'cheque' => 'Chèque', 'especes' => 'Espèces', 'virement' => 'Virement',
        'creation' => 'Création', 'mise_a_jour' => 'Mise à jour', 'connexion' => 'Connexion',
        'deconnexion' => 'Déconnexion', 'terminee' => 'Terminée', 'export_csv' => 'Export CSV',
        'import_csv' => 'Import CSV', 'generation_relances' => 'Génération des relances',
        'paiement_ro' => 'Paiement RO', 'paiement_rc' => 'Paiement RC', 'paiement_rac' => 'Paiement RAC',
        'facturation' => 'Facturation', 'commande' => 'Commande', 'client_a_prevenir' => 'Client à prévenir',
        'client_non_venu' => 'Client non venu', 'incoherence' => 'Incohérence',
        'appel' => 'Appel', 'sms' => 'SMS', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp',
        'interne' => 'Interne', 'a_preparer' => 'À préparer', 'envoye' => 'Envoyé',
        'echec' => 'Échec', 'non_envoye' => 'Non envoyé',
        'fait_manuellement' => 'Contact confirmé', 'test_message' => 'Vérification du message',
        'message_envoye' => 'Message envoyé',
        'suppression' => 'Suppression',
        'a_completer' => 'À compléter', 'acceptee' => 'Acceptée', 'refusee' => 'Refusée',
        'database' => 'Base de données', 'documents' => 'Documents', 'success' => 'Réussie',
        'failed' => 'Échec', 'running' => 'En cours', 'uploaded' => 'Déposé',
        'review' => 'À vérifier', 'validated' => 'Validé', 'rejected' => 'Rejeté',
        'traite' => 'Traité', 'a_verifier' => 'À vérifier', 'ignore' => 'Ignoré', 'erreur' => 'Erreur',
        'security_update' => 'Sécurité mise à jour', 'nir_reveal' => 'Affichage du NIR',
        'document_download' => 'Téléchargement de document',
        'partielle' => 'Partielle', 'expiree' => 'Expirée',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function status_badge(string $status): string
{
    $success = ['pec_acceptee', 'facture', 'teletransmis', 'verres_recus', 'pret', 'remis', 'cloture', 'oui', 'encaisse', 'terminee', 'recue', 'prete', 'envoye', 'fait_manuellement', 'traite'];
    $danger = ['pec_refusee', 'bloque', 'annule', 'retard', 'critique', 'urgente', 'incident', 'annulee', 'erreur'];
    $warning = ['envoyee', 'en_attente', 'demande_mutuelle', 'a_facturer', 'commande_a_faire', 'partiel', 'cheque_caution', 'haute', 'reportee', 'sav', 'derogation', 'a_verifier'];
    $class = in_array($status, $success, true) ? 'success' : (in_array($status, $danger, true) ? 'danger' : (in_array($status, $warning, true) ? 'warning' : 'neutral'));
    return '<span class="badge badge-' . $class . '">' . e(status_label($status)) . '</span>';
}

function dossier_number(int $id): string
{
    return 'D-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function pagination_items(int $currentPage, int $totalPages, int $radius = 2): array
{
    $totalPages = max(1, $totalPages);
    $currentPage = max(1, min($totalPages, $currentPage));
    $pages = [1, $totalPages];
    for ($page = max(1, $currentPage - $radius); $page <= min($totalPages, $currentPage + $radius); $page++) {
        $pages[] = $page;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $items = [];
    $previous = null;
    foreach ($pages as $page) {
        if ($previous !== null && $page - $previous > 1) {
            $items[] = null;
        }
        $items[] = $page;
        $previous = $page;
    }
    return $items;
}
