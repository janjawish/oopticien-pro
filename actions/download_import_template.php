<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin','patron']);

$headers = [
    'NOM','Prénom','ORDO','SECU','MUTUELLE/TP+TM','DEVIS','Part RO','Part RC',
    'RAC','TOTALE','FACTURE','TELETRANS','PAIEMENT SECU','PAIEMENT RC',
    'PAIEMENT RAC','TYPE DE PAIEMENT RAC','COMMENTAIRE',
    'STATUT PEC','ENVOI PEC','RÉPONSE PEC','RÉFÉRENCE PEC',
];

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="modele-import-oopticien.csv"');
$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $headers, ';');
fclose($output);
exit;
