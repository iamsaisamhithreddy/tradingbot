let activeTradeId = null;
let activeTradeData = null;

// Toggle all checkboxes in sidebar
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.trade-select');
    checkboxes.forEach(cb => {
        const item = cb.closest('.trade-item');
        if (item && item.style.display !== 'none') {
            cb.checked = master.checked;
        }
    });
    updateSelectedCount();
}

// Update the counter showing how many checkboxes are checked (visible rows only,
// consistent with the outcome/search filter currently applied)
function updateSelectedCount() {
    const checked = Array.from(document.querySelectorAll('.trade-select:checked')).filter(cb => {
        const item = cb.closest('.trade-item');
        return item && item.style.display !== 'none';
    }).length;
    document.getElementById('selected-count').innerText = checked;
}

// Populate the pair filter dropdown from the trade items already in the sidebar.
// Runs once on DOMContentLoaded so it reflects whatever the PHP partial rendered.
function populatePairFilter() {
    const select = document.getElementById('pair-filter');
    if (!select) return;

    const pairs = new Set();
    document.querySelectorAll('.trade-item .trade-pair').forEach(el => {
        const pair = el.innerText.trim().toUpperCase();
        if (pair) pairs.add(pair);
    });

    // Sort alphabetically and append as <option> elements
    Array.from(pairs).sort().forEach(pair => {
        const opt = document.createElement('option');
        opt.value = pair;
        opt.textContent = pair;
        select.appendChild(opt);
    });
}

// Initialize the selection counter and automated broadcast runner
document.addEventListener('DOMContentLoaded', () => {
    updateSelectedCount();
    populatePairFilter();

    const urlParams = new URLSearchParams(window.location.search);

    // 1. Check if it's an automated Telegram Broadcast
    if (urlParams.has('run_broadcast') && urlParams.get('run_broadcast') === '1') {
        const tradeIdsStr = urlParams.get('trade_ids');
        if (tradeIdsStr) {
            const tradeIds = tradeIdsStr.split(',').filter(x => x);
            if (tradeIds.length > 0) {
                runAutomatedBroadcast(tradeIds);
            }
        }
    }
    // 2. Otherwise, check if we just need to load a specific trade into the UI
    else if (urlParams.has('search-id') || urlParams.has('trade_id')) {
        const idParam = urlParams.get('search-id') || urlParams.get('trade_id');
        const loadId = parseInt(idParam);

        if (!isNaN(loadId) && loadId > 0) {
            // Pre-fill the input box for good UX
            const searchInput = document.getElementById('search-id');
            if (searchInput) searchInput.value = loadId;

            // Automatically fetch and show the chart
            loadTrade(loadId);
        }
    }
});

// Automated rendering and Telegram PDF broadcast pipeline (fully server-side GD)
async function runAutomatedBroadcast(tradeIds) {
    const overlay = document.getElementById('loading-overlay');
    overlay.style.display = 'flex';

    // Step 1: Generate all GD chart images on the server
    for (let i = 0; i < tradeIds.length; i++) {
        const id = tradeIds[i];
        overlay.innerHTML = `<span style="text-align: center; font-size: 18px; color: #f8fafc; font-family: 'Outfit', sans-serif;">📊 Generating Telegram PDF Report<br><br>Rendering Chart ${i + 1}/${tradeIds.length} (ID #${id})...<br><small style="color: #64748b; font-size: 13px;">Server-side GD rendering in progress</small></span>`;

        // Highlight list item
        document.querySelectorAll('.trade-item').forEach(el => el.classList.remove('active'));
        const activeItem = document.getElementById(`trade-${id}`);
        if (activeItem) {
            activeItem.classList.add('active');
            activeItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        try {
            const currentUrl = window.location.pathname;
            const res = await fetch(`${currentUrl}?ajax=1&trade_id=${id}`, { credentials: 'same-origin' });
            const data = await res.json();
            if (data.error) {
                console.error(`Error generating chart for trade #${id}:`, data.error);
            }
        } catch (err) {
            console.error(`Error generating chart for trade #${id}:`, err);
        }
    }

    // Step 2: Compile PDF and broadcast
    overlay.innerHTML = `<span style="text-align: center; font-size: 18px; color: #f8fafc; font-family: 'Outfit', sans-serif;">🤖 Compiling Outcomes PDF & Broadcasting...</span>`;

    const currentUrl = window.location.pathname;
    try {
        await fetch(`${currentUrl}?ajax=1&set_pdf_ids=1`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'trade_ids': tradeIds.join(',') }),
            credentials: 'same-origin'
        });

        const bResp = await fetch(`${currentUrl}?generate_pdf=1&broadcast=1`, { credentials: 'same-origin' });
        const bRes = await bResp.json();

        if (bRes.success) {
            overlay.innerHTML = `<span style="text-align: center; font-size: 18px; color: #10b981; font-family: 'Outfit', sans-serif;">✅ Outcomes PDF Report compiled and broadcasted successfully to all Telegram users!</span>`;
            setTimeout(() => {
                const referrer = document.referrer;
                let returnUrl = '../evaluate_win_loss.php?broadcast_success=1';

                if (referrer && (referrer.includes('evaluate_win_loss.php') || referrer.includes('daily_trades_pdf.php'))) {
                    returnUrl = referrer.split('?')[0] + '?broadcast_success=1';
                }

                window.location.href = returnUrl;
            }, 2500);
        } else {
            throw new Error(bRes.error || "Broadcast failed due to an unknown server error.");
        }
    } catch (err) {
        overlay.innerHTML = `<span style="text-align: center; font-size: 18px; color: #ef4444; font-family: 'Outfit', sans-serif;">❌ Broadcast Error: ${err.message}<br><br><button onclick="window.location.href='../evaluate_win_loss.php'" style="background:#1e293b; color:#fff; border:1px solid #334155; padding:8px 16px; border-radius:6px; margin-top: 15px; cursor:pointer;">Return to Dashboard</button></span>`;
    }
}

