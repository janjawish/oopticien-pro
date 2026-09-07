<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
require_post();
verify_csrf();
$keys=['mutual_followup_hours_open','pec_auto_accept_days_open','invoice_followup_days_open','ro_payment_followup_days_open','rc_payment_followup_days_open','client_not_come_followup_1_days','client_not_come_followup_2_days','client_not_come_followup_3_days'];
$pdo=db();$pdo->beginTransaction();
try{
    $stmt=$pdo->prepare('INSERT INTO settings (setting_key,setting_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach($keys as $key){
        $value=filter_input(INPUT_POST,$key,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>365]]);
        if($value===false||$value===null)throw new RuntimeException('Valeur incorrecte pour '.$key);
        $stmt->execute([$key,(string)$value,'Délai configurable']);
    }
    $pdo->commit();log_action('settings',null,'mise_a_jour','Délais de relance mis à jour');flash('success','Les paramètres ont été enregistrés.');
}catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();flash('danger','Vérifiez les délais saisis.');}
redirect('pages/settings.php');
