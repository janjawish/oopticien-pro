<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
$type=(string)($_GET['type']??'');
if(!in_array($type,['clients','dossiers'],true)){http_response_code(400);exit('Export inconnu.');}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="oopticien-'.$type.'-'.date('Y-m-d').'.csv"');
$out=fopen('php://output','wb');
fwrite($out,"\xEF\xBB\xBF");
if($type==='clients'){
    fputcsv($out,['Fiche','Nom','Prénom','Téléphone','Email','Adresse','NIR','Régime','Mutuelle','Adhérent','Notes'],';');
    $rows=db()->query('SELECT fiche_number,last_name,first_name,phone,email,address,social_security_number,social_security_scheme,mutual_name,membership_number,notes FROM clients ORDER BY last_name,first_name');
}else{
    fputcsv($out,['Dossier','Nom','Prénom','Statut','Mutuelle','Devis','Facture','Télétransmission','RO','RC','RAC','Total','Alerte','Commentaire'],';');
    $rows=db()->query('SELECT d.id,c.last_name,c.first_name,d.folder_status,d.mutual_status,d.quote_date,d.invoice_date,d.teletrans_date,d.ro_amount,d.rc_amount,d.rac_amount,d.total_amount,d.total_warning,d.optician_comment FROM dossiers d JOIN clients c ON c.id=d.client_id ORDER BY d.id');
}
foreach($rows as $row)fputcsv($out,array_values($row),';');
log_action('export',null,'export_csv','Export '.$type);
fclose($out);
exit;