// Generate multipage PDF report of all checked + currently visible trade outcomes
// (fully server-side GD). "Visible" respects whatever the search box and the
// Wins/Losses dropdown are currently filtered to - a checkbox that's checked
// but hidden by the filter is NOT included, so switching to "Losses Only" and
// hitting PDF genuinely sends losses only, even if wins are still checked
// underneath from before you changed the filter.
async function generateCheckedPdf() {
    const checkedBoxes = Array.from(document.querySelectorAll('.trade-select:checked')).filter(cb => {
        const item = cb.closest('.trade-item');
        return item && item.style.display !== 'none';
    });
    if (checkedBoxes.length === 0) {
        alert('Please check at least one visible trade signal to generate the PDF report.');
        return;
    }

    if (!confirm(`This will compile a PDF report containing charts for the ${checkedBoxes.length} selected trades. Continue?`)) {
        return;
    }

    const btn = document.getElementById('btn-generate-pdf');
    const originalText = btn.innerText;
    btn.disabled = true;

    const overlay = document.getElementById('loading-overlay');
    overlay.style.display = 'flex';

    const tradeIds = checkedBoxes.map(cb => cb.value);

    // Step 1: Generate all GD chart images on the server
    for (let i = 0; i < tradeIds.length; i++) {
        const id = tradeIds[i];
        overlay.innerHTML = `<span style="text-align: center;">Rendering Chart ${i + 1}/${tradeIds.length} (ID #${id})...<br><small style="color: var(--text-secondary)">Server-side GD rendering</small></span>`;

        document.querySelectorAll('.trade-item').forEach(el => el.classList.remove('active'));
        const activeItem = document.getElementById(`trade-${id}`);
        if (activeItem) {
            activeItem.classList.add('active');
            activeItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        try {
            const currentUrl = window.location.pathname;
            const res = await fetch(`${currentUrl}?ajax=1&trade_id=${id}`, { credentials: 'same-origin' });
            const data = await res.json();
            if (data.error) {
                console.error(`Error generating chart for trade #${id}:`, data.error);
            }
        } catch (err) {
            console.error(`Error generating chart for trade #${id}:`, err);
        }
    }

    // Step 2: Compile PDF
    overlay.innerHTML = `<span style="text-align: center;">Compiling Multipage A4 PDF Document...</span>`;

    const currentUrl = window.location.pathname;
    try {
        await fetch(`${currentUrl}?ajax=1&set_pdf_ids=1`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'trade_ids': tradeIds.join(',') }),
            credentials: 'same-origin'
        });

        const pdfUrl = `${currentUrl}?generate_pdf=1`;
        window.location.href = pdfUrl;
    } catch (err) {
        console.error('Failed to set PDF trade IDs:', err);
        alert('Failed to generate PDF due to a network error.');
    }

    overlay.style.display = 'none';
    btn.disabled = false;
    btn.innerText = originalText;
}

// Export current chart as PNG download (uses server-side GD image)
function exportCurrentScreenshot() {
    if (!activeTradeId) {
        alert('Please select and load a trade first.');
        return;
    }
    const pair = document.getElementById('active-pair').innerText;
    const currentUrl = window.location.pathname;
    const link = document.createElement('a');
    link.download = `Trade_${activeTradeId}_${pair}.png`;
    link.href = `${currentUrl}?get_chart_image=1&trade_id=${activeTradeId}`;
    link.click();
}

// Filter trades in sidebar (pair search text box + pair dropdown + Wins/Losses outcome dropdown).
// All three filters are AND-ed together. If streak filter is enabled,
// re-runs applyStreakFilter() on the narrowed set.
function filterTrades() {
    const query = document.getElementById('search').value.toUpperCase();
    const outcomeDropdown = document.getElementById('outcome-filter');
    const outcomeVal = outcomeDropdown ? outcomeDropdown.value : 'all'; // 'all' | 'win' | 'loss'
    const pairDropdown  = document.getElementById('pair-filter');
    const pairVal = pairDropdown ? pairDropdown.value : 'all';          // 'all' | 'EURUSD' | …
    const items = document.querySelectorAll('.trade-item');

    let shownCount = 0;
    let hiddenCount = 0;

    items.forEach(item => {
        const pairText = item.querySelector('.trade-pair').innerText.toUpperCase();

        // Pair dropdown: exact match (search box still does substring match independently)
        const matchesPair   = (pairVal === 'all') || (pairText === pairVal);

        // Search box: substring match (AND-ed with pair dropdown — clear search to use
        // dropdown alone, or leave dropdown on "All Pairs" to use search alone)
        const matchesSearch = pairText.includes(query);

        // Prefer the data-result attribute rendered by trade_list_partial.php.
        // Fallback: infer from the visible "| WIN" / "| LOSS" badge text, in
        // case an older cached copy of that partial (without data-result)
        // is still being served.
        let itemResult = (item.dataset.result || '').toLowerCase();
        if (!itemResult) {
            const itemText = item.innerText.toUpperCase();
            if (itemText.includes('| WIN')) itemResult = 'win';
            else if (itemText.includes('| LOSS')) itemResult = 'loss';
            else itemResult = 'pending';
        }

        const matchesOutcome = (outcomeVal === 'all') || (itemResult === outcomeVal);
        const shouldShow = matchesPair && matchesSearch && matchesOutcome;
        shouldShow ? shownCount++ : hiddenCount++;

        // Use !important priority so this can't silently lose to a
        // pre-existing CSS rule on .trade-item (e.g. display: flex !important).
        // If streak filter is active and this item was streak-hidden, keep it hidden for now.
        // applyStreakFilter() will re-evaluate after filterTrades finishes.
        const streakActive = document.getElementById('streak-filter-enable')?.checked;
        if (shouldShow && streakActive && item.dataset.streakHidden === '1') {
            // leave display:none — applyStreakFilter will re-check
        } else {
            item.style.setProperty('display', shouldShow ? 'block' : 'none', 'important');
        }

        if (!shouldShow) {
            // Hidden by search/outcome — clear streak tag to avoid stale state
            delete item.dataset.streakHidden;
        }
    });
    console.log(`[filterTrades] pair=${pairVal} outcome=${outcomeVal} search="${query}" -> shown=${shownCount} hidden=${hiddenCount} (total=${items.length})`);
    updateSelectedCount();

    // If streak filter is active, re-apply it on the now-filtered list
    const streakEnabled = document.getElementById('streak-filter-enable');
    if (streakEnabled && streakEnabled.checked) {
        applyStreakFilter();
    }
}

