<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin','patron']);

$preset=(string)($_GET['period']??'30');
$today=new DateTimeImmutable('today');
[$from,$to]=match($preset){
    'today'=>[$today,$today],
    '7'=>[$today->modify('-6 days'),$today],
    'month'=>[$today->modify('first day of this month'),$today],
    'custom'=>[
        DateTimeImmutable::createFromFormat('!Y-m-d',(string)($_GET['from']??''))?:$today->modify('-29 days'),
        DateTimeImmutable::createFromFormat('!Y-m-d',(string)($_GET['to']??''))?:$today,
    ],
    default=>[$today->modify('-29 days'),$today],
};
if($from>$to){[$from,$to]=[$to,$from];}
$dateFrom=$from->format('Y-m-d 00:00:00');$dateTo=$to->format('Y-m-d 23:59:59');
function stat_scalar(string $sql,array $params=[]):float{$stmt=db()->prepare($sql);$stmt->execute($params);return (float)$stmt->fetchColumn();}
$periodParams=[$dateFrom,$dateTo];
$stats=[
 'created'=>(int)stat_scalar('SELECT COUNT(*) FROM dossiers WHERE created_at BETWEEN ? AND ?',$periodParams),
 'active'=>(int)stat_scalar("SELECT COUNT(*) FROM dossiers WHERE folder_status NOT IN ('cloture','annule','remis','sav') AND created_at BETWEEN ? AND ?",$periodParams),
 'closed'=>(int)stat_scalar("SELECT COUNT(*) FROM dossiers WHERE folder_status IN ('cloture','remis') AND updated_at BETWEEN ? AND ?",$periodParams),
 'blocked'=>(int)stat_scalar("SELECT COUNT(*) FROM dossiers WHERE folder_status='bloque' AND updated_at BETWEEN ? AND ?",$periodParams),
 'ro'=>stat_scalar('SELECT COALESCE(SUM(ro_amount),0) FROM dossiers WHERE created_at BETWEEN ? AND ?',$periodParams),
 'rc'=>stat_scalar('SELECT COALESCE(SUM(rc_amount),0) FROM dossiers WHERE created_at BETWEEN ? AND ?',$periodParams),
 'rac'=>stat_scalar('SELECT COALESCE(SUM(rac_amount),0) FROM dossiers WHERE created_at BETWEEN ? AND ?',$periodParams),
 'ro_late'=>(int)stat_scalar("SELECT COUNT(*) FROM payments WHERE payer='ro' AND status='retard' AND updated_at BETWEEN ? AND ?",$periodParams),
 'rc_late'=>(int)stat_scalar("SELECT COUNT(*) FROM payments WHERE payer='rc' AND status='retard' AND updated_at BETWEEN ? AND ?",$periodParams),
 'rac_due'=>(int)stat_scalar("SELECT COUNT(*) FROM payments WHERE payer='client' AND expected_amount>paid_amount AND status IN ('attendu','partiel','retard') AND updated_at BETWEEN ? AND ?",$periodParams),
 'mutual_delay'=>stat_scalar('SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR,pec_sent_at,pec_response_at)),0) FROM dossiers WHERE pec_sent_at IS NOT NULL AND pec_response_at IS NOT NULL AND pec_response_at BETWEEN ? AND ?',$periodParams),
 'invoice_delay'=>stat_scalar('SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR,pec_response_at,invoice_date)),0) FROM dossiers WHERE pec_response_at IS NOT NULL AND invoice_date IS NOT NULL AND invoice_date BETWEEN ? AND ?',$periodParams),
 'pickup_delay'=>stat_scalar("SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR,go.ready_at,d.updated_at)),0) FROM glass_orders go JOIN dossiers d ON d.id=go.dossier_id WHERE go.ready_at IS NOT NULL AND d.folder_status IN ('remis','cloture') AND d.updated_at BETWEEN ? AND ?",$periodParams),
 'warnings'=>(int)stat_scalar('SELECT COUNT(*) FROM dossiers WHERE total_warning=1 AND updated_at BETWEEN ? AND ?',$periodParams),
];
$stmt=db()->prepare("SELECT task_type,COUNT(*) AS total FROM tasks WHERE due_at<NOW() AND status IN ('a_faire','en_cours','reportee') AND due_at BETWEEN ? AND ? GROUP BY task_type ORDER BY total DESC");$stmt->execute($periodParams);$overdue=$stmt->fetchAll();
$stmt=db()->prepare('SELECT COALESCE(u.name,"Non attribué") AS employee,COUNT(*) AS total FROM dossiers d LEFT JOIN users u ON u.id=d.created_by WHERE d.created_at BETWEEN ? AND ? GROUP BY d.created_by,u.name ORDER BY total DESC');$stmt->execute($periodParams);$employees=$stmt->fetchAll();
$pageTitle='Statistiques patron';require dirname(__DIR__) . '/includes/header.php';
$cards=[
 ['created','Dossiers créés',''],['active','Dossiers en cours',''],['closed','Dossiers clôturés','accent-green'],['blocked','Dossiers bloqués','accent-red'],
 ['ro','RO attendu','money'],['rc','RC attendu','money'],['rac','RAC attendu','money'],['warnings','Incohérences','accent-red'],
 ['ro_late','RO en retard','accent-orange'],['rc_late','RC en retard','accent-orange'],['rac_due','RAC non encaissés','accent-orange'],
 ['mutual_delay','Réponse mutuelle moyenne','hours'],['invoice_delay','Facturation après PEC','hours'],['pickup_delay','Prêt → retrait moyen','hours'],
];
?>
<section class="page-actions"><div class="copy"><h2>Activité du <?= e($from->format('d/m/Y')) ?> au <?= e($to->format('d/m/Y')) ?></h2><p>Indicateurs calculés depuis les données internes Oopticien Pro.</p></div></section>
<form class="filters" method="get"><label class="field"><span>Période</span><select name="period"><option value="today" <?= $preset==='today'?'selected':'' ?>>Aujourd’hui</option><option value="7" <?= $preset==='7'?'selected':'' ?>>7 jours</option><option value="30" <?= $preset==='30'?'selected':'' ?>>30 jours</option><option value="month" <?= $preset==='month'?'selected':'' ?>>Mois en cours</option><option value="custom" <?= $preset==='custom'?'selected':'' ?>>Personnalisée</option></select></label><label class="field"><span>Du</span><input type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>"></label><label class="field"><span>Au</span><input type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>"></label><button class="btn btn-primary" type="submit">Appliquer</button></form>
<div class="stats-grid"><?php foreach($cards as [$key,$label,$format]):?><article class="stat-card <?= str_starts_with($format,'accent-')?e($format):'' ?>"><div class="stat-top"><?= e($label) ?></div><div class="stat-value"><?= $format==='money'?e(format_euros($stats[$key])):($format==='hours'?e(number_format($stats[$key],1,',',' ').' h'):(int)$stats[$key]) ?></div></article><?php endforeach;?></div>
<div class="content-grid">
<section class="card table-card"><div class="card-header"><div><h2>Tâches en retard par type</h2><p>État actuel, échéances comprises dans la période</p></div></div><div class="table-wrap"><table><thead><tr><th>Type</th><th>Nombre</th></tr></thead><tbody><?php foreach($overdue as $row):?><tr><td><?= e(status_label($row['task_type'])) ?></td><td><?= (int)$row['total'] ?></td></tr><?php endforeach;?><?php if(!$overdue):?><tr><td colspan="2" class="empty-state">Aucune tâche en retard.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card table-card"><div class="card-header"><div><h2>Dossiers par employé</h2><p>Selon le créateur du dossier</p></div></div><div class="table-wrap"><table><thead><tr><th>Employé</th><th>Dossiers</th></tr></thead><tbody><?php foreach($employees as $row):?><tr><td><?= e($row['employee']) ?></td><td><?= (int)$row['total'] ?></td></tr><?php endforeach;?><?php if(!$employees):?><tr><td colspan="2" class="empty-state">Aucun dossier sur cette période.</td></tr><?php endif;?></tbody></table></div></section>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php';?>
