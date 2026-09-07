<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$isCli=PHP_SAPI==='cli';
if($isCli){
    require_once $root.'/includes/functions.php';
    require_once $root.'/includes/db.php';
    require_once $root.'/includes/reminder_engine.php';
    app_config();
}else{
    require_once $root.'/includes/auth.php';
    require_once $root.'/includes/reminder_engine.php';
    require_role(['admin','patron']);
    require_post();
    verify_csrf();
}

$result=run_reminder_generation();
$created=(int)$result['created'];
if($isCli){
    echo '['.date('Y-m-d H:i:s').'] '.$created.' nouvelle(s) tâche(s) créée(s). Workflow : '.json_encode($result['workflow'],JSON_UNESCAPED_UNICODE)."\n";
    exit(0);
}
flash('success',$created.' nouvelle(s) relance(s) générée(s).');
redirect('pages/relances.php');
