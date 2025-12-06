<?php
declare(strict_types=1);

// Basic configuration defaults (kept in sync conceptually with scan.php)
if (!defined('ACCESS_BITRIX')) {
    define('ACCESS_BITRIX', true);
}
if (!defined('ACCESS_PASSWORD')) {
    define('ACCESS_PASSWORD', '123456');
}
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
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
                    Update ACCESS_PASSWORD in restore.php and reload the page.
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
 * Authenticate user via Bitrix or password form.
 *
 * @return void
 */
function authenticateUser(): void
{
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'];
    $bitrixPrologPath = $docRoot ? $docRoot . '/bitrix/modules/main/include/prolog_before.php' : '';
    $useBitrix = (defined('ACCESS_BITRIX') && ACCESS_BITRIX === true && $bitrixPrologPath && file_exists($bitrixPrologPath));

    if ($useBitrix) {
        require_once $bitrixPrologPath;
        global $USER;
        if (!$USER || !$USER->IsAdmin()) {
            header('HTTP/1.1 403 Forbidden');
            echo 'Access denied';
            exit;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

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

/**
 * List available JSONL files in scan_tmp.
 *
 * @param string $scanTmpDir
 * @return array<int, string>
 */
function getAvailableJsonlFiles(string $scanTmpDir): array
{
    if (!is_dir($scanTmpDir)) {
        return [];
    }
    $files = glob($scanTmpDir . '/*.jsonl');
    if ($files === false) {
        return [];
    }
    return array_map('basename', $files);
}

/**
 * Normalize and validate relative path.
 *
 * @param string $path
 * @return string|null
 */
function sanitizeRelativePath(string $path): ?string
{
    $path = trim(str_replace(['\\', '//'], '/', $path));
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, '..')) {
        return null;
    }
    return $path;
}

/**
 * Normalize a path without requiring it to exist.
 *
 * @param string $path
 * @return string
 */
function normalizePath(string $path): string
{
    $parts = [];
    foreach (explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }
    return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
}

/**
 * Check if full path stays inside base.
 *
 * @param string $baseDir
 * @param string $fullPath
 * @return bool
 */
function isPathInsideBase(string $baseDir, string $fullPath): bool
{
    $baseReal = realpath($baseDir);
    if ($baseReal === false) {
        return false;
    }
    $candidate = normalizePath($fullPath);
    $baseNormalized = rtrim(normalizePath($baseReal), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($candidate . DIRECTORY_SEPARATOR, $baseNormalized);
}

/**
 * Restore files from JSONL snapshot.
 *
 * @param string $sourceFile
 * @param string $targetDir
 * @param bool $dryRun
 * @param bool $overwrite
 * @return array<string, int>
 */
function restoreFromJsonl(string $sourceFile, string $targetDir, bool $dryRun, bool $overwrite): array
{
    $counters = [
        'totalLines' => 0,
        'validRecords' => 0,
        'created' => 0,
        'overwritten' => 0,
        'skipped' => 0,
        'invalid' => 0,
        'errors' => 0,
    ];

    $handle = fopen($sourceFile, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open source file');
    }

    $baseReal = realpath($targetDir);
    if ($baseReal === false && !$dryRun) {
        if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            fclose($handle);
            throw new RuntimeException('Unable to create target directory');
        }
        $baseReal = realpath($targetDir);
    }
    if ($baseReal === false) {
        $baseReal = $targetDir;
    }

    while (($line = fgets($handle)) !== false) {
        $counters['totalLines']++;
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $record = json_decode($line, true);
        if (!is_array($record) || !isset($record['file'], $record['content']) || !is_string($record['file']) || !is_string($record['content'])) {
            $counters['invalid']++;
            continue;
        }
        $sanitized = sanitizeRelativePath($record['file']);
        if ($sanitized === null) {
            $counters['invalid']++;
            continue;
        }
        $fullPath = $baseReal . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sanitized);
        if (!isPathInsideBase($baseReal, $fullPath)) {
            $counters['invalid']++;
            continue;
        }
        $counters['validRecords']++;

        if ($dryRun) {
            if (file_exists($fullPath)) {
                $overwrite ? $counters['overwritten']++ : $counters['skipped']++;
            } else {
                $counters['created']++;
            }
            continue;
        }

        $dirName = dirname($fullPath);
        if (!is_dir($dirName) && !mkdir($dirName, 0775, true) && !is_dir($dirName)) {
            $counters['errors']++;
            continue;
        }

        if (file_exists($fullPath)) {
            if (!$overwrite) {
                $counters['skipped']++;
                continue;
            }
            $written = file_put_contents($fullPath, $record['content']);
            if ($written === false) {
                $counters['errors']++;
            } else {
                $counters['overwritten']++;
            }
        } else {
            $written = file_put_contents($fullPath, $record['content']);
            if ($written === false) {
                $counters['errors']++;
            } else {
                $counters['created']++;
            }
        }
    }

    fclose($handle);

    return $counters;
}

