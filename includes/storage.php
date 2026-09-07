<?php
declare(strict_types=1);

/**
 * Renvoie un dossier privé, créé au premier usage.
 * Le chemin est volontairement extérieur au dossier public de l'application.
 */
function private_storage_path(string $subdirectory = ''): string
{
    $root = rtrim((string) (app_config()['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
    if ($root === '') {
        throw new RuntimeException('Le chemin de stockage privé n’est pas configuré.');
    }

    $path = $root . ($subdirectory !== '' ? DIRECTORY_SEPARATOR . trim($subdirectory, '/\\') : '');
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Impossible de créer le dossier de stockage privé.');
    }
    return $path;
}

function random_storage_name(string $extension = ''): string
{
    $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? '');
    return bin2hex(random_bytes(20)) . ($extension !== '' ? '.' . $extension : '');
}

/**
 * Valide et déplace un fichier envoyé par HTTP dans le stockage privé.
 *
 * @return array{original_name:string,stored_name:string,mime_type:string,size_bytes:int,sha256:string,path:string}
 */
function store_uploaded_file(
    array $upload,
    string $subdirectory,
    array $allowedMimeTypes,
    int $maximumBytes
): array {
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille autorisée par PHP.',
            UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille autorisée.',
            UPLOAD_ERR_PARTIAL => 'Le transfert du fichier est incomplet.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier n’a été sélectionné.',
        ];
        throw new RuntimeException($messages[$error] ?? 'Le transfert du fichier a échoué.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);
    if (!is_uploaded_file($temporaryPath) || $size < 1 || $size > $maximumBytes) {
        throw new RuntimeException('Fichier invalide ou taille non autorisée.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($temporaryPath);
    if (!isset($allowedMimeTypes[$mime])) {
        throw new RuntimeException('Type de fichier non autorisé.');
    }

    $originalName = basename((string) ($upload['name'] ?? 'document'));
    $storedName = random_storage_name((string) $allowedMimeTypes[$mime]);
    $destination = private_storage_path($subdirectory) . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('Impossible de stocker le fichier.');
    }
    @chmod($destination, 0640);

    return [
        'original_name' => mb_substr($originalName, 0, 255),
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'size_bytes' => filesize($destination) ?: $size,
        'sha256' => hash_file('sha256', $destination),
        'path' => $destination,
    ];
}

function safe_private_file(string $subdirectory, string $storedName): string
{
    if ($storedName === '' || basename($storedName) !== $storedName) {
        throw new RuntimeException('Nom de fichier invalide.');
    }
    $directory = realpath(private_storage_path($subdirectory));
    $path = realpath(private_storage_path($subdirectory) . DIRECTORY_SEPARATOR . $storedName);
    if ($directory === false || $path === false || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Fichier privé introuvable.');
    }
    return $path;
}

function stream_private_download(string $path, string $mimeType, string $downloadName): never
{
    if (!is_file($path)) {
        http_response_code(404);
        exit('Fichier introuvable.');
    }
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'document';
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Cache-Control: private, no-store, max-age=0');
    readfile($path);
    exit;
}

