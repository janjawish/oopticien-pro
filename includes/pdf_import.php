<?php
declare(strict_types=1);

function locate_local_executable(string $name): ?string
{
    $environmentNames = [
        'pdftotext' => 'OOPTICIEN_PDFTOTEXT_PATH',
        'pdftoppm' => 'OOPTICIEN_PDFTOPPM_PATH',
        'tesseract' => 'OOPTICIEN_TESSERACT_PATH',
    ];
    $environmentPath = getenv($environmentNames[$name] ?? '');
    if ($environmentPath !== false && is_file($environmentPath)) {
        return $environmentPath;
    }

    $executable = $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    $localAppData = getenv('LOCALAPPDATA') ?: '';
    $programFiles = array_filter([
        getenv('ProgramFiles') ?: '',
        getenv('ProgramFiles(x86)') ?: '',
    ]);
    $candidates = [];
    if ($name === 'tesseract' && $localAppData !== '') {
        $candidates[] = $localAppData . '/Programs/Tesseract-OCR/tesseract.exe';
    }
    if (in_array($name, ['pdftotext', 'pdftoppm'], true) && $localAppData !== '') {
        $patterns = [
            $localAppData . '/Microsoft/WinGet/Packages/oschwartz10612.Poppler_*/poppler-*/Library/bin/' . $executable,
            $localAppData . '/Programs/poppler-*/Library/bin/' . $executable,
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $match) {
                $candidates[] = $match;
            }
        }
    }
    foreach ($programFiles as $programFilesDirectory) {
        if ($name === 'tesseract') {
            $candidates[] = $programFilesDirectory . '/Tesseract-OCR/tesseract.exe';
        }
        if (in_array($name, ['pdftotext', 'pdftoppm'], true)) {
            foreach (glob($programFilesDirectory . '/poppler-*/Library/bin/' . $executable) ?: [] as $match) {
                $candidates[] = $match;
            }
            $candidates[] = $programFilesDirectory . '/poppler/Library/bin/' . $executable;
        }
    }
    $candidates[] = dirname(__DIR__) . '/tools/' . $name . '/' . $executable;

    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        if ($directory !== '') {
            $candidates[] = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . $executable;
        }
    }
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return str_replace('/', DIRECTORY_SEPARATOR, $candidate);
        }
    }
    return null;
}

function run_local_process(array $command, ?string $workingDirectory = null): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('La fonction PHP proc_open est désactivée : le lecteur PDF local ne peut pas démarrer.');
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Impossible de démarrer l’outil de lecture PDF.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

function tesseract_languages(string $executable): array
{
    $result = run_local_process([$executable, '--list-langs']);
    if ($result['code'] !== 0) {
        return [];
    }
    $languages = [];
    foreach (preg_split('/\R/u', $result['stdout'] . "\n" . $result['stderr']) ?: [] as $line) {
        $language = strtolower(trim($line));
        if (preg_match('/^[a-z][a-z0-9_]{1,20}$/', $language)) {
            $languages[] = $language;
        }
    }
    return array_values(array_unique($languages));
}

/**
 * Diagnostic sans import, affiché sur la page Cosium après une installation.
 */
function pdf_import_diagnostics(): array
{
    $pdftotext = locate_local_executable('pdftotext');
    $pdftoppm = locate_local_executable('pdftoppm');
    $tesseract = locate_local_executable('tesseract');
    $languages = $tesseract ? tesseract_languages($tesseract) : [];
    $missing = [];
    if (!function_exists('proc_open')) {
        $missing[] = 'fonction PHP proc_open';
    }
    if (!$pdftotext) {
        $missing[] = 'Poppler / pdftotext';
    }
    if (!$pdftoppm) {
        $missing[] = 'Poppler / pdftoppm';
    }
    if (!$tesseract) {
        $missing[] = 'Tesseract OCR';
    }
    return [
        'ready' => $missing === [],
        'missing' => $missing,
        'pdftotext' => $pdftotext,
        'pdftoppm' => $pdftoppm,
        'tesseract' => $tesseract,
        'languages' => $languages,
        'french_ready' => in_array('fra', $languages, true),
    ];
}

function pdf_text_is_usable(string $text): bool
{
    $lettersAndNumbers = preg_replace('/[^\pL\pN]+/u', '', $text) ?? '';
    return mb_strlen($lettersAndNumbers) >= 30;
}

/**
 * Extrait d'abord la couche texte. Si le PDF Cosium est imprimé comme une
 * image, rend les pages avec Poppler puis lance Tesseract en français.
 */