// ============================================================
// STREAK FILTER
// POSTs visible trade IDs in batches of 100 to the server.
// Server reads each CSV and counts consecutive same-direction
// candles ending at (inclusive of) the signal candle.
// Trades that don't meet min_streak are hidden; others stay.
// ============================================================
let _streakAbortController = null;

async function applyStreakFilter() {
    const checkbox = document.getElementById('streak-filter-enable');
    const minInput = document.getElementById('streak-min');
    const statusEl = document.getElementById('streak-status');

    // --- If unchecked: restore items hidden by streak filter and clear stats ---
    if (!checkbox || !checkbox.checked) {
        document.querySelectorAll('.trade-item[data-streak-hidden="1"]').forEach(item => {
            item.removeAttribute('data-streak-hidden');
            item.style.setProperty('display', 'block', 'important');
        });
        if (statusEl) statusEl.textContent = '';
        removeStreakStatsPanel();
        updateSelectedCount();
        return;
    }

    const minStreak = Math.max(1, parseInt(minInput.value) || 1);

    // Collect all items not hidden by search/outcome filter
    const allItems    = Array.from(document.querySelectorAll('.trade-item'));
    const visibleItems = allItems.filter(item => {
        // Visible = not hidden by filterTrades (streak-hidden items may already be 'none' from prior run)
        // We check dataset.streakHidden to distinguish the two hide sources
        if (item.dataset.streakHidden === '1') return true;  // was hidden by streak, counts as "visible to streak"
        return item.style.getPropertyValue('display') !== 'none';
    });

    if (visibleItems.length === 0) {
        if (statusEl) statusEl.textContent = '0 match';
        updateSelectedCount();
        return;
    }

    const tradeIds = visibleItems.map(item => item.id.replace('trade-', '')).filter(Boolean);
    if (statusEl) statusEl.textContent = `Checking ${tradeIds.length}…`;

    // Cancel any in-flight streak request
    if (_streakAbortController) _streakAbortController.abort();
    _streakAbortController = new AbortController();
    const signal = _streakAbortController.signal;

    const currentUrl = window.location.pathname;
    const BATCH_SIZE = 100;
    const allResults = {};

    try {
        // Batch POSTs to avoid URL/body limits
        for (let i = 0; i < tradeIds.length; i += BATCH_SIZE) {
            const batch = tradeIds.slice(i, i + BATCH_SIZE);
            if (statusEl) statusEl.textContent = `Checking ${i + batch.length}/${tradeIds.length}…`;

            const resp = await fetch(
                `${currentUrl}?ajax=1&check_streak=1`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `min_streak=${minStreak}&trade_ids=${batch.join(',')}`,
                    credentials: 'same-origin',
                    signal
                }
            );

            const text = await resp.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('[streakFilter] JSON parse error. Raw response:', text.substring(0, 300));
                if (statusEl) statusEl.textContent = 'Server error';
                return;
            }

            if (!data.success) {
                console.error('[streakFilter] server error:', data.error);
                if (statusEl) statusEl.textContent = 'Error';
                return;
            }

            // Log first batch in full detail for debugging
            if (i === 0) {
                const sampleKeys = Object.keys(data.results).slice(0, 3);
                const sample = {};
                sampleKeys.forEach(k => sample[k] = data.results[k]);
                console.log('[streakFilter] Full detail of first 3 results:', JSON.stringify(sample, null, 2));
                console.log('[streakFilter] Total results in batch:', Object.keys(data.results).length);
            }

            Object.assign(allResults, data.results);
        }

        // Apply results: show/hide based on streak
        let shown = 0, hidden = 0;
        visibleItems.forEach(item => {
            const tid  = item.id.replace('trade-', '');
            const info = allResults[tid];

            // If hidden by filterTrades (outcome/search), leave it hidden regardless
            // We detect this by checking if item was originally visible before streak
            const hiddenByFilter = item.dataset.streakHidden !== '1' &&
                                   item.style.getPropertyValue('display') === 'none';
            if (hiddenByFilter) return;

            const meets = info && info.meets_streak;
            if (meets) {
                item.removeAttribute('data-streak-hidden');
                item.style.setProperty('display', 'block', 'important');
                shown++;
            } else {
                item.dataset.streakHidden = '1';
                item.style.setProperty('display', 'none', 'important');
                hidden++;
            }
        });

        if (statusEl) statusEl.textContent = `${shown} match`;
        console.log(`[streakFilter] min=${minStreak} shown=${shown} hidden=${hidden} total_checked=${tradeIds.length}`);
        updateSelectedCount();

        // Build stats from visible items that passed the streak filter
        renderStreakStatsPanel(minStreak);

    } catch (err) {
        if (err.name === 'AbortError') return;
        console.error('[streakFilter] fetch error:', err);
        if (statusEl) statusEl.textContent = 'Error';
    }
}

// ============================================================
// STREAK STATS PANEL
// Reads visible (streak-passing) trade items from the sidebar
// and renders a summary + per-pair table + candle pattern viz
// above the chart workspace.
// ============================================================

function removeStreakStatsPanel() {
    const old = document.getElementById('streak-stats-panel');
    if (old) old.remove();
}

