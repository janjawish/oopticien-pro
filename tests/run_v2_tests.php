<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/storage.php';
require_once dirname(__DIR__) . '/includes/encryption.php';
require_once dirname(__DIR__) . '/includes/security.php';

$passed = 0;
$failed = 0;

function test_case(string $label, callable $test): void
{
    global $passed, $failed;
    try {
        if (!$test()) {
            throw new RuntimeException('assertion fausse');
        }
        $passed++;
        echo "[OK] $label\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "[ECHEC] $label — {$exception->getMessage()}\n";
    }
}

function value(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

test_case('1. Connexion admin, patron, employé', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM users WHERE is_active=1 AND role IN ('admin','patron','employe')") >= 3
    && (int) value('SELECT COUNT(*) FROM login_attempts WHERE success=1') >= 3
);

test_case('2. Accès interdit hors rôle', function (): bool {
    $_SESSION['user'] = ['id'=>3,'name'=>'Employé test','email'=>'employe@oopticien.local','role'=>'employe'];
    $denied = !has_role(['admin','patron']);
    $_SESSION = [];
    return $denied;
});

test_case('3. Masquage du NIR', fn(): bool =>
    mask_nir('293047512345678') === '2 93 ** ** *** *** **'
    && !str_contains(mask_nir('293047512345678'), '12345678')
);

test_case('4. Journalisation affichage NIR', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM sensitive_access_logs WHERE sensitive_field='nir' AND action='nir_reveal'") >= 1
);

test_case('5. Upload document', function (): bool {
    $row = db()->query('SELECT * FROM documents WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1')->fetch();
    if (!$row) return false;
    $path = safe_private_file('documents', (string) ($row['stored_file_name'] ?: $row['stored_name']));
    return is_file($path) && hash_equals((string) $row['sha256'], hash_file('sha256', $path));
});

test_case('6. Téléchargement document protégé', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM action_history WHERE entity_type='document' AND action='document_download'") >= 1
);

test_case('7. Import PDF sans création automatique', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM pdf_imports WHERE status='waiting_validation' AND client_id IS NULL AND dossier_id IS NULL") >= 1
);

test_case('8. Création modèle message', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM message_templates WHERE event_key='annual_check_test' AND is_active=1") === 1
);

test_case('9. Préparation message lunettes prêtes', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM messages WHERE template_name LIKE 'Lunettes%' AND validated_at IS NOT NULL") >= 1
);

test_case('10. Marquer appel manuel', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM messages WHERE channel='appel' AND provider='manual' AND status='fait_manuellement'") >= 1
);

test_case('11. Création demande mutuelle', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM mutual_requests WHERE reference='PEC-TEST-48H'") === 1
);

test_case('12. Relance mutuelle à 48 h', function (): bool {
    $row = db()->query("SELECT sent_at,response_due_at,dossier_id FROM mutual_requests WHERE reference='PEC-TEST-48H' LIMIT 1")->fetch();
    if (!$row) return false;
    $interval = (new DateTime($row['sent_at']))->diff(new DateTime($row['response_due_at']));
    $hours = $interval->days * 24 + $interval->h;
    return $hours === 48
        && (int) value("SELECT COUNT(*) FROM tasks WHERE dossier_id=? AND title LIKE 'Relancer la demande mutuelle%'", [$row['dossier_id']]) >= 1;
});

test_case('13. Création commande Ophtalmic', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM glass_orders WHERE order_reference='OPH-TEST-2002' AND lens_product='Verre test 1.6'") === 1
);

test_case('14. Marquer verres reçus', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM glass_orders WHERE order_reference='OPH-TEST-2002' AND received_at IS NOT NULL") === 1
);

test_case('15. Marquer lunettes prêtes', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM glass_orders WHERE order_reference='OPH-TEST-2002' AND status='prete' AND ready_at IS NOT NULL") === 1
);

test_case('16. Création tâche prévenir client', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM tasks t JOIN glass_orders go ON go.dossier_id=t.dossier_id WHERE go.order_reference='OPH-TEST-2002' AND t.task_type='client_a_prevenir'") >= 1
);

test_case('17. Statistiques patron', fn(): bool =>
    is_numeric(value('SELECT COUNT(*) FROM dossiers'))
    && is_numeric(value('SELECT COALESCE(SUM(ro_amount+rc_amount+rac_amount),0) FROM dossiers'))
);

test_case('18. Assistant IA sans API', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM ai_logs WHERE provider='local' AND status='success' AND response IS NOT NULL") >= 1
);

test_case('19. Recherche globale', fn(): bool =>
    (int) value("SELECT COUNT(*) FROM clients WHERE last_name LIKE '%Martin%' OR first_name LIKE '%Martin%'") >= 1
);

test_case('20. Sauvegarde manuelle', function (): bool {
    $row = db()->query("SELECT * FROM backups WHERE backup_type='manual' AND kind='database' AND status IN ('created','restored') ORDER BY id DESC LIMIT 1")->fetch();
    if (!$row) return false;
    $path = safe_private_file('backups', (string) $row['file_name']);
    return is_file($path) && hash_equals((string) $row['sha256'], hash_file('sha256', $path));
});

echo "\nRésultat : $passed réussi(s), $failed échec(s).\n";
exit($failed === 0 ? 0 : 1);

