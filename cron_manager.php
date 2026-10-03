<?php
session_start();

// Only admins can access this page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// --- 0. HANDLE GET ACTIONS (EXPORT) ---
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    exec('crontab -l 2>&1', $crons, $status);
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="crontab_backup_' . date('Y-m-d_H-i-s') . '.txt"');
    if ($status === 0 && strpos(implode("", $crons), 'no crontab') === false) {
        echo implode("\n", $crons) . "\n";
    } else {
        echo "# No active cron jobs found on the server at time of export.\n";
    }
    exit;
}

$message = '';
$rootDir = realpath(__DIR__);

// --- 1. INITIALIZE JSON STORAGE FOR COMMENTS ---
$jsonFile = __DIR__ . '/cron_comments.json';
if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, json_encode([]));
}
$commentsData = json_decode(file_get_contents($jsonFile), true);
if (!is_array($commentsData)) {
    $commentsData = [];
}

// --- 2. HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- ACTION: IMPORT CRONTAB ---
    if ($action === 'import' && isset($_FILES['import_file'])) {
        if ($_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $uploadedPath = $_FILES['import_file']['tmp_name'];
            $fileContent  = file_get_contents($uploadedPath);
            $fileContent  = str_replace("\r\n", "\n", $fileContent);
            $fileContent  = str_replace("\r", "\n", $fileContent);
            $fileContent  = trim($fileContent) . "\n";
            $tmpFile = tempnam(sys_get_temp_dir(), 'cron_import');
            file_put_contents($tmpFile, $fileContent);
            exec("crontab " . escapeshellarg($tmpFile) . " 2>&1", $out, $ret);
            unlink($tmpFile);
            $message = $ret === 0
                ? "<div class='alert alert-success'>Crontab successfully replaced from imported file!</div>"
                : "<div class='alert alert-danger'>Error importing crontab: " . htmlspecialchars(implode("\n", $out)) . "</div>";
        } else {
            $message = "<div class='alert alert-danger'>Error uploading the file.</div>";
        }
    }
    // --- ACTION: SAVE COMMENT ---
    elseif ($action === 'save_comment' && isset($_POST['cron_key'], $_POST['cron_comment'])) {
        $cronKey = $_POST['cron_key'];
        $commentsData[$cronKey] = trim($_POST['cron_comment']);
        $message = file_put_contents($jsonFile, json_encode($commentsData, JSON_PRETTY_PRINT))
            ? "<div class='alert alert-success'>Note saved successfully!</div>"
            : "<div class='alert alert-danger'>Error: Could not write to cron_comments.json. Check file permissions.</div>";
    }
    // --- ACTION: EDIT CRON LINE ---
    elseif ($action === 'edit' && isset($_POST['line_index'], $_POST['new_cron_line'])) {
        exec('crontab -l 2>&1', $currentCrons, $status);
        if ($status !== 0 && strpos(implode("", $currentCrons), 'no crontab') !== false) {
            $currentCrons = [];
            $status = 0;
        }
        if ($status === 0) {
            $lineIndex  = (int)$_POST['line_index'];
            $newLine    = trim($_POST['new_cron_line']);
            $parts      = preg_split('/\s+/', $newLine);
            // Validate: must have at least 6 parts (5 schedule + command)
            if (count($parts) < 6) {
                $message = "<div class='alert alert-danger'>Invalid cron expression. Must include min, hour, day, month, weekday + command.</div>";
            } elseif (isset($currentCrons[$lineIndex])) {
                $oldKey = preg_replace('/^#\s*/', '', trim($currentCrons[$lineIndex]));
                $currentCrons[$lineIndex] = $newLine;
                // Move comment to new key if it changed
                if (isset($commentsData[$oldKey])) {
                    $commentsData[$newLine] = $commentsData[$oldKey];
                    unset($commentsData[$oldKey]);
                    file_put_contents($jsonFile, json_encode($commentsData, JSON_PRETTY_PRINT));
                }
                $newCronContent = implode("\n", $currentCrons) . "\n";
                $tmpFile = tempnam(sys_get_temp_dir(), 'cron_edit');
                file_put_contents($tmpFile, $newCronContent);
                exec("crontab " . escapeshellarg($tmpFile) . " 2>&1", $out, $ret);
                unlink($tmpFile);
                $message = $ret === 0
                    ? "<div class='alert alert-success'>Cron job updated successfully!</div>"
                    : "<div class='alert alert-danger'>Error saving crontab: " . htmlspecialchars(implode("\n", $out)) . "</div>";
            }
        }
    }
    // --- ACTIONS: CRON MANAGEMENT (Toggle, Delete, Add, Bulk) ---
    else {
        exec('crontab -l 2>&1', $currentCrons, $status);
        if ($status !== 0 && strpos(implode("", $currentCrons), 'no crontab') !== false) {
            $currentCrons = [];
            $status = 0;
        }
        if ($status === 0) {
            $requiresSave = false;

            if ($action === 'toggle' && isset($_POST['line_index'])) {
                $lineIndex = (int)$_POST['line_index'];
                if (isset($currentCrons[$lineIndex])) {
                    $line = $currentCrons[$lineIndex];
                    $currentCrons[$lineIndex] = (strpos(trim($line), '#') === 0)
                        ? preg_replace('/^#\s*/', '', $line)
                        : '# ' . $line;
                    $requiresSave = true;
                }
            } elseif ($action === 'delete' && isset($_POST['line_index'])) {
                unset($currentCrons[(int)$_POST['line_index']]);
                $requiresSave = true;
            } elseif ($action === 'add') {
                $min     = trim($_POST['cron_min'])     ?: '*';
                $hour    = trim($_POST['cron_hour'])    ?: '*';
                $day     = trim($_POST['cron_day'])     ?: '*';
                $month   = trim($_POST['cron_month'])   ?: '*';
                $weekday = trim($_POST['cron_weekday']) ?: '*';
                $file    = trim($_POST['cron_file']);
                $currentCrons[] = "$min $hour $day $month $weekday /usr/bin/php -q $file >/dev/null 2>&1";
                $requiresSave = true;
            } elseif (in_array($action, ['bulk_disable', 'bulk_enable', 'bulk_delete']) && !empty($_POST['bulk_indices'])) {
                foreach (explode(',', $_POST['bulk_indices']) as $i) {
                    $i = (int)$i;
                    if (!isset($currentCrons[$i])) continue;
                    if ($action === 'bulk_delete') {
                        unset($currentCrons[$i]);
                    } elseif ($action === 'bulk_disable' && strpos(trim($currentCrons[$i]), '#') !== 0) {
                        $currentCrons[$i] = '# ' . $currentCrons[$i];
                    } elseif ($action === 'bulk_enable' && strpos(trim($currentCrons[$i]), '#') === 0) {
                        $currentCrons[$i] = preg_replace('/^#\s*/', '', $currentCrons[$i]);
                    }
                }
                $requiresSave = true;
            } elseif ($action === 'disable_all') {
                foreach ($currentCrons as $i => $line) {
                    $lineTrim = trim($line);
                    if (empty($lineTrim) || strpos($lineTrim, 'SHELL=') === 0 || strpos($lineTrim, 'MAILTO=') === 0) continue;
                    if (strpos($lineTrim, '#') !== 0) $currentCrons[$i] = '# ' . $line;
                }
                $requiresSave = true;
            }

            if ($requiresSave) {
                $newCronContent = implode("\n", $currentCrons) . "\n";
                $tmpFile = tempnam(sys_get_temp_dir(), 'cron_update');
                file_put_contents($tmpFile, $newCronContent);
                exec("crontab " . escapeshellarg($tmpFile) . " 2>&1", $out, $ret);
                unlink($tmpFile);
                $message = $ret === 0
                    ? "<div class='alert alert-success'>Crontab updated successfully!</div>"
                    : "<div class='alert alert-danger'>Error saving crontab: " . htmlspecialchars(implode("\n", $out)) . "</div>";
            }
        }
    }
}

