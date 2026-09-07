<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';redirect_if_not_logged_in();require_post();verify_csrf();
$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);$status=post_string('status');$allowed=['envoyee','confirmee','en_fabrication','expediee','recue','montage','prete','incident','annulee'];
if(!$id||!in_array($status,$allowed,true)){flash('danger','Statut de commande invalide.');redirect('pages/ophtalmic_orders.php');}
$stmt=db()->prepare('SELECT go.*,d.client_id FROM glass_orders go JOIN dossiers d ON d.id=go.dossier_id WHERE go.id=?');$stmt->execute([$id]);$order=$stmt->fetch();if(!$order){flash('danger','Commande introuvable.');redirect('pages/ophtalmic_orders.php');}
$timestamps=['envoyee'=>'sent_at','confirmee'=>'confirmed_at','recue'=>'received_at','montage'=>'mounted_at','prete'=>'ready_at'];$sql='UPDATE glass_orders SET status=?';if(isset($timestamps[$status])){$column=$timestamps[$status];$sql.=',`'.$column.'`=COALESCE(`'.$column.'`,NOW())';}$sql.=' WHERE id=?';
// Nom de colonne choisi uniquement dans une liste interne ci-dessus.
db()->prepare($sql)->execute([$status,$id]);
$folderStatus=match($status){'envoyee','confirmee','en_fabrication','expediee'=>'commande_envoyee','recue'=>'verres_recus','montage'=>'montage','prete'=>'pret',default=>null};
if($folderStatus){$stmt=db()->prepare("UPDATE dossiers SET status_changed_at=IF(folder_status='sav',status_changed_at,NOW()),folder_status=IF(folder_status='sav',folder_status,?),next_action=IF(folder_status='sav',next_action,?) WHERE id=?");$stmt->execute([$folderStatus,$status==='prete'?'Prévenir le client':'Suivre la commande verrier',$order['dossier_id']]);}
if($status==='prete')create_task_if_not_exists((int)$order['dossier_id'],(int)$order['client_id'],'Prévenir le client : lunettes prêtes','client_a_prevenir',date('Y-m-d H:i:s'),'haute');
log_action('glass_order',$id,'mise_a_jour','Statut '.status_label($status));flash('success','Le statut de la commande a été mis à jour.');redirect('pages/ophtalmic_orders.php');