function renderStreakStatsPanel(minStreak) {
    removeStreakStatsPanel();

    // Gather all visible (streak-matched) trade items
    const matchedItems = Array.from(document.querySelectorAll('.trade-item')).filter(item => {
        return item.style.getPropertyValue('display') !== 'none'
            && item.dataset.streakHidden !== '1';
    });

    if (matchedItems.length === 0) return;

    // Tally per-pair win/loss from data-result attribute
    const pairMap = {}; // { EURUSD: { wins: N, losses: N } }
    let totalWins = 0, totalLosses = 0;

    matchedItems.forEach(item => {
        const pair   = (item.querySelector('.trade-pair')?.innerText || 'UNKNOWN').toUpperCase();
        const result = (item.dataset.result || '').toLowerCase();
        if (!pairMap[pair]) pairMap[pair] = { wins: 0, losses: 0 };
        if (result === 'win')  { pairMap[pair].wins++;   totalWins++; }
        if (result === 'loss') { pairMap[pair].losses++;  totalLosses++; }
    });

    const totalTrades = matchedItems.length;
    const winRate     = totalTrades > 0 ? ((totalWins / totalTrades) * 100).toFixed(1) : '0.0';

    // Sort pairs: best win-rate first, then by total trades
    const pairList = Object.entries(pairMap).sort(([, a], [, b]) => {
        const wrA = (a.wins + a.losses) > 0 ? a.wins / (a.wins + a.losses) : 0;
        const wrB = (b.wins + b.losses) > 0 ? b.wins / (b.wins + b.losses) : 0;
        if (wrB !== wrA) return wrB - wrA;
        return (b.wins + b.losses) - (a.wins + a.losses);
    });

    // Best performing pair (highest win rate with at least 1 trade)
    const best = pairList[0];
    const bestTotal = best ? best[1].wins + best[1].losses : 0;
    const bestWR    = bestTotal > 0 ? ((best[1].wins / bestTotal) * 100).toFixed(0) : '0';

    // Candle pattern visualiser for the streak
    // Pattern: [minStreak trend candles] [1-2 opp candles] [1 confirm candle]
    // Heights vary slightly to look like real candles
    const isSell = false; // We render a generic BUY-style; colour inverted for SELL visually
    const trendHeights  = [18, 22, 26, 20, 24].slice(0, minStreak);
    const oppHeights    = [14, 12];
    const confirmHeight = 20;

    function candleHtml(heights, cls) {
        return heights.map(h =>
            `<div class="sc ${cls}" style="height:${h}px;" title="${cls.includes('trend') ? 'Trend' : cls.includes('opp') ? 'Pullback' : 'Confirm'}"></div>`
        ).join('');
    }

    const candleViz = `
        <div style="margin-top:14px; padding-top:12px; border-top:1px solid var(--border-color);">
            <div style="font-size:11px; color:var(--text-secondary); font-weight:600; margin-bottom:8px; letter-spacing:0.5px; text-transform:uppercase;">Pattern (≥${minStreak} candle streak)</div>
            <div style="display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap;">
                <div>
                    <div style="font-size:10px; color:var(--text-secondary); margin-bottom:4px;">① Trend run (≥${minStreak})</div>
                    <div class="streak-candles">
                        ${candleHtml(trendHeights, 'sc-trend')}
                        ${minStreak > 5 ? `<span style="font-size:11px;color:var(--text-secondary);align-self:center;">+${minStreak - 5} more</span>` : ''}
                    </div>
                </div>
                <div style="color:var(--text-secondary); align-self:flex-end; padding-bottom:4px; font-size:16px; font-weight:300;">→</div>
                <div>
                    <div style="font-size:10px; color:var(--text-secondary); margin-bottom:4px;">② Pullback (1–2)</div>
                    <div class="streak-candles">
                        ${candleHtml(oppHeights, 'sc-opp')}
                    </div>
                </div>
                <div style="color:var(--text-secondary); align-self:flex-end; padding-bottom:4px; font-size:16px; font-weight:300;">→</div>
                <div>
                    <div style="font-size:10px; color:var(--text-secondary); margin-bottom:4px;">③ Signal / Confirm</div>
                    <div class="streak-candles">
                        <div class="sc sc-confirm" style="height:${confirmHeight}px;" title="Signal candle (confirmation)"></div>
                    </div>
                </div>
                <div style="align-self:flex-end; padding-bottom:2px;">
                    <div class="streak-candles-label">
                        <span><span class="legend-dot" style="background:var(--accent-green);"></span> Trend</span>
                        <span><span class="legend-dot" style="background:rgba(239,68,68,0.55);"></span> Pullback</span>
                        <span><span class="legend-dot" style="background:var(--accent-green);border:1px solid #fff;"></span> Confirm</span>
                    </div>
                </div>
            </div>
            <div style="font-size:10px; color:var(--text-secondary); margin-top:8px; opacity:0.7;">
                Pullback wicks must not breach the open of the last trend candle.
            </div>
        </div>`;

    // Per-pair table rows
    const pairRows = pairList.map(([pair, d]) => {
        const tot  = d.wins + d.losses;
        const wr   = tot > 0 ? ((d.wins / tot) * 100).toFixed(0) : 0;
        const wrColor = wr >= 60 ? 'var(--accent-green)' : wr >= 40 ? '#f0a93b' : 'var(--accent-red)';
        return `
            <tr>
                <td style="font-weight:700; color:var(--text-main);">${pair}</td>
                <td style="color:var(--accent-green); font-weight:600;">${d.wins}W</td>
                <td style="color:var(--accent-red); font-weight:600;">${d.losses}L</td>
                <td>
                    <div class="wr-bar-wrap">
                        <div class="wr-bar-bg"><div class="wr-bar-fill" style="width:${wr}%; background:${wrColor};"></div></div>
                        <span class="wr-pct" style="color:${wrColor};">${wr}%</span>
                    </div>
                </td>
            </tr>`;
    }).join('');

    // ---- 30-minute resolution time buckets ----
    // Reads data-wlt="HH:MM" (IST) stamped on each trade item by trade_list_partial.php
    const timeMap = {};
    matchedItems.forEach(item => {
        const wlt = (item.dataset.wlt || '').trim();
        if (!wlt) return;
        const parts = wlt.split(':');
        if (parts.length < 2) return;
        const h = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);
        if (isNaN(h) || isNaN(m)) return;
        // Floor to 30-min slot
        const slotM = m < 30 ? 0 : 30;
        const slot  = String(h).padStart(2,'0') + ':' + String(slotM).padStart(2,'0');
        if (!timeMap[slot]) timeMap[slot] = { wins: 0, losses: 0 };
        const result = (item.dataset.result || '').toLowerCase();
        if (result === 'win')  timeMap[slot].wins++;
        if (result === 'loss') timeMap[slot].losses++;
    });

    function slotLabel(slot) {
        const [h, m] = slot.split(':').map(Number);
        const endM = m === 0 ? 30 : 0;
        const endH = m === 0 ? h : (h + 1) % 24;
        return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0')
             + '\u2013' + String(endH).padStart(2,'0') + ':' + String(endM).padStart(2,'0');
    }

    // Sort: best win rate first; ties broken by most trades (then by time string)
    const timeSlots = Object.entries(timeMap).sort(([slotA, dA], [slotB, dB]) => {
        const totA = dA.wins + dA.losses;
        const totB = dB.wins + dB.losses;
        const wrA  = totA > 0 ? dA.wins / totA : 0;
        const wrB  = totB > 0 ? dB.wins / totB : 0;
        if (wrB !== wrA) return wrB - wrA;     // higher win rate first
        if (totB !== totA) return totB - totA; // more trades first
        return slotA.localeCompare(slotB);     // earlier time first as final tie-break
    });

    let timeTableHtml = '';
    if (timeSlots.length > 0) {
        const timeRows = timeSlots.map(([slot, d]) => {
            const tot = d.wins + d.losses;
            const wr  = tot > 0 ? ((d.wins / tot) * 100).toFixed(0) : 0;
            const wrColor = wr >= 60 ? 'var(--accent-green)' : wr >= 40 ? '#f0a93b' : 'var(--accent-red)';
            return `
                <tr>
                    <td style="font-weight:700; color:var(--text-main); white-space:nowrap;">${slotLabel(slot)} <span style="font-size:9px;color:var(--text-secondary);">IST</span></td>
                    <td style="color:var(--accent-green); font-weight:600;">${d.wins}W</td>
                    <td style="color:var(--accent-red); font-weight:600;">${d.losses}L</td>
                    <td>
                        <div class="wr-bar-wrap">
                            <div class="wr-bar-bg"><div class="wr-bar-fill" style="width:${wr}%;background:${wrColor};"></div></div>
                            <span class="wr-pct" style="color:${wrColor};">${wr}%</span>
                        </div>
                    </td>
                </tr>`;
        }).join('');

        timeTableHtml = `
        <div style="margin-top:14px; padding-top:12px; border-top:1px solid var(--border-color);">
            <div style="font-size:11px; color:var(--text-secondary); font-weight:600; margin-bottom:8px; letter-spacing:0.5px; text-transform:uppercase;">
                ⏱ Resolution Time Distribution (30-min slots)
            </div>
            <table class="streak-pair-table">
                <thead>
                    <tr>
                        <th>Time (IST)</th>
                        <th>Wins</th>
                        <th>Losses</th>
                        <th>Win Rate</th>
                    </tr>
                </thead>
                <tbody>${timeRows}</tbody>
            </table>
        </div>`;
    }

    const panel = document.createElement('div');
    panel.id = 'streak-stats-panel';
    panel.innerHTML = `
        <div class="streak-stats-header">
            <div class="streak-stats-title">
                📊 Streak Filter Results
                <span class="streak-stats-badge">≥${minStreak} candle pattern</span>
            </div>
            ${best ? `<div style="font-size:12px; color:var(--text-secondary);">
                Best Pair: <strong style="color:#f0a93b;">${best[0]} (${best[1].wins}W / ${best[1].losses}L)</strong>
            </div>` : ''}
        </div>

        <div class="streak-summary-row">
            <div class="streak-stat-box">
                <div class="val val-total">${totalTrades}</div>
                <div class="lbl">Matched</div>
            </div>
            <div class="streak-stat-box">
                <div class="val val-win">${totalWins}</div>
                <div class="lbl">Wins</div>
            </div>
            <div class="streak-stat-box">
                <div class="val val-loss">${totalLosses}</div>
                <div class="lbl">Losses</div>
            </div>
            <div class="streak-stat-box">
                <div class="val val-wr">${winRate}%</div>
                <div class="lbl">Win Rate</div>
            </div>
        </div>

        ${pairList.length > 0 ? `
        <table class="streak-pair-table">
            <thead>
                <tr>
                    <th>Pair</th>
                    <th>Wins</th>
                    <th>Losses</th>
                    <th>Win Rate</th>
                </tr>
            </thead>
            <tbody>${pairRows}</tbody>
        </table>` : ''}

        ${timeTableHtml}

        ${candleViz}
    `;

    // Insert panel above the .workspace div
    const workspace = document.querySelector('.workspace');
    if (workspace) {
        workspace.parentNode.insertBefore(panel, workspace);
    }
}

// Load trade directly by entering ID input
function loadTradeByIdInput() {
    const idInput = document.getElementById('search-id');
    const id = parseInt(idInput.value);
    if (isNaN(id) || id <= 0) {
        alert('Please enter a valid numeric Trade ID.');
        return;
    }
    loadTrade(id);
}

// Load and render trade chart (displays server-side GD-rendered PNG)
function loadTrade(id) {
    // Highlight active item
    document.querySelectorAll('.trade-item').forEach(item => item.classList.remove('active'));
    const tradeItem = document.getElementById(`trade-${id}`);
    if (tradeItem) {
        tradeItem.classList.add('active');
    }

    // Show loading
    const overlay = document.getElementById('loading-overlay');
    overlay.style.display = 'flex';
    overlay.innerHTML = "Loading Chart Data...";
    // null-guard: #chart-placeholder is wiped from the DOM after the first trade
    // loads (container.innerHTML replaces everything), so guard against null here.
    const placeholder = document.getElementById('chart-placeholder');
    if (placeholder) placeholder.style.display = 'none';

    activeTradeId = id;

    // Fetch trade metadata from AJAX endpoint (also generates GD image on server)
    const currentUrl = window.location.pathname;
    fetch(`${currentUrl}?ajax=1&trade_id=${id}`, { credentials: 'same-origin' })
        .then(res => {
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            return res.json();
        })
        .then(data => {
            overlay.style.display = 'none';
            if (data.error) {
                alert(data.error);
                return;
            }

            activeTradeData = data;

            // Update UI header bar
            document.getElementById('active-pair').innerText = data.pair;
            const dirLabel = data.direction === 'UP' ? 'BUY' : 'SELL';

            // Update sidebar candle count badge for this trade
            if (data.candleCount !== null && data.candleCount !== undefined) {
                const badge = document.getElementById(`candle-badge-${id}`);
                if (badge) {
                    const isWin  = data.tradeResult === 'win';
                    const isLoss = data.tradeResult === 'loss';
                    const badgeColor = isWin ? 'var(--accent-green)' : isLoss ? 'var(--accent-red)' : 'var(--text-secondary)';
                    badge.textContent = `${data.candleCount}c`;
                    badge.title = `Resolved in ${data.candleCount} candle${data.candleCount !== 1 ? 's' : ''} (signal → resolution, inclusive)`;
                    badge.style.cssText = `
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 10px;
                        font-weight: 700;
                        color: ${badgeColor};
                        background: transparent;
                        border: 1px solid ${badgeColor};
                        border-radius: 4px;
                        padding: 1px 5px;
                        margin-left: auto;
                        letter-spacing: 0.3px;
                        opacity: 0.9;
                        flex-shrink: 0;
                    `;
                    // Also store on the item for potential future use
                    const tradeItemEl = document.getElementById(`trade-${id}`);
                    if (tradeItemEl) tradeItemEl.dataset.candleCount = data.candleCount;
                }
            }

            let outcomeHtml = '';
            if (data.tradeResult) {
                const outcomeClean = data.tradeResult.toUpperCase().replace(/_/g, ' ');
                let badgeClass = 'badge-pending';
                if (data.tradeResult === 'win') badgeClass = 'badge-win';
                else if (data.tradeResult === 'loss') badgeClass = 'badge-loss';
                else if (data.tradeResult === 'setup_not_formed') badgeClass = 'badge-setup_not_formed';

                outcomeHtml = `&nbsp;&nbsp;Outcome: <span class="badge ${badgeClass}">${outcomeClean}</span>`;
                if (data.winLossPrice) {
                    outcomeHtml += `&nbsp;&nbsp;Res. Price: <strong>${parseFloat(data.winLossPrice).toFixed(5)}</strong>`;
                }
                if (data.winLossTime) {
                    outcomeHtml += `&nbsp;&nbsp;Res. Time: <strong>${data.winLossTime} IST</strong>`;
                }
                // Candle count in header — only if resolved
                if (data.candleCount !== null && data.candleCount !== undefined) {
                    const ccColor = data.tradeResult === 'win' ? 'var(--accent-green)' : data.tradeResult === 'loss' ? 'var(--accent-red)' : 'var(--text-secondary)';
                    outcomeHtml += `&nbsp;&nbsp;<span title="Candles from signal to resolution (inclusive, counted from chart timestamps)" style="display:inline-flex;align-items:center;gap:3px;font-size:12px;">🕯️ <strong style="color:${ccColor};">${data.candleCount}c</strong> <span style="color:var(--text-secondary);font-size:11px;">to resolve</span></span>`;
                }
            }

            let newsHtml = '';
            if (data.nearbyNews) {
                const n = data.nearbyNews;
                const badgeClass = n.impact >= 3 ? 'badge-news-3' : (n.impact === 2 ? 'badge-news-2' : 'badge-news-1');
                const offset = n.offset_minutes;
                const offsetLabel = offset === 0 ? 'at resolution' : (offset > 0 ? `+${offset}m` : `${offset}m`);
                newsHtml = `<div style="flex-basis: 100%; margin-top: 6px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">📰 Highest-impact news within 15m: <span class="badge ${badgeClass}" title="${n.event_time_ist} IST">${n.event_name} [Impact ${n.impact}] (${offsetLabel})</span></div>`;
            }

            document.getElementById('active-meta').innerHTML = `
                <span class="trade-direction ${data.direction === 'UP' ? 'direction-up' : 'direction-down'}">${dirLabel}</span>
                &nbsp;&nbsp;Alert Time: <strong>${data.alertTimeIST} IST</strong>
                &nbsp;&nbsp;Target Price: <strong>${parseFloat(data.targetPrice).toFixed(5)}</strong>
                ${outcomeHtml}
                ${newsHtml}
            `;
            document.getElementById('chart-actions').style.display = 'flex';

            // Display the server-rendered GD chart image
            const container = document.getElementById('chart-container');
            const imgUrl = `${currentUrl}?get_chart_image=1&trade_id=${id}&t=${Date.now()}`;
            container.innerHTML = `<img src="${imgUrl}" alt="Trade #${id} Chart" style="width: 100%; height: 100%; object-fit: contain; display: block;">` +
                                  `<div id="loading-overlay" style="display: none;">Loading Chart Data...</div>`;
        })
        .catch(err => {
            overlay.style.display = 'none';
            alert('Failed to load chart data. Make sure the dataset CSV file is uploaded on the server.');
            console.error(err);
        });
}
// ============================================================
// CANDLE COUNT TABLE
// Fetches candle counts for ALL currently visible trades in
// one batch POST, then renders a sortable, filterable table
// in a modal overlay. Counts come from CSV timestamps —
// not DB timestamps — so late-recorded resolutions don't skew.
// ============================================================

