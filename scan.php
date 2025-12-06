<?php
/**
 * Universal file scanning script.
 *
 * Main settings:
 * 1) ACCESS_BITRIX - set true to use Bitrix admin authorization; if Bitrix is not detected, password mode is used automatically.
 * 2) ACCESS_PASSWORD - password to access the script when not using Bitrix.
 * 3) CHUNK_SIZE - how many files to read per AJAX request (chunk).
 * 4) SKIP_SYMLINKS - whether to skip symbolic links.
 * 5) excludePrefixes - skip files/folders starting with these prefixes.
 * 6) excludeFiles - files to skip.
 * 7) excludeDirs - directories to skip.
 * 8) DEBUG_MODE - enable detailed debug logging.
 */

// -------------------------------------
// 1. Configuration
// -------------------------------------

define('CHUNK_SIZE', 10);               // How many files to read per AJAX request (chunk)
define('ACCESS_BITRIX', true);          // Set true for admin-only access, false for password login (auto-disables if Bitrix not detected)
define('ACCESS_PASSWORD', '123456');    // Password to access the script (if not using Bitrix)
define('TOKEN_MAX', '800000');         // Maximum token count per file (to limit size)
$SKIP_SYMLINKS = false;

// Exclusions
$excludePrefixes = ['_', 'test']; // Files/folders starting with these prefixes are skipped
$excludeFiles    = ['composer.lock'];
$excludeDirs     = ['log', 'vendor', 'old'];

// Enable detailed debug log?
define('DEBUG_MODE', false); // Set to true for more details

// Determine Bitrix availability and access mode
$docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
$bitrixPrologPath = $docRoot ? $docRoot . '/bitrix/modules/main/include/prolog_before.php' : '';
$useBitrix = (defined('ACCESS_BITRIX') && ACCESS_BITRIX === true && $bitrixPrologPath && file_exists($bitrixPrologPath));

// -------------------------------------
// 0. If running in Bitrix mode, connect the core first
// -------------------------------------
if ($useBitrix) {
    require_once $bitrixPrologPath;
    global $USER;
    // Exit if not an admin
    if (!$USER->IsAdmin()) {
        header("HTTP/1.1 403 Forbidden");
        echo "Access denied";
        exit;
    }
}

// -------------------------------------
// 1. Start the session (after prolog_before)
// -------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Holds warning text for password mode (not used when default password is blocked)
$passwordWarning = '';

// -------------------------------------
// 2. If not in Bitrix mode, check the password
// -------------------------------------
if (!$useBitrix) {
    if (ACCESS_PASSWORD === '123456') {
        renderDefaultPasswordWarning();
    }
    if (empty($_SESSION['scan2json_authenticated']) || $_SESSION['scan2json_authenticated'] !== true) {
        $errorMessage = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['access_password'] ?? '';
            if ($password === ACCESS_PASSWORD) {
                $_SESSION['scan2json_authenticated'] = true;
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
            $errorMessage = 'Invalid password.';
        }
        renderPasswordForm($errorMessage);
    }
}

// Make sure the session has required variables
if (!isset($_SESSION['SCAN_TMPFILE']))  $_SESSION['SCAN_TMPFILE']  = '';
if (!isset($_SESSION['SCAN_FILECOUNT']))$_SESSION['SCAN_FILECOUNT'] = 0;
if (!isset($_SESSION['SCAN_OFFSET']))   $_SESSION['SCAN_OFFSET']   = 0; 
if (!isset($_SESSION['CUR_FOLDER']))    $_SESSION['CUR_FOLDER']    = '';

// -------------------------------------
// 3. Global debug log (if needed)
// -------------------------------------
$GLOBALS['DEBUG_LOG'] = [];
function debugLog($msg) {
    if (DEBUG_MODE) {
        $GLOBALS['DEBUG_LOG'][] = $msg;
    }
}

/**
 * Renders a Bootstrap-based password login page and exits.
 *
 * @param string|null $errorMessage Optional error message to display above the form.
 * @return void
 */
function renderPasswordForm(?string $errorMessage = null): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Access required</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h1 class="h4 mb-3 text-center">Enter password</h1>
                        <?php if ($errorMessage !== null): ?>
                            <div class="alert alert-danger py-2 mb-3">
                                <?=htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8')?>
                            </div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="mb-3">
                                <label for="access_password" class="form-label">Password</label>
                                <input
                                    type="password"
                                    name="access_password"
                                    id="access_password"
                                    class="form-control"
                                    autocomplete="current-password"
                                    required
                                >
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Log in</button>
                        </form>
                    </div>
                </div>
                <p class="text-muted small mt-3 text-center">
                    Tip: change the ACCESS_PASSWORD constant in the script before using it on a real server.
                </p>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Renders a blocking notice when the default password is still in use.
 *
 * @return void
 */
function renderDefaultPasswordWarning(): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Access blocked</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-sm border-danger">
                    <div class="card-body">
                        <h1 class="h4 mb-3 text-center text-danger">Access blocked</h1>
                        <div class="alert alert-warning mb-0">
                            Please change the ACCESS_PASSWORD constant from the default value before using this tool.
                        </div>
                    </div>
                </div>
                <p class="text-muted small mt-3 text-center">
                    Update ACCESS_PASSWORD in scan.php and reload the page.
                </p>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Reset scan-related session state.
 *
 * @return void
 */
function resetScanState(): void
{
    $_SESSION['SCAN_TMPFILE']    = '';
    $_SESSION['SCAN_FILECOUNT']  = 0;
    $_SESSION['SCAN_OFFSET']     = 0;
    $_SESSION['SCAN_JSONLFILE']  = '';
    $_SESSION['SCAN_JSONFILE']   = '';
    $_SESSION['SCAN_FOLDERNAME'] = '';
}

/**
 * Decode custom selection JSON and return allowed top-level items.
 *
 * @param string $raw
 * @return array<string>
 */
function parseCustomSelection(string $raw): array
{
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return [];
    }
    $clean = [];
    foreach ($decoded['items'] as $item) {
        if (is_string($item) && $item !== '') {
            $clean[] = $item;
        }
    }
    return $clean;
}

/**
 * Recursively clear files and subdirectories inside scan_tmp.
 *
 * @param string $dir
 * @return array<string, int> Array with deleted and errors counters.
 */
