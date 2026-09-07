<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/storage.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

$categories = ['ordonnance','mutuelle','identite','facture','devis','cosium','autre'];
$category = post_string('category');
$category = in_array($category, $categories, true) ? $category : 'autre';
$clientId = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT) ?: null;
$dossierId = filter_input(INPUT_POST, 'dossier_id', FILTER_VALIDATE_INT) ?: null;
$returnUrl = 'pages/documents.php';
if ($dossierId) {
    $returnUrl .= '?dossier_id='.$dossierId;
} elseif ($clientId) {
    $returnUrl .= '?client_id='.$clientId;
}

if (!$clientId && !$dossierId) {
    flash('danger', 'Associez le document à un client ou à un dossier.');
    redirect($returnUrl);
}
if ($dossierId) {
    $stmt = db()->prepare('SELECT client_id FROM dossiers WHERE id = ?');
    $stmt->execute([$dossierId]);
    $dossierClient = $stmt->fetchColumn();
    if (!$dossierClient || ($clientId && (int)$dossierClient !== $clientId)) {
        flash('danger', 'Le dossier et le client ne correspondent pas.');
        redirect($returnUrl);
    }
    $clientId = (int) $dossierClient;
}

try {
    $file = store_uploaded_file(
        $_FILES['document'] ?? [],
        'documents',
        ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'],
        10 * 1024 * 1024
    );
    $typeMap = [
        'ordonnance'=>'ordonnance','mutuelle'=>'pec_mutuelle','facture'=>'facture',
        'devis'=>'devis','cosium'=>'pdf_cosium','identite'=>'autre','autre'=>'autre',
    ];
    $stmt = db()->prepare(
        'INSERT INTO documents (
            client_id,dossier_id,category,document_type,original_name,original_file_name,
            stored_name,stored_file_name,file_path,mime_type,size_bytes,file_size,description,sha256,uploaded_by
         ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $clientId,$dossierId,$category,$typeMap[$category]??'autre',
        $file['original_name'],$file['original_name'],$file['stored_name'],$file['stored_name'],
        'documents/'.$file['stored_name'],$file['mime_type'],$file['size_bytes'],$file['size_bytes'],
        post_nullable('description'),$file['sha256'],current_user()['id'],
    ]);
    $id = (int) db()->lastInsertId();
    log_action('document', $id, 'document_upload', 'Dépôt de '.$file['original_name']);
    flash('success', 'Le document a été stocké dans l’espace privé.');
} catch (Throwable $exception) {
    flash('danger', 'Dépôt impossible : '.$exception->getMessage());
}
redirect($returnUrl);