function extract_pdf_text_minimal(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException('Le PDF importé est introuvable.');
    }
    @set_time_limit(180);
    $text = '';
    $pdftotext = locate_local_executable('pdftotext');
    if ($pdftotext) {
        $native = run_local_process([$pdftotext, '-layout', '-enc', 'UTF-8', $path, '-']);
        if ($native['code'] === 0) {
            $text = trim($native['stdout']);
        }
    }
    if (pdf_text_is_usable($text)) {
        return $text;
    }

    $pdftoppm = locate_local_executable('pdftoppm');
    $tesseract = locate_local_executable('tesseract');
    if (!$pdftoppm || !$tesseract) {
        throw new RuntimeException(
            'Ce PDF est une image. Poppler et Tesseract OCR doivent être installés sur le serveur.'
        );
    }

    $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oopticien-ocr-' . bin2hex(random_bytes(8));
    if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('Impossible de créer le dossier temporaire OCR.');
    }

    try {
        $pagePrefix = $temporaryDirectory . DIRECTORY_SEPARATOR . 'page';
        $render = run_local_process([$pdftoppm, '-png', '-r', '200', $path, $pagePrefix]);
        if ($render['code'] !== 0) {
            $detail = trim($render['stderr']);
            throw new RuntimeException('Le PDF Cosium n’a pas pu être préparé pour la lecture OCR.' . ($detail !== '' ? ' Détail : ' . mb_substr($detail, 0, 400) : ''));
        }
        $pageFiles = glob($pagePrefix . '-*.png') ?: [];
        natsort($pageFiles);
        if (!$pageFiles) {
            throw new RuntimeException('Aucune page exploitable n’a été trouvée dans le PDF.');
        }
        if (count($pageFiles) > 20) {
            throw new RuntimeException('Le PDF dépasse la limite de 20 pages.');
        }

        $languages = tesseract_languages($tesseract);
        $ocrLanguage = in_array('fra', $languages, true)
            ? (in_array('eng', $languages, true) ? 'fra+eng' : 'fra')
            : (in_array('eng', $languages, true) ? 'eng' : null);
        $chunks = [];
        $ocrErrors = [];
        foreach ($pageFiles as $pageFile) {
            $ocrCommand = [$tesseract, $pageFile, 'stdout'];
            if ($ocrLanguage !== null) {
                $ocrCommand[] = '-l';
                $ocrCommand[] = $ocrLanguage;
            }
            $ocrCommand[] = '--psm';
            $ocrCommand[] = '6';
            $ocr = run_local_process($ocrCommand);
            if ($ocr['code'] === 0 && trim($ocr['stdout']) !== '') {
                $chunks[] = trim($ocr['stdout']);
            } elseif (trim($ocr['stderr']) !== '') {
                $ocrErrors[] = trim($ocr['stderr']);
            }
        }
        $text = trim(implode("\n\n", $chunks));
        if (!pdf_text_is_usable($text)) {
            $detail = $ocrErrors ? ' Détail : ' . mb_substr(implode(' | ', $ocrErrors), 0, 500) : '';
            throw new RuntimeException('L’OCR n’a pas reconnu suffisamment de texte. Vérifiez la qualité du PDF.' . $detail);
        }
        return $text;
    } finally {
        foreach (glob($temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $temporaryFile) {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
        @rmdir($temporaryDirectory);
    }
}

function cosium_clean_text(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    $lines = array_map(
        static fn(string $line): string => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line),
        explode("\n", $text)
    );
    return trim(implode("\n", array_filter($lines, static fn(string $line): bool => $line !== '')));
}

function cosium_match(string $text, string $pattern): ?string
{
    if (!preg_match($pattern, $text, $match)) {
        return null;
    }
    $value = trim((string) ($match[1] ?? ''));
    return $value !== '' ? $value : null;
}

function cosium_date(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, trim($value));
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