function clearScanTmp(string $dir): array
{
    $deleted = 0;
    $errors = 0;
    if (!is_dir($dir)) {
        return ['deleted' => 0, 'errors' => 0];
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return ['deleted' => 0, 'errors' => 1];
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $result = clearScanTmp($path);
            $deleted += $result['deleted'];
            $errors += $result['errors'];
            if (@rmdir($path)) {
                $deleted++;
            } else {
                $errors++;
            }
        } else {
            if (@unlink($path)) {
                $deleted++;
            } else {
                $errors++;
            }
        }
    }
    return ['deleted' => $deleted, 'errors' => $errors];
}

/**
 * Check if scan_tmp contains any files or folders.
 *
 * @param string $dir
 * @return bool
 */
function scanTmpHasData(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return false;
    }
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            return true;
        }
    }
    return false;
}

/**
 * Convert bytes to a short human-readable string (e.g., 950B, 1.2KB, 12MB).
 *
 * @param int $bytes
 * @return string
 */
function formatSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    if ($size >= 100) {
        $sizeStr = (string) round($size);
    } elseif ($size >= 10) {
        $sizeStr = number_format($size, 1);
    } else {
        $sizeStr = number_format($size, 2);
    }
    return $sizeStr . $units[$i];
}

// -------------------------------------
// 4. AJAX: handle one chunk (scanChunk)
// -------------------------------------
if (
    isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === 'Y'
    && isset($_REQUEST['action']) && $_REQUEST['action'] === 'scanChunk'
) {
    // Return JSON only
    header('Content-Type: application/json; charset=UTF-8');

    $tmpFile   = $_SESSION['SCAN_TMPFILE']   ?? '';
    $total     = (int) ($_SESSION['SCAN_FILECOUNT'] ?? 0);
    $offset    = (int) ($_SESSION['SCAN_OFFSET'] ?? 0);

    // New: path to the jsonl file and json file
    $jsonlFile = '';
    $jsonFile = '';
    if ($tmpFile) {
        $jsonlFile = preg_replace('/\.txt$/', '.jsonl', $tmpFile);
        $jsonFile = preg_replace('/\.txt$/', '.json', $tmpFile);
        if (!isset($_SESSION['SCAN_JSONLFILE'])) {
            $_SESSION['SCAN_JSONLFILE'] = $jsonlFile;
        }
        if (!isset($_SESSION['SCAN_JSONFILE'])) {
            $_SESSION['SCAN_JSONFILE'] = $jsonFile;
        }
    }

    // If the file list is not built
    if (!$tmpFile || !file_exists($tmpFile) || $total <= 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'File list is not initialized or is empty.'
        ]);
        exit;
    }

    // Function to check for binary files
    function isBinaryFile($path) {
        $h = @fopen($path, 'rb');
        if (!$h) return true; // if open failed, treat as binary to skip it
        $chunk = fread($h, 1024);
        fclose($h);
        return (strpos($chunk, "\0") !== false);
    }

    // Read N lines from file starting at byte offset
    function readPathsChunk($filePath, $byteOffset, $count) {
        $paths = [];
        $h = fopen($filePath, 'rb');
        fseek($h, $byteOffset);
        $readLines = 0;
        while (!feof($h) && $readLines < $count) {
            $line = fgets($h);
            if ($line === false) break;
            $line = trim($line);
            if ($line !== '') {
                $paths[] = $line;
                $readLines++;
            }
        }
        $newOffset = ftell($h);
        fclose($h);
        return [$paths, $newOffset];
    }

    // Read a chunk of paths
    list($paths, $newOffset) = readPathsChunk($tmpFile, $offset, CHUNK_SIZE);

    if (empty($paths)) {
        // Count tokens in the jsonl file
        $tokenCount = 0;
        $jsonlFilePath = $_SESSION['SCAN_JSONLFILE'] ?? '';
        if ($jsonlFilePath && file_exists($jsonlFilePath)) {
            $fh = fopen($jsonlFilePath, 'rb');
            while (($line = fgets($fh)) !== false) {
                $row = json_decode($line, true);
                if (isset($row['content'])) {
                    $tokenCount += str_word_count($row['content']);
                }
            }
            fclose($fh);
        }

        // Build JSON files in parts
        $jsonFilePath = $_SESSION['SCAN_JSONFILE'] ?? '';
        $jsonParts = [];
        $jsonPartFiles = [];
        $folderName = $_SESSION['SCAN_FOLDERNAME'] ?? 'result';
        $safeFolder = preg_replace('/[^a-zA-Z0-9_\-]+/u', '_', $folderName);
        $tokenMax = (int)constant('TOKEN_MAX');
        $zipFile = '';
        $jsonlSize = '';
        $jsonSize = '';
        $jsonlZipSize = '';
        $jsonZipSize = '';
        $partsZipSize = '';
        $jsonFileCreated = false;
        $jsonlZipFile = '';
        $jsonZipFile = '';
        $splitMode = false;
        if ($jsonlFilePath && file_exists($jsonlFilePath) && $jsonFilePath) {
            $fh = fopen($jsonlFilePath, 'rb');
            $jsonHandle = @fopen($jsonFilePath, 'wb');
            $firstJsonItem = true;
            if ($jsonHandle) {
                fwrite($jsonHandle, "[\n");
            }
            $currentPart = [];
            $currentTokens = 0;
            $partNum = 1;
            $baseName = basename($jsonFilePath, '.json');
            while (($line = fgets($fh)) !== false) {
                $row = json_decode($line, true);
                if (!$row) continue;
                $tokens = isset($row['content']) ? str_word_count($row['content']) : 0;
                // Stream to the single JSON file as an array
                if ($jsonHandle) {
                    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    if ($encoded !== false) {
                        if (!$firstJsonItem) {
                            fwrite($jsonHandle, ",\n");
                        }
                        fwrite($jsonHandle, $encoded);
                        $firstJsonItem = false;
                    }
                }
                // If adding the line exceeds the limit, save the part and start a new one
                if ($currentTokens + $tokens > $tokenMax && count($currentPart) > 0) {
                    $splitMode = true;
                    $partFile = dirname($jsonFilePath) . '/' . $baseName . '_' . $partNum . '.json';
                    file_put_contents($partFile, json_encode($currentPart, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
                    $jsonPartFiles[] = basename($partFile);
                    $partNum++;
                    $currentPart = [];
                    $currentTokens = 0;
                }
                $currentPart[] = $row;
                $currentTokens += $tokens;
            }
            fclose($fh);
            if ($jsonHandle) {
                fwrite($jsonHandle, "\n]");
                fclose($jsonHandle);
                $jsonFileCreated = true;
            }
            // Save the last part only if we actually split
            if ($splitMode && count($currentPart) > 0) {
                $partFile = dirname($jsonFilePath) . '/' . $baseName . '_' . $partNum . '.json';
                file_put_contents($partFile, json_encode($currentPart, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
                $jsonPartFiles[] = basename($partFile);
            }

            // Normalize part numbering with padding
            if ($splitMode && count($jsonPartFiles) > 0) {
                $totalParts = count($jsonPartFiles);
                $pad = strlen((string)$totalParts);
                $renamedParts = [];
                foreach ($jsonPartFiles as $idx => $fname) {
                    $newName = $baseName . '_' . str_pad($idx + 1, $pad, '0', STR_PAD_LEFT) . '.json';
                    $oldPath = dirname($jsonFilePath) . '/' . $fname;
                    $newPath = dirname($jsonFilePath) . '/' . $newName;
                    if ($newPath !== $oldPath) {
                        @rename($oldPath, $newPath);
                    }
                    $renamedParts[] = $newName;
                }
                $jsonPartFiles = $renamedParts;
            }

            // Archive all parts into a zip
            if ($splitMode && count($jsonPartFiles) > 0) {
                $zipFile = dirname($jsonFilePath) . '/' . $baseName . '_parts.zip';
                $zip = new ZipArchive();
                if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    foreach ($jsonPartFiles as $f) {
                        $zip->addFile(dirname($jsonFilePath) . '/' . $f, $f);
                    }
                    $zip->close();
                } else {
                    $zipFile = '';
                }
            }
            // Zip JSONL
            if (file_exists($jsonlFilePath)) {
                $jsonlBase = basename($jsonlFilePath, '.jsonl');
                $jsonlZipPath = dirname($jsonlFilePath) . '/' . $jsonlBase . '.jsonl.zip';
                $zip = new ZipArchive();
                if ($zip->open($jsonlZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $zip->addFile($jsonlFilePath, basename($jsonlFilePath));
                    $zip->close();
                    $jsonlZipFile = basename($jsonlZipPath);
                    $jsonlZipSize = file_exists($jsonlZipPath) ? formatSize((int) filesize($jsonlZipPath)) : '';
                }
                $jsonlSize = formatSize((int) filesize($jsonlFilePath));
            }
            // Zip single JSON (if created)
            if ($jsonFileCreated && file_exists($jsonFilePath)) {
                $jsonBase = basename($jsonFilePath, '.json');
                $jsonZipPath = dirname($jsonFilePath) . '/' . $jsonBase . '.json.zip';
                $zip = new ZipArchive();
                if ($zip->open($jsonZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $zip->addFile($jsonFilePath, basename($jsonFilePath));
                    $zip->close();
                    $jsonZipFile = basename($jsonZipPath);
                }
                $jsonSize = formatSize((int) filesize($jsonFilePath));
                if (file_exists($jsonZipPath ?? '')) {
                    $jsonZipSize = formatSize((int) filesize($jsonZipPath));
                }
            }
            if (file_exists($zipFile)) {
                $partsZipSize = formatSize((int) filesize($zipFile));
            }
        }

        echo json_encode([
            'status'    => 'done',
            'message'   => 'All files processed',
            'processed' => 0,
            'total'     => $total,
            'jsonl'     => '',
            'jsonl_file'=> basename($_SESSION['SCAN_JSONLFILE'] ?? ''),
            'json_file' => $jsonFileCreated ? basename($_SESSION['SCAN_JSONFILE'] ?? '') : '',
            'json_parts'=> $jsonPartFiles,
            'zip_file'  => $zipFile ? basename($zipFile) : '',
            'jsonl_zip' => $jsonlZipFile,
            'json_zip'  => $jsonZipFile,
            'jsonl_size'=> $jsonlSize,
            'json_size' => $jsonSize,
            'jsonl_zip_size' => $jsonlZipSize,
            'json_zip_size' => $jsonZipSize,
            'zip_size'  => $partsZipSize,
            'token_count' => $tokenCount
        ]);
        $_SESSION['SCAN_OFFSET'] = $newOffset;
        exit;
    }

    // Process paths
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    $jsonl   = '';
    $count   = 0;

    // Open jsonl file for appending
    $jsonlFp = @fopen($jsonlFile, 'ab');
    if (!$jsonlFp) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Failed to open file for writing: ' . $jsonlFile
        ]);
        exit;
    }

    foreach ($paths as $p) {
        // Skip binaries
        if (isBinaryFile($p)) {
            continue;
        }
        $content = @file_get_contents($p);
        if ($content === false) {
            continue;
        }
        // Path relative to document root
        $rel = ltrim(str_replace($docRoot, '', $p), '/');
        $data = [
            'file'    => $rel,
            'content' => $content
        ];
        $line = json_encode($data, JSON_UNESCAPED_UNICODE)."\n";
        fwrite($jsonlFp, $line);
        $count++;
    }
    fclose($jsonlFp);

    // Update the offset
    $_SESSION['SCAN_OFFSET'] = $newOffset;

    // Return result
    echo json_encode([
        'status'    => 'ok',
        'processed' => $count,
        'total'     => $total,
        'jsonl'     => '',
        'jsonl_file'=> basename($jsonlFile),
        'json_file' => basename($jsonFile)
        // json_parts is not returned on intermediate chunks
    ]);
    exit;
}

// -------------------------------------
// 5. Functions to build the file list
// -------------------------------------
function shouldProcessPath($fullPath, &$visitedInodes) {
    global $SKIP_SYMLINKS;

    if ($SKIP_SYMLINKS && is_link($fullPath)) {
        debugLog("[shouldProcessPath] Skipping symlink: $fullPath");
        return false;
    }

    $inode = @fileinode($fullPath);
    // Skip if already seen (and not the symlink itself)
    if ($inode && isset($visitedInodes[$inode]) && !is_link($fullPath)) {
        debugLog("[shouldProcessPath] Duplicate inode, skipping: $fullPath");
        return false;
    }
    // Mark any non-symlink so we do not enter it again
    if ($inode && !is_link($fullPath)) {
        $visitedInodes[$inode] = true;
    }
    return true;
}

/**
 * Collect file paths recursively into the temp list.
 *
 * @param string $dir
 * @param resource $fp
 * @param array<int, bool> $visitedInodes
 * @param array<string> $allowedRootItems
 * @param int $depth
 * @return void
 */
function collectFilePathsToFile($dir, $fp, &$visitedInodes, array $allowedRootItems, $depth = 0) {
    global $excludePrefixes, $excludeFiles, $excludeDirs;
    
    // Get all items in the folder
    $items = @scandir($dir);
    
    if ($items === false) {
        debugLog("[scandir failed] $dir → " . print_r(error_get_last(), true));
        return;
    }

    if (!is_array($items)) return;

    $hasFiles = false;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        if ($depth === 0 && !empty($allowedRootItems) && !in_array($item, $allowedRootItems, true)) {
            continue;
        }

        // Skip folders with the configured prefixes
        foreach ($excludePrefixes as $pref) {
            if (strpos($item, $pref) === 0) {
                continue 2;
            }
        }

        // Full path to file or folder
        $full = $dir . '/' . $item;

        // Log current item being processed
        debugLog("[collectFilePathsToFile] Checking: $full");

        if (!shouldProcessPath($full, $visitedInodes)) {
            continue;
        }

        // If this is a folder
        if (is_dir($full)) {
            if (in_array($item, $excludeDirs)) {
                continue;
            }
            // Recursively handle nested folders
            collectFilePathsToFile($full, $fp, $visitedInodes, $allowedRootItems, $depth + 1);
        } elseif (is_file($full)) {
            // If this is a file, write its path
            if (in_array($item, $excludeFiles)) {
                continue;
            }
            fwrite($fp, $full . "\n");
            $hasFiles = true;
        }
    }

    if ($hasFiles) {
        debugLog("[collectFilePathsToFile] Folder with files: $dir");
    } else {
        debugLog("[collectFilePathsToFile] Folder is empty: $dir");
    }
}

