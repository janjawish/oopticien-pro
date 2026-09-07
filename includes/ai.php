<?php
declare(strict_types=1);

require_once __DIR__ . '/assistant_actions.php';

/**
 * Point d’extension pour un futur fournisseur. V2 reste locale par défaut :
 * aucune donnée n’est transmise tant qu’un adaptateur approuvé n’est pas installé.
 */
function call_ai_provider(string $prompt, array $safeContext = []): ?string
{
    $provider=(string)get_setting('ai_provider','local');
    if($provider==='local'||trim((string)get_setting('ai_api_key_encrypted',''))===''){
        return null;
    }
    // Adaptateur volontairement neutre en V2 : l’échec ne bloque jamais les règles locales.
    return null;
}

function assistant_folder_lines(array $rows,string $action):string
{
    if(!$rows)return "Aucun dossier correspondant.\nPriorité : normale.";
    $lines=[];
    foreach($rows as $row){
        $number=dossier_number((int)$row['id']);
        $name=trim(($row['first_name']??'').' '.($row['last_name']??''));
        $lines[]='- [PRIORITÉ '.mb_strtoupper((string)($row['priority']??'haute')).'] ['.$number.']('.app_url('pages/dossier_view.php?id='.$row['id']).') · '.$name.' — '.$action;
    }
    return implode("\n",$lines);
}

function run_local_assistant(string $preset,?int $dossierId=null):string
{
    return match($preset){
        'urgent'=>assistant_folder_lines(db()->query("SELECT DISTINCT d.id,d.priority,c.first_name,c.last_name FROM dossiers d JOIN clients c ON c.id=d.client_id LEFT JOIN tasks t ON t.dossier_id=d.id WHERE d.folder_status NOT IN ('cloture','annule','sav') AND (d.priority='urgente' OR d.total_warning=1 OR (t.status IN ('a_faire','en_cours','reportee') AND t.due_at<=NOW())) ORDER BY FIELD(d.priority,'urgente','haute','normale','basse') LIMIT 20")->fetchAll(),'Ouvrir et traiter la prochaine action'),
        'followups'=>assistant_folder_lines(db()->query("SELECT DISTINCT d.id,d.priority,c.first_name,c.last_name FROM dossiers d JOIN clients c ON c.id=d.client_id JOIN tasks t ON t.dossier_id=d.id WHERE t.status IN ('a_faire','en_cours','reportee') AND t.task_type IN ('mutuelle','client_a_prevenir','client_non_venu') AND t.due_at<=NOW() ORDER BY t.due_at LIMIT 20")->fetchAll(),'Effectuer la relance puis journaliser'),
        'payments'=>assistant_folder_lines(db()->query("SELECT DISTINCT d.id,d.priority,c.first_name,c.last_name FROM payments p JOIN dossiers d ON d.id=p.dossier_id JOIN clients c ON c.id=d.client_id WHERE p.status='retard' OR (p.expected_amount>p.paid_amount AND p.status IN ('attendu','partiel')) ORDER BY p.updated_at LIMIT 20")->fetchAll(),'Vérifier le règlement attendu'),
        'to_invoice'=>assistant_folder_lines(db()->query("SELECT d.id,d.priority,c.first_name,c.last_name FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.folder_status='a_facturer' ORDER BY COALESCE(d.pec_response_at,d.updated_at),d.id LIMIT 50")->fetchAll(),'Facturer le dossier puis renseigner la date de facture'),
        'warnings'=>assistant_folder_lines(db()->query("SELECT d.id,d.priority,c.first_name,c.last_name FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.total_warning=1 ORDER BY d.updated_at DESC LIMIT 20")->fetchAll(),'Corriger RO + RC + RAC ou le total'),
        'folder'=>assistant_folder_summary($dossierId),
        'ready_message'=>assistant_ready_message($dossierId),
        default=>assistant_team_today(),
    };
}

function assistant_folder_summary(?int $id):string
{
    if(!$id)return 'Choisissez un dossier à résumer.';
    $stmt=db()->prepare('SELECT d.*,c.first_name,c.last_name,(SELECT COUNT(*) FROM tasks t WHERE t.dossier_id=d.id AND t.status IN ("a_faire","en_cours","reportee")) AS open_tasks FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.id=?');$stmt->execute([$id]);$d=$stmt->fetch();if(!$d)return 'Dossier introuvable.';
    return dossier_number((int)$d['id']).' · '.$d['first_name'].' '.$d['last_name']."\nStatut : ".status_label($d['folder_status'])."\nMutuelle : ".status_label($d['mutual_status'])."\nMontants : RO ".format_euros($d['ro_amount']).', RC '.format_euros($d['rc_amount']).', RAC '.format_euros($d['rac_amount'])."\nTâches ouvertes : ".$d['open_tasks']."\nAction recommandée : ".($d['next_action']?:'vérifier le dossier et définir la prochaine action').'.';
}

function assistant_ready_message(?int $id):string
{
    if(!$id)return 'Choisissez un dossier.';
    $stmt=db()->prepare('SELECT d.id,c.first_name FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.id=?');$stmt->execute([$id]);$d=$stmt->fetch();if(!$d)return 'Dossier introuvable.';
    return 'Proposition à relire :'.PHP_EOL.PHP_EOL.'Bonjour '.$d['first_name'].', vos lunettes pour le dossier '.dossier_number((int)$d['id']).' sont prêtes. Vous pouvez venir les récupérer chez '.get_setting('boutique_name','Oopticien Pro').'.'.PHP_EOL.PHP_EOL.'Action recommandée : ouvrir le dossier, cliquer sur « Prévenir le client », prévisualiser puis valider.';
}

function assistant_team_today():string
{
    $overdue=(int)db()->query("SELECT COUNT(*) FROM tasks WHERE status IN ('a_faire','en_cours','reportee') AND due_at<=NOW()")->fetchColumn();
    $notify=(int)db()->query('SELECT COUNT(*) FROM glass_orders WHERE ready_at IS NOT NULL AND client_notified_at IS NULL')->fetchColumn();
    $warnings=(int)db()->query('SELECT COUNT(*) FROM dossiers WHERE total_warning=1')->fetchColumn();
    $toInvoice=(int)db()->query("SELECT COUNT(*) FROM dossiers WHERE folder_status='a_facturer'")->fetchColumn();
    return "[PRIORITÉ HAUTE] Facturer $toInvoice dossier(s) dont la PEC est acceptée.\n[PRIORITÉ HAUTE] Traiter $overdue tâche(s) échue(s).\n[PRIORITÉ HAUTE] Prévenir $notify client(s) dont les lunettes sont prêtes.\n[PRIORITÉ NORMALE] Contrôler $warnings incohérence(s) de montant.\nAction recommandée : commencer par les dossiers à facturer, puis la page Relances.";
}

function render_assistant_response(string $text): string
{
    $output = '';
    $offset = 0;
    if (preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $index => $match) {
            $output .= e(substr($text, $offset, $match[1] - $offset));
            $label = $matches[1][$index][0];
            $url = $matches[2][$index][0];
            if (str_starts_with($url, app_url(''))) {
                $output .= '<a href="' . e($url) . '">' . e($label) . '</a>';
            } else {
                $output .= e($match[0]);
            }
            $offset = $match[1] + strlen($match[0]);
        }
    }
    $output .= e(substr($text, $offset));
    return nl2br($output);
}