authenticateUser();

$docRoot = realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'];
$scanTmpDir = $docRoot . '/scan_tmp';
$availableFiles = getAvailableJsonlFiles($scanTmpDir);
$message = '';
$messageType = 'info';
$resultCounters = null;

$selectedFile = $_POST['source_file'] ?? '';
$customFile = trim($_POST['source_file_custom'] ?? '');
$targetDirInput = trim($_POST['target_dir'] ?? 'restored_project');
$dryRun = isset($_POST['dry_run']);
$overwrite = isset($_POST['overwrite']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourcePath = '';
    if ($customFile !== '') {
        $sourcePath = $customFile;
    } elseif ($selectedFile !== '') {
        $sourcePath = $scanTmpDir . '/' . $selectedFile;
    }

    if ($sourcePath === '') {
        $message = 'Source JSONL file is required.';
        $messageType = 'danger';
    } elseif (!is_file($sourcePath) || !is_readable($sourcePath)) {
        $message = 'Source file not found or unreadable.';
        $messageType = 'danger';
    } else {
        $targetDirInput = trim($targetDirInput, " \\/");
        if ($targetDirInput === '' || str_contains($targetDirInput, '..')) {
            $message = 'Invalid target directory.';
            $messageType = 'danger';
        } else {
            $targetDir = $docRoot . DIRECTORY_SEPARATOR . $targetDirInput;
            if (!isPathInsideBase($docRoot, $targetDir . DIRECTORY_SEPARATOR . 'dummy')) {
                $message = 'Target directory must be under DOCUMENT_ROOT.';
                $messageType = 'danger';
            } else {
                try {
                    $resultCounters = restoreFromJsonl($sourcePath, $targetDir, $dryRun, $overwrite);
                    $messageType = ($resultCounters['errors'] === 0 && $resultCounters['invalid'] === 0) ? 'success' : 'warning';
                    $message = 'Restore completed.';
                } catch (RuntimeException $e) {
                    $message = $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Restore from JSONL</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h1 class="mb-3">Project Restore from JSONL</h1>
    <p class="text-muted">Reconstruct files from a JSONL snapshot produced by scan.php. Files will be recreated under the selected target directory inside DOCUMENT_ROOT.</p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?=$messageType?>">
            <?=htmlspecialchars($message, ENT_QUOTES, 'UTF-8')?>
            <?php if ($resultCounters): ?>
                <div class="mt-2 small">
                    <div>Total lines: <?=$resultCounters['totalLines']?></div>
                    <div>Valid records: <?=$resultCounters['validRecords']?></div>
                    <div>Created: <?=$resultCounters['created']?></div>
                    <div>Overwritten: <?=$resultCounters['overwritten']?></div>
                    <div>Skipped: <?=$resultCounters['skipped']?></div>
                    <div>Invalid: <?=$resultCounters['invalid']?></div>
                    <div>Errors: <?=$resultCounters['errors']?></div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="card card-body">
        <div class="mb-3">
            <label class="form-label">Source JSONL (from scan_tmp)</label>
            <select name="source_file" class="form-select">
                <option value="">-- Select a file --</option>
                <?php foreach ($availableFiles as $f): ?>
                    <option value="<?=htmlspecialchars($f, ENT_QUOTES, 'UTF-8')?>" <?=($f === $selectedFile) ? 'selected' : ''?>><?=$f?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Or specify a relative path manually below.</div>
        </div>
        <div class="mb-3">
            <label class="form-label">Custom source path (relative or absolute)</label>
            <input type="text" name="source_file_custom" class="form-control" value="<?=htmlspecialchars($customFile, ENT_QUOTES, 'UTF-8')?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Target directory (under DOCUMENT_ROOT)</label>
            <input type="text" name="target_dir" class="form-control" value="<?=htmlspecialchars($targetDirInput, ENT_QUOTES, 'UTF-8')?>" required>
            <div class="form-text">Example: restored_project</div>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="dry_run" id="dryRun" <?= $dryRun ? 'checked' : '' ?>>
            <label class="form-check-label" for="dryRun">Dry run (simulate only)</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="overwrite" id="overwrite" <?= $overwrite ? 'checked' : '' ?>>
            <label class="form-check-label" for="overwrite">Overwrite existing files</label>
        </div>
        <button type="submit" class="btn btn-primary">Start restore</button>
    </form>
</div>
</body>
</html>