// --- 3. FETCH CURRENT CRONS FOR DISPLAY ---
exec('crontab -l 2>&1', $crons, $status);

// --- 4. GET ALL PHP FILES FOLDER-WISE ---
$phpFilesByFolder = [];
try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($iterator as $path => $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $dir         = dirname($path);
            $relativeDir = str_replace($rootDir, '', $dir);
            $relativeDir = str_replace('\\', '/', $relativeDir);
            if (empty($relativeDir)) $relativeDir = '/ (Root Directory)';
            $phpFilesByFolder[$relativeDir][] = ['name' => $file->getFilename(), 'full_path' => $path];
        }
    }
    ksort($phpFilesByFolder);
} catch (Exception $e) {
    $message .= "<div class='alert alert-danger'>Could not scan directory for files.</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cron Job Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/cronstrue@latest/dist/cronstrue.min.js"></script>
    <style>
        :root {
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --primary: #3b82f6;
            --active-green: #10b981;
            --active-green-bg: #dcfce7;
            --active-green-text: #166534;
            --pause-yellow: #f59e0b;
            --delete-red: #ef4444;
            --disabled-red-bg: #fee2e2;
            --disabled-red-text: #991b1b;
            --sys-gray: #94a3b8;
            --sys-gray-bg: #f1f5f9;
            --sys-gray-text: #475569;
            --edit-blue: #6366f1;
        }

        body { font-family: 'Inter', sans-serif; margin: 0; padding: 40px 20px; background: var(--bg-color); color: var(--text-main); }
        .container { max-width: 1200px; margin: auto; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { margin: 0; font-weight: 700; color: #0f172a; font-size: 1.8rem; }

        .header-actions { display: flex; flex-wrap: wrap; gap: 15px; align-items: center; background: var(--card-bg); padding: 12px 18px; border-radius: 8px; border: 1px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .import-form { display: flex; align-items: center; gap: 10px; border-left: 1px solid var(--border-color); padding-left: 15px; }
        .import-form input[type="file"] { font-size: 12px; max-width: 180px; }

        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; font-size: 14px; }
        .alert-success { background-color: var(--active-green-bg); color: var(--active-green-text); border: 1px solid #bbf7d0; }
        .alert-danger  { background-color: var(--disabled-red-bg); color: var(--disabled-red-text); border: 1px solid #fecaca; }

        .search-bar-container { margin-bottom: 20px; }
        .search-bar-container input {
            width: 100%; padding: 14px 20px; border-radius: 8px; border: 1px solid var(--border-color);
            font-size: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); box-sizing: border-box;
            transition: border-color 0.2s; font-family: 'Inter', sans-serif;
        }
        .search-bar-container input:focus { outline: none; border-color: var(--primary); }

        .add-form { background: var(--card-bg); padding: 25px; border-radius: 12px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border-color); }
        .add-form h3 { margin-top: 0; font-size: 1.1rem; color: #334155; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        .form-row { display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .form-group input[type="text"] { width: 90px; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-family: monospace; text-align: center; font-size: 14px; transition: border-color 0.2s; }
        .form-group input[type="text"]:focus { outline: none; border-color: var(--primary); }
        .form-group select { padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; min-width: 280px; font-size: 14px; color: var(--text-main); background: #fff; cursor: pointer; }
        .form-group select:focus { outline: none; border-color: var(--primary); }

        .btn-add { background-color: var(--primary); color: white; padding: 10px 24px; border: none; border-radius: 6px; font-weight: 600; font-size: 14px; cursor: pointer; transition: background 0.2s; height: 40px; }
        .btn-add:hover:not(:disabled) { background-color: #2563eb; }
        .btn-add:disabled { opacity: 0.5; cursor: not-allowed; }

        .bulk-toolbar {
            background: var(--card-bg); padding: 15px 20px; border-radius: 8px; margin-bottom: 16px;
            border: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02); flex-wrap: wrap;
        }
        .bulk-toolbar .cb-group { display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; font-weight: 600; font-size: 14px; }
        .bulk-toolbar input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; }
        .bulk-actions-form { display: flex; gap: 10px; margin: 0; align-items: center; margin-left: auto; flex-wrap: wrap; }

        .cron-list { display: flex; flex-direction: column; gap: 16px; }

        /* Card wrapper holds the main card + the edit panel below it */
        .cron-card-wrapper { display: flex; flex-direction: column; }

        .cron-card {
            background: var(--card-bg); border-radius: 8px; padding: 20px; display: flex;
            align-items: center; gap: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            border: 1px solid var(--border-color); transition: box-shadow 0.2s;
        }
        .cron-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }

        .card-cb-wrapper { flex-shrink: 0; padding-left: 5px; }
        .card-cb-wrapper input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; }
        .card-cb-placeholder { width: 23px; flex-shrink: 0; }

        .card-active  { border-left: 6px solid var(--active-green); }
        .card-disabled { border-left: 6px solid var(--delete-red); background-color: #fafafa; }
        .card-config  { border-left: 6px solid var(--sys-gray); background-color: #f8fafc; }

        .card-status  { width: 100px; flex-shrink: 0; }
        .card-content { flex-grow: 1; display: flex; flex-direction: column; gap: 8px; overflow: hidden; }
        .card-actions { display: flex; gap: 10px; flex-shrink: 0; flex-wrap: wrap; }

        .status-badge { padding: 6px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; display: inline-block; text-align: center; letter-spacing: 0.5px; }
        .status-active   { background-color: var(--active-green-bg); color: var(--active-green-text); }
        .status-disabled { background-color: var(--disabled-red-bg); color: var(--disabled-red-text); }
        .status-config   { background-color: var(--sys-gray-bg); color: var(--sys-gray-text); }

        .cron-readable { display: none; background-color: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; align-self: flex-start; }
        .cron-command  { font-family: 'Courier New', Courier, monospace; font-size: 14px; color: #334155; word-break: break-all; line-height: 1.4; }
        .card-disabled .cron-command { color: #94a3b8; }

        .note-section { width: 100%; max-width: 800px; margin-top: 4px; }
        .display-note {
            font-size: 13px; color: var(--text-muted); cursor: pointer; padding: 10px 14px;
            background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px;
            transition: all 0.2s ease; min-height: 20px;
        }
        .display-note:hover { background: #f1f5f9; border-color: #94a3b8; color: #334155; }
        .empty-note { color: #94a3b8; }
        .edit-form { display: flex; gap: 10px; align-items: center; width: 100%; }
        .edit-input {
            flex-grow: 1; padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 6px;
            font-size: 13px; font-family: 'Inter', sans-serif; color: var(--text-main);
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
        }
        .edit-input:focus { outline: none; border-color: #94a3b8; }

        /* ── EDIT CRON PANEL ── */
        .edit-cron-panel {
            background: #f0f4ff;
            border: 1px solid #c7d2fe;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 20px 24px;
            display: none;
            flex-direction: column;
            gap: 16px;
        }
        .edit-cron-panel.open { display: flex; }

        .edit-cron-panel h4 {
            margin: 0 0 4px 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--edit-blue);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Raw textarea row */
        .edit-raw-row { display: flex; gap: 10px; align-items: center; }
        .edit-raw-row textarea {
            flex-grow: 1; font-family: 'Courier New', monospace; font-size: 13px;
            padding: 10px 14px; border: 1px solid #a5b4fc; border-radius: 6px;
            background: #fff; color: var(--text-main); resize: vertical; min-height: 42px; height: 42px;
            line-height: 1.4; box-sizing: border-box;
        }
        .edit-raw-row textarea:focus { outline: none; border-color: var(--edit-blue); }

        /* Assisted builder inside panel */
        .edit-builder { display: flex; flex-direction: column; gap: 12px; }
        .edit-builder .form-row { margin-bottom: 0; }
        .edit-builder .form-group input[type="text"] { width: 90px; }
        .edit-builder .form-group select { min-width: 220px; }
        .edit-builder .form-group select.file-select { min-width: 260px; }

        /* Preview badge inside panel */
        .edit-preview-badge {
            background: #e0f2fe; color: #0369a1; padding: 5px 12px; border-radius: 4px;
            font-size: 12px; font-weight: 600; align-self: flex-start; display: none;
        }

        .edit-panel-actions { display: flex; gap: 10px; align-items: center; }

        /* Buttons */
        .btn { padding: 9px 18px; border: none; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; transition: filter 0.2s; color: #fff; font-family: 'Inter', sans-serif; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 5px; }
        .btn:hover { filter: brightness(0.9); }

        .btn-pause  { background-color: var(--pause-yellow); color: #854d0e; }
        .btn-enable { background-color: var(--active-green); }
        .btn-delete { background-color: var(--delete-red); }
        .btn-dark   { background-color: #334155; }
        .btn-edit   { background-color: var(--edit-blue); }
        .btn-save   { background-color: #64748b; }
        .btn-cancel { background-color: #e2e8f0; color: #475569; }
        .btn-cancel:hover { background-color: #cbd5e1; }
        .btn-save-cron { background-color: var(--edit-blue); }
    </style>
</head>
<body>
<div class="container">

    <!-- HEADER -->
    <div class="page-header">
        <h2>Server Cron Job Manager</h2>
        <div class="header-actions">
            <a href="?action=export" class="btn btn-dark">⬇️ Export Backup</a>
            <form method="POST" enctype="multipart/form-data" class="import-form"
                  onsubmit="return confirm('WARNING: This will completely REPLACE your existing crontab. Are you absolutely sure?');">
                <input type="hidden" name="action" value="import">
                <input type="file" name="import_file" accept=".txt,.cron" required>
                <button type="submit" class="btn btn-save">⬆️ Import & Replace</button>
            </form>
        </div>
    </div>

    <?= $message ?>

    <!-- ADD NEW CRON FORM -->
    <div class="add-form">
        <h3>➕ Add New Cron Job</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group" style="flex-grow:1;">
                    <label>1. Select Folder</label>
                    <select id="folder_select" onchange="updateFileDropdown('folder_select','cron_file','add_btn')">
                        <option value="">-- Choose a Folder --</option>
                        <?php foreach (array_keys($phpFilesByFolder) as $folder): ?>
                            <option value="<?= htmlspecialchars($folder) ?>"><?= htmlspecialchars($folder) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex-grow:1;">
                    <label>2. Select PHP File</label>
                    <select name="cron_file" id="cron_file" required disabled>
                        <option value="">-- Select Folder First --</option>
                    </select>
                </div>
                <div class="form-group" style="flex-grow:1;">
                    <label>3. Select Schedule Preset</label>
                    <select id="cron_preset" onchange="applyPreset('cron_preset','cron_min','cron_hour','cron_day','cron_month','cron_weekday')">
                        <option value="">-- Custom Schedule --</option>
                        <option value="* * * * *">Every Minute</option>
                        <option value="*/5 * * * *">Every 5 Minutes</option>
                        <option value="0 * * * *">Once an Hour</option>
                        <option value="0 0 * * *">Every Day at Midnight</option>
                        <option value="0 0 * * 1-5">Every Weekday</option>
                        <option value="0 0 * * 0">Once a Week on Sunday</option>
                        <option value="0 0 1 * *">Once a Month</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Min</label><input type="text" name="cron_min" id="cron_min" value="*" maxlength="30" required></div>
                <div class="form-group"><label>Hour</label><input type="text" name="cron_hour" id="cron_hour" value="*" maxlength="30" required></div>
                <div class="form-group"><label>Day</label><input type="text" name="cron_day" id="cron_day" value="*" maxlength="30" required></div>
                <div class="form-group"><label>Month</label><input type="text" name="cron_month" id="cron_month" value="*" maxlength="30" required></div>
                <div class="form-group"><label>Weekday</label><input type="text" name="cron_weekday" id="cron_weekday" value="*" maxlength="30" required></div>
                <div class="form-group"><button type="submit" class="btn-add" id="add_btn" disabled>Add Job</button></div>
            </div>
        </form>
    </div>

    <!-- CURRENT CRONS LIST -->
    <?php if ($status !== 0 && empty($crons)): ?>
        <div class="alert alert-danger">Failed to load crontab. Ensure your server permits the 'exec' function.</div>
    <?php elseif (empty($crons)): ?>
        <div class="alert alert-success">No cron jobs found. Use the form above to add one, or import a backup.</div>
    <?php else: ?>

        <div class="search-bar-container">
            <input type="text" id="searchInput" placeholder="🔍 Search by file path, folder, or custom note...">
        </div>

        <div class="bulk-toolbar">
            <label class="cb-group" for="selectAllCb">
                <input type="checkbox" id="selectAllCb"> Select All
            </label>
            <form id="bulkForm" method="POST" class="bulk-actions-form">
                <input type="hidden" name="action" id="bulkActionInput" value="">
                <input type="hidden" name="bulk_indices" id="bulkIndicesInput" value="">
                <button type="button" class="btn btn-enable" onclick="submitBulk('bulk_enable')">Enable Selected</button>
                <button type="button" class="btn btn-pause"  onclick="submitBulk('bulk_disable')">Disable Selected</button>
                <button type="button" class="btn btn-delete" onclick="submitBulk('bulk_delete')">Delete Selected</button>
                <div style="width:1px;height:24px;background:#cbd5e1;margin:0 8px;"></div>
                <button type="button" class="btn btn-dark" onclick="submitBulk('disable_all')">⚠️ Disable All</button>
            </form>
        </div>

        <div class="cron-list" id="cronListContainer">
            <?php foreach ($crons as $index => $line): ?>
                <?php
                    $line = trim($line);
                    if (empty($line)) continue;

                    $isDisabled = (strpos($line, '#') === 0);
                    $isConfig   = (strpos($line, 'SHELL=') === 0 || strpos($line, 'MAILTO=') === 0);

                    $cronKey      = preg_replace('/^#\s*/', '', $line);
                    $savedComment = $commentsData[$cronKey] ?? '';

                    if ($isConfig) {
                        $cardClass = 'card-config'; $badgeClass = 'status-config'; $badgeText = 'System';
                    } elseif ($isDisabled) {
                        $cardClass = 'card-disabled'; $badgeClass = 'status-disabled'; $badgeText = 'Paused';
                    } else {
                        $cardClass = 'card-active'; $badgeClass = 'status-active'; $badgeText = 'Active';
                    }
                ?>

                <!-- Wrapper: card + inline edit panel sit together -->
                <div class="cron-card-wrapper" id="wrapper_<?= $index ?>">

                    <div class="cron-card <?= $cardClass ?>">

                        <!-- Checkbox -->
                        <?php if (!$isConfig): ?>
                            <div class="card-cb-wrapper">
                                <input type="checkbox" class="row-checkbox" value="<?= $index ?>">
                            </div>
                        <?php else: ?>
                            <div class="card-cb-placeholder"></div>
                        <?php endif; ?>

                        <!-- Status -->
                        <div class="card-status">
                            <span class="status-badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                        </div>

                        <!-- Content -->
                        <div class="card-content">
                            <div class="cron-readable"></div>
                            <div class="cron-command"><?= htmlspecialchars($line) ?></div>

                            <?php if (!$isConfig): ?>
                                <div class="note-section">
                                    <div id="display_note_<?= $index ?>" class="display-note" onclick="enableEdit(<?= $index ?>)">
                                        <?php if (!empty($savedComment)): ?>
                                            <span class="note-text-val"><?= htmlspecialchars($savedComment) ?></span>
                                        <?php else: ?>
                                            <span class="empty-note">Add a description for this cron job...</span>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" class="edit-form" id="edit_note_<?= $index ?>" style="display:none;">
                                        <input type="hidden" name="action" value="save_comment">
                                        <input type="hidden" name="cron_key" value="<?= htmlspecialchars($cronKey) ?>">
                                        <input type="text" name="cron_comment" class="edit-input"
                                               value="<?= htmlspecialchars($savedComment) ?>"
                                               placeholder="Type a description...">
                                        <button type="submit" class="btn btn-save">💾 Save Note</button>
                                        <button type="button" class="btn btn-cancel" onclick="cancelEdit(<?= $index ?>)">✖</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Actions -->
                        <?php if (!$isConfig): ?>
                            <div class="card-actions">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="line_index" value="<?= $index ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <?php if ($isDisabled): ?>
                                        <button type="submit" class="btn btn-enable">Enable</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-pause">Pause</button>
                                    <?php endif; ?>
                                </form>

                                <!-- ✏️ EDIT BUTTON -->
                                <button type="button" class="btn btn-edit"
                                        onclick="toggleEditPanel(<?= $index ?>, <?= htmlspecialchars(json_encode($cronKey), ENT_QUOTES) ?>)">
                                    ✏️ Edit
                                </button>

                                <form method="POST" style="margin:0;"
                                      onsubmit="return confirm('Delete this cron job?');">
                                    <input type="hidden" name="line_index" value="<?= $index ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-delete">Delete</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ✏️ INLINE EDIT PANEL (hidden by default) -->
                    <?php if (!$isConfig): ?>
                    <div class="edit-cron-panel" id="edit_panel_<?= $index ?>">
                        <h4>✏️ Edit Cron Job</h4>

                        <form method="POST" id="edit_cron_form_<?= $index ?>">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="line_index" value="<?= $index ?>">
                            <!-- Hidden field that actually gets submitted -->
                            <input type="hidden" name="new_cron_line" id="hidden_cron_line_<?= $index ?>"
                                   value="<?= htmlspecialchars($cronKey) ?>">

                            <!-- 1. Raw cron expression -->
                            <div>
                                <h4 style="font-size:12px;color:#475569;text-transform:uppercase;margin:0 0 6px 0;">
                                    Raw Cron Expression
                                </h4>
                                <div class="edit-raw-row">
                                    <textarea id="raw_edit_<?= $index ?>"
                                              oninput="syncFromRaw(<?= $index ?>)"
                                              ><?= htmlspecialchars($cronKey) ?></textarea>
                                </div>
                                <div class="edit-preview-badge" id="preview_badge_<?= $index ?>"></div>
                            </div>

                            <!-- 2. Assisted builder -->
                            <div class="edit-builder">
                                <h4 style="font-size:12px;color:#475569;text-transform:uppercase;margin:0 0 6px 0;">
                                    Schedule Builder (auto-fills raw expression above)
                                </h4>
                                <div class="form-row">
                                    <!-- Schedule Preset -->
                                    <div class="form-group">
                                        <label>Preset</label>
                                        <select id="edit_preset_<?= $index ?>"
                                                onchange="applyPreset('edit_preset_<?= $index ?>','edit_min_<?= $index ?>','edit_hour_<?= $index ?>','edit_day_<?= $index ?>','edit_month_<?= $index ?>','edit_weekday_<?= $index ?>')">
                                            <option value="">-- Custom --</option>
                                            <option value="* * * * *">Every Minute</option>
                                            <option value="*/5 * * * *">Every 5 Minutes</option>
                                            <option value="0 * * * *">Once an Hour</option>
                                            <option value="0 0 * * *">Every Day at Midnight</option>
                                            <option value="0 0 * * 1-5">Every Weekday</option>
                                            <option value="0 0 * * 0">Once a Week on Sunday</option>
                                            <option value="0 0 1 * *">Once a Month</option>
                                        </select>
                                    </div>
                                    <!-- Time fields -->
                                    <div class="form-group"><label>Min</label>
                                        <input type="text" id="edit_min_<?= $index ?>" maxlength="30"
                                               oninput="syncFromBuilder(<?= $index ?>)"></div>
                                    <div class="form-group"><label>Hour</label>
                                        <input type="text" id="edit_hour_<?= $index ?>" maxlength="30"
                                               oninput="syncFromBuilder(<?= $index ?>)"></div>
                                    <div class="form-group"><label>Day</label>
                                        <input type="text" id="edit_day_<?= $index ?>" maxlength="30"
                                               oninput="syncFromBuilder(<?= $index ?>)"></div>
                                    <div class="form-group"><label>Month</label>
                                        <input type="text" id="edit_month_<?= $index ?>" maxlength="30"
                                               oninput="syncFromBuilder(<?= $index ?>)"></div>
                                    <div class="form-group"><label>Weekday</label>
                                        <input type="text" id="edit_weekday_<?= $index ?>" maxlength="30"
                                               oninput="syncFromBuilder(<?= $index ?>)"></div>
                                </div>

                                <!-- File picker -->
                                <div class="form-row">
                                    <div class="form-group" style="flex-grow:1;">
                                        <label>Folder</label>
                                        <select id="edit_folder_<?= $index ?>"
                                                onchange="updateFileDropdown('edit_folder_<?= $index ?>','edit_file_<?= $index ?>',null,<?= $index ?>)">
                                            <option value="">-- Choose Folder --</option>
                                            <?php foreach (array_keys($phpFilesByFolder) as $folder): ?>
                                                <option value="<?= htmlspecialchars($folder) ?>"><?= htmlspecialchars($folder) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex-grow:1;">
                                        <label>PHP File</label>
                                        <select id="edit_file_<?= $index ?>" class="file-select" disabled
                                                onchange="injectFile(<?= $index ?>)">
                                            <option value="">-- Select Folder First --</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Save / Cancel -->
                            <div class="edit-panel-actions">
                                <button type="submit" class="btn btn-save-cron">💾 Save Changes</button>
                                <button type="button" class="btn btn-cancel"
                                        onclick="toggleEditPanel(<?= $index ?>)">✖ Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                </div><!-- /.cron-card-wrapper -->
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// ── Folder data passed from PHP ──
const folderData = <?= json_encode($phpFilesByFolder, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// ── Cascading Dropdowns (shared by Add form and Edit panels) ──
function updateFileDropdown(folderSelectId, fileSelectId, addBtnId = null, editIndex = null) {
    const folderSelect  = document.getElementById(folderSelectId);
    const fileSelect    = document.getElementById(fileSelectId);
    const selectedFolder = folderSelect.value;

    fileSelect.innerHTML = '<option value="">-- Select a PHP File --</option>';

    if (selectedFolder && folderData[selectedFolder]) {
        folderData[selectedFolder].forEach(file => {
            const option = document.createElement('option');
            option.value = file.full_path;
            option.textContent = file.name;
            fileSelect.appendChild(option);
        });
        fileSelect.disabled = false;
    } else {
        fileSelect.disabled = true;
    }

    if (addBtnId) {
        document.getElementById(addBtnId).disabled = !selectedFolder;
    }
}

// ── Apply Schedule Preset (shared) ──
function applyPreset(presetId, minId, hourId, dayId, monthId, weekdayId) {
    const presetVal = document.getElementById(presetId).value;
    if (!presetVal) return;
    const parts = presetVal.split(' ');
    if (parts.length === 5) {
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        set(minId, parts[0]); set(hourId, parts[1]); set(dayId, parts[2]);
        set(monthId, parts[3]); set(weekdayId, parts[4]);
        // If called from an edit panel, also sync the raw textarea
        const match = presetId.match(/edit_preset_(\d+)$/);
        if (match) syncFromBuilder(parseInt(match[1]));
    }
}

// ─────────────────────────────────────────────
// EDIT PANEL LOGIC
// ─────────────────────────────────────────────
function toggleEditPanel(index, currentLine) {
    const panel = document.getElementById('edit_panel_' + index);
    const isOpen = panel.classList.contains('open');

    // Close all open panels first
    document.querySelectorAll('.edit-cron-panel.open').forEach(p => p.classList.remove('open'));

    if (!isOpen) {
        panel.classList.add('open');
        if (currentLine !== undefined) {
            initEditPanel(index, currentLine);
        }
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function initEditPanel(index, rawCronLine) {
    // Pre-fill raw textarea
    const rawEl = document.getElementById('raw_edit_' + index);
    rawEl.value = rawCronLine;
    document.getElementById('hidden_cron_line_' + index).value = rawCronLine;

    // Parse schedule fields from the raw line
    const parts = rawCronLine.trim().split(/\s+/);
    if (parts.length >= 5) {
        const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        setVal('edit_min_'     + index, parts[0]);
        setVal('edit_hour_'    + index, parts[1]);
        setVal('edit_day_'     + index, parts[2]);
        setVal('edit_month_'   + index, parts[3]);
        setVal('edit_weekday_' + index, parts[4]);
    }
    updatePreviewBadge(index, rawCronLine);
}

// Sync hidden field + preview when the raw textarea changes
function syncFromRaw(index) {
    const rawVal = document.getElementById('raw_edit_' + index).value.trim();
    document.getElementById('hidden_cron_line_' + index).value = rawVal;

    // Also update the individual schedule fields
    const parts = rawVal.split(/\s+/);
    if (parts.length >= 5) {
        const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        setVal('edit_min_'     + index, parts[0]);
        setVal('edit_hour_'    + index, parts[1]);
        setVal('edit_day_'     + index, parts[2]);
        setVal('edit_month_'   + index, parts[3]);
        setVal('edit_weekday_' + index, parts[4]);
    }
    updatePreviewBadge(index, rawVal);
}

// Sync raw textarea + hidden field when builder fields change
function syncFromBuilder(index) {
    const g = id => { const el = document.getElementById(id); return el ? el.value.trim() || '*' : '*'; };
    const schedPart = `${g('edit_min_'+index)} ${g('edit_hour_'+index)} ${g('edit_day_'+index)} ${g('edit_month_'+index)} ${g('edit_weekday_'+index)}`;

    // Keep the command part from the current raw value (everything after the 5 schedule fields)
    const existingRaw = document.getElementById('raw_edit_' + index).value.trim();
    const existingParts = existingRaw.split(/\s+/);
    const commandPart = existingParts.slice(5).join(' ');

    const newLine = commandPart ? `${schedPart} ${commandPart}` : schedPart;
    document.getElementById('raw_edit_' + index).value = newLine;
    document.getElementById('hidden_cron_line_' + index).value = newLine;
    updatePreviewBadge(index, newLine);
}

// Inject a selected file path into the raw textarea's command portion
function injectFile(index) {
    const filePath = document.getElementById('edit_file_' + index).value;
    if (!filePath) return;

    // Build new command: use existing schedule fields + new file
    const g = id => { const el = document.getElementById(id); return el ? el.value.trim() || '*' : '*'; };
    const schedPart = `${g('edit_min_'+index)} ${g('edit_hour_'+index)} ${g('edit_day_'+index)} ${g('edit_month_'+index)} ${g('edit_weekday_'+index)}`;
    const newLine = `${schedPart} /usr/bin/php -q ${filePath} >/dev/null 2>&1`;

    document.getElementById('raw_edit_' + index).value = newLine;
    document.getElementById('hidden_cron_line_' + index).value = newLine;
    updatePreviewBadge(index, newLine);
}

function updatePreviewBadge(index, line) {
    const badge = document.getElementById('preview_badge_' + index);
    if (!badge || typeof cronstrue === 'undefined') return;
    const match = line.match(/^((?:[\w\*\/\-\,]+\s+){4}[\w\*\/\-\,]+)/);
    if (match && match[1]) {
        try {
            badge.textContent = '⏱️ ' + cronstrue.toString(match[1].trim());
            badge.style.display = 'inline-block';
        } catch(e) {
            badge.style.display = 'none';
        }
    } else {
        badge.style.display = 'none';
    }
}

// ── Note click-to-edit ──
function enableEdit(index) {
    document.getElementById('display_note_' + index).style.display = 'none';
    document.getElementById('edit_note_' + index).style.display = 'flex';
    document.querySelector('#edit_note_' + index + ' input[name="cron_comment"]').focus();
}
function cancelEdit(index) {
    document.getElementById('display_note_' + index).style.display = 'block';
    document.getElementById('edit_note_' + index).style.display = 'none';
}

// ── Bulk Actions ──
const selectAllCb   = document.getElementById('selectAllCb');
const rowCheckboxes = document.querySelectorAll('.row-checkbox');

if (selectAllCb) {
    selectAllCb.addEventListener('change', function(e) {
        rowCheckboxes.forEach(cb => {
            const parentWrapper = cb.closest('.cron-card-wrapper');
            if (parentWrapper && parentWrapper.style.display !== 'none') {
                cb.checked = e.target.checked;
            }
        });
    });
}

function submitBulk(actionType) {
    let selectedIndices = [];
    if (actionType !== 'disable_all') {
        document.querySelectorAll('.row-checkbox:checked').forEach(cb => selectedIndices.push(cb.value));
        if (selectedIndices.length === 0) { alert("Please select at least one cron job first."); return; }
        if (actionType === 'bulk_delete' && !confirm("Delete selected cron jobs?")) return;
    } else {
        if (!confirm("WARNING: Disable ALL active cron jobs?")) return;
    }
    document.getElementById('bulkActionInput').value = actionType;
    document.getElementById('bulkIndicesInput').value = selectedIndices.join(',');
    document.getElementById('bulkForm').submit();
}

// ── Live Search ──
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function(e) {
        const term = e.target.value.toLowerCase();
        document.querySelectorAll('.cron-card-wrapper').forEach(wrapper => {
            const commandText = (wrapper.querySelector('.cron-command') || {}).textContent?.toLowerCase() || '';
            const noteEl      = wrapper.querySelector('.note-text-val');
            const noteText    = noteEl ? noteEl.textContent.toLowerCase() : '';
            const show        = commandText.includes(term) || noteText.includes(term);
            wrapper.style.display = show ? '' : 'none';
            if (!show) {
                const cb = wrapper.querySelector('.row-checkbox');
                if (cb) cb.checked = false;
            }
        });
    });
}

// ── Cronstrue: Human Readable Parser ──
document.addEventListener('DOMContentLoaded', function() {
    if (typeof cronstrue === 'undefined') return;
    document.querySelectorAll('.cron-card').forEach(card => {
        if (card.classList.contains('card-config')) return;
        const commandEl    = card.querySelector('.cron-command');
        const readableBadge = card.querySelector('.cron-readable');
        if (commandEl && readableBadge) {
            let text  = commandEl.textContent.trim().replace(/^#\s*/, '');
            const match = text.match(/^((?:[\w\*\/\-\,]+\s+){4}[\w\*\/\-\,]+)/);
            if (match && match[1]) {
                try {
                    const serverTimeStr = cronstrue.toString(match[1].trim());
                    const cronParts = match[1].trim().split(/\s+/);
                    let hoursPart   = cronParts[1];
                    let istHours    = [];
                    if (!hoursPart.includes('*') && !hoursPart.includes('/')) {
                        hoursPart.split(',').forEach(h => {
                            let shifted = (parseInt(h) + 5) % 24;
                            let ampm    = shifted >= 12 ? 'PM' : 'AM';
                            let dispH   = shifted % 12 || 12;
                            istHours.push(`${String(dispH).padStart(2,'0')}:00 ${ampm}`);
                        });
                    }
                    readableBadge.innerHTML = istHours.length > 0
                        ? `<img src="/flags/india-flag-icon.png" alt="India" style="height:14px;vertical-align:middle;margin-right:4px;"> IST: At ${istHours.join(' and ')}`
                        : `⏱️ ${serverTimeStr} (Server Time)`;
                    readableBadge.style.display = 'inline-block';
                } catch(e) {}
            }
        }
    });
});
</script>
</body>
</html>