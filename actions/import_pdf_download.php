<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/storage.php';
require_role(['admin','patron']);
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$stmt=db()->prepare('SELECT * FROM pdf_imports WHERE id=?');
$stmt->execute([$id]);$import=$stmt->fetch();
if(!$import){http_response_code(404);exit('Import introuvable.');}
$path=safe_private_file('pdf-imports',$import['stored_file_path']?:$import['stored_name']);
if(!hash_equals($import['sha256'],hash_file('sha256',$path))){http_response_code(409);exit('Contrôle d’intégrité échoué.');}
log_sensitive_access($import['client_id']?(int)$import['client_id']:null,'pdf_import_download','Import #'.$import['id']);
stream_private_download($path,'application/pdf',$import['original_file_name']?:$import['original_name']);