// -------------------------------------
// 6. Form processing
// -------------------------------------
$method                 = $_POST['folder_select_method'] ?? 'manual'; 
$currentFolderDropdown  = $_SESSION['CUR_FOLDER'] ?? '';
$docRoot                = realpath($_SERVER['DOCUMENT_ROOT']);
$scanTmpDir             = $docRoot . '/scan_tmp';
$hasGeneratedData       = scanTmpHasData($scanTmpDir);
$manualPathInput        = ltrim((string)($_POST['scan_folder'] ?? ''), '/');
$customSelectionRaw     = $_POST['custom_selection'] ?? '';
$allowedRootItems       = parseCustomSelection($customSelectionRaw);
$selectionError         = '';
$currentSubdirs         = [];
$currentFiles           = [];
$openSelection          = isset($_POST['open_selection']) && $_POST['open_selection'] === '1';
$clearMessage           = '';

// Reset
if (isset($_POST['reset'])) {
    resetScanState();
    $_SESSION['CUR_FOLDER']      = '';
    $currentFolderDropdown       = '';
    $customSelectionRaw         = '';
    $allowedRootItems           = [];
    $selectionError             = '';
    $hasGeneratedData           = scanTmpHasData($scanTmpDir);
}

// Clear generated data in scan_tmp
if (isset($_POST['clear_generated'])) {
    $res = clearScanTmp($scanTmpDir);
    resetScanState();
    $_SESSION['CUR_FOLDER']      = '';
    $currentFolderDropdown       = '';
    $customSelectionRaw         = '';
    $allowedRootItems           = [];
    $selectionError             = '';
    $clearMessage = "Deleted {$res['deleted']} item(s) from scan_tmp" . ($res['errors'] ? " with {$res['errors']} error(s)" : '');
    $hasGeneratedData = scanTmpHasData($scanTmpDir);
}

// Expand subfolders
if (
    $method === 'dropdown'
    && isset($_POST['expandDropdown'])
    && !isset($_POST['collect_and_scan'])
) {
    resetScanState();
    $sel   = trim($_POST['selected_subfolder'] ?? '', '/');
    if ($sel !== '') {
        $tryRel  = $currentFolderDropdown 
                   ? "$currentFolderDropdown/$sel" 
                   : $sel;
        $fullTry = $docRoot . '/' . $tryRel;
        if (is_dir($fullTry)) {
            $_SESSION['CUR_FOLDER']    = $tryRel;
            $currentFolderDropdown     = $tryRel;
        }
    }
}

// -------------------------------------
// 7. "Generate and scan"
// -------------------------------------
$scanMessage = '';
if (isset($_POST['collect_and_scan'])) {
    $_SESSION['SCAN_TMPFILE']   = '';
    $_SESSION['SCAN_FILECOUNT'] = 0;
    $_SESSION['SCAN_OFFSET']    = 0;
    $_SESSION['SCAN_JSONLFILE'] = '';
    $_SESSION['SCAN_FOLDERNAME'] = '';

    $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    $fullPathToScan = '';
    $folderName = '';

    if ($method === 'manual') {
        $input = ltrim($_POST['scan_folder'] ?? '', '/');
        $path  = $docRoot . '/' . $input;
        $folderName = $input !== '' ? basename($input) : 'root';
        if (is_dir($path) && is_readable($path)) {
            $fullPathToScan = $path;
        } else {
            $scanMessage = "Folder \"{$path}\" not found or not readable.";
        }
    } else {
        $try = $docRoot . '/' . ltrim($currentFolderDropdown, '/');
        $folderName = $currentFolderDropdown !== '' ? basename($currentFolderDropdown) : 'root';
        if (is_dir($try)) {
            $fullPathToScan = $try;
        }
    }

    // Save folder name in the session
    $_SESSION['SCAN_FOLDERNAME'] = $folderName;

    if (!$fullPathToScan) {
        $scanMessage = "Folder not found or outside of DOCUMENT_ROOT.";
    } elseif ($customSelectionRaw !== '' && empty($allowedRootItems)) {
        $scanMessage = "Please select at least one item to scan.";
    } else {
        $tmpDir = $docRoot . '/scan_tmp'; 
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        // Build file name using folder name and timestamp
        $safeFolder = preg_replace('/[^a-zA-Z0-9_\-]+/u', '_', $folderName);
        $timestamp = date('ymdHis');
        $baseName = $safeFolder . '_' . $timestamp;
        $tmpFilePath = $tmpDir . '/' . $baseName . '.txt';

        $fp = @fopen($tmpFilePath, 'wb');
        if (!$fp) {
            $scanMessage = "Failed to create temporary file: $tmpFilePath";
        } else {
            $visited = [];
            collectFilePathsToFile($fullPathToScan, $fp, $visited, $allowedRootItems, 0);
            fclose($fp);

            // Count lines
            $lineCount = 0;
            $check = fopen($tmpFilePath, 'rb');
            while (!feof($check)) {
                $line = fgets($check);
                if ($line !== false) $lineCount++;
            }
            fclose($check);

            $_SESSION['SCAN_TMPFILE']   = $tmpFilePath;
            $_SESSION['SCAN_FILECOUNT'] = $lineCount;
            $_SESSION['SCAN_OFFSET']    = 0;
            // json/jsonl files share this base name
            $_SESSION['SCAN_JSONLFILE'] = preg_replace('/\.txt$/', '.jsonl', $tmpFilePath);
            $_SESSION['SCAN_JSONFILE']  = preg_replace('/\.txt$/', '.json', $tmpFilePath);

            $scanMessage = "List prepared. Total files: $lineCount. Starting automatic scanning...";
        }
    }
}

