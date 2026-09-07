<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/storage.php';
require_once dirname(__DIR__) . '/includes/pdf_import.php';
require_role(['admin','patron']);
require_post();
verify_csrf();
try {
    $file=store_uploaded_file($_FILES['pdf']??[],'pdf-imports',['application/pdf'=>'pdf','application/x-pdf'=>'pdf'],10*1024*1024);
    $text=extract_pdf_text_minimal($file['path']);
    $parsed=parse_cosium_pdf_text($text);
    $safeText=mb_substr(redact_nir_from_text($text),0,200000);
    $json=json_encode($parsed,JSON_UNESCAPED_UNICODE);
    $stmt=db()->prepare('INSERT INTO pdf_imports (original_name,original_file_name,stored_name,stored_file_path,mime_type,size_bytes,sha256,status,extracted_text,parsed_data,extracted_json,created_by) VALUES (?,?,?,?,?,?,?,"waiting_validation",?,?,?,?)');
    $stmt->execute([$file['original_name'],$file['original_name'],$file['stored_name'],$file['stored_name'],$file['mime_type'],$file['size_bytes'],$file['sha256'],$safeText,$json,$json,current_user()['id']]);
    $id=(int)db()->lastInsertId();
    log_action('pdf_import',$id,'pdf_import','PDF Cosium déposé pour validation');
    redirect('pages/import_preview.php?id='.$id);
} catch(Throwable $exception) {
    flash('danger','Import PDF impossible : '.$exception->getMessage());
    redirect('pages/import_pdf.php');
}