function parse_cosium_pdf_text(string $rawText): array
{
    $text = cosium_clean_text($rawText);
    $data = [];

    $fullName = cosium_match($text, '/^Nom\s*:\s*(.+?)(?=\s+(?:Num[ée]ro|N[ée]\s+le)\s*:|$)/imu');
    if ($fullName) {
        $fullName = preg_replace('/^(?:M(?:ME|LLE)?\.?|[A-Z]{1,4}\.)\s+/iu', '', $fullName) ?? $fullName;
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        if (count($parts) >= 2) {
            $data['first_name'] = array_pop($parts);
            $data['last_name'] = implode(' ', $parts);
        } else {
            $data['last_name'] = $fullName;
        }
    }

    $data['fiche_number'] = cosium_match($text, '/\bNum[ée]ro\s*:\s*([A-Z0-9\-\/]{2,50})/iu');
    $data['birth_date'] = cosium_date(cosium_match($text, '/\bN[ée]\s+le\s*:\s*(\d{2}[\/-]\d{2}[\/-]\d{4})/iu'));

    foreach (['Port', 'Dom', 'Bureau'] as $phoneType) {
        $phone = cosium_match($text, '/^T[ée]l\s+' . $phoneType . '\.?\s*:\s*([0-9 +().-]{8,30})$/imu');
        if ($phone) {
            $data['phone'] = $phone;
            break;
        }
    }
    if (preg_match('/[\w.!#$%&\'*+\/=?^`{|}~-]+@[\w.-]+\.[A-Z]{2,}/iu', $text, $email)) {
        $data['email'] = $email[0];
    }

    $addresses = [];
    if (preg_match_all('/^Adresse[ \t]*:[ \t]*([^\n]*)$/imu', $text, $addressMatches)) {
        foreach ($addressMatches[1] as $address) {
            $address = trim($address);
            if ($address !== '' && !preg_match('/^(?:Courrier|Facturation)(?:\s*,\s*(?:Courrier|Facturation))*$/iu', $address)) {
                $addresses[] = $address;
            }
        }
    }
    if ($addresses) {
        $data['address'] = implode("\n", $addresses);
    }

    $data['insured_name'] = cosium_match($text, '/^Assur[ée]\s*:\s*(.+)$/imu');
    $data['social_security_scheme'] = cosium_match($text, '/^Caisse\s+s[ée]cu\s*:\s*(.+)$/imu');
    $data['reimbursement_rate'] = cosium_match($text, '/Taux\s+rmbst\s*:\s*([0-9]+(?:[.,][0-9]+)?)/iu');

    $mutual = cosium_match($text, '/^Compl[ée]mentaire\s*:\s*(.+?)(?=\s+Relation\s*:|$)/imu');
    if ($mutual) {
        $data['mutual_name'] = trim(preg_replace('/^\d+\s*[-:]\s*/u', '', $mutual) ?? $mutual);
    }
    $data['membership_number'] = cosium_match($text, '/^Num\.?\s+adh[ée]rent\s*:\s*([A-Z0-9\-\/ ]{1,100})$/imu');
    $data['mutual_valid_from'] = cosium_date(cosium_match($text, '/Date\s+deb\.?\s+compl\s*:\s*(\d{2}[\/-]\d{2}[\/-]\d{4})/iu'));
    $data['mutual_valid_to'] = cosium_date(cosium_match($text, '/Date\s+fin\s+compl\s*:\s*(\d{2}[\/-]\d{2}[\/-]\d{4})/iu'));
    $data['dossier_date'] = cosium_date(cosium_match($text, '/Dossier\s+du\s+(\d{2}[\/-]\d{2}[\/-]\d{4})/iu'));

    $corrections = [];
    if (preg_match_all('/\bV[LP]\s*:\s*([^\n]+)/iu', $text, $correctionMatches)) {
        foreach ($correctionMatches[1] as $correction) {
            $correction = trim($correction);
            if ($correction !== '' && !in_array($correction, $corrections, true)) {
                $corrections[] = $correction;
            }
        }
    }
    if ($corrections) {
        $data['corrections'] = $corrections;
    }

    $notes = [];
    if (!empty($data['birth_date'])) {
        $notes[] = 'Date de naissance Cosium : ' . (new DateTime($data['birth_date']))->format('d/m/Y');
    }
    if (!empty($data['insured_name'])) {
        $notes[] = 'Assuré : ' . $data['insured_name'];
    }
    if (!empty($data['reimbursement_rate'])) {
        $notes[] = 'Taux de remboursement : ' . str_replace('.', ',', $data['reimbursement_rate']) . ' %';
    }
    if (!empty($data['mutual_valid_from']) || !empty($data['mutual_valid_to'])) {
        $notes[] = 'Droits complémentaires : '
            . ($data['mutual_valid_from'] ? format_date($data['mutual_valid_from']) : 'date non lue')
            . ' au '
            . ($data['mutual_valid_to'] ? format_date($data['mutual_valid_to']) : 'date non lue');
    }
    if ($notes) {
        $data['notes'] = implode("\n", $notes);
    }

    $normalizedNirText = preg_replace('/[ .-]/', '', $text) ?: '';
    if (preg_match('/(?<!\d)([12]\d{12,14})(?!\d)/', $normalizedNirText, $nir)) {
        try {
            $data['social_security_number_encrypted'] = encrypt_sensitive_value($nir[1]);
        } catch (Throwable) {
            $data['social_security_number_encrypted'] = null;
        }
    }

    return array_filter(
        $data,
        static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []
    );
}

function redact_nir_from_text(string $text): string
{
    return preg_replace_callback(
        '/(?<!\d)([12](?:[ .-]?\d){12,14})(?!\d)/',
        static fn(array $match): string => mask_nir($match[1]),
        $text
    ) ?? $text;
}