// Handle the "Level up" button
if (isset($_POST['goUp']) && $currentFolderDropdown) {
    // Split current path by "/"
    resetScanState();
    $parentFolder = dirname($currentFolderDropdown);
    $_SESSION['CUR_FOLDER'] = $parentFolder === '/' ? '' : $parentFolder;
    $currentFolderDropdown = $_SESSION['CUR_FOLDER'];  // Update current folder
}

// Build lists for current folder (subdirs and files)
$listingTarget = $method === 'manual' ? $manualPathInput : $currentFolderDropdown;
$listingPath = $docRoot . '/' . trim($listingTarget, '/');
if (is_dir($listingPath)) {
    $items = @scandir($listingPath);
    if (is_array($items)) {
        foreach ($items as $itm) {
            if ($itm === '.' || $itm === '..') {
                continue;
            }
            $full = $listingPath . '/' . $itm;
            if (is_dir($full)) {
                $currentSubdirs[] = $itm;
            } elseif (is_file($full)) {
                $currentFiles[] = $itm;
            }
        }
    }
}
sort($currentSubdirs);
sort($currentFiles);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>File Scanner</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-4">
    <h1 class="mb-4">File Scanner</h1>

    <?php if ($passwordWarning !== ''): ?>
        <div class="alert alert-warning"><?=htmlspecialchars($passwordWarning, ENT_QUOTES, 'UTF-8')?></div>
    <?php endif; ?>

    <!-- Form -->
    <form method="post" action="" class="mb-4" id="scanForm">
        <input type="hidden" name="custom_selection" id="custom_selection" value="<?=htmlspecialchars($customSelectionRaw, ENT_QUOTES, 'UTF-8')?>">
        <div class="mb-3">
            <label class="form-label fw-bold">How to select the folder:</label>

            <div class="form-check">
                <input 
                    class="form-check-input" 
                    type="radio" 
                    name="folder_select_method" 
                    id="radioManual" 
                    value="manual"
                    <?php if ($method === 'manual') echo 'checked'; ?>
                >
                <label class="form-check-label" for="radioManual">
                    Enter path manually ("/" is the root)
                </label>
            </div>

            <div class="ms-3 mt-2" id="manualBlock" style="display:none;">
                <label for="scan_folder" class="form-label">
                    Examples: "/", "/local", "/catalog/images"
                </label>
                <div class="d-flex align-items-center gap-2">
                    <input 
                        type="text" 
                        name="scan_folder" 
                        id="scan_folder" 
                        class="form-control" 
                        value="<?=htmlspecialchars($_POST['scan_folder'] ?? '')?>"
                        placeholder="Example: /local"
                    >
                    <button type="button" class="btn btn-secondary choose-items-btn text-nowrap">Choose items…</button>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input 
                    class="form-check-input" 
                    type="radio" 
                    name="folder_select_method" 
                    id="radioDropdown"
                    value="dropdown"
                    <?php if ($method === 'dropdown') echo 'checked'; ?>
                >
                <label class="form-check-label" for="radioDropdown">
                    Select from list
                </label>
            </div>

            <div class="mt-2" id="dropdownBlock" style="display:none;">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="alert alert-secondary p-2 mb-0 flex-grow-1">
                        <strong>Current folder:</strong>
                        <?php if (!$currentFolderDropdown): ?>
                            Site root
                        <?php else: ?>
                            /<?=htmlspecialchars($currentFolderDropdown)?>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-secondary choose-items-btn">Choose items…</button>
                </div>

                <div class="d-flex align-items-center gap-2 mb-3">
                    <?php
                    $subfolders = $currentSubdirs;
                    if (!empty($subfolders)) {
                        ?>
                        <!-- Automatic submit -->
                        <input type="hidden" name="expandDropdown" value="1">
                        <select name="selected_subfolder"
                                class="form-select w-auto"
                                onchange="this.form.submit();">
                            <option value="">-- Select a subfolder --</option>
                            <?php foreach ($subfolders as $sf): ?>
                                <option value="<?=htmlspecialchars($sf)?>"><?=$sf?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php
                    }
                    ?>
                    <?php if ($currentFolderDropdown): ?>
                        <button type="submit" name="goUp" class="btn btn-outline-secondary">Go up one level</button>
                        <button type="submit" name="reset" class="btn btn-outline-danger">Reset path</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="selectionPanel" class="bg-white border rounded p-3 mb-3" style="display:none;">
            <h6 class="mb-1">Select folders and files to include in the scan</h6>
            <p class="text-muted small mb-3">All items are selected by default. Uncheck those you want to exclude.</p>
            <div class="row g-2 mb-3">
                <?php
                $hadEntries = false;
                if (!empty($currentSubdirs)) {
                    $hadEntries = true;
                    foreach ($currentSubdirs as $dir) {
                        $checked = (empty($allowedRootItems) || in_array($dir, $allowedRootItems, true)) ? 'checked' : '';
                        ?>
                        <div class="col-6">
                            <label class="form-check">
                                <input class="form-check-input cs-entry" type="checkbox" data-name="<?=htmlspecialchars($dir, ENT_QUOTES, 'UTF-8')?>" <?=$checked?>>
                                <span class="form-check-label">[dir] <?=$dir?></span>
                            </label>
                        </div>
                        <?php
                    }
                }
                if (!empty($currentFiles)) {
                    $hadEntries = true;
                    foreach ($currentFiles as $file) {
                        $checked = (empty($allowedRootItems) || in_array($file, $allowedRootItems, true)) ? 'checked' : '';
                        ?>
                        <div class="col-6">
                            <label class="form-check">
                                <input class="form-check-input cs-entry" type="checkbox" data-name="<?=htmlspecialchars($file, ENT_QUOTES, 'UTF-8')?>" <?=$checked?>>
                                <span class="form-check-label">[file] <?=$file?></span>
                            </label>
                        </div>
                        <?php
                    }
                }
                if (!$hadEntries) {
                    echo '<div class="col-12 text-muted">No items found in this folder.</div>';
                }
                ?>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-primary btn-sm" id="applySelection">Apply selection</button>
                <button type="button" class="btn btn-secondary btn-sm" id="cancelSelection">Cancel</button>
                <span class="text-danger small" id="selectionError" style="display:none;">Please select at least one item to scan.</span>
            </div>
        </div>

        <div class="mt-4 mb-2">
            <input type="hidden" name="open_selection" id="open_selection" value="<?=$openSelection ? '1' : ''?>">
            <button type="submit" name="collect_and_scan" class="btn btn-primary me-2" id="scanBtn">
                Generate and scan
            </button>
            <button type="button" class="btn btn-outline-danger me-2" id="stopScanBtn" style="display:none;">
                Stop scan
            </button>
            <?php if ($hasGeneratedData): ?>
                <button type="submit" name="clear_generated" class="btn btn-outline-danger">
                    Delete data
                </button>
            <?php endif; ?>
        </div>
        <div id="inlineProgress" style="display:none;"></div>
    </form>

    <!-- Message output -->
    <?php if ($scanMessage !== ''): ?>
        <div class="alert alert-info">
            <?=htmlspecialchars($scanMessage)?>
        </div>
    <?php endif; ?>
    <?php if ($clearMessage !== ''): ?>
        <div class="alert alert-warning">
            <?=htmlspecialchars($clearMessage)?>
        </div>
    <?php endif; ?>

    <?php
    // Check whether the file list exists for scanning
    $total   = (int)$_SESSION['SCAN_FILECOUNT'];
    $tmpFile = $_SESSION['SCAN_TMPFILE'];
    $offset  = $_SESSION['SCAN_OFFSET'];
    $jsonlFile = $_SESSION['SCAN_JSONLFILE'] ?? '';
    $scanFolderName = $_SESSION['SCAN_FOLDERNAME'] ?? 'result';
    // Build names for downloading based on generated files
    $downloadFileName = $jsonlFile ? basename($jsonlFile) : 'result.jsonl';
    $downloadJsonFileName = ($_SESSION['SCAN_JSONFILE'] ?? '') ? basename($_SESSION['SCAN_JSONFILE']) : 'result.json';

    if ($tmpFile && file_exists($tmpFile) && $total > 0):
    ?>
        <div id="scanProgress" class="alert alert-warning">
            <div class="progress mb-2" style="height: 8px;">
                <div id="progressBar" class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            Automatic scanning in progress...<br>
            Processed: <span id="processedCount">0</span> of <?=$total?>
        </div>

        <div id="downloadBlock" style="display:none;" class="mb-3">
            <div class="alert alert-success">
                <b>Done!</b> 
                <div id="downloadJsonlRow" class="mt-2" style="display:none;">
                    <strong>JSONL:</strong>
                    <a id="downloadLink" href="" download="<?=htmlspecialchars($downloadFileName)?>">Download file</a>
                    <small class="text-muted" id="jsonlSize" style="display:none;"></small>
                    <span id="downloadJsonlZipWrap" style="display:none;">
                        &nbsp;|&nbsp;
                        <a id="downloadJsonlZip" href="" download="">Download zip</a>
                        <small class="text-muted" id="jsonlZipSize" style="display:none;"></small>
                    </span>
                </div>
                <div id="downloadJsonRow" class="mt-2" style="display:none;">
                    <strong>JSON:</strong>
                    <a id="downloadJsonLink" href="" download="<?=htmlspecialchars($downloadJsonFileName)?>">Download file</a>
                    <small class="text-muted" id="jsonSize" style="display:none;"></small>
                    <span id="downloadJsonZipWrap" style="display:none;">
                        &nbsp;|&nbsp;
                        <a id="downloadJsonZip" href="" download="">Download zip</a>
                        <small class="text-muted" id="jsonZipSize" style="display:none;"></small>
                    </span>
                </div>
                <div id="downloadJsonPartsRow" class="mt-2" style="display:none;">
                    <strong>JSON (parts):</strong>
                    <span id="downloadPartsZipWrap" style="display:none;">
                        <a id="downloadZipLink" href="" download="">Download archive (ZIP)</a>
                        <small class="text-muted" id="partsZipSize" style="display:none;"></small>
                    </span>
                </div>
                <div id="tokenCountBlock" class="mt-2"></div>
            </div>
        </div>
        <script>
        function formatNumber(n) {
            return n.toLocaleString('en-US');
        }
        let totalFiles = <?=$total?>;
        let isScanning = true;
        let jsonlFile = <?=json_encode($jsonlFile ? basename($jsonlFile) : '')?>;
        let jsonFile = <?=json_encode($_SESSION['SCAN_JSONFILE'] ? basename($_SESSION['SCAN_JSONFILE']) : '')?>;
        let downloadFileName = <?=json_encode($downloadFileName)?>;
        let downloadJsonFileName = <?=json_encode($downloadJsonFileName)?>;
        let downloadJsonlRow = document.getElementById('downloadJsonlRow');
        let downloadJsonRow = document.getElementById('downloadJsonRow');
        let downloadJsonPartsRow = document.getElementById('downloadJsonPartsRow');
        let stopBtn = document.getElementById('stopScanBtn');
        let scanBtn = document.getElementById('scanBtn');
        let inlineProgress = document.getElementById('inlineProgress');
        let inlineProgressBar = document.getElementById('inlineProgressBar');
        let progressBar = document.getElementById('progressBar');
        let jsonlSizeEl = document.getElementById('jsonlSize');
        let jsonlZipSizeEl = document.getElementById('jsonlZipSize');
        let jsonSizeEl = document.getElementById('jsonSize');
        let jsonZipSizeEl = document.getElementById('jsonZipSize');
        let partsZipSizeEl = document.getElementById('partsZipSize');
        if (scanBtn) {
            scanBtn.disabled = true;
            scanBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Scanning...';
        }
        if (inlineProgress && inlineProgressBar) {
            inlineProgress.style.display = '';
            inlineProgressBar.style.width = '0%';
            inlineProgressBar.classList.remove('bg-success');
            inlineProgressBar.setAttribute('aria-valuenow', '0');
        }
        function scanNextChunk() {
            if (!isScanning) return;
            let xhr = new XMLHttpRequest();
            xhr.open('GET', '?ajax=Y&action=scanChunk&_t=' + (new Date().getTime()), true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        let resp = JSON.parse(xhr.responseText);
                        let sp = document.getElementById('scanProgress');

                        if (resp.status === 'error') {
                            sp.classList.remove('alert-warning', 'alert-success');
                            sp.classList.add('alert-danger');
                            sp.textContent = resp.message; 
                            isScanning = false;
                            if (scanBtn) {
                                scanBtn.disabled = false;
                                scanBtn.textContent = 'Generate and scan';
                            }
                        } 
                        else if (resp.status === 'done') {
                            sp.classList.remove('alert-warning', 'alert-danger');
                            sp.classList.add('alert-success');
                            sp.textContent = 'All files processed!';
                            isScanning = false;
                            if (scanBtn) {
                                scanBtn.disabled = false;
                                scanBtn.textContent = 'Generate and scan';
                            }
                            if (inlineProgress) inlineProgress.style.display = 'none';
                            if (stopBtn) stopBtn.style.display = 'none';
                            // Show download link and token info
                            if (resp.jsonl_file) {
                                let block = document.getElementById('downloadBlock');
                                // JSONL row
                                let link = document.getElementById('downloadLink');
                                link.href = '/scan_tmp/' + resp.jsonl_file;
                                link.setAttribute('download', resp.jsonl_file || downloadFileName);
                                downloadJsonlRow.style.display = '';
                                if (jsonlSizeEl && resp.jsonl_size) {
                                    jsonlSizeEl.textContent = ' (' + resp.jsonl_size + ')';
                                    jsonlSizeEl.style.display = '';
                                }
                                if (resp.jsonl_zip) {
                                    let zipWrap = document.getElementById('downloadJsonlZipWrap');
                                    let zipLink = document.getElementById('downloadJsonlZip');
                                    zipLink.href = '/scan_tmp/' + resp.jsonl_zip;
                                    zipLink.setAttribute('download', resp.jsonl_zip || '');
                                    zipWrap.style.display = '';
                                    if (jsonlZipSizeEl && resp.jsonl_zip_size) {
                                        jsonlZipSizeEl.textContent = ' (' + resp.jsonl_zip_size + ')';
                                        jsonlZipSizeEl.style.display = '';
                                    }
                                }
                                // JSON row
                                if (resp.json_file) {
                                    let jsonRow = document.getElementById('downloadJsonRow');
                                    let jsonLink = document.getElementById('downloadJsonLink');
                                    jsonLink.href = '/scan_tmp/' + resp.json_file;
                                    jsonLink.setAttribute('download', resp.json_file || downloadJsonFileName);
                                    jsonRow.style.display = '';
                                    if (jsonSizeEl && resp.json_size) {
                                        jsonSizeEl.textContent = ' (' + resp.json_size + ')';
                                        jsonSizeEl.style.display = '';
                                    }
                                    if (resp.json_zip) {
                                        let jsonZipWrap = document.getElementById('downloadJsonZipWrap');
                                        let jsonZipLink = document.getElementById('downloadJsonZip');
                                        jsonZipLink.href = '/scan_tmp/' + resp.json_zip;
                                        jsonZipLink.setAttribute('download', resp.json_zip || '');
                                        jsonZipWrap.style.display = '';
                                        if (jsonZipSizeEl && resp.json_zip_size) {
                                            jsonZipSizeEl.textContent = ' (' + resp.json_zip_size + ')';
                                            jsonZipSizeEl.style.display = '';
                                        }
                                    }
                                }
                                // JSON parts row (zip only)
                                if (resp.zip_file) {
                                    let partsRow = document.getElementById('downloadJsonPartsRow');
                                    let partsZipWrap = document.getElementById('downloadPartsZipWrap');
                                    let zipLink = document.getElementById('downloadZipLink');
                                    zipLink.href = '/scan_tmp/' + resp.zip_file;
                                    zipLink.setAttribute('download', resp.zip_file || '');
                                    partsZipWrap.style.display = '';
                                    partsRow.style.display = '';
                                    if (partsZipSizeEl && resp.zip_size) {
                                        partsZipSizeEl.textContent = ' (' + resp.zip_size + ')';
                                        partsZipSizeEl.style.display = '';
                                    }
                                }
                                block.style.display = '';
                                if (resp.token_count !== undefined) {
                                    document.getElementById('tokenCountBlock').textContent =
                                        'Tokens (words) in file: ' + formatNumber(resp.token_count);
                                }
                            }
                        }
                        else if (resp.status === 'ok') {
                            let processedSpan = document.getElementById('processedCount');
                            let pCount = parseInt(processedSpan.innerText, 10);
                            pCount += resp.processed;
                            processedSpan.innerText = pCount;

                            if (progressBar && totalFiles > 0) {
                                let percent = Math.min(100, Math.round((pCount / totalFiles) * 100));
                                progressBar.style.width = percent + '%';
                                progressBar.setAttribute('aria-valuenow', String(percent));
                            }
                            if (inlineProgressBar && totalFiles > 0) {
                                let percent = Math.min(100, Math.round((pCount / totalFiles) * 100));
                                inlineProgressBar.style.width = percent + '%';
                                inlineProgressBar.setAttribute('aria-valuenow', String(percent));
                                if (percent === 100) {
                                    inlineProgressBar.classList.add('bg-success');
                                }
                            }

                            if (pCount < totalFiles) {
                                scanNextChunk();
                            } else {
                                sp.classList.remove('alert-warning', 'alert-danger');
                                sp.classList.add('alert-success');
                                sp.textContent = 'All files processed!';
                                isScanning = false;
                                if (inlineProgress) inlineProgress.style.display = 'none';
                                if (scanBtn) {
                                    scanBtn.disabled = false;
                                    scanBtn.textContent = 'Generate and scan';
                                }
                                if (stopBtn) stopBtn.style.display = 'none';
                                // Show download link
                                if (resp.jsonl_file) {
                                    let block = document.getElementById('downloadBlock');
                                    // JSONL row
                                    let link = document.getElementById('downloadLink');
                                    link.href = '/scan_tmp/' + resp.jsonl_file;
                                    link.setAttribute('download', resp.jsonl_file || downloadFileName);
                                    downloadJsonlRow.style.display = '';
                                    if (resp.jsonl_zip) {
                                        let zipWrap = document.getElementById('downloadJsonlZipWrap');
                                        let zipLink = document.getElementById('downloadJsonlZip');
                                        zipLink.href = '/scan_tmp/' + resp.jsonl_zip;
                                        zipLink.setAttribute('download', resp.jsonl_zip || '');
                                        zipWrap.style.display = '';
                                    }
                                    // JSON row
                                    if (resp.json_file) {
                                        let jsonRow = document.getElementById('downloadJsonRow');
                                        let jsonLink = document.getElementById('downloadJsonLink');
                                        jsonLink.href = '/scan_tmp/' + resp.json_file;
                                        jsonLink.setAttribute('download', resp.json_file || downloadJsonFileName);
                                        jsonRow.style.display = '';
                                        if (resp.json_zip) {
                                            let jsonZipWrap = document.getElementById('downloadJsonZipWrap');
                                            let jsonZipLink = document.getElementById('downloadJsonZip');
                                            jsonZipLink.href = '/scan_tmp/' + resp.json_zip;
                                            jsonZipLink.setAttribute('download', resp.json_zip || '');
                                            jsonZipWrap.style.display = '';
                                        }
                                    }
                                    // JSON parts row (zip only)
                                    if (resp.zip_file) {
                                        let partsRow = document.getElementById('downloadJsonPartsRow');
                                        let partsZipWrap = document.getElementById('downloadPartsZipWrap');
                                        let zipLink = document.getElementById('downloadZipLink');
                                        zipLink.href = '/scan_tmp/' + resp.zip_file;
                                        zipLink.setAttribute('download', resp.zip_file || '');
                                        partsZipWrap.style.display = '';
                                        partsRow.style.display = '';
                                    }
                                    block.style.display = '';
                                }
                            }
                        }
                    } catch (e) {
                        let sp = document.getElementById('scanProgress');
                        if (sp) {
                            sp.classList.remove('alert-warning', 'alert-success');
                            sp.classList.add('alert-danger');
                            sp.textContent = 'Response parse error: ' + e;
                        }
                        isScanning = false;
                        if (scanBtn) {
                            scanBtn.disabled = false;
                            scanBtn.textContent = 'Generate and scan';
                        }
                        if (stopBtn) stopBtn.style.display = 'none';
                        if (inlineProgress) inlineProgress.style.display = 'none';
                    }
                }
            };
            xhr.send();
        }

        if (stopBtn) {
            stopBtn.style.display = '';
            stopBtn.addEventListener('click', function() {
                isScanning = false;
                stopBtn.style.display = 'none';
                if (scanBtn) {
                    scanBtn.disabled = false;
                    scanBtn.textContent = 'Generate and scan';
                }
                if (inlineProgress) inlineProgress.style.display = 'none';
                let sp = document.getElementById('scanProgress');
                if (sp) {
                    sp.classList.remove('alert-warning');
                    sp.classList.add('alert-info');
                    sp.textContent = 'Scanning stopped by user.';
                }
            });
        }

        // Run the first request
        scanNextChunk();
        </script>
    <?php endif; ?>

    <?php if (DEBUG_MODE): ?>
        <hr>
        <pre><?= print_r($GLOBALS['DEBUG_LOG'], true) ?></pre>
    <?php endif; ?>

</div>

<!-- Bootstrap JS (optional) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Toggle blocks (radio manual / dropdown)
(function(){
    let radMan = document.getElementById('radioManual');
    let radDrop = document.getElementById('radioDropdown');
    let manBlock = document.getElementById('manualBlock');
    let dropBlock = document.getElementById('dropdownBlock');
    let chooseBtns = Array.from(document.querySelectorAll('.choose-items-btn'));
    let selectionPanel = document.getElementById('selectionPanel');
    let applySelection = document.getElementById('applySelection');
    let cancelSelection = document.getElementById('cancelSelection');
    let selectionError = document.getElementById('selectionError');
    let customSelectionInput = document.getElementById('custom_selection');
    let openSelectionInput = document.getElementById('open_selection');
    let openSelectionOnLoad = <?= $openSelection ? 'true' : 'false' ?>;

    function toggleMeth() {
        if (radMan.checked) {
            manBlock.style.display = '';
            dropBlock.style.display = 'none';
        } else {
            manBlock.style.display = 'none';
            dropBlock.style.display = '';
        }
    }

    radMan.addEventListener('change', toggleMeth);
    radDrop.addEventListener('change', toggleMeth);
    toggleMeth();

    if (chooseBtns.length && selectionPanel) {
        chooseBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (radMan.checked) {
                    if (openSelectionInput) openSelectionInput.value = '1';
                    document.getElementById('scanForm').submit();
                } else {
                    selectionPanel.style.display = 'block';
                    if (selectionError) selectionError.style.display = 'none';
                }
            });
        });
    }

    if (applySelection && selectionPanel) {
        applySelection.addEventListener('click', function() {
            let boxes = Array.from(document.querySelectorAll('.cs-entry'));
            let selected = boxes.filter(function(el){ return el.checked; }).map(function(el){ return el.getAttribute('data-name'); });
            if (selected.length === 0) {
                if (selectionError) selectionError.style.display = 'inline';
                return;
            }
            if (selectionError) selectionError.style.display = 'none';
            if (customSelectionInput) {
                customSelectionInput.value = JSON.stringify({items: selected});
            }
            selectionPanel.style.display = 'none';
        });
    }

    if (cancelSelection && selectionPanel) {
        cancelSelection.addEventListener('click', function() {
            if (selectionError) selectionError.style.display = 'none';
            selectionPanel.style.display = 'none';
        });
    }

    if (openSelectionOnLoad && selectionPanel) {
        selectionPanel.style.display = 'block';
        if (selectionError) selectionError.style.display = 'none';
        if (openSelectionInput) openSelectionInput.value = '';
    }
})();
</script>

</body>
</html>
