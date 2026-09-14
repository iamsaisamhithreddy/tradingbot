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

// Initialize the selection counter and automated broadcast runner
document.addEventListener('DOMContentLoaded', () => {
    updateSelectedCount();

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

// Filter trades in sidebar (pair search text box + Wins/Losses outcome dropdown)
// Active pill outcome value ('all' | 'win' | 'loss' | 'setup_not_formed')
let _activeOutcome = 'all';

function setOutcomeFilter(btn) {
    _activeOutcome = btn.dataset.outcome;
    document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterTrades();
}

function filterTrades() {
    const query      = (document.getElementById('search')?.value || '').toUpperCase();
    const outcomeVal = _activeOutcome;
    const items      = document.querySelectorAll('.trade-item');
    let shown = 0, hidden = 0;

    items.forEach(item => {
        const pairText    = (item.querySelector('.trade-pair')?.innerText || '').toUpperCase();
        const itemResult  = (item.dataset.result || '').toLowerCase();
        const matchSearch  = pairText.includes(query);
        const matchOutcome = (outcomeVal === 'all') || (itemResult === outcomeVal);
        const shouldShow   = matchSearch && matchOutcome;
        shouldShow ? shown++ : hidden++;
        item.style.setProperty('display', shouldShow ? 'block' : 'none', 'important');
    });

    console.log(`[filterTrades] outcome=${outcomeVal} search="${query}" shown=${shown} hidden=${hidden}`);
    updateSelectedCount();
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
    document.getElementById('chart-placeholder').style.display = 'none';

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
