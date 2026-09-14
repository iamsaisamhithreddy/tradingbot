<?php
// =========================================================================
// BACKEND API: Process data from ESP8266 OR the Web UI (POST Requests)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);

    if (!$payload || !isset($payload['data'])) {
        http_response_code(400); // Tell the ESP it was a bad request
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON or missing "data" array.']);
        exit;
    }

    // Cutoff timestamp (matches evaluate.php logic)
    $cutoffEnd = strtotime('2026-03-01 00:00:00');
    
    $grouped_data = [];
    
    foreach ($payload['data'] as $row) {
        $instrument = str_replace('/', '', $row['instrument']);
        $ts = (int)$row['timestamp'];
        
$target_folder = __DIR__ . '/'; 
        
        if (!isset($grouped_data[$target_folder])) {
            $grouped_data[$target_folder] = [];
        }
        if (!isset($grouped_data[$target_folder][$instrument])) {
            $grouped_data[$target_folder][$instrument] = [];
        }
        $grouped_data[$target_folder][$instrument][] = $row;
    }

    $results = [];

    // --- CORE STRATEGY LOGIC ---
    function detectStreakPattern($candles, $index) {
        $timestamp = $candles[$index]['timestamp'];
        $dt = new DateTime("@$timestamp");
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        $time_hi = (int)$dt->format('Hi'); 
        
        if ($time_hi < 1230 || $time_hi > 2130) return 0;

        $getDir = function($c) {
            if ($c['close'] > $c['open']) return 1;
            if ($c['close'] < $c['open']) return -1;
            return 0; 
        };

        $current_dir = $getDir($candles[$index]);
        if ($current_dir == 0) return 0; 

        $pullback_dir = $getDir($candles[$index - 1]);
        if ($pullback_dir != -$current_dir || $pullback_dir == 0) return 0;

        $p_count = 1; 
        if ($getDir($candles[$index - 2]) == $pullback_dir) {
            $p_count = 2; 
        }

        if ($getDir($candles[$index - $p_count - 1]) == $pullback_dir) return 0;

        $c4_idx = $index - $p_count - 1; 
        $c3_idx = $index - $p_count - 2; 
        $c2_idx = $index - $p_count - 3; 
        $c1_idx = $index - $p_count - 4; 

        if ($c1_idx < 0) return 0; 

        for ($j = $c1_idx; $j <= $index; $j++) {
            if ($candles[$j]['open'] == $candles[$j]['close']) return 0;
        }

        if ($getDir($candles[$c4_idx]) != $current_dir) return 0;
        if ($getDir($candles[$c3_idx]) != $current_dir) return 0;
        if ($getDir($candles[$c2_idx]) != $current_dir) return 0;
        if ($getDir($candles[$c1_idx]) != $current_dir) return 0;

        $candle4_open = $candles[$c4_idx]['open'];

        for ($p = 1; $p <= $p_count; $p++) {
            $pb_candle = $candles[$index - $p];
            if ($current_dir == 1) { 
                if ($pb_candle['low'] < $candle4_open) return 0;
            } else { 
                if ($pb_candle['high'] > $candle4_open) return 0;
            }
        }

        return $current_dir; 
    }
    // ---------------------------

    foreach ($grouped_data as $folder => $instruments) {
        // Ensure target directory exists
        if (!file_exists($folder)) {
            mkdir($folder, 0755, true); 
        }

        foreach ($instruments as $instrument => $new_rows) {
            $filename = "FX_{$instrument}.csv";
            $filepath = $folder . '/' . $filename;

            $all_data = [];
            
            // 2. LOAD EXISTING DATA
            if (file_exists($filepath)) {
                if (($handle = fopen($filepath, "r")) !== FALSE) {
                    $header = fgetcsv($handle); 
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        if (count($data) >= 5 && is_numeric($data[0])) {
                            $ts = (int)$data[0];
                            $all_data[$ts] = [
                                'timestamp' => $ts,
                                'open'      => (float)$data[1],
                                'high'      => (float)$data[2],
                                'low'       => (float)$data[3],
                                'close'     => (float)$data[4],
                                'alert'     => isset($data[5]) ? (int)$data[5] : 0,
                                'volume'    => isset($data[6]) ? (int)$data[6] : 0
                            ];
                        }
                    }
                    fclose($handle);
                }
            }

            $updated_count = 0;
            $added_count = 0;

            // 3. MERGE & UPDATE BASED ON TIMESTAMP KEY
            foreach ($new_rows as $row) {
                $ts = (int)$row['timestamp'];
                
                if (isset($all_data[$ts])) {
                    $updated_count++; // Overwriting existing entry
                } else {
                    $added_count++; // Adding brand new entry
                }

                $all_data[$ts] = [
                    'timestamp' => $ts,
                    'open'      => (float)$row['open'],
                    'high'      => (float)$row['high'],
                    'low'       => (float)$row['low'],
                    'close'     => (float)$row['close'],
                    'alert'     => isset($all_data[$ts]['alert']) ? $all_data[$ts]['alert'] : 0,
                    'volume'    => isset($all_data[$ts]['volume']) ? $all_data[$ts]['volume'] : 0
                ];
            }

            // 4. SORT CHRONOLOGICALLY BY TIMESTAMP
            ksort($all_data);
            $merged = array_values($all_data);

            // 5. RECALCULATE STRATEGY ALERTS (Optimized for Server Load)
            $totalCandles = count($merged);
            // Only recalculate the last few candles (+ buffer) to save CPU
            $startIndex = max(7, $totalCandles - count($new_rows) - 20); 

            for ($i = $startIndex; $i < $totalCandles; $i++) {
                $merged[$i]['alert'] = detectStreakPattern($merged, $i);
            }

            // 6. SAVE UPDATED DATA TO CSV
            $fp = fopen($filepath, 'w');
            fputcsv($fp, ['time', 'open', 'high', 'low', 'close', 'Pattern Alert', 'Volume']); 
            
            foreach ($merged as $row) {
                fputcsv($fp, [
                    $row['timestamp'], $row['open'], $row['high'], 
                    $row['low'], $row['close'], $row['alert'], $row['volume']
                ]);
            }
            fclose($fp);

            $results[$instrument] = "+{$added_count} new | ↻{$updated_count} updated (in " . basename($folder) . ")";
        }
    }

    echo json_encode(['status' => 'success', 'details' => $results]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ESP8266 Live Data Receiver</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #eef2f5;
            padding: 2rem;
            max-width: 860px;
            margin: auto;
        }
        .header-box {
            background: #1e293b;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .header-box h2 { margin: 0; font-size: 1.5rem; }
        .header-box p { margin: 5px 0 0 0; color: #94a3b8; font-size: 0.9rem; }

        .container {
            background: white;
            padding: 25px;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        /* ── Tab Bar ── */
        .tabs {
            display: flex;
            gap: 4px;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 4px;
            margin-bottom: 22px;
        }
        .tab-btn {
            flex: 1;
            padding: 10px 0;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s;
        }
        .tab-btn.active {
            background: white;
            color: #1e293b;
            box-shadow: 0 1px 4px rgba(0,0,0,0.10);
        }
        .tab-btn:hover:not(.active) { color: #334155; }

        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* ── Shared label ── */
        .panel-label {
            margin: 0 0 12px 0;
            color: #475569;
            font-size: 14px;
        }

        /* ── Textarea (JSON paste) ── */
        textarea {
            width: 100%;
            height: 250px;
            padding: 15px;
            font-family: "Courier New", Courier, monospace;
            font-size: 13px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            resize: vertical;
            background: #f8fafc;
            color: #1e293b;
            transition: border-color 0.15s;
        }
        textarea:focus { outline: none; border-color: #3b82f6; }

        /* ── File Drop Zone ── */
        .drop-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.18s;
            background: #f8fafc;
            position: relative;
        }
        .drop-zone.drag-over {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .drop-zone .dz-icon { font-size: 2.4rem; margin-bottom: 10px; }
        .drop-zone .dz-title {
            font-size: 15px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 4px;
        }
        .drop-zone .dz-sub { font-size: 13px; color: #94a3b8; }
        .drop-zone .dz-sub span { color: #3b82f6; font-weight: 600; }

        /* File list */
        #fileList {
            margin-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .file-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f1f5f9;
            border-radius: 6px;
            padding: 9px 12px;
            font-size: 13px;
            color: #334155;
        }
        .file-chip .fc-icon { font-size: 1.1rem; }
        .file-chip .fc-name { flex: 1; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-chip .fc-size { color: #94a3b8; font-size: 12px; white-space: nowrap; }
        .file-chip .fc-remove {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 16px;
            padding: 0 2px;
            line-height: 1;
            transition: color 0.12s;
        }
        .file-chip .fc-remove:hover { color: #ef4444; }

        /* CSV config row */
        .csv-config {
            display: none;
            margin-top: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
        }
        .csv-config.visible { display: block; }
        .csv-config h4 { margin: 0 0 14px 0; font-size: 14px; color: #475569; font-weight: 600; }
        .config-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .config-item label { display: block; font-size: 12px; color: #64748b; margin-bottom: 5px; font-weight: 500; }
        .config-item select, .config-item input[type="number"] {
            width: 100%;
            padding: 8px 10px;
            border: 1.5px solid #e2e8f0;
            border-radius: 5px;
            font-size: 13px;
            background: white;
            color: #1e293b;
        }
        .config-item select:focus, .config-item input[type="number"]:focus {
            outline: none;
            border-color: #3b82f6;
        }
        .col-map-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 12px;
        }
        .col-map-grid .config-item label { color: #64748b; }

        /* ── Submit Button ── */
        button.submit-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 14px 24px;
            font-size: 15px;
            border-radius: 6px;
            cursor: pointer;
            margin-top: 18px;
            font-weight: 600;
            width: 100%;
            transition: all 0.2s;
        }
        button.submit-btn:hover { background: #2563eb; transform: translateY(-1px); }
        button.submit-btn:disabled { background: #94a3b8; cursor: not-allowed; transform: none; }

        /* ── Status Box ── */
        #status {
            margin-top: 20px;
            padding: 15px;
            border-radius: 6px;
            display: none;
            font-weight: 500;
            white-space: pre-wrap;
            font-size: 14px;
            line-height: 1.5;
        }
        .success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
    </style>
</head>
<body>

<div class="header-box">
    <h2>📊 Server Active</h2>
    <p>Endpoint ready to receive POST payloads from the NodeMCU ESP8266.</p>
</div>

<div class="container">

    <!-- Tab Bar -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('paste', this)">📋 Paste JSON</button>
        <button class="tab-btn" onclick="switchTab('upload-json', this)">📄 Upload JSON</button>
        <button class="tab-btn" onclick="switchTab('upload-csv', this)">📊 Upload CSV</button>
    </div>

    <!-- ── Tab 1: Paste JSON ── -->
    <div id="tab-paste" class="tab-panel active">
        <p class="panel-label">Manually paste a ForexFactory JSON payload:</p>
        <textarea id="jsonInput" placeholder='{"data": [{"timestamp": 1781072100, "instrument": "EUR/USD", "open": 1.0820, "high": 1.0835, "low": 1.0810, "close": 1.0828}]}'></textarea>
    </div>

    <!-- ── Tab 2: Upload JSON ── -->
    <div id="tab-upload-json" class="tab-panel">
        <p class="panel-label">Upload one or more <strong>.json</strong> files — each must contain a <code>{"data": [...]}</code> payload:</p>
        <div class="drop-zone" id="dropZoneJson"
             ondragover="dzDrag(event, 'dropZoneJson')"
             ondragleave="dzLeave('dropZoneJson')"
             ondrop="dzDrop(event, 'json')">
            <input type="file" id="jsonFileInput" accept=".json,application/json" multiple
                   onchange="handleFileSelect(this.files, 'json')">
            <div class="dz-icon">📂</div>
            <div class="dz-title">Drop JSON files here</div>
            <div class="dz-sub">or <span>click to browse</span></div>
        </div>
        <div id="fileList-json" class="file-list-container"></div>
    </div>

    <!-- ── Tab 3: Upload CSV ── -->
    <div id="tab-upload-csv" class="tab-panel">
        <p class="panel-label">Upload one or more <strong>.csv</strong> files. Map columns below before submitting:</p>
        <div class="drop-zone" id="dropZoneCsv"
             ondragover="dzDrag(event, 'dropZoneCsv')"
             ondragleave="dzLeave('dropZoneCsv')"
             ondrop="dzDrop(event, 'csv')">
            <input type="file" id="csvFileInput" accept=".csv,text/csv" multiple
                   onchange="handleFileSelect(this.files, 'csv')">
            <div class="dz-icon">📊</div>
            <div class="dz-title">Drop CSV files here</div>
            <div class="dz-sub">or <span>click to browse</span></div>
        </div>
        <div id="fileList-csv" class="file-list-container"></div>

        <!-- CSV configuration panel -->
        <div class="csv-config" id="csvConfig">
            <h4>⚙️ CSV Column Mapping</h4>
            <div class="config-grid">
                <div class="config-item">
                    <label>Instrument column <em>(or fixed value)</em></label>
                    <input type="text" id="csvInstrumentCol" placeholder="e.g. instrument  or  EURUSD" value="instrument">
                </div>
                <div class="config-item">
                    <label>Header row</label>
                    <select id="csvHasHeader">
                        <option value="1">Yes – first row is header</option>
                        <option value="0">No header – use column numbers</option>
                    </select>
                </div>
            </div>
            <div class="col-map-grid">
                <div class="config-item">
                    <label>Timestamp col</label>
                    <input type="text" id="csvColTs"    placeholder="timestamp" value="timestamp">
                </div>
                <div class="config-item">
                    <label>Open col</label>
                    <input type="text" id="csvColOpen"  placeholder="open" value="open">
                </div>
                <div class="config-item">
                    <label>High col</label>
                    <input type="text" id="csvColHigh"  placeholder="high" value="high">
                </div>
                <div class="config-item">
                    <label>Low col</label>
                    <input type="text" id="csvColLow"   placeholder="low" value="low">
                </div>
                <div class="config-item">
                    <label>Close col</label>
                    <input type="text" id="csvColClose" placeholder="close" value="close">
                </div>
                <div class="config-item">
                    <label>Volume col <em>(optional)</em></label>
                    <input type="text" id="csvColVol"   placeholder="volume" value="volume">
                </div>
            </div>
        </div>
    </div>

    <!-- Submit -->
    <button class="submit-btn" id="submitBtn" onclick="handleSubmit()">⚡ Process &amp; Update CSV</button>
    <div id="status"></div>
</div>

<script>
// ─────────────────────────────────────────────
//  State
// ─────────────────────────────────────────────
let activeTab = 'paste';
const fileStore = { json: [], csv: [] };

// ─────────────────────────────────────────────
//  Tabs
// ─────────────────────────────────────────────
function switchTab(name, btn) {
    activeTab = name;
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    clearStatus();
}

// ─────────────────────────────────────────────
//  Drop Zone helpers
// ─────────────────────────────────────────────
function dzDrag(e, id) {
    e.preventDefault();
    document.getElementById(id).classList.add('drag-over');
}
function dzLeave(id) {
    document.getElementById(id).classList.remove('drag-over');
}
function dzDrop(e, type) {
    e.preventDefault();
    const id = type === 'json' ? 'dropZoneJson' : 'dropZoneCsv';
    document.getElementById(id).classList.remove('drag-over');
    handleFileSelect(e.dataTransfer.files, type);
}

// ─────────────────────────────────────────────
//  File select / list rendering
// ─────────────────────────────────────────────
function handleFileSelect(files, type) {
    for (const f of files) {
        const ext = f.name.split('.').pop().toLowerCase();
        const ok  = type === 'json' ? ext === 'json' : ext === 'csv';
        if (!ok) { showStatus(`❌ "${f.name}" is not a .${type} file — skipped.`, 'error'); continue; }
        // Avoid duplicates by name
        if (fileStore[type].some(x => x.name === f.name)) continue;
        fileStore[type].push(f);
    }
    renderFileList(type);
    if (type === 'csv') {
        document.getElementById('csvConfig').classList.toggle('visible', fileStore.csv.length > 0);
    }
}

function formatBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    return (b/1048576).toFixed(1) + ' MB';
}

function renderFileList(type) {
    const container = document.getElementById('fileList-' + type);
    if (fileStore[type].length === 0) { container.innerHTML = ''; return; }
    container.innerHTML = '<div id="fileList" class="file-list-container" style="margin-top:14px;display:flex;flex-direction:column;gap:8px;">'
        + fileStore[type].map((f, i) => `
        <div class="file-chip">
            <span class="fc-icon">${type === 'json' ? '📄' : '📊'}</span>
            <span class="fc-name">${escHtml(f.name)}</span>
            <span class="fc-size">${formatBytes(f.size)}</span>
            <button class="fc-remove" title="Remove" onclick="removeFile('${type}', ${i})">✕</button>
        </div>`).join('')
    + '</div>';
}

function removeFile(type, idx) {
    fileStore[type].splice(idx, 1);
    renderFileList(type);
    if (type === 'csv') {
        document.getElementById('csvConfig').classList.toggle('visible', fileStore.csv.length > 0);
    }
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─────────────────────────────────────────────
//  CSV → JSON conversion
// ─────────────────────────────────────────────
function parseCsvText(text) {
    const lines  = text.trim().split(/\r?\n/);
    const hasHdr = document.getElementById('csvHasHeader').value === '1';
    const cols   = {
        ts:   document.getElementById('csvColTs').value.trim(),
        open: document.getElementById('csvColOpen').value.trim(),
        high: document.getElementById('csvColHigh').value.trim(),
        low:  document.getElementById('csvColLow').value.trim(),
        close:document.getElementById('csvColClose').value.trim(),
        vol:  document.getElementById('csvColVol').value.trim()
    };
    const instrField = document.getElementById('csvInstrumentCol').value.trim();

    let headers = [];
    let dataLines = lines;

    if (hasHdr) {
        headers   = lines[0].split(',').map(h => h.trim().toLowerCase());
        dataLines = lines.slice(1);
    }

    function getVal(row, colName) {
        const lower = colName.toLowerCase();
        if (hasHdr) {
            const idx = headers.indexOf(lower);
            return idx >= 0 ? row[idx] : undefined;
        } else {
            const n = parseInt(colName);
            return isNaN(n) ? undefined : row[n];
        }
    }

    // Detect if instrField is a column name or a fixed value
    function getInstrument(row) {
        if (hasHdr && headers.includes(instrField.toLowerCase())) {
            return getVal(row, instrField);
        }
        // Not a header – treat as fixed instrument name
        const lower = instrField.toLowerCase();
        if (!hasHdr && !isNaN(parseInt(instrField))) {
            return getVal(row, instrField); // column index
        }
        return instrField; // literal e.g. "EURUSD"
    }

    const rows = [];
    for (const line of dataLines) {
        if (!line.trim()) continue;
        const parts = line.split(',').map(p => p.trim().replace(/^"|"$/g,''));
        const ts = parseFloat(getVal(parts, cols.ts));
        if (isNaN(ts)) continue;
        rows.push({
            timestamp:  ts,
            instrument: getInstrument(parts),
            open:  parseFloat(getVal(parts, cols.open))  || 0,
            high:  parseFloat(getVal(parts, cols.high))  || 0,
            low:   parseFloat(getVal(parts, cols.low))   || 0,
            close: parseFloat(getVal(parts, cols.close)) || 0,
            volume:parseFloat(getVal(parts, cols.vol))   || 0
        });
    }
    return rows;
}

// ─────────────────────────────────────────────
//  Read file as text (promise)
// ─────────────────────────────────────────────
function readFile(file) {
    return new Promise((res, rej) => {
        const r = new FileReader();
        r.onload  = e => res(e.target.result);
        r.onerror = () => rej(new Error('Read failed: ' + file.name));
        r.readAsText(file);
    });
}

// ─────────────────────────────────────────────
//  Main submit handler
// ─────────────────────────────────────────────
async function handleSubmit() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    showStatus('⚙️ Processing…', 'info');

    try {
        let allRows = [];

        if (activeTab === 'paste') {
            const raw = document.getElementById('jsonInput').value.trim();
            if (!raw) { showStatus('❌ Paste some JSON first.', 'error'); return; }
            const parsed = JSON.parse(raw);
            if (!parsed.data) throw new Error('Missing "data" array in JSON.');
            allRows = parsed.data;

        } else if (activeTab === 'upload-json') {
            if (!fileStore.json.length) { showStatus('❌ Add at least one JSON file.', 'error'); return; }
            for (const f of fileStore.json) {
                const text   = await readFile(f);
                const parsed = JSON.parse(text);
                if (!parsed.data) throw new Error(`"data" array missing in ${f.name}`);
                allRows = allRows.concat(parsed.data);
            }

        } else if (activeTab === 'upload-csv') {
            if (!fileStore.csv.length) { showStatus('❌ Add at least one CSV file.', 'error'); return; }
            for (const f of fileStore.csv) {
                const text = await readFile(f);
                const rows = parseCsvText(text);
                if (!rows.length) throw new Error(`No valid rows found in ${f.name}. Check column mapping.`);
                allRows = allRows.concat(rows);
            }
        }

        showStatus(`⚙️ Sending ${allRows.length} rows to server…`, 'info');

        const response = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ data: allRows })
        });

        const text = await response.text();
        const result = JSON.parse(text);

        if (result.status === 'success') {
            let msg = `✅ Success! ${allRows.length} rows processed.\n\nUpdated Files:\n\n`;
            for (const [pair, detail] of Object.entries(result.details)) {
                msg += `• ${pair}: ${detail}\n`;
            }
            showStatus(msg, 'success');
            // Clear inputs on success
            document.getElementById('jsonInput').value = '';
            fileStore.json = []; fileStore.csv = [];
            renderFileList('json'); renderFileList('csv');
            document.getElementById('csvConfig').classList.remove('visible');
        } else {
            showStatus('❌ API Error: ' + (result.message || 'Unknown error'), 'error');
        }

    } catch (err) {
        showStatus('❌ ' + err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

// ─────────────────────────────────────────────
//  Status helpers
// ─────────────────────────────────────────────
function showStatus(msg, cls) {
    const el = document.getElementById('status');
    el.style.display = 'block';
    el.className = cls || '';
    el.innerText = msg;
}
function clearStatus() {
    const el = document.getElementById('status');
    el.style.display = 'none';
    el.innerText = '';
}
</script>

</body>
</html>