<?php
// =========================================================================
// BACKEND API: Process data from ESP8266 OR the Web UI (POST Requests)
// FILE NAMING: FX_EURUSD-2025-06-15.csv  (IST date per pair per day)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('memory_limit', '1024M');
    set_time_limit(600);
    header('Content-Type: application/json');

    $input = file_get_contents('php://input');

    // 1. SAVE INCOMING PAYLOAD TO TEMP JSON
    $tempJsonPath = __DIR__ . '/temp_payload_' . uniqid() . '.json';
    file_put_contents($tempJsonPath, $input);

    // 2. DECODE
    $payload = json_decode(file_get_contents($tempJsonPath), true);

    if (!$payload || !isset($payload['data'])) {
        if (file_exists($tempJsonPath)) unlink($tempJsonPath);
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON or missing "data" array.']);
        exit;
    }

    // IST timezone helper
    $IST = new DateTimeZone('Asia/Kolkata');

    // Helper: get IST date string "Y-m-d" from a unix timestamp
    function getISTDate($ts, $IST) {
        $dt = new DateTime("@$ts");
        $dt->setTimezone($IST);
        return $dt->format('Y-m-d');
    }

    $cutoffEarlier = strtotime('2025-06-23 00:00:00');
    $cutoffNew     = strtotime('2026-03-01 00:00:00');

    // 3. GROUP BY: base_folder / instrument / IST_date
    //    Key: [folder][instrument][date] => [ rows ]
    $grouped = [];

    foreach ($payload['data'] as $row) {
        $instrument = str_replace(['/', '_', ' '], '', $row['instrument']); // e.g. EURUSD
        $ts         = (int)$row['timestamp'];

        if ($ts < $cutoffEarlier) {
                // Everything before 2025-06-23
                $base_folder = __DIR__ . '/BEFORE-JUN-2025';
            } elseif ($ts < $cutoffNew) {
                // 2025-06-23 through 2026-02-28
                $base_folder = __DIR__ . '/JUN-2025 TO FEB-2026';
            } else {
                // 2026-03-01 onward
                $base_folder = __DIR__ . '/dataset';
            }

        $ist_date = getISTDate($ts, $IST); // e.g. "2025-06-15"

        $grouped[$base_folder][$instrument][$ist_date][] = $row;
    }

    // --- STRATEGY LOGIC ---
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

    // NOTE ON STRATEGY ALERTS ACROSS DAY BOUNDARIES:
    // detectStreakPattern needs up to 8 candles of lookback.
    // For the first ~8 rows of any day file, we load the tail of the
    // previous day's file to supply that context, then discard those
    // rows after recalculation — only the current-day rows are written.

    $results = [];

    // 4. PROCESS EACH FOLDER / INSTRUMENT / DATE
    foreach ($grouped as $base_folder => $instruments) {
        foreach ($instruments as $instrument => $dates) {

            // Each pair gets its own subfolder: /dataset/EURUSD/
            $pair_folder = $base_folder . '/' . $instrument;
            if (!file_exists($pair_folder)) {
                mkdir($pair_folder, 0755, true);
            }

            foreach ($dates as $ist_date => $new_rows) {

                // File: /dataset/EURUSD/FX_EURUSD-2025-06-15.csv
                $filename = "FX_{$instrument}-{$ist_date}.csv";
                $filepath = $pair_folder . '/' . $filename;

                // --- LOAD EXISTING DATA FOR THIS DATE FILE ---
                $all_data = [];

                if (file_exists($filepath)) {
                    if (($handle = fopen($filepath, 'r')) !== false) {
                        fgetcsv($handle); // skip header
                        while (($data = fgetcsv($handle)) !== false) {
                            if (count($data) >= 5 && is_numeric($data[0])) {
                                $ts    = (int)$data[0];
                                $open  = (float)$data[1];
                                $high  = (float)$data[2];
                                $low   = (float)$data[3];
                                $close = (float)$data[4];
                                if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) continue;
                                $all_data[$ts] = [
                                    'timestamp' => $ts,
                                    'open'      => $open,
                                    'high'      => $high,
                                    'low'       => $low,
                                    'close'     => $close,
                                    'alert'     => isset($data[5]) ? (int)$data[5] : 0,
                                    'volume'    => isset($data[6]) ? (float)$data[6] : 0,
                                ];
                            }
                        }
                        fclose($handle);
                    }
                }

                // --- LOAD PREVIOUS DAY'S TAIL FOR STRATEGY LOOKBACK ---
                // We need up to 8 candles before the first candle of this day.
                $prev_date_obj = new DateTime($ist_date, $IST);
                $prev_date_obj->modify('-1 day');
                $prev_date    = $prev_date_obj->format('Y-m-d');
                $prev_file    = $pair_folder . "/FX_{$instrument}-{$prev_date}.csv";
                $prev_tail    = []; // candles from previous day (context only)

                if (file_exists($prev_file)) {
                    if (($ph = fopen($prev_file, 'r')) !== false) {
                        fgetcsv($ph); // skip header
                        $prev_rows = [];
                        while (($prow = fgetcsv($ph)) !== false) {
                            if (count($prow) >= 5 && is_numeric($prow[0])) {
                                $pts = (int)$prow[0];
                                $prev_rows[$pts] = [
                                    'timestamp' => $pts,
                                    'open'      => (float)$prow[1],
                                    'high'      => (float)$prow[2],
                                    'low'       => (float)$prow[3],
                                    'close'     => (float)$prow[4],
                                    'alert'     => isset($prow[5]) ? (int)$prow[5] : 0,
                                    'volume'    => isset($prow[6]) ? (float)$prow[6] : 0,
                                ];
                            }
                        }
                        fclose($ph);
                        ksort($prev_rows);
                        $prev_tail = array_slice(array_values($prev_rows), -8); // last 8 candles
                    }
                }

                // --- MERGE NEW ROWS INTO THIS DAY'S DATA ---
                $updated_count = 0;
                $added_count   = 0;

                foreach ($new_rows as $row) {
                    $ts    = (int)$row['timestamp'];
                    $open  = (float)$row['open'];
                    $high  = (float)$row['high'];
                    $low   = (float)$row['low'];
                    $close = (float)$row['close'];

                    if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) continue;

                    if (isset($all_data[$ts])) {
                        $updated_count++;
                    } else {
                        $added_count++;
                    }

                    $all_data[$ts] = [
                        'timestamp' => $ts,
                        'open'      => $open,
                        'high'      => $high,
                        'low'       => $low,
                        'close'     => $close,
                        'alert'     => isset($all_data[$ts]['alert']) ? $all_data[$ts]['alert'] : 0,
                        'volume'    => isset($row['volume'])
                            ? (float)$row['volume']
                            : (isset($all_data[$ts]['volume']) ? $all_data[$ts]['volume'] : 0),
                    ];
                }

                // --- SORT CHRONOLOGICALLY ---
                ksort($all_data);
                $day_candles = array_values($all_data);

                // --- BUILD COMBINED ARRAY: prev_tail + day_candles ---
                // prev_tail entries are context only; we'll write only day_candles
                $combined   = array_merge($prev_tail, $day_candles);
                $offset     = count($prev_tail); // index in $combined where day starts
                $totalC     = count($combined);

                // Recalculate alerts for day_candles that could be affected by new rows
                // Start from max(offset, offset + count(day_candles) - count(new_rows) - 20)
                // but always stay within day range
                $newRowCount   = count($new_rows);
                $startInDay    = max(0, count($day_candles) - $newRowCount - 20);
                $startInCombined = $offset + $startInDay;

                for ($i = $startInCombined; $i < $totalC; $i++) {
                    // Only recalculate if we have enough lookback
                    if ($i >= 7) {
                        $combined[$i]['alert'] = detectStreakPattern($combined, $i);
                    }
                }

                // Extract only day rows back (strip prev_tail)
                $final_day = array_slice($combined, $offset);

                // --- ATOMIC WRITE ---
                $fp = fopen($filepath, 'c+');
                if ($fp && flock($fp, LOCK_EX)) {
                    ftruncate($fp, 0);
                    fputcsv($fp, ['time', 'open', 'high', 'low', 'close', 'Pattern Alert', 'Volume']);
                    foreach ($final_day as $r) {
                        fputcsv($fp, [
                            $r['timestamp'], $r['open'], $r['high'],
                            $r['low'], $r['close'], $r['alert'], $r['volume'],
                        ]);
                    }
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }

                $key = "{$instrument}@{$ist_date}";
                $results[$key] = "+{$added_count} new | ↻{$updated_count} updated → {$filename}";
            }
        }
    }

    // 5. CLEANUP TEMP FILE
    if (file_exists($tempJsonPath)) unlink($tempJsonPath);

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
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #eef2f5; padding: 2rem; max-width: 860px; margin: auto; }
        .header-box { background: #1e293b; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .header-box h2 { margin: 0; font-size: 1.5rem; }
        .header-box p { margin: 5px 0 0 0; color: #94a3b8; font-size: 0.9rem; }
        .container { background: white; padding: 25px; border-radius: 0 0 8px 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .tabs { display: flex; gap: 4px; background: #f1f5f9; border-radius: 8px; padding: 4px; margin-bottom: 22px; }
        .tab-btn { flex: 1; padding: 10px 0; border: none; border-radius: 6px; background: transparent; color: #64748b; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.18s; }
        .tab-btn.active { background: white; color: #1e293b; box-shadow: 0 1px 4px rgba(0,0,0,0.10); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .panel-label { margin: 0 0 12px 0; color: #475569; font-size: 14px; }
        textarea { width: 100%; height: 250px; padding: 15px; font-family: "Courier New", Courier, monospace; font-size: 13px; border: 2px solid #e2e8f0; border-radius: 6px; resize: vertical; background: #f8fafc; color: #1e293b; }
        textarea:focus { outline: none; border-color: #3b82f6; }
        .drop-zone { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 40px 20px; text-align: center; cursor: pointer; background: #f8fafc; position: relative; }
        .drop-zone.drag-over { border-color: #3b82f6; background: #eff6ff; }
        .drop-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
        .drop-zone .dz-icon { font-size: 2.4rem; margin-bottom: 10px; }
        .drop-zone .dz-title { font-size: 15px; font-weight: 600; color: #334155; margin-bottom: 4px; }
        .drop-zone .dz-sub { font-size: 13px; color: #94a3b8; }
        .file-chip { display: flex; align-items: center; gap: 10px; background: #f1f5f9; border-radius: 6px; padding: 9px 12px; font-size: 13px; color: #334155; }
        .file-chip .fc-name { flex: 1; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .csv-config { display: none; margin-top: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
        .csv-config.visible { display: block; }
        .config-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .config-item label { display: block; font-size: 12px; color: #64748b; margin-bottom: 5px; }
        .config-item select, .config-item input[type="text"] { width: 100%; padding: 8px 10px; border: 1.5px solid #e2e8f0; border-radius: 5px; font-size: 13px; }
        .col-map-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 12px; }
        button.submit-btn { background: #3b82f6; color: white; border: none; padding: 14px 24px; font-size: 15px; border-radius: 6px; cursor: pointer; margin-top: 18px; font-weight: 600; width: 100%; }
        button.submit-btn:disabled { background: #94a3b8; cursor: not-allowed; }
        #status { margin-top: 20px; padding: 15px; border-radius: 6px; display: none; font-weight: 500; white-space: pre-wrap; font-size: 14px; line-height: 1.5; }
        .success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
    </style>
</head>
<body>

<div class="header-box">
    <h2>📊 Server Active</h2>
    <p>Endpoint ready to receive POST payloads from the NodeMCU ESP8266.<br>
       Files saved as <code>FX_EURUSD-2025-06-15.csv</code> (IST date per pair per day)</p>
</div>

<div class="container">
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('paste', this)">📋 Paste JSON</button>
        <button class="tab-btn" onclick="switchTab('upload-json', this)">📄 Upload JSON</button>
        <button class="tab-btn" onclick="switchTab('upload-csv', this)">📊 Upload CSV</button>
    </div>

    <div id="tab-paste" class="tab-panel active">
        <p class="panel-label">Manually paste a ForexFactory JSON payload:</p>
        <textarea id="jsonInput" placeholder='{"data": [{"timestamp": 1781072100, "instrument": "EUR/USD", "open": 1.0820, "high": 1.0835, "low": 1.0810, "close": 1.0828}]}'></textarea>
    </div>

    <div id="tab-upload-json" class="tab-panel">
        <p class="panel-label">Upload one or more <strong>.json</strong> files:</p>
        <div class="drop-zone" id="dropZoneJson" ondragover="dzDrag(event, 'dropZoneJson')" ondragleave="dzLeave('dropZoneJson')" ondrop="dzDrop(event, 'json')">
            <input type="file" id="jsonFileInput" accept=".json,application/json" multiple onchange="handleFileSelect(this.files, 'json')">
            <div class="dz-icon">📂</div>
            <div class="dz-title">Drop JSON files here</div>
        </div>
        <div id="fileList-json"></div>
    </div>

    <div id="tab-upload-csv" class="tab-panel">
        <p class="panel-label">Upload one or more <strong>.csv</strong> files:</p>
        <div class="drop-zone" id="dropZoneCsv" ondragover="dzDrag(event, 'dropZoneCsv')" ondragleave="dzLeave('dropZoneCsv')" ondrop="dzDrop(event, 'csv')">
            <input type="file" id="csvFileInput" accept=".csv,text/csv" multiple onchange="handleFileSelect(this.files, 'csv')">
            <div class="dz-icon">📊</div>
            <div class="dz-title">Drop CSV files here</div>
        </div>
        <div id="fileList-csv"></div>

        <div class="csv-config" id="csvConfig">
            <h4>⚙️ CSV Column Mapping</h4>
            <div class="config-grid">
                <div class="config-item">
                    <label>Instrument column (or fixed value)</label>
                    <input type="text" id="csvInstrumentCol" value="instrument">
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
                <div class="config-item"><label>Timestamp col</label><input type="text" id="csvColTs" value="timestamp"></div>
                <div class="config-item"><label>Open col</label><input type="text" id="csvColOpen" value="open"></div>
                <div class="config-item"><label>High col</label><input type="text" id="csvColHigh" value="high"></div>
                <div class="config-item"><label>Low col</label><input type="text" id="csvColLow" value="low"></div>
                <div class="config-item"><label>Close col</label><input type="text" id="csvColClose" value="close"></div>
                <div class="config-item"><label>Volume col</label><input type="text" id="csvColVol" value="volume"></div>
            </div>
        </div>
    </div>

    <button class="submit-btn" id="submitBtn" onclick="handleSubmit()">⚡ Process & Update CSV</button>
    <div id="status"></div>
</div>

<script>
let activeTab = 'paste';
const fileStore = { json: [], csv: [] };
const BATCH_SIZE = 2000;

function switchTab(name, btn) {
    activeTab = name;
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    clearStatus();
}

function dzDrag(e, id) { e.preventDefault(); document.getElementById(id).classList.add('drag-over'); }
function dzLeave(id) { document.getElementById(id).classList.remove('drag-over'); }
function dzDrop(e, type) {
    e.preventDefault();
    const id = type === 'json' ? 'dropZoneJson' : 'dropZoneCsv';
    document.getElementById(id).classList.remove('drag-over');
    handleFileSelect(e.dataTransfer.files, type);
}

function handleFileSelect(files, type) {
    for (const f of files) {
        const ext = f.name.split('.').pop().toLowerCase();
        if ((type === 'json' && ext !== 'json') || (type === 'csv' && ext !== 'csv')) continue;
        if (fileStore[type].some(x => x.name === f.name)) continue;
        fileStore[type].push(f);
    }
    renderFileList(type);
    if (type === 'csv') document.getElementById('csvConfig').classList.toggle('visible', fileStore.csv.length > 0);
}

function renderFileList(type) {
    const container = document.getElementById('fileList-' + type);
    if (fileStore[type].length === 0) { container.innerHTML = ''; return; }
    container.innerHTML = fileStore[type].map((f, i) => `
        <div class="file-chip">
            <span>${type === 'json' ? '📄' : '📊'}</span>
            <span class="fc-name">${f.name}</span>
            <button onclick="removeFile('${type}', ${i})">✕</button>
        </div>`).join('');
}

function removeFile(type, idx) {
    fileStore[type].splice(idx, 1);
    renderFileList(type);
    if (type === 'csv') document.getElementById('csvConfig').classList.toggle('visible', fileStore.csv.length > 0);
}

function parseCsvText(text) {
    const lines = text.trim().split(/\r?\n/);
    const hasHdr = document.getElementById('csvHasHeader').value === '1';
    const cols = {
        ts:    document.getElementById('csvColTs').value.trim(),
        open:  document.getElementById('csvColOpen').value.trim(),
        high:  document.getElementById('csvColHigh').value.trim(),
        low:   document.getElementById('csvColLow').value.trim(),
        close: document.getElementById('csvColClose').value.trim(),
        vol:   document.getElementById('csvColVol').value.trim()
    };
    const instrField = document.getElementById('csvInstrumentCol').value.trim();

    let headers = [];
    let dataLines = lines;

    if (hasHdr) {
        headers = lines[0].split(',').map(h => h.trim().toLowerCase());
        dataLines = lines.slice(1);
    }

    function getVal(row, colName) {
        if (hasHdr) {
            const idx = headers.indexOf(colName.toLowerCase());
            return idx >= 0 ? row[idx] : undefined;
        } else {
            const n = parseInt(colName);
            return isNaN(n) ? undefined : row[n];
        }
    }

    function getInstrument(row) {
        if (hasHdr && headers.includes(instrField.toLowerCase())) return getVal(row, instrField);
        if (!hasHdr && !isNaN(parseInt(instrField))) return getVal(row, instrField);
        return instrField;
    }

    const rows = [];
    for (const line of dataLines) {
        if (!line.trim()) continue;
        const parts = line.split(',').map(p => p.trim().replace(/^"|"$/g, ''));
        const ts = parseFloat(getVal(parts, cols.ts));
        if (isNaN(ts)) continue;
        rows.push({
            timestamp:  ts,
            instrument: getInstrument(parts),
            open:       parseFloat(getVal(parts, cols.open))  || 0,
            high:       parseFloat(getVal(parts, cols.high))  || 0,
            low:        parseFloat(getVal(parts, cols.low))   || 0,
            close:      parseFloat(getVal(parts, cols.close)) || 0,
            volume:     parseFloat(getVal(parts, cols.vol))   || 0
        });
    }
    return rows;
}

function readFile(file) {
    return new Promise((res, rej) => {
        const r = new FileReader();
        r.onload = e => res(e.target.result);
        r.onerror = () => rej(new Error('Failed reading: ' + file.name));
        r.readAsText(file);
    });
}

async function sendBatch(batch) {
    const response = await fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ data: batch })
    });
    const text = await response.text();
    return JSON.parse(text);
}

async function handleSubmit() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    showStatus('⚙️ Preparing data...', 'info');

    try {
        let allRows = [];

        if (activeTab === 'paste') {
            const raw = document.getElementById('jsonInput').value.trim();
            if (!raw) throw new Error('Please paste JSON data first.');
            const parsed = JSON.parse(raw);
            if (!parsed.data) throw new Error('JSON is missing "data" array.');
            allRows = parsed.data;
        } else if (activeTab === 'upload-json') {
            if (!fileStore.json.length) throw new Error('Please select a JSON file.');
            for (const f of fileStore.json) {
                const text = await readFile(f);
                const parsed = JSON.parse(text);
                if (!parsed.data) throw new Error(`Missing "data" array in ${f.name}`);
                allRows = allRows.concat(parsed.data);
            }
        } else if (activeTab === 'upload-csv') {
            if (!fileStore.csv.length) throw new Error('Please select a CSV file.');
            for (const f of fileStore.csv) {
                const text = await readFile(f);
                const rows = parseCsvText(text);
                if (!rows.length) throw new Error(`No valid rows parsed from ${f.name}`);
                allRows = allRows.concat(rows);
            }
        }

        const totalRows = allRows.length;
        let processed = 0;
        let combinedResults = {};

        for (let i = 0; i < totalRows; i += BATCH_SIZE) {
            const chunk = allRows.slice(i, i + BATCH_SIZE);
            showStatus(`⚙️ Uploading batch (${processed + chunk.length} / ${totalRows} rows)...`, 'info');

            const result = await sendBatch(chunk);
            if (result.status === 'success') {
                Object.assign(combinedResults, result.details);
            } else {
                throw new Error(result.message || 'Error during batch upload.');
            }

            processed += chunk.length;

            if (processed < totalRows) {
                showStatus(`⏳ Cooldown... waiting 3s before next batch.`, 'info');
                await new Promise(resolve => setTimeout(resolve, 3000));
            }
        }

        let msg = `✅ Success! ${totalRows} total rows uploaded.\n\nUpdated Files:\n\n`;
        for (const [key, detail] of Object.entries(combinedResults)) {
            msg += `• ${key}: ${detail}\n`;
        }
        showStatus(msg, 'success');

        document.getElementById('jsonInput').value = '';
        fileStore.json = []; fileStore.csv = [];
        renderFileList('json'); renderFileList('csv');
        document.getElementById('csvConfig').classList.remove('visible');

    } catch (err) {
        showStatus('❌ ' + err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

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