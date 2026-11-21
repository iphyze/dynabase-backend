<?php
declare(strict_types=1);
/** Private AI chat attachments and generated downloads; never expose direct storage URLs. */

function aiChatFileStore(): string
{
    $dir = trim(envString('AI_CHAT_STORAGE_DIR'));
    // Set AI_CHAT_STORAGE_DIR to an absolute path outside public_html on production.
    // By default uploads go to a deny-all Apache folder under the backend.
    if ($dir === '') $dir = dirname(__DIR__) . '/storage/uploads/ai-chat';
    if ($dir[0] !== '/' && !preg_match('/^[A-Z]:[\\\\\/]/i', $dir)) {
        throw new RuntimeException('AI_CHAT_STORAGE_DIR must be an absolute path.', 503);
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('AI file storage is unavailable.', 503);
    }
    if (!is_writable($dir)) throw new RuntimeException('AI file storage is not writable.', 503);
    // Protect the fallback storage directory on Apache; nginx deployments must deny this path separately.
    if (realpath($dir) === realpath(dirname(__DIR__) . '/storage/uploads/ai-chat')) {
        $deny = $dir.'/.htaccess';
        if (!file_exists($deny)) @file_put_contents($deny, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return rtrim($dir, '/\\');
}

function aiChatFilePath(string $storageName): string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $storageName)) throw new RuntimeException('Invalid stored file.', 500);
    return aiChatFileStore() . DIRECTORY_SEPARATOR . $storageName;
}

function aiChatCleanFileName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\pL\pN._() -]+/u', '_', $name) ?? 'document';
    $name = trim($name, '. ');
    return substr($name ?: 'document', 0, 170);
}

function aiChatUploadTypes(): array
{
    return [
        'txt'=>'text/plain','md'=>'text/markdown','csv'=>'text/csv','json'=>'application/json',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf'=>'application/pdf', 'zip'=>'application/zip', 'sql'=>'text/plain',
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp',
    ];
}

function aiChatDownloadTypes(): array
{
    return [
        'txt'=>'text/plain; charset=utf-8', 'md'=>'text/markdown; charset=utf-8',
        'csv'=>'text/csv; charset=utf-8', 'json'=>'application/json',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
}

function aiChatCheckArchive(string $path, string $extension): void
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive is required for office document uploads.', 503);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('The Office document is invalid.', 422);
    try {
        if ($zip->numFiles > 800) throw new RuntimeException('The Office document has too many internal files.', 422);
        $total = 0;
        for ($i=0; $i<$zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entryName = (string)($stat['name'] ?? '');
            if (str_contains($entryName, '../') || str_starts_with($entryName, '/')) throw new RuntimeException('Invalid Office document structure.', 422);
            $total += (int)($stat['size']??0);
            if ($total > 25000000) throw new RuntimeException('The Office document expands beyond the allowed size.', 422);
        }
        $required = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
        if ($zip->locateName($required) === false || $zip->locateName('[Content_Types].xml') === false) throw new RuntimeException('File extension does not match the Office document.', 422);
    } finally { $zip->close(); }
}