let _candleTableData = [];   // cache last fetched results
let _candleTableSort = { col: 'candle_count', dir: 'asc' };

function removeCandleTable() {
    const old = document.getElementById('candle-count-modal');
    if (old) old.remove();
}

async function showCandleCountTable() {
    removeCandleTable();

    // Collect ALL trade items (not just visible — user may want full table)
    // but respect current search/outcome filter for relevance
    const allItems = Array.from(document.querySelectorAll('.trade-item'));
    const visibleItems = allItems.filter(item => item.style.getPropertyValue('display') !== 'none');

    if (visibleItems.length === 0) {
        alert('No trades visible. Clear filters first.');
        return;
    }

    const tradeIds = visibleItems.map(item => item.id.replace('trade-', '')).filter(Boolean);

    // Build + show modal shell immediately
    const modal = document.createElement('div');
    modal.id = 'candle-count-modal';
    modal.innerHTML = `
        <div id="ccm-backdrop" onclick="removeCandleTable()" style="
            position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9998;
        "></div>
        <div id="ccm-panel" style="
            position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
            z-index:9999; width:min(820px,95vw); max-height:82vh;
            background:var(--bg-card); border:1px solid var(--border-color);
            border-radius:12px; display:flex; flex-direction:column;
            box-shadow:0 24px 60px rgba(0,0,0,0.5); font-family:'Outfit',sans-serif;
        ">
            <div style="
                display:flex; align-items:center; justify-content:space-between;
                padding:14px 18px; border-bottom:1px solid var(--border-color);
                flex-shrink:0;
            ">
                <div style="font-size:15px; font-weight:700; color:var(--text-main);">
                    🕯️ Candle Count Table
                    <span style="font-size:11px; font-weight:400; color:var(--text-secondary); margin-left:8px;">
                        signal → resolution (inclusive, from CSV timestamps)
                    </span>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <input type="text" id="ccm-search" placeholder="Filter pair…"
                        oninput="renderCandleTable()"
                        style="padding:5px 10px; border-radius:6px; border:1px solid var(--border-color);
                               background:var(--bg-input,#1e293b); color:var(--text-main); font-size:12px; width:120px;">
                    <select id="ccm-result-filter" onchange="renderCandleTable()"
                        style="padding:5px 8px; border-radius:6px; border:1px solid var(--border-color);
                               background:var(--bg-input,#1e293b); color:var(--text-main); font-size:12px;">
                        <option value="all">All</option>
                        <option value="win">Wins</option>
                        <option value="loss">Losses</option>
                    </select>
                    <button onclick="exportCandleTableCsv()"
                        style="padding:5px 12px; border-radius:6px; border:1px solid var(--accent-blue);
                               background:rgba(59,130,246,0.12); color:var(--accent-blue);
                               font-size:12px; cursor:pointer; font-family:'Outfit',sans-serif;">
                        ⬇ CSV
                    </button>
                    <button onclick="removeCandleTable()"
                        style="padding:5px 10px; border-radius:6px; border:1px solid var(--border-color);
                               background:transparent; color:var(--text-secondary);
                               font-size:13px; cursor:pointer; line-height:1;">✕</button>
                </div>
            </div>
            <div id="ccm-stats" style="
                padding:10px 18px; font-size:12px; color:var(--text-secondary);
                border-bottom:1px solid var(--border-color); flex-shrink:0;
            ">Fetching candle counts for ${tradeIds.length} trades…</div>
            <div id="ccm-body" style="overflow-y:auto; flex:1; padding:0 0 4px 0;">
                <div style="text-align:center; padding:40px; color:var(--text-secondary); font-size:13px;">
                    ⏳ Loading from CSV data…
                </div>
            </div>
        </div>`;
    document.body.appendChild(modal);

    // Fetch in batches of 100
    const BATCH = 100;
    const currentUrl = window.location.pathname;
    const allResults = {};

    try {
        for (let i = 0; i < tradeIds.length; i += BATCH) {
            const batch = tradeIds.slice(i, i + BATCH);
            document.getElementById('ccm-stats').textContent =
                `Fetching ${Math.min(i + BATCH, tradeIds.length)} / ${tradeIds.length} trades…`;

            const resp = await fetch(`${currentUrl}?ajax=1&get_candle_counts=1`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `trade_ids=${batch.join(',')}`,
                credentials: 'same-origin'
            });
            const data = await resp.json();
            if (data.results) Object.assign(allResults, data.results);
        }
    } catch (err) {
        document.getElementById('ccm-body').innerHTML =
            `<div style="text-align:center;padding:30px;color:var(--accent-red);">❌ Fetch error: ${err.message}</div>`;
        return;
    }

    // Store for sort/filter/export
    _candleTableData = Object.entries(allResults).map(([id, r]) => ({
        id: parseInt(id),
        pair:         r.pair        || '—',
        direction:    r.direction   || '—',
        trade_result: r.trade_result || 'pending',
        alert_time:   r.alert_time_ist || '—',
        res_time:     r.res_time_ist   || '—',
        candle_count: r.candle_count,   // null = pending/no CSV
    }));

    renderCandleTable();
}

