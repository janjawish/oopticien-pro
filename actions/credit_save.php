<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:0;
$clientId=filter_input(INPUT_POST,'client_id',FILTER_VALIDATE_INT);
$label=post_string('label',180);
$source=post_string('source');
$initial=post_decimal('initial_amount');
$used=post_decimal('used_amount');
$beneficiaryIds = array_values(array_unique(array_filter(array_map(
    static fn(mixed $value): int => filter_var($value, FILTER_VALIDATE_INT) ?: 0,
    is_array($_POST['beneficiary_ids'] ?? null) ? $_POST['beneficiary_ids'] : []
))));
if(!$clientId || $label==='' || $initial<0 || $used<0 || $used>$initial){
    flash('danger','Vérifiez le client, le libellé et les montants. Le montant utilisé ne peut pas dépasser l’initial.');
    redirect('pages/avoirs.php'.($clientId?'?client_id='.$clientId:''));
}
if(!in_array($source,['mutuelle','cmu','commercial','autre'],true)) $source='autre';
$beneficiaryIds[] = (int) $clientId;
$beneficiaryIds = array_values(array_unique($beneficiaryIds));
$placeholders = implode(',', array_fill(0, count($beneficiaryIds), '?'));
$stmt = db()->prepare('SELECT COUNT(*) FROM clients WHERE id IN ('.$placeholders.')');
$stmt->execute($beneficiaryIds);
if ((int) $stmt->fetchColumn() !== count($beneficiaryIds)) {
    flash('danger', 'Un des bénéficiaires sélectionnés est introuvable.');
    redirect('pages/avoirs.php'.($clientId?'?client_id='.$clientId:''));
}

$remaining=$initial-$used;
$pdo = db();
try {
    $pdo->beginTransaction();
    if($id){
        $stmt=$pdo->prepare('SELECT id FROM credits WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        if(!$stmt->fetchColumn()){
            throw new RuntimeException('Avoir introuvable.');
        }
        $stmt=$pdo->prepare('UPDATE credits SET client_id=?,label=?,source=?,initial_amount=?,used_amount=?,remaining_amount=?,comment=? WHERE id=?');
        $stmt->execute([$clientId,$label,$source,$initial,$used,$remaining,post_nullable('comment'),$id]);
        $action = 'mise_a_jour';
    }else{
        $stmt=$pdo->prepare('INSERT INTO credits (client_id,dossier_id,label,source,initial_amount,used_amount,remaining_amount,comment,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$clientId,filter_input(INPUT_POST,'dossier_id',FILTER_VALIDATE_INT)?:null,$label,$source,$initial,$used,$remaining,post_nullable('comment'),current_user()['id']]);
        $id=(int)$pdo->lastInsertId();
        $action = 'creation';
    }

    $stmt = $pdo->prepare('DELETE FROM credit_beneficiaries WHERE credit_id=?');
    $stmt->execute([$id]);
    $stmt = $pdo->prepare('INSERT INTO credit_beneficiaries (credit_id,client_id) VALUES (?,?)');
    foreach ($beneficiaryIds as $beneficiaryId) {
        $stmt->execute([$id, $beneficiaryId]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Impossible d’enregistrer cet avoir familial.');
    redirect('pages/avoirs.php'.($clientId?'?client_id='.$clientId:''));
}

$sharedCount = max(0, count($beneficiaryIds) - 1);
log_action('avoir',$id,$action,'Avoir familial pour le client #'.$clientId.' · '.$sharedCount.' bénéficiaire(s) supplémentaire(s)');
flash('success',$action === 'creation' ? 'L’avoir familial a été ajouté.' : 'L’avoir familial et ses bénéficiaires ont été modifiés.');
redirect('pages/avoirs.php?client_id='.$clientId);