/** Validate archives without unpacking or executing their contents. */
function aiChatCheckZipUpload(string $path): void
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive extension is required for ZIP uploads.', 503);
    $signature = (string)file_get_contents($path, false, null, 0, 4);
    if (!in_array($signature, ["PK\x03\x04", "PK\x05\x06"], true)) throw new RuntimeException('This is not a ZIP archive.', 422);
    $archive = new ZipArchive();
    if ($archive->open($path, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('This ZIP archive is damaged.', 422);
    try {
        if ($archive->numFiles > 1200) throw new RuntimeException('ZIP archive contains too many files (max 1,200).', 422);
        $total = 0;
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $stat = $archive->statIndex($i);
            if (!is_array($stat)) throw new RuntimeException('Invalid ZIP entry.', 422);
            $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
            if ($name === '' || str_starts_with($name, '/') || preg_match('~(^|/)\\.\\.(/|$)|^[A-Za-z]:/~', $name)) throw new RuntimeException('Unsafe path in ZIP archive.', 422);
            if ((int)($stat['encryption_method'] ?? 0) !== 0) throw new RuntimeException('Encrypted ZIP archives cannot be analysed.', 422);
            if ($archive->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === 3 /* Unix */ && (((int)$attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('ZIP symbolic links are not accepted.', 422);
            }
            $size = (int)($stat['size'] ?? 0);
            $compressed = (int)($stat['comp_size'] ?? 0);
            $total += $size;
            if ($total > 60 * 1024 * 1024 || $size > 12 * 1024 * 1024 || ($size > 1024 * 1024 && $compressed > 0 && $size / $compressed > 150)) {
                throw new RuntimeException('ZIP archive expands beyond the safe analysis limit.', 422);
            }
        }
    } finally { $archive->close(); }
}

function aiChatValidateUpload(array $file): array
{
    if ((int)($file['error']??UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Please select a supported file.', 422);
    $size = (int)($file['size']??0);
    if ($size < 1 || $size > 5*1024*1024) throw new RuntimeException('Each attachment must be smaller than 5 MB.', 422);
    $tmp = (string)($file['tmp_name']??'');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Invalid upload.', 422);
    $name = aiChatCleanFileName((string)($file['name']??''));
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $types = aiChatUploadTypes();
    if (!isset($types[$ext])) throw new RuntimeException('Accepted files: PDF, DOCX, XLSX, CSV, TXT, MD, JSON, SQL, ZIP, PNG, JPG and WebP.', 422);
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (in_array($ext, ['png','jpg','jpeg','webp'], true)) {
        // Never trust an image filename or browser-supplied content type alone.
        $image = @getimagesize($tmp);
        if (!is_array($image) || ($image['mime'] ?? '') !== $types[$ext] || $mime !== $types[$ext]) {
            throw new RuntimeException('The image contents do not match its file type.', 422);
        }
        $width = (int)($image[0] ?? 0);
        $height = (int)($image[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 7000 || $height > 7000 || $width * $height > 25000000) {
            throw new RuntimeException('Image dimensions are too large.', 422);
        }
    } elseif ($ext === 'pdf') {
        if (strncmp((string)file_get_contents($tmp, false, null, 0, 5), '%PDF-', 5) !== 0) throw new RuntimeException('This is not a valid PDF document.', 422);
    } elseif ($ext === 'zip') {
        aiChatCheckZipUpload($tmp);
    } elseif (in_array($ext, ['docx','xlsx'], true)) {
        aiChatCheckArchive($tmp, $ext);
        if ($ext==='xlsx' && !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) throw new RuntimeException('PhpSpreadsheet must be installed to read Excel files.', 503);
    } elseif (str_contains((string)file_get_contents($tmp, false, null, 0, min($size,1024)), "\0")) {
        throw new RuntimeException('Binary data is not accepted as a text document.', 422);
    }
    // The MIME sniff is advisory for text (systems differ); ZIP/PDF signatures are validated above.
    return [$name,$ext,$types[$ext],$size];
}

function aiChatImageInput(array $row): array
{
    $ext = strtolower((string)($row['extension'] ?? ''));
    $allowed = array_intersect_key(aiChatUploadTypes(), array_flip(['png','jpg','jpeg','webp']));
    if (!isset($allowed[$ext]) || ($row['mime_type'] ?? '') !== $allowed[$ext]) {
        throw new RuntimeException('Unsupported image attachment.', 422);
    }
    $path = aiChatFilePath((string)$row['storage_name']);
    $size = (int)($row['byte_size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024 || !is_file($path) || filesize($path) !== $size) {
        throw new RuntimeException('The image attachment is missing or invalid.', 422);
    }
    $actual = @getimagesize($path);
    if (!is_array($actual) || ($actual['mime'] ?? '') !== $allowed[$ext]) {
        throw new RuntimeException('Invalid image attachment.', 422);
    }
    $binary = file_get_contents($path);
    if (!is_string($binary)) throw new RuntimeException('Unable to read image attachment.', 422);
    return ['type'=>'input_image', 'image_url'=>'data:'.$allowed[$ext].';base64,'.base64_encode($binary), 'detail'=>'auto'];
}

function aiChatFileMeta(array $row): array
{
    return ['id'=>(int)$row['id'], 'name'=>$row['original_name'], 'size'=>(int)$row['byte_size'],
        'extension'=>$row['extension'], 'kind'=>$row['kind']];
}

function aiChatOwnFile(mysqli $conn, int $fileId, int $userId, int $threadId): array
{
    $row = dbFetchOne($conn, 'SELECT f.* FROM dynabase_ai_chat_files f JOIN dynabase_ai_chat_threads t ON t.id=f.thread_id WHERE f.id=? AND f.thread_id=? AND f.user_id=? AND t.user_id=? LIMIT 1', 'iiii', [$fileId,$threadId,$userId,$userId]);
    if (!$row) throw new RuntimeException('Attachment not found in your conversation.', 404);
    return $row;
}

function aiChatExtractFileText(array $row): string
{
    $path = aiChatFilePath((string)$row['storage_name']);
    if (!is_file($path)) throw new RuntimeException('The attached file is no longer available.', 404);
    $ext = (string)$row['extension'];
    if (in_array($ext, ['txt','md','csv','json','sql'], true)) {
        $raw = file_get_contents($path, false, null, 0, 30000);
        return mb_safe_utf8((string)$raw);
    }
    if ($ext === 'zip') {
        // Only read bounded text samples. Never extract archives onto disk or execute any included files.
        aiChatCheckZipUpload($path);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Could not read ZIP contents.', 422);
        try {
            $names = [];
            $snippets = [];
            $remaining = 26000;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                if (str_ends_with($entry, '/')) continue;
                $names[] = $entry;
                // Don't accidentally disclose credentials/config files or vendored dependencies to AI.
                if (preg_match('~(^|/)(\\.env(?:\\.|$)|vendor/|node_modules/|\\.git/|id_rsa|connection\\.php|wp-config\\.php|database\\.php|config\\.php|secrets?\\.[^/]+|.*\\.(?:key|pem|p12|pfx)$)~i', $entry)) continue;
                if (!preg_match('/\\.(?:txt|md|csv|json|sql|js|jsx|ts|tsx|php|css|html|xml|yml|yaml|py)$/i', $entry)) continue;
                if ($remaining < 200 || count($snippets) >= 16) continue;
                $stat = $zip->statIndex($i);
                if ((int)($stat['size'] ?? 0) > 2 * 1024 * 1024) continue;
                $stream = $zip->getStream($entry);
                if ($stream === false) continue;
                try { $sample = stream_get_contents($stream, min(3000, $remaining)); }
                finally { fclose($stream); }
                if (!is_string($sample) || str_contains($sample, "\0")) continue;
                $sample = mb_safe_utf8($sample);
                $remaining -= strlen($sample);
                $snippets[] = "File: {$entry}\n" . $sample;
            }
            return mb_safe_utf8("ZIP archive listing (".count($names)." files):\n".implode("\n", array_slice($names, 0, 120))."\n\nText excerpts (partial; binaries and credentials omitted):\n".implode("\n\n", $snippets));
        } finally { $zip->close(); }
    }
    if ($ext === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Unable to read DOCX content.', 422);
        try { $raw = $zip->getFromName('word/document.xml'); } finally { $zip->close(); }
        if (!is_string($raw)) throw new RuntimeException('Unable to read DOCX content.', 422);
        $raw = preg_replace('/<\/w:p>/', "\n", $raw) ?? $raw;
        $raw = preg_replace('/<w:tab\b[^>]*\/>/', "\t", $raw) ?? $raw;
        return mb_safe_utf8(html_entity_decode(strip_tags($raw), ENT_QUOTES|ENT_XML1, 'UTF-8'));
    }
    if ($ext === 'xlsx') {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) throw new RuntimeException('PhpSpreadsheet is required to read Excel attachments.', 503);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        try {
            $lines=[];
            foreach (array_slice($spreadsheet->getWorksheetIterator() instanceof Traversable ? iterator_to_array($spreadsheet->getWorksheetIterator()) : [],0,3) as $sheet) {
                $lines[]='Sheet: '.$sheet->getTitle();
                foreach ($sheet->rangeToArray('A1:O'.min(150,$sheet->getHighestDataRow()),null,false,false) as $values) {
                    $lines[]=implode(' | ',array_map(static fn($v)=>trim((string)$v), $values));
                }
            }
            return mb_safe_utf8(implode("\n",$lines));
        } finally { $spreadsheet->disconnectWorksheets(); unset($spreadsheet); }
    }
    return ''; // PDF is passed directly as input_file; never guess extraction.
}

function mb_safe_utf8(string $text): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text) ?? $text;
    if (function_exists('mb_convert_encoding')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    else $text = iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: '';
    return substr($text, 0, 24000);
}

function aiChatWriteGenerated(mysqli $conn, int $userId, int $threadId, int $messageId, mixed $spec): ?array
{
    if (!is_array($spec)) return null;
    $ext = strtolower(trim((string)($spec['format']??'')));
    $types = aiChatDownloadTypes();
    if (!isset($types[$ext])) return null;
    $content = (string)($spec['content']??'');
    if ($content === '' || strlen($content)>50000) return null;
    $name = aiChatCleanFileName((string)($spec['filename']??'report.'.$ext));
    if (strtolower((string)pathinfo($name,PATHINFO_EXTENSION)) !== $ext) $name = substr((string)pathinfo($name,PATHINFO_FILENAME),0,145).'.'.$ext;
    $storage = bin2hex(random_bytes(32));
    $path=aiChatFilePath($storage);
    if ($ext === 'json') {
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('AI produced an invalid JSON attachment.', 422);
        $content = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    if ($ext === 'xlsx') {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) return null;
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $ss->getActiveSheet();
        $handle = fopen('php://temp','w+');
        fwrite($handle,$content); rewind($handle);
        $row=1;
        while (($cells=fgetcsv($handle))!==false && $row<=250) {
            foreach (array_slice($cells,0,25) as $col=>$value) {
                $ws->setCellValueExplicit([$col+1,$row],mb_safe_utf8((string)$value),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $row++;
        }
        fclose($handle);
        try { (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($path); }
        finally { $ss->disconnectWorksheets(); unset($ss); }
    } else {
        if ($ext === 'csv') {
            $handle=fopen('php://temp','w+'); fwrite($handle,$content); rewind($handle);
            $safe=fopen('php://temp','w+');
            while(($cells=fgetcsv($handle))!==false) {
                fputcsv($safe,array_map(static function($value){
                    $s=(string)$value;
                    return preg_match('/^[\s]*[=+@\-]/u',$s) ? "'".$s : $s;
                },$cells));
            }
            rewind($safe); $content=stream_get_contents($safe); fclose($handle); fclose($safe);
        }
        if (file_put_contents($path,$content,LOCK_EX)===false) throw new RuntimeException('Could not save the generated file.',503);
    }
    @chmod($path,0600);
    $size=(int)filesize($path);
    try {
        $stmt=dbExecute($conn,'INSERT INTO dynabase_ai_chat_files (thread_id,user_id,message_id,kind,original_name,storage_name,extension,mime_type,byte_size) VALUES (?,?,?,\'generated\',?,?,?,?,?)','iiissssi',[$threadId,$userId,$messageId,$name,$storage,$ext,$types[$ext],$size]);
        $id=(int)$stmt->insert_id; $stmt->close();
        return ['id'=>$id,'name'=>$name,'size'=>$size,'extension'=>$ext,'kind'=>'generated'];
    } catch(Throwable $e) { @unlink($path); throw $e; }
}

function aiChatCleanupFiles(mysqli $conn, ?int $threadId = null, ?int $olderDays = null): void
{
    if ($threadId !== null) {
        $rows = dbFetchAll($conn,'SELECT storage_name FROM dynabase_ai_chat_files WHERE thread_id=?','i',[$threadId]);
    } elseif ($olderDays !== null) {
        $rows = dbFetchAll($conn,'SELECT storage_name FROM dynabase_ai_chat_files WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)','i',[$olderDays]);
    } else return;
    foreach ($rows as $row) @unlink(aiChatFilePath((string)$row['storage_name']));
    if ($olderDays !== null) {
        $stmt=dbExecute($conn,'DELETE FROM dynabase_ai_chat_files WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)','i',[$olderDays]);
        $stmt->close();
    }
}