function renderCandleTable() {
    const searchVal  = (document.getElementById('ccm-search')?.value || '').toUpperCase();
    const resultVal  = document.getElementById('ccm-result-filter')?.value || 'all';
    const { col, dir } = _candleTableSort;

    // Filter
    let rows = _candleTableData.filter(r => {
        const matchPair   = !searchVal || r.pair.toUpperCase().includes(searchVal);
        const matchResult = resultVal === 'all' || r.trade_result === resultVal;
        return matchPair && matchResult;
    });

    // Sort
    rows.sort((a, b) => {
        let va = a[col], vb = b[col];
        if (col === 'candle_count') {
            va = va === null ? 9999 : va;
            vb = vb === null ? 9999 : vb;
        }
        if (typeof va === 'string') va = va.toLowerCase();
        if (typeof vb === 'string') vb = vb.toLowerCase();
        if (va < vb) return dir === 'asc' ? -1 : 1;
        if (va > vb) return dir === 'asc' ?  1 : -1;
        return 0;
    });

    // Summary stats
    const resolved  = rows.filter(r => r.candle_count !== null);
    const wins      = resolved.filter(r => r.trade_result === 'win');
    const losses    = resolved.filter(r => r.trade_result === 'loss');
    const avgAll    = resolved.length ? (resolved.reduce((s, r) => s + r.candle_count, 0) / resolved.length).toFixed(1) : '—';
    const avgWin    = wins.length    ? (wins.reduce((s, r) => s + r.candle_count, 0) / wins.length).toFixed(1)       : '—';
    const avgLoss   = losses.length  ? (losses.reduce((s, r) => s + r.candle_count, 0) / losses.length).toFixed(1)   : '—';
    const minC      = resolved.length ? Math.min(...resolved.map(r => r.candle_count)) : '—';
    const maxC      = resolved.length ? Math.max(...resolved.map(r => r.candle_count)) : '—';

    const statsEl = document.getElementById('ccm-stats');
    if (statsEl) statsEl.innerHTML = `
        <span style="margin-right:16px;">Showing <strong>${rows.length}</strong> trades (${resolved.length} resolved)</span>
        <span style="margin-right:16px; color:var(--text-main);">Avg candles: <strong>${avgAll}</strong></span>
        <span style="margin-right:16px; color:var(--accent-green);">Win avg: <strong>${avgWin}</strong></span>
        <span style="margin-right:16px; color:var(--accent-red);">Loss avg: <strong>${avgLoss}</strong></span>
        <span style="color:var(--text-secondary);">Range: ${minC} – ${maxC}</span>
    `;

    // Sort arrow helper
    const arr = (c) => col === c ? (dir === 'asc' ? ' ▲' : ' ▼') : ' ⇅';
    const thStyle = `padding:8px 12px; text-align:left; font-size:11px; font-weight:700;
                     color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;
                     border-bottom:1px solid var(--border-color); cursor:pointer;
                     white-space:nowrap; background:var(--bg-card); position:sticky; top:0; z-index:1;`;

    function sortBy(c) {
        if (_candleTableSort.col === c) {
            _candleTableSort.dir = _candleTableSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            _candleTableSort.col = c;
            _candleTableSort.dir = c === 'candle_count' ? 'asc' : 'asc';
        }
        renderCandleTable();
    }

    const tableRows = rows.map((r, idx) => {
        const dirLabel  = r.direction.toUpperCase() === 'UP' ? 'BUY' : 'SELL';
        const dirColor  = r.direction.toUpperCase() === 'UP' ? 'var(--accent-green)' : 'var(--accent-red)';
        const resColor  = r.trade_result === 'win' ? 'var(--accent-green)' : r.trade_result === 'loss' ? 'var(--accent-red)' : 'var(--text-secondary)';
        const resLabel  = r.trade_result.toUpperCase().replace(/_/g, ' ');
        const cc        = r.candle_count;
        const ccDisplay = cc === null ? '<span style="color:var(--text-secondary); font-size:11px;">—</span>' :
            `<span style="
                display:inline-flex; align-items:center; justify-content:center;
                min-width:32px; padding:2px 8px;
                font-size:13px; font-weight:800;
                color:${resColor};
                background:${r.trade_result === 'win' ? 'rgba(16,185,129,0.08)' : r.trade_result === 'loss' ? 'rgba(239,68,68,0.08)' : 'transparent'};
                border:1px solid ${resColor};
                border-radius:6px;">
                ${cc}
            </span>`;

        const rowBg = idx % 2 === 0 ? 'transparent' : 'rgba(255,255,255,0.02)';

        return `<tr style="background:${rowBg}; cursor:pointer;" onclick="loadTrade(${r.id}); removeCandleTable();"
                    onmouseover="this.style.background='rgba(59,130,246,0.07)'"
                    onmouseout="this.style.background='${rowBg}'">
            <td style="padding:7px 12px; font-size:12px; color:var(--text-secondary);">#${r.id}</td>
            <td style="padding:7px 12px; font-size:13px; font-weight:700; color:var(--text-main);">${r.pair}</td>
            <td style="padding:7px 12px;"><span style="font-size:11px; font-weight:700; color:${dirColor};">${dirLabel}</span></td>
            <td style="padding:7px 12px; font-size:11px; color:var(--text-secondary); white-space:nowrap;">${r.alert_time}</td>
            <td style="padding:7px 12px; font-size:11px; color:var(--text-secondary); white-space:nowrap;">${r.res_time || '—'}</td>
            <td style="padding:7px 12px; text-align:center;">${ccDisplay}</td>
            <td style="padding:7px 12px;"><span style="font-size:11px; font-weight:700; color:${resColor};">${resLabel}</span></td>
        </tr>`;
    }).join('');

    const bodyEl = document.getElementById('ccm-body');
    if (!bodyEl) return;

    bodyEl.innerHTML = rows.length === 0
        ? `<div style="text-align:center;padding:40px;color:var(--text-secondary);font-size:13px;">No trades match the current filter.</div>`
        : `<table style="width:100%; border-collapse:collapse; font-family:'Outfit',sans-serif;">
            <thead>
                <tr>
                    <th style="${thStyle}" onclick="sortBy('id')">ID${arr('id')}</th>
                    <th style="${thStyle}" onclick="sortBy('pair')">Pair${arr('pair')}</th>
                    <th style="${thStyle}" onclick="sortBy('direction')">Dir${arr('direction')}</th>
                    <th style="${thStyle}" onclick="sortBy('alert_time')">Alert (IST)${arr('alert_time')}</th>
                    <th style="${thStyle}" onclick="sortBy('res_time')">Resolution (IST)${arr('res_time')}</th>
                    <th style="${thStyle} text-align:center;" onclick="sortBy('candle_count')">Candles${arr('candle_count')}</th>
                    <th style="${thStyle}" onclick="sortBy('trade_result')">Result${arr('trade_result')}</th>
                </tr>
            </thead>
            <tbody>${tableRows}</tbody>
          </table>`;
}

function exportCandleTableCsv() {
    if (!_candleTableData.length) return;
    const searchVal = (document.getElementById('ccm-search')?.value || '').toUpperCase();
    const resultVal = document.getElementById('ccm-result-filter')?.value || 'all';

    const rows = _candleTableData.filter(r => {
        const matchPair   = !searchVal || r.pair.toUpperCase().includes(searchVal);
        const matchResult = resultVal === 'all' || r.trade_result === resultVal;
        return matchPair && matchResult;
    });

    const header = ['ID', 'Pair', 'Direction', 'Alert Time (IST)', 'Resolution Time (IST)', 'Candle Count', 'Result'];
    const csvLines = [
        header.join(','),
        ...rows.map(r => [
            r.id,
            r.pair,
            r.direction.toUpperCase() === 'UP' ? 'BUY' : 'SELL',
            r.alert_time,
            r.res_time || '',
            r.candle_count ?? '',
            r.trade_result
        ].join(','))
    ];

    const blob = new Blob([csvLines.join('\n')], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `candle_counts_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
}