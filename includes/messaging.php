<?php
declare(strict_types=1);

function message_context(array $folder): array
{
    $today = date('d/m/Y');
    $shopName = (string) get_setting('boutique_name', app_config()['app_name'] ?? 'Oopticien Pro');
    return [
        '{{client_prenom}}' => (string) ($folder['first_name'] ?? ''),
        '{{client_nom}}' => (string) ($folder['last_name'] ?? ''),
        '{{dossier_numero}}' => isset($folder['id']) ? dossier_number((int) $folder['id']) : '',
        '{{boutique_nom}}' => $shopName,
        '{{date_rendez_vous}}' => '',
        '{{pieces_manquantes}}' => '',
        '{{message_libre}}' => '',
        '{{prenom}}' => (string) ($folder['first_name'] ?? ''),
        '{{nom}}' => (string) ($folder['last_name'] ?? ''),
        '{{telephone}}' => (string) ($folder['phone'] ?? ''),
        '{{date}}' => $today,
        '{{nom_boutique}}' => $shopName,
        '{{lien_google_maps}}' => (string) get_setting('google_maps_link', ''),
    ];
}

function render_message_template(string $text, array $context): string
{
    return strtr($text, $context);
}

function normalized_whatsapp_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) {
        $digits = '33' . substr($digits, 1);
    }
    return $digits;
}

function mark_client_notified(PDO $pdo, int $dossierId): void
{
    $stmt = $pdo->prepare('UPDATE glass_orders SET client_notified_at=COALESCE(client_notified_at,NOW()) WHERE dossier_id=?');
    $stmt->execute([$dossierId]);
    $stmt = $pdo->prepare(
        "UPDATE dossiers SET status_changed_at=IF(folder_status IN ('remis','cloture','sav'),status_changed_at,NOW()),
         folder_status=IF(folder_status IN ('remis','cloture','sav'),folder_status,'client_prevenu'),
         next_action=IF(folder_status IN ('remis','cloture','sav'),next_action,'Attendre le passage du client') WHERE id=?"
    );
    $stmt->execute([$dossierId]);
    $stmt = $pdo->prepare(
        "UPDATE tasks SET status='terminee',completed_at=NOW()
         WHERE dossier_id=? AND task_type='client_a_prevenir' AND status IN ('a_faire','en_cours','reportee')"
    );
    $stmt->execute([$dossierId]);
}
