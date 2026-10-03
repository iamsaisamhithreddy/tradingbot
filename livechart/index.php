<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Multi-Chart Terminal</title>
<script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@400;700;800&display=swap" rel="stylesheet"/>
<style>
:root {
  --bg:#0a0c0f; --surface:#111418; --border:#1e2530; --border2:#2a3340;
  --accent:#00e5a0; --accent2:#0090ff; --up:#00c896; --down:#ff4d6a;
  --alert:#ffb703; --text:#e2e8f0; --muted:#5a6a7e;
  --mono:'JetBrains Mono',monospace; --display:'Syne',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--mono);height:100vh;overflow:hidden;display:flex;flex-direction:column}

/* HEADER */
.header{display:flex;align-items:center;justify-content:space-between;padding:8px 16px;background:var(--surface);border-bottom:1px solid var(--border);gap:12px;flex-shrink:0;height:48px}
.logo{font-family:var(--display);font-size:16px;font-weight:800;color:var(--accent);letter-spacing:-.5px;white-space:nowrap}
.logo span{color:var(--muted);font-weight:400}
.header-controls{display:flex;align-items:center;gap:10px}
.pair-add-btn{display:flex;align-items:center;gap:6px;padding:5px 12px;background:transparent;border:1px solid var(--border2);border-radius:5px;color:var(--muted);font-family:var(--mono);font-size:11px;cursor:pointer;transition:all .15s}
.pair-add-btn:hover{border-color:var(--accent);color:var(--accent)}
.intervals,.layout-btns{display:flex;gap:3px}
.iv-btn,.layout-btn{padding:4px 9px;background:transparent;border:1px solid var(--border2);border-radius:4px;color:var(--muted);font-family:var(--mono);font-size:11px;cursor:pointer;transition:all .15s}
.iv-btn:hover{border-color:var(--accent);color:var(--accent)}
.iv-btn.active{background:var(--accent);border-color:var(--accent);color:#000;font-weight:700}
.layout-btn:hover{border-color:var(--accent2);color:var(--accent2)}
.layout-btn.active{background:var(--accent2);border-color:var(--accent2);color:#fff;font-weight:700}

/* WORKSPACE */
.workspace{display:flex;flex:1;overflow:hidden}
.grid-area{flex:1;overflow:hidden}
.chart-grid{display:grid;width:100%;height:100%;gap:1px;background:var(--border)}
.layout-1x1{grid-template-columns:1fr;grid-template-rows:1fr}
.layout-1x2{grid-template-columns:1fr 1fr;grid-template-rows:1fr}
.layout-2x2{grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr}
.layout-2x3{grid-template-columns:1fr 1fr 1fr;grid-template-rows:1fr 1fr}
.layout-3x3{grid-template-columns:1fr 1fr 1fr;grid-template-rows:1fr 1fr 1fr}

/* CHART CELL */
.chart-cell{position:relative;background:var(--bg);overflow:hidden;min-height:80px;border:1.5px solid transparent;transition:border .15s}
.chart-cell:hover{border-color:var(--border2)}
.cell-header{position:absolute;top:0;left:0;right:0;z-index:10;display:flex;align-items:center;padding:5px 8px;background:rgba(10,12,15,.85);backdrop-filter:blur(4px);border-bottom:1px solid var(--border);pointer-events:none;gap:6px}
.cell-pair{font-size:11px;font-weight:700;color:var(--text)}
.cell-price{font-size:11px;font-weight:600;transition:color .3s}
.cell-price.up{color:var(--up)}.cell-price.down{color:var(--down)}
.cell-change{font-size:9px;padding:1px 5px;border-radius:3px}
.cell-change.up{color:var(--up);background:rgba(0,200,150,.12)}.cell-change.down{color:var(--down);background:rgba(255,77,106,.12)}
.cell-iv-badge{font-size:9px;color:var(--muted);padding:1px 5px;border:1px solid var(--border2);border-radius:3px;margin-left:auto}
.cell-actions{position:absolute;top:5px;right:6px;z-index:20;display:flex;align-items:center;gap:4px;opacity:0;transition:opacity .15s}
.chart-cell:hover .cell-actions{opacity:1}
.cell-btn{width:22px;height:22px;border-radius:3px;border:1px solid var(--border2);background:rgba(17,20,24,.9);color:var(--muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .15s;pointer-events:all}
.cell-btn:hover{border-color:var(--accent);color:var(--accent)}
.cell-btn.expand-btn{border-color:rgba(0,229,160,.4);color:var(--accent);font-size:13px}
.cell-btn.expand-btn:hover{background:rgba(0,229,160,.12)}
.cell-btn.close-btn:hover{border-color:var(--down);color:var(--down)}
.cell-chart{width:100%;height:100%;padding-top:30px}
.cell-status{position:absolute;bottom:6px;left:8px;z-index:10;display:flex;align-items:center;gap:4px;font-size:9px;color:var(--muted);pointer-events:none}
.dot{width:5px;height:5px;border-radius:50%;background:var(--muted)}
.dot.live{background:var(--accent);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.ohlc-box{position:absolute;top:36px;left:8px;z-index:15;background:rgba(10,12,15,.92);backdrop-filter:blur(6px);border:1px solid var(--border2);border-radius:5px;padding:7px 10px;font-size:10px;line-height:1.8;pointer-events:none;display:none;min-width:180px}
.ohlc-box .ohlc-time{color:var(--muted);font-size:9px;margin-bottom:3px}
.ohlc-row{display:flex;justify-content:space-between;gap:12px}
.ohlc-lbl{color:var(--muted)}.ohlc-val{font-weight:600}
.ohlc-val.up{color:var(--up)}.ohlc-val.down{color:var(--down)}
.candle-timer-badge{position:absolute;bottom:6px;right:6px;z-index:10;background:rgba(10,12,15,.85);border:1px solid var(--border2);color:var(--accent2);padding:3px 7px;border-radius:3px;font-size:10px;font-weight:700;pointer-events:none}

/* TV SIDEBAR */
.tv-sidebar{width:0;overflow:hidden;background:var(--surface);border-left:1px solid var(--border);transition:width .3s ease;display:flex;flex-direction:column;flex-shrink:0}
.tv-sidebar.open{width:480px}
.tv-header{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid var(--border);flex-shrink:0;height:36px}
.tv-header-label{font-size:10px;color:var(--accent2);text-transform:uppercase;letter-spacing:1px;font-weight:700}
.tv-close-btn{background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:2px 6px;border-radius:3px}
.tv-close-btn:hover{color:var(--down)}
.tv-pair-label{font-size:11px;color:var(--text);font-weight:600}
.tv-iframe-wrap{flex:1;overflow:hidden;position:relative}
.tv-iframe-wrap iframe{width:100%;height:100%;border:none}
.tv-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;background:var(--bg);transition:opacity .3s}
.tv-loading.hidden{opacity:0;pointer-events:none}
.spinner{width:28px;height:28px;border:2px solid var(--border2);border-top-color:var(--accent2);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.tv-loading-text{font-size:10px;color:var(--muted)}

/* FULLSCREEN OVERLAY */
#fullscreen-overlay{display:none;position:fixed;top:48px;left:0;right:0;bottom:0;z-index:500;background:var(--bg);flex-direction:row}
#fullscreen-overlay.open{display:flex}
#fs-chart-wrap{flex:1;position:relative;border-right:1px solid var(--border);overflow:hidden;min-width:0;display:flex;flex-direction:column}
#fs-topbar{position:absolute;top:0;left:0;right:0;z-index:20;display:flex;align-items:center;gap:10px;padding:6px 10px;background:rgba(10,12,15,.92);backdrop-filter:blur(6px);border-bottom:1px solid var(--border);height:44px}
#fs-pair-label{font-size:13px;font-weight:700;color:var(--text)}
#fs-price{font-size:13px;font-weight:700}
#fs-price.up{color:var(--up)}#fs-price.down{color:var(--down)}
#fs-change{font-size:11px;padding:2px 6px;border-radius:3px}
#fs-change.up{color:var(--up);background:rgba(0,200,150,.12)}
#fs-change.down{color:var(--down);background:rgba(255,77,106,.12)}
.fs-topbar-btn{background:transparent;border:1px solid var(--border2);color:var(--muted);cursor:pointer;border-radius:4px;padding:3px 9px;font-family:var(--mono);font-size:11px;transition:all .15s;white-space:nowrap}
.fs-topbar-btn:hover{border-color:var(--accent);color:var(--accent)}
.fs-topbar-btn.active{border-color:#a78bfa;color:#a78bfa;background:rgba(167,139,250,.1)}
#fs-close-btn:hover{border-color:var(--down)!important;color:var(--down)!important}

/* CANDLE CLOCK */
#fs-candle-clock{display:flex;align-items:center;gap:10px;padding:0 12px;border-left:1px solid var(--border2);border-right:1px solid var(--border2)}
.clock-ring{position:relative;width:38px;height:38px;flex-shrink:0}
.clock-ring svg{transform:rotate(-90deg)}
.clock-ring-bg{fill:none;stroke:var(--border2);stroke-width:3.5}
.clock-ring-fill{fill:none;stroke-width:3.5;stroke-linecap:round;stroke-dasharray:100.5;stroke-dashoffset:0;transition:stroke-dashoffset 1s linear,stroke .5s}
.clock-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.clock-mm{font-size:12px;font-weight:800;line-height:1;font-family:var(--display)}
.clock-ss{font-size:8px;font-weight:600;line-height:1;color:var(--muted)}
.clock-meta{display:flex;flex-direction:column;gap:1px}
.clock-label{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
.clock-interval-txt{font-size:11px;font-weight:700;color:var(--text)}
.clk-green .clock-ring-fill{stroke:var(--accent)}.clk-green .clock-mm{color:var(--accent)}
.clk-orange .clock-ring-fill{stroke:#ffb703}.clk-orange .clock-mm{color:#ffb703}
.clk-red .clock-ring-fill{stroke:var(--down)}.clk-red .clock-mm{color:var(--down)}

/* FS INNER CHART AREA */
#fs-chart-inner{display:flex;flex-direction:column;flex:1;margin-top:44px;overflow:hidden;min-height:0}
#fs-chart-container{flex:1;min-height:0;width:100%}

/* RSI PANEL */
#fs-rsi-panel{height:160px;flex-shrink:0;border-top:2px solid var(--border2);position:relative;background:var(--bg);overflow:hidden;display:none}
#fs-rsi-panel.rsi-open{display:block}
#fs-rsi-container{width:100%;height:100%;padding-top:24px}
#fs-rsi-header{position:absolute;top:0;left:0;right:0;z-index:10;display:flex;align-items:center;gap:8px;padding:3px 10px;background:rgba(10,12,15,.85);border-bottom:1px solid var(--border);height:24px}
.rsi-badge{font-size:10px;font-weight:700;color:#a78bfa}
.rsi-period-badge{font-size:9px;color:var(--muted);border:1px solid var(--border2);border-radius:3px;padding:1px 5px}
#rsi-live-val{font-size:11px;font-weight:700}
#rsi-live-val.ob{color:var(--down)}#rsi-live-val.os{color:var(--up)}#rsi-live-val.mid{color:var(--text)}
.rsi-level-hint{font-size:9px;color:#333;margin-left:4px}
#rsi-settings-btn{margin-left:auto;background:transparent;border:1px solid var(--border2);color:var(--muted);cursor:pointer;border-radius:3px;padding:1px 7px;font-family:var(--mono);font-size:9px;transition:all .15s;pointer-events:all}
#rsi-settings-btn:hover{border-color:var(--accent2);color:var(--accent2)}

/* RSI SETTINGS */
#rsi-settings-dialog{
  display:none;position:fixed;z-index:900;
  top:140px;left:calc(50% - 150px);
  background:var(--surface);border:1px solid var(--border2);
  border-radius:10px;width:300px;
  box-shadow:0 20px 60px rgba(0,0,0,.8);
}
#rsi-settings-dialog.dlg-open{display:block}
#rsi-dlg-titlebar{
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 14px;background:rgba(255,255,255,.04);
  border-bottom:1px solid var(--border2);border-radius:10px 10px 0 0;
  cursor:grab;
}
#rsi-dlg-titlebar:active{cursor:grabbing}
.dlg-title{font-size:12px;font-weight:700;color:var(--text);letter-spacing:.5px}
.dlg-title-sub{font-size:10px;color:var(--muted);font-weight:400;margin-left:6px}
.dlg-close-btn{background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px;line-height:1;padding:0 2px;transition:color .15s}
.dlg-close-btn:hover{color:var(--down)}
#rsi-dlg-body{padding:16px 14px;display:flex;flex-direction:column;gap:12px}
.dlg-section{font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:-4px}
.dlg-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
.dlg-label{font-size:12px;color:var(--text);display:flex;align-items:center;gap:8px}
.swatch{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.swatch-purple{background:#a78bfa}
.swatch-yellow{background:#eab308}
.swatch-red{background:var(--down)}
.swatch-green{background:var(--up)}
.dlg-input{
  width:76px;background:var(--bg);border:1px solid var(--border2);
  border-radius:5px;color:var(--text);font-family:var(--mono);
  font-size:12px;padding:6px 8px;outline:none;text-align:center;
  transition:border-color .15s;
}
.dlg-input:focus{border-color:var(--accent2)}
.dlg-divider{height:1px;background:var(--border)}
#rsi-dlg-footer{
  padding:10px 14px;border-top:1px solid var(--border);
  display:flex;gap:8px;justify-content:flex-end;border-radius:0 0 10px 10px;
}
.dlg-btn{padding:5px 16px;border-radius:5px;font-family:var(--mono);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;border:1px solid var(--border2);background:transparent;color:var(--muted)}
.dlg-btn:hover{border-color:var(--muted);color:var(--text)}
.dlg-btn.primary{background:var(--accent2);border-color:var(--accent2);color:#fff}
.dlg-btn.primary:hover{background:#0080ee}

/* FS OHLC */
#fs-ohlc{position:absolute;top:50px;left:8px;z-index:15;background:rgba(10,12,15,.92);backdrop-filter:blur(6px);border:1px solid var(--border2);border-radius:5px;padding:7px 10px;font-size:10px;line-height:1.8;pointer-events:none;display:none;min-width:180px}
#fs-ohlc .ohlc-time{color:var(--muted);font-size:9px;margin-bottom:3px}

/* FS TV */
#fs-resizer{width:4px;cursor:col-resize;background:var(--border);flex-shrink:0;transition:background .15s}
#fs-resizer:hover,#fs-resizer.dragging{background:var(--accent)}
#fs-tv-wrap{width:42%;position:relative;overflow:hidden;min-width:200px;flex-shrink:0}
#fs-tv-iframe{width:100%;height:100%;border:none;display:block}
#fs-tv-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;background:var(--bg);transition:opacity .3s}
#fs-tv-loading.hidden{opacity:0;pointer-events:none}
#fs-tv-label{position:absolute;top:6px;left:10px;z-index:5;font-size:10px;color:var(--accent2);text-transform:uppercase;letter-spacing:1px;font-weight:700;pointer-events:none}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:600;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:10px;padding:20px;min-width:320px;max-width:90vw}
.modal-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:14px}
.modal-search{width:100%;background:var(--bg);border:1px solid var(--border2);border-radius:5px;color:var(--text);font-family:var(--mono);font-size:12px;padding:7px 10px;outline:none;margin-bottom:10px}
.modal-search:focus{border-color:var(--accent)}
.modal-pairs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;max-height:260px;overflow-y:auto}
.modal-pair-btn{padding:6px 8px;background:var(--bg);border:1px solid var(--border2);border-radius:4px;color:var(--muted);font-family:var(--mono);font-size:10px;cursor:pointer;text-align:center;transition:all .15s}
.modal-pair-btn:hover{border-color:var(--accent);color:var(--accent)}
.modal-pair-btn.selected{border-color:var(--accent);color:var(--accent);background:rgba(0,229,160,.08)}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
.modal-btn{padding:6px 14px;border-radius:4px;font-family:var(--mono);font-size:11px;cursor:pointer;border:1px solid var(--border2);color:var(--muted);background:transparent}
.modal-btn.primary{background:var(--accent);border-color:var(--accent);color:#000;font-weight:700}

::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
</style>
</head>
<body>

<div class="header">
  <div class="logo">CHART<span>TERMINAL</span></div>
  <div class="header-controls">
    <div class="layout-btns" id="layout-btns">
      <button class="layout-btn" data-layout="1x1" title="1 chart">▣</button>
      <button class="layout-btn" data-layout="1x2" title="2 charts">⊞</button>
      <button class="layout-btn active" data-layout="2x2" title="4 charts">⊟</button>
      <button class="layout-btn" data-layout="2x3" title="6 charts">⊠</button>
      <button class="layout-btn" data-layout="3x3" title="9 charts">⊡</button>
    </div>
    <div style="width:1px;height:20px;background:var(--border2)"></div>
    <div class="intervals" id="global-intervals">
      <button class="iv-btn active" data-limit="4000" data-tv="5" data-label="M5">M5</button>
      <button class="iv-btn" data-limit="500" data-tv="60" data-label="1H">1H</button>
      <button class="iv-btn" data-limit="200" data-tv="240" data-label="4H">4H</button>
    </div>
    <div style="width:1px;height:20px;background:var(--border2)"></div>
    <button class="pair-add-btn" id="add-pair-btn">＋ Add Pair</button>
    <div style="display:flex;align-items:center;gap:6px;font-size:10px;color:var(--muted)">
      ⏱ <span id="header-timer" style="color:var(--accent);font-weight:700">--:--</span>
    </div>
  </div>
</div>

<div class="workspace" id="workspace">
  <div class="grid-area" id="grid-area">
    <div class="chart-grid layout-2x2" id="chart-grid"></div>
  </div>
  <div class="tv-sidebar" id="tv-sidebar">
    <div class="tv-header">
      <span class="tv-header-label">📺 TradingView</span>
      <span class="tv-pair-label" id="tv-pair-label">—</span>
      <button class="tv-close-btn" id="tv-close-btn">✕</button>
    </div>
    <div class="tv-iframe-wrap">
      <iframe id="tv-iframe" src="" title="TradingView"></iframe>
      <div class="tv-loading" id="tv-loading">
        <div class="spinner"></div>
        <div class="tv-loading-text">Loading TradingView...</div>
      </div>
    </div>
  </div>
</div>

<!-- FULLSCREEN OVERLAY -->
<div id="fullscreen-overlay">
  <div id="fs-chart-wrap">
    <div id="fs-topbar">
      <span id="fs-pair-label">—</span>
      <span id="fs-price">—</span>
      <span id="fs-change">—</span>

      <!-- CANDLE CLOCK -->
      <div id="fs-candle-clock" class="clk-green">
        <div class="clock-ring">
          <svg width="38" height="38" viewBox="0 0 38 38">
            <circle class="clock-ring-bg" cx="19" cy="19" r="16"/>
            <circle class="clock-ring-fill" id="clock-ring-fill" cx="19" cy="19" r="16"/>
          </svg>
          <div class="clock-center">
            <span class="clock-mm" id="clock-mm">05</span>
            <span class="clock-ss" id="clock-ss">00</span>
          </div>
        </div>
        <div class="clock-meta">
          <span class="clock-label">Candle closes</span>
          <span class="clock-interval-txt" id="clock-interval-txt">M5</span>
        </div>
      </div>

      <button class="fs-topbar-btn" id="rsi-toggle-btn">RSI</button>
      <button class="fs-topbar-btn" id="fs-close-btn" style="margin-left:auto">✕ Exit</button>
    </div>

    <div id="fs-chart-inner">
      <div id="fs-chart-container"></div>
      <div id="fs-rsi-panel">
        <div id="fs-rsi-header">
          <span class="rsi-badge">RSI</span>
          <span class="rsi-period-badge" id="rsi-period-badge">14/14</span>
          <span id="rsi-live-val" class="mid">RSI:—</span>
          <span id="rsi-ma-val" style="font-size:11px;font-weight:700;color:#eab308;margin-left:4px">MA:—</span>
          <span class="rsi-level-hint">▸70 OB &nbsp; ▾30 OS</span>
          <button id="rsi-settings-btn">⚙ Settings</button>
        </div>
        <div id="fs-rsi-container"></div>
        <div id="rsi-settings-popup" style="display:none"></div>
      </div>
    </div>

    <div id="fs-ohlc">
      <div class="ohlc-time" id="fs-ohlc-time">—</div>
      <div class="ohlc-row"><span class="ohlc-lbl">O</span><span class="ohlc-val" id="fs-ohlc-o">—</span></div>
      <div class="ohlc-row"><span class="ohlc-lbl">H</span><span class="ohlc-val up" id="fs-ohlc-h">—</span></div>
      <div class="ohlc-row"><span class="ohlc-lbl">L</span><span class="ohlc-val down" id="fs-ohlc-l">—</span></div>
      <div class="ohlc-row"><span class="ohlc-lbl">C</span><span class="ohlc-val" id="fs-ohlc-c">—</span></div>
    </div>
  </div>

  <div id="fs-resizer"></div>

  <div id="fs-tv-wrap">
    <div id="fs-tv-label">📺 TRADINGVIEW</div>
    <iframe id="fs-tv-iframe" src="" title="TradingView"></iframe>
    <div id="fs-tv-loading">
      <div class="spinner"></div>
      <div class="tv-loading-text">Loading TradingView...</div>
    </div>
  </div>
</div>

<!-- RSI SETTINGS DIALOG (draggable) -->
<div id="rsi-settings-dialog">
  <div id="rsi-dlg-titlebar">
    <span class="dlg-title">RSI Settings <span class="dlg-title-sub">drag to move</span></span>
    <button class="dlg-close-btn" id="rsi-dlg-close">×</button>
  </div>
  <div id="rsi-dlg-body">
    <span class="dlg-section">RSI</span>
    <div class="dlg-row">
      <span class="dlg-label"><span class="swatch swatch-purple"></span>RSI Length</span>
      <input class="dlg-input" id="rsi-len-input" type="number" value="14" min="2" max="200"/>
    </div>
    <div class="dlg-divider"></div>
    <span class="dlg-section">RSI-based MA</span>
    <div class="dlg-row">
      <span class="dlg-label"><span class="swatch swatch-yellow"></span>MA Length</span>
      <input class="dlg-input" id="rsi-ma-input" type="number" value="14" min="1" max="200"/>
    </div>
    <div class="dlg-divider"></div>
    <span class="dlg-section">Levels</span>
    <div class="dlg-row">
      <span class="dlg-label"><span class="swatch swatch-red"></span>Overbought</span>
      <input class="dlg-input" id="rsi-ob-input" type="number" value="70" min="50" max="99"/>
    </div>
    <div class="dlg-row">
      <span class="dlg-label"><span class="swatch swatch-green"></span>Oversold</span>
      <input class="dlg-input" id="rsi-os-input" type="number" value="30" min="1" max="50"/>
    </div>
  </div>
  <div id="rsi-dlg-footer">
    <button class="dlg-btn" id="rsi-dlg-cancel">Cancel</button>
    <button class="dlg-btn primary" id="rsi-apply-btn">Apply</button>
  </div>
</div>

<script>
// ── DRAGGABLE RSI DIALOG ─────────────────────
(function(){
  const dlg  = document.getElementById('rsi-settings-dialog');
  const bar  = document.getElementById('rsi-dlg-titlebar');
  let dragging=false, ox=0, oy=0, startL=0, startT=0;

  bar.addEventListener('mousedown', e=>{
    dragging=true;
    ox=e.clientX; oy=e.clientY;
    const r=dlg.getBoundingClientRect();
    startL=r.left; startT=r.top;
    dlg.style.transform='none';
    dlg.style.left=startL+'px'; dlg.style.top=startT+'px';
    document.body.style.userSelect='none';
  });
  document.addEventListener('mousemove', e=>{
    if(!dragging) return;
    const dx=e.clientX-ox, dy=e.clientY-oy;
    dlg.style.left=Math.max(0,startL+dx)+'px';
    dlg.style.top= Math.max(0,startT+dy)+'px';
  });
  document.addEventListener('mouseup',()=>{ if(!dragging)return; dragging=false; document.body.style.userSelect=''; });

  bar.addEventListener('touchstart',e=>{
    const t=e.touches[0];
    ox=t.clientX; oy=t.clientY;
    const r=dlg.getBoundingClientRect();
    startL=r.left; startT=r.top;
    dlg.style.transform='none';
    dlg.style.left=startL+'px'; dlg.style.top=startT+'px';
  },{passive:true});
  document.addEventListener('touchmove',e=>{
    if(!e.target.closest('#rsi-dlg-titlebar')) return;
    const t=e.touches[0];
    dlg.style.left=Math.max(0,startL+(t.clientX-ox))+'px';
    dlg.style.top= Math.max(0,startT+(t.clientY-oy))+'px';
  },{passive:true});
})();
</script>

<div class="modal-overlay" id="modal-overlay">
  <div class="modal">
    <div class="modal-title">Add Chart Pair</div>
    <input class="modal-search" id="modal-search" placeholder="Search... EUR, GBP, BTC" type="text"/>
    <div class="modal-pairs" id="modal-pairs"></div>
    <div class="modal-actions">
      <button class="modal-btn" id="modal-cancel">Cancel</button>
      <button class="modal-btn primary" id="modal-add">Add Selected</button>
    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════
const POLL_MS = 5000;

const ALL_PAIRS = [
  'BTC/USD','ETH/USD','LTC/USD',
  'AUD/CAD','AUD/CHF','AUD/JPY','AUD/USD','CAD/JPY','CHF/JPY',
  'EUR/AUD','EUR/CAD','EUR/CHF','EUR/GBP','EUR/JPY','EUR/USD',
  'GBP/AUD','GBP/CAD','GBP/CHF','GBP/JPY','GBP/USD',
  'USD/CAD','USD/CHF','USD/JPY','XAU/USD','XAG/USD','WTI/USD'
];

// ═══════════════════════════════════════
// READ ?symbol= FROM URL
// ═══════════════════════════════════════
function getURLSymbol() {
  const params = new URLSearchParams(window.location.search);
  const raw    = params.get('symbol'); // e.g. "USDCHF"
  if (!raw) return null;
  // Try to match against ALL_PAIRS (with slash) — e.g. USDCHF → USD/CHF
  const upper = raw.toUpperCase();
  // Direct match with slash
  if (ALL_PAIRS.includes(upper)) return upper;
  // Try inserting slash at position 3
  const withSlash = upper.slice(0,3) + '/' + upper.slice(3);
  if (ALL_PAIRS.includes(withSlash)) return withSlash;
  // Return as-is with slash if 6 chars
  if (upper.length === 6) return withSlash;
  return null;
}

const URL_SYMBOL = getURLSymbol(); // e.g. "USD/CHF" or null

let activePairs     = URL_SYMBOL ? [URL_SYMBOL] : ['AUD/CAD','EUR/USD','GBP/JPY','USD/JPY'];
let currentInterval = { limit:4000, tv:'5', label:'M5' };
let currentLayout   = '2x2';
let focusedPair     = null;
let tvOpenPair      = null;
const pairState     = {};

// ═══════════════════════════════════════
// HEADER TIMER
// ═══════════════════════════════════════
function updateHeaderTimer() {
  const ms   = parseInt(currentInterval.tv) * 60000;
  const now  = Date.now();
  const diff = Math.max(0, Math.ceil(now/ms)*ms - now);
  const m    = Math.floor((diff%3600000)/60000);
  const s    = Math.floor((diff%60000)/1000);
  const el   = document.getElementById('header-timer');
  if (el) el.textContent = String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
}
setInterval(updateHeaderTimer, 1000);
updateHeaderTimer();

// ═══════════════════════════════════════
// CANDLE CLOCK
// ═══════════════════════════════════════
const CIRC = 2 * Math.PI * 16;

function tickCandleClock() {
  const ms       = parseInt(currentInterval.tv) * 60000;
  const totalSec = ms / 1000;
  const now      = Date.now();
  const diff     = Math.max(0, Math.ceil(now/ms)*ms - now);
  const diffSec  = Math.ceil(diff/1000);
  const m = Math.floor(diffSec/60);
  const s = diffSec % 60;
  const progress = diffSec / totalSec;
  const offset   = CIRC * (1 - progress);

  const ringEl = document.getElementById('clock-ring-fill');
  const mmEl   = document.getElementById('clock-mm');
  const ssEl   = document.getElementById('clock-ss');
  const wrap   = document.getElementById('fs-candle-clock');
  if (!ringEl) return;

  ringEl.style.strokeDashoffset = offset;
  mmEl.textContent = String(m).padStart(2,'0');
  ssEl.textContent = String(s).padStart(2,'0');
  wrap.className   = progress > 0.4 ? 'clk-green' : progress > 0.15 ? 'clk-orange' : 'clk-red';
  ringEl.style.filter = diffSec <= 30 ? 'drop-shadow(0 0 3px currentColor)' : '';
}
setInterval(tickCandleClock, 1000);
tickCandleClock();

function updateClockLabel() {
  const el = document.getElementById('clock-interval-txt');
  if (el) el.textContent = currentInterval.label;
}

// ═══════════════════════════════════════
// STATE
// ═══════════════════════════════════════
function ensurePairState(sym) {
  if (!pairState[sym]) pairState[sym] = { chart:null, series:null, allCandles:[], latestTime:0, pollTimer:null };
  return pairState[sym];
}
function idKey(sym) { return sym.replace('/','_'); }

// ═══════════════════════════════════════
// BUILD GRID
// ═══════════════════════════════════════
function buildGrid() {
  const grid = document.getElementById('chart-grid');
  grid.className = 'chart-grid layout-' + currentLayout;

  const max = getMaxCells(currentLayout);
  while (activePairs.length > max) activePairs.pop();

  grid.querySelectorAll('.chart-cell').forEach(cell => {
    if (!activePairs.includes(cell.dataset.symbol)) {
      destroyCell(cell.dataset.symbol);
      cell.remove();
    }
  });

  activePairs.forEach(sym => {
    if (!grid.querySelector(`.chart-cell[data-symbol="${sym}"]`)) {
      const cell = createCellEl(sym);
      grid.appendChild(cell);
      requestAnimationFrame(() => initCellChart(sym));
    }
  });

  setTimeout(resizeAllCharts, 50);
}

function getMaxCells(layout) {
  return {'1x1':1,'1x2':2,'2x2':4,'2x3':6,'3x3':9}[layout] || 4;
}

function createCellEl(sym) {
  const k   = idKey(sym);
  const div = document.createElement('div');
  div.className    = 'chart-cell';
  div.dataset.symbol = sym;
  div.innerHTML = `
    <div class="cell-header">
      <span class="cell-pair">${sym}</span>
      <span class="cell-price" id="price-${k}">—</span>
      <span class="cell-change" id="change-${k}">—</span>
      <span class="cell-iv-badge" id="iv-${k}">${currentInterval.label}</span>
    </div>
    <div class="cell-actions">
      <button class="cell-btn" title="TradingView" onclick="event.stopPropagation();openTV('${sym}')">📺</button>
      <button class="cell-btn expand-btn" title="Fullscreen + TV" onclick="event.stopPropagation();openFSOverlay('${sym}')">⛶</button>
      <button class="cell-btn close-btn" title="Remove" onclick="event.stopPropagation();removePair('${sym}')">✕</button>
    </div>
    <div class="cell-chart" id="cell-chart-${k}"></div>
    <div class="ohlc-box" id="ohlc-${k}">
      <div class="ohlc-time" id="ohlc-time-${k}">—</div>
      <div class="ohlc-row"><span class="ohlc-lbl">O</span><span class="ohlc-val" id="ohlc-o-${k}">—</span></div>
      <div class="ohlc-row"><span class="ohlc-lbl">H</span><span class="ohlc-val up" id="ohlc-h-${k}">—</span></div>
      <div class="ohlc-row"><span class="ohlc-lbl">L</span><span class="ohlc-val down" id="ohlc-l-${k}">—</span></div>
      <div class="ohlc-row"><span class="ohlc-lbl">C</span><span class="ohlc-val" id="ohlc-c-${k}">—</span></div>
    </div>
    <div class="candle-timer-badge" id="ctimer-${k}">⏱ --:--</div>
    <div class="cell-status">
      <div class="dot" id="dot-${k}"></div>
      <span id="status-${k}" style="font-size:9px;color:var(--muted)">Loading...</span>
    </div>`;
  return div;
}

// ═══════════════════════════════════════
// CELL CHART
// ═══════════════════════════════════════
function initCellChart(sym) {
  const k   = idKey(sym);
  const el  = document.getElementById('cell-chart-' + k);
  if (!el) return;
  const st  = ensurePairState(sym);
  if (st.chart) { st.chart.remove(); st.chart = null; }

  st.chart = LightweightCharts.createChart(el, {
    width: el.clientWidth, height: el.clientHeight,
    layout:{ backgroundColor:'#0a0c0f', textColor:'#5a6a7e' },
    grid:{ vertLines:{ color:'#1e2530' }, horzLines:{ color:'#1e2530' } },
    crosshair:{ mode: LightweightCharts.CrosshairMode.Normal },
    localization:{ timeFormatter: t => fmtTime(t) },
    timeScale:{ borderColor:'#1e2530', timeVisible:true, secondsVisible:false, tickMarkFormatter:(t,tp) => tp<3?fmtDate(t):fmtTime(t) },
    rightPriceScale:{ borderColor:'#1e2530' },
    handleScroll:true, handleScale:true,
  });

  st.series = st.chart.addCandlestickSeries({
    upColor:'#00c896', downColor:'#ff4d6a',
    borderUpColor:'#00c896', borderDownColor:'#ff4d6a',
    wickUpColor:'#00c896', wickDownColor:'#ff4d6a',
    priceFormat:{ type:'custom', formatter: p => p.toFixed(5) },
  });

  const ohlcBox = document.getElementById('ohlc-'+k);
  st.chart.subscribeCrosshairMove(param => {
    if (!param.time || !param.seriesPrices?.size) { ohlcBox && (ohlcBox.style.display='none'); return; }
    const d = param.seriesPrices.get(st.series);
    if (!d || !ohlcBox) return;
    ohlcBox.style.display = 'block';
    document.getElementById('ohlc-time-'+k).textContent = fmtDateTime(param.time);
    document.getElementById('ohlc-o-'+k).textContent = d.open.toFixed(5);
    document.getElementById('ohlc-h-'+k).textContent = d.high.toFixed(5);
    document.getElementById('ohlc-l-'+k).textContent = d.low.toFixed(5);
    const cc = document.getElementById('ohlc-c-'+k);
    if (cc) { cc.textContent=d.close.toFixed(5); cc.className='ohlc-val '+(d.close>=d.open?'up':'down'); }
  });

  new ResizeObserver(() => { if(st.chart) st.chart.applyOptions({width:el.clientWidth,height:el.clientHeight}); }).observe(el);
  fetchFull(sym);
}

// ═══════════════════════════════════════
// TIME HELPERS
// ═══════════════════════════════════════
function toDate(t) { return typeof t==='object' ? new Date(t.year,t.month-1,t.day) : new Date(t*1000); }
function fmtTime(t) { return toDate(t).toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',hour12:false,timeZone:'Asia/Kolkata'}); }
function fmtDate(t) { return toDate(t).toLocaleDateString('en-IN',{day:'numeric',month:'short',timeZone:'Asia/Kolkata'}); }
function fmtDateTime(t) { return fmtDate(t) + ' • ' + fmtTime(t) + ' IST'; }

// ═══════════════════════════════════════
// DATA FETCH (GRID)
// ═══════════════════════════════════════
async function fetchFull(sym) {
  const st = ensurePairState(sym);
  stopPolling(sym);
  setDot(sym,'loading');
  try {
    const r = await fetch(`fetch_live_data.php?symbol=${encodeURIComponent(sym)}&mode=full&limit=${currentInterval.limit}&_=${Date.now()}`);
    if (!r.ok) throw new Error('HTTP '+r.status);
    const d = await r.json();
    if (d.error) throw new Error(d.error);
    st.allCandles = d.candlesticks || [];
    st.latestTime = st.allCandles.length>=2 ? st.allCandles[st.allCandles.length-2].time : 0;
    st.series.setData(st.allCandles);
    setMarkers(sym);
    zoomToLast(sym,80);
    updateCellPrice(sym);
    setDot(sym,'live');
    startPolling(sym);
  } catch(e) { setDot(sym,'error'); setTimeout(()=>fetchFull(sym),5000); }
}

async function fetchDelta(sym) {
  const st = ensurePairState(sym);
  try {
    const r = await fetch(`fetch_live_data.php?symbol=${encodeURIComponent(sym)}&mode=delta&since=${st.latestTime}&_=${Date.now()}`);
    if (!r.ok) throw new Error('HTTP '+r.status);
    const d = await r.json();
    if (d.error) throw new Error(d.error);
    if (Array.isArray(d.candles) && d.candles.length) {
      d.candles.forEach(c => {
        const i = st.allCandles.findIndex(x=>x.time===c.time);
        if (i>=0) st.allCandles[i]=c; else st.allCandles.push(c);
        st.series.update(c);
      });
      setMarkers(sym);
      if (st.allCandles.length>=2) st.latestTime = st.allCandles[st.allCandles.length-2].time;
      updateCellPrice(sym);
    }
    setDot(sym,'live');
  } catch(e) { setDot(sym,'error'); }
}

function startPolling(sym) { const st=ensurePairState(sym); stopPolling(sym); st.pollTimer=setInterval(()=>fetchDelta(sym),POLL_MS); }
function stopPolling(sym)  { const st=ensurePairState(sym); if(st.pollTimer){clearInterval(st.pollTimer);st.pollTimer=null;} }
function stopAllPolling()  { activePairs.forEach(stopPolling); }

function setMarkers(sym) {
  const st = ensurePairState(sym);
  if (!st.series) return;
  st.series.setMarkers(st.allCandles.filter(c=>c.alert).map(c=>({
    time:c.time,
    position: c.alert_dir===1?'belowBar':'aboveBar',
    color:    c.alert_dir===1?'#00c896':c.alert_dir===-1?'#ff4d6a':'#ffb703',
    shape:    c.alert_dir===1?'arrowUp':'arrowDown',
    text:     c.alert_dir===1?'BUY':c.alert_dir===-1?'SELL':'ALERT',
  })));
}

// ═══════════════════════════════════════
// UI HELPERS
// ═══════════════════════════════════════
function updateCellPrice(sym) {
  const st = ensurePairState(sym), k = idKey(sym), cs = st.allCandles;
  if (!cs.length) return;
  const last=cs[cs.length-1], prev=cs[cs.length-2]||last;
  const ch=last.close-prev.close, pct=prev.close?(ch/prev.close*100):0, up=ch>=0;
  const pe=document.getElementById('price-'+k), ce=document.getElementById('change-'+k);
  if(pe){pe.textContent=last.close.toFixed(5);pe.className='cell-price '+(up?'up':'down');}
  if(ce){ce.textContent=(up?'+':'')+pct.toFixed(2)+'%';ce.className='cell-change '+(up?'up':'down');}
}

function zoomToLast(sym,n) {
  const st=ensurePairState(sym); if(!st.chart||!st.allCandles.length) return;
  const total=st.allCandles.length;
  if(total>n) st.chart.timeScale().setVisibleLogicalRange({from:total-n,to:total-1});
  else st.chart.timeScale().fitContent();
}

function setDot(sym,type) {
  const k=idKey(sym), dot=document.getElementById('dot-'+k), stat=document.getElementById('status-'+k);
  if(!dot) return;
  dot.className='dot '+(type==='live'?'live':'');
  if(stat) stat.textContent=type==='live'?'LIVE':type==='error'?'ERR':'Loading...';
}

function resizeAllCharts() {
  activePairs.forEach(sym=>{
    const st=ensurePairState(sym), el=document.getElementById('cell-chart-'+idKey(sym));
    if(st.chart&&el) st.chart.applyOptions({width:el.clientWidth,height:el.clientHeight});
  });
}

// ═══════════════════════════════════════
// FULLSCREEN OVERLAY
// ═══════════════════════════════════════
let fsChart=null, fsSeries=null, fsSymbol=null, fsPollTimer=null;
let fsAllCandles=[], fsLatestTime=0;

function openFSOverlay(sym) {
  closeFSOverlay();
  focusedPair = sym;

  document.getElementById('fullscreen-overlay').classList.add('open');
  document.getElementById('fs-pair-label').textContent = sym;
  document.getElementById('fs-price').textContent = '—';
  document.getElementById('fs-change').textContent = '—';
  tickCandleClock();

  initFSChart(sym);

  if (!rsiOpen) {
    rsiOpen = true;
    document.getElementById('fs-rsi-panel').classList.add('rsi-open');
    document.getElementById('rsi-toggle-btn').classList.add('active');
    if (!rsiChart) initRSIChart();
  }

  const tvSym = sym.replace('/','');
  const tvUrl = `https://s.tradingview.com/widgetembed/?frameElementId=tv_fs&symbol=FX:${tvSym}&interval=${currentInterval.tv}&hidesidetoolbar=1&symboledit=0&saveimage=1&toolbarbg=0a0c0f&studies=%5B%5D&theme=dark&style=1&timezone=Asia%2FKolkata`;
  const iframe  = document.getElementById('fs-tv-iframe');
  const loading = document.getElementById('fs-tv-loading');
  loading.classList.remove('hidden');
  iframe.onload = () => loading.classList.add('hidden');
  iframe.src = tvUrl;
}

function closeFSOverlay() {
  if (!focusedPair) return;
  focusedPair = null;
  fsSymbol    = null;

  if (fsPollTimer) { clearInterval(fsPollTimer); fsPollTimer=null; }
  if (fsChart)     { fsChart.remove(); fsChart=null; fsSeries=null; }
  destroyRSIChart();
  fsAllCandles=[]; fsLatestTime=0;

  document.getElementById('fs-tv-iframe').src='';
  document.getElementById('fs-tv-loading').classList.remove('hidden');
  document.getElementById('fullscreen-overlay').classList.remove('open');
}

function initFSChart(sym) {
  fsSymbol = sym;
  if (fsChart) { fsChart.remove(); fsChart=null; fsSeries=null; }
  fsAllCandles=[]; fsLatestTime=0;

  const el = document.getElementById('fs-chart-container');
  fsChart = LightweightCharts.createChart(el, {
    width:el.clientWidth, height:el.clientHeight,
    layout:{ backgroundColor:'#0a0c0f', textColor:'#5a6a7e' },
    grid:{ vertLines:{color:'#1e2530'}, horzLines:{color:'#1e2530'} },
    crosshair:{ mode:LightweightCharts.CrosshairMode.Normal },
    localization:{ timeFormatter:t=>fmtTime(t) },
    timeScale:{ borderColor:'#1e2530', timeVisible:true, secondsVisible:false, tickMarkFormatter:(t,tp)=>tp<3?fmtDate(t):fmtTime(t) },
    rightPriceScale:{ borderColor:'#1e2530' },
    handleScroll:true, handleScale:true,
  });

  fsSeries = fsChart.addCandlestickSeries({
    upColor:'#00c896', downColor:'#ff4d6a',
    borderUpColor:'#00c896', borderDownColor:'#ff4d6a',
    wickUpColor:'#00c896', wickDownColor:'#ff4d6a',
    priceFormat:{ type:'custom', formatter:p=>p.toFixed(5) },
  });

  fsChart.subscribeCrosshairMove(param => {
    const box = document.getElementById('fs-ohlc');

    if (!syncingXhair && rsiChart && rsiSeries) {
      syncingXhair = true;
      if (param.time !== undefined) {
        const t = normalizeTime(param.time);
        const rsiVal = rsiTimeMap.get(t);
        if (rsiVal !== undefined) {
          rsiChart.setCrosshairPosition(rsiVal, t, rsiSeries);
        } else {
          rsiChart.clearCrosshairPosition();
        }
        const rsiEl = document.getElementById('rsi-live-val');
        if (rsiEl && rsiVal !== undefined) {
          rsiEl.textContent = 'RSI:' + rsiVal.toFixed(1);
          rsiEl.className = rsiVal >= rsiOB ? 'ob' : rsiVal <= rsiOS ? 'os' : 'mid';
        }
        const maVal = (() => {
          if (!rsiMaDataCache.length) return undefined;
          const entry = rsiMaDataCache.find(d => d.time === t);
          return entry ? entry.value : undefined;
        })();
        const maEl = document.getElementById('rsi-ma-val');
        if (maEl && maVal !== undefined) maEl.textContent = 'MA:' + maVal.toFixed(1);
      } else {
        rsiChart.clearCrosshairPosition();
      }
      clearXhairSync();
    }

    if (!param.time||!param.seriesPrices?.size) { box.style.display='none'; return; }
    const d = param.seriesPrices.get(fsSeries);
    if (!d) return;
    box.style.display='block';
    document.getElementById('fs-ohlc-time').textContent = fmtDateTime(param.time);
    document.getElementById('fs-ohlc-o').textContent = d.open.toFixed(5);
    document.getElementById('fs-ohlc-h').textContent = d.high.toFixed(5);
    document.getElementById('fs-ohlc-l').textContent = d.low.toFixed(5);
    const cc=document.getElementById('fs-ohlc-c');
    if(cc){cc.textContent=d.close.toFixed(5);cc.className='ohlc-val '+(d.close>=d.open?'up':'down');}
  });

  new ResizeObserver(()=>{ if(fsChart){const e=document.getElementById('fs-chart-container');fsChart.applyOptions({width:e.clientWidth,height:e.clientHeight});} }).observe(el);
  fetchFSFull(sym);
}

async function fetchFSFull(sym) {
  if (fsSymbol!==sym) return;
  if (fsPollTimer) { clearInterval(fsPollTimer); fsPollTimer=null; }
  try {
    const r = await fetch(`fetch_live_data.php?symbol=${encodeURIComponent(sym)}&mode=full&limit=${currentInterval.limit}&_=${Date.now()}`);
    if (!r.ok) throw new Error('HTTP '+r.status);
    const d = await r.json();
    if (d.error) throw new Error(d.error);
    if (fsSymbol!==sym) return;
    fsAllCandles = d.candlesticks||[];
    fsLatestTime = fsAllCandles.length>=2 ? fsAllCandles[fsAllCandles.length-2].time : 0;
    fsSeries.setData(fsAllCandles);
    setFSMarkers();
    const tot=fsAllCandles.length;
    if(tot>80) fsChart.timeScale().setVisibleLogicalRange({from:tot-80,to:tot-1});
    else fsChart.timeScale().fitContent();
    updateFSPrice();
    if (rsiOpen) renderRSI();
    fsPollTimer = setInterval(()=>fetchFSDelta(sym), POLL_MS);
  } catch(e) { setTimeout(()=>fetchFSFull(sym),5000); }
}

async function fetchFSDelta(sym) {
  if (fsSymbol!==sym) return;
  try {
    const r = await fetch(`fetch_live_data.php?symbol=${encodeURIComponent(sym)}&mode=delta&since=${fsLatestTime}&_=${Date.now()}`);
    if (!r.ok) throw new Error('HTTP '+r.status);
    const d = await r.json();
    if (d.error||fsSymbol!==sym) return;
    if (Array.isArray(d.candles)&&d.candles.length) {
      d.candles.forEach(c=>{
        const i=fsAllCandles.findIndex(x=>x.time===c.time);
        if(i>=0) fsAllCandles[i]=c; else fsAllCandles.push(c);
        fsSeries.update(c);
      });
      setFSMarkers();
      if(fsAllCandles.length>=2) fsLatestTime=fsAllCandles[fsAllCandles.length-2].time;
      updateFSPrice();
      if(rsiOpen) renderRSI();
    }
  } catch(e) {}
}

function setFSMarkers() {
  if (!fsSeries) return;
  fsSeries.setMarkers(fsAllCandles.filter(c=>c.alert).map(c=>({
    time:c.time,
    position:c.alert_dir===1?'belowBar':'aboveBar',
    color:c.alert_dir===1?'#00c896':c.alert_dir===-1?'#ff4d6a':'#ffb703',
    shape:c.alert_dir===1?'arrowUp':'arrowDown',
    text:c.alert_dir===1?'BUY':c.alert_dir===-1?'SELL':'ALERT',
  })));
}

function updateFSPrice() {
  if (!fsAllCandles.length) return;
  const last=fsAllCandles[fsAllCandles.length-1], prev=fsAllCandles[fsAllCandles.length-2]||last;
  const ch=last.close-prev.close, pct=prev.close?(ch/prev.close*100):0, up=ch>=0;
  const pe=document.getElementById('fs-price'), ce=document.getElementById('fs-change');
  pe.textContent=last.close.toFixed(5); pe.className=up?'up':'down';
  ce.textContent=(up?'+':'')+pct.toFixed(2)+'%'; ce.className=up?'up':'down';
}

// ═══════════════════════════════════════
// RSI
// ═══════════════════════════════════════
let rsiOpen=false, rsiChart=null, rsiSeries=null, rsiMaSeries=null, rsiOBLine=null, rsiOSLine=null, rsiMidLine=null;
let rsiPeriod=14, rsiOB=70, rsiOS=30, rsiMaPeriod=14;
let rsiDataCache=[], rsiMaDataCache=[];
let rsiTimeMap=new Map();
let candleTimeMap=new Map();
let syncingXhair=false;
let syncingRange=false;
let _fsSyncHandler=null;
let _xhairRaf=null;
function clearXhairSync() {
  if (_xhairRaf) cancelAnimationFrame(_xhairRaf);
  _xhairRaf = requestAnimationFrame(() => { syncingXhair=false; _xhairRaf=null; });
}

function normalizeTime(t) {
  if (typeof t === 'number') return t;
  if (t && typeof t === 'object' && t.year) return Date.UTC(t.year, t.month-1, t.day)/1000;
  return 0;
}

function calcRSI(candles, period) {
  const out=[]; if(candles.length<period+1) return out;
  let ag=0, al=0;
  for(let i=1;i<=period;i++){
    const d=candles[i].close-candles[i-1].close;
    if(d>0) ag+=d; else al+=Math.abs(d);
  }
  ag/=period; al/=period;
  out.push({time:candles[period].time, value:parseFloat((al===0?100:100-100/(1+ag/al)).toFixed(2))});
  for(let i=period+1;i<candles.length;i++){
    const d=candles[i].close-candles[i-1].close;
    const g=d>0?d:0, l=d<0?-d:0;
    ag=(ag*(period-1)+g)/period;
    al=(al*(period-1)+l)/period;
    out.push({time:candles[i].time, value:parseFloat((al===0?100:100-100/(1+ag/al)).toFixed(2))});
  }
  return out;
}

function calcSMAofRSI(rsiData, maPeriod) {
  const out=[];
  for(let i=maPeriod-1;i<rsiData.length;i++){
    let sum=0;
    for(let j=i-maPeriod+1;j<=i;j++) sum+=rsiData[j].value;
    out.push({time:rsiData[i].time, value:parseFloat((sum/maPeriod).toFixed(2))});
  }
  return out;
}

function initRSIChart() {
  destroyRSIChart();
  const el = document.getElementById('fs-rsi-container');
  if (!el) return;

  rsiChart = LightweightCharts.createChart(el, {
    width:el.clientWidth, height:el.clientHeight,
    layout:{ backgroundColor:'#0a0c0f', textColor:'#5a6a7e' },
    grid:{ vertLines:{color:'#1a1f28'}, horzLines:{color:'#1a1f28'} },
    crosshair:{ mode:LightweightCharts.CrosshairMode.Normal },
    rightPriceScale:{ borderColor:'#1e2530', scaleMargins:{top:0.05,bottom:0.05} },
    timeScale:{ borderColor:'#1e2530', timeVisible:false, secondsVisible:false, visible:false },
    localization:{ timeFormatter:t=>fmtTime(t) },
    handleScroll:true, handleScale:true,
  });

  rsiSeries  = rsiChart.addLineSeries({ color:'#a78bfa', lineWidth:1.5, priceFormat:{type:'custom',formatter:v=>v.toFixed(1)}, lastValueVisible:true, priceLineVisible:false });
  rsiMaSeries= rsiChart.addLineSeries({ color:'#eab308', lineWidth:1.5, priceFormat:{type:'custom',formatter:v=>v.toFixed(1)}, lastValueVisible:true, priceLineVisible:false });
  rsiOBLine  = rsiChart.addLineSeries({ color:'rgba(255,77,106,.5)', lineWidth:1, lineStyle:LightweightCharts.LineStyle.Dashed, lastValueVisible:false, priceLineVisible:false, crosshairMarkerVisible:false });
  rsiOSLine  = rsiChart.addLineSeries({ color:'rgba(0,200,150,.5)',  lineWidth:1, lineStyle:LightweightCharts.LineStyle.Dashed, lastValueVisible:false, priceLineVisible:false, crosshairMarkerVisible:false });
  rsiMidLine = rsiChart.addLineSeries({ color:'rgba(90,106,126,.3)', lineWidth:1, lineStyle:LightweightCharts.LineStyle.Dotted, lastValueVisible:false, priceLineVisible:false, crosshairMarkerVisible:false });

  _fsSyncHandler = (range) => {
    if (syncingRange || !range || !rsiChart) return;
    syncingRange = true;
    rsiChart.timeScale().setVisibleLogicalRange({
      from: range.from - rsiPeriod,
      to:   range.to   - rsiPeriod,
    });
    syncingRange = false;
  };
  fsChart.timeScale().subscribeVisibleLogicalRangeChange(_fsSyncHandler);

  rsiChart.timeScale().subscribeVisibleLogicalRangeChange((range) => {
    if (syncingRange || !range || !fsChart) return;
    syncingRange = true;
    fsChart.timeScale().setVisibleLogicalRange({
      from: range.from + rsiPeriod,
      to:   range.to   + rsiPeriod,
    });
    syncingRange = false;
  });

  rsiChart.subscribeCrosshairMove(param => {
    if (syncingXhair || !fsChart || !fsSeries) return;
    syncingXhair = true;
    if (param.time !== undefined) {
      const t = normalizeTime(param.time);
      const closeVal = candleTimeMap.get(t);
      if (closeVal !== undefined) {
        fsChart.setCrosshairPosition(closeVal, t, fsSeries);
        const box = document.getElementById('fs-ohlc');
        const candle = fsAllCandles.find(c => c.time === t);
        if (candle && box) {
          box.style.display = 'block';
          document.getElementById('fs-ohlc-time').textContent = fmtDateTime(t);
          document.getElementById('fs-ohlc-o').textContent = candle.open.toFixed(5);
          document.getElementById('fs-ohlc-h').textContent = candle.high.toFixed(5);
          document.getElementById('fs-ohlc-l').textContent = candle.low.toFixed(5);
          const cc = document.getElementById('fs-ohlc-c');
          if (cc) { cc.textContent = candle.close.toFixed(5); cc.className = 'ohlc-val ' + (candle.close >= candle.open ? 'up' : 'down'); }
        }
        const rsiEntry = rsiDataCache.find(d => d.time === t);
        if (rsiEntry) {
          const rsiEl = document.getElementById('rsi-live-val');
          if (rsiEl) { rsiEl.textContent = 'RSI:' + rsiEntry.value.toFixed(1); rsiEl.className = rsiEntry.value >= rsiOB ? 'ob' : rsiEntry.value <= rsiOS ? 'os' : 'mid'; }
        }
        const maEntry = rsiMaDataCache.find(d => d.time === t);
        if (maEntry) {
          const maEl = document.getElementById('rsi-ma-val');
          if (maEl) maEl.textContent = 'MA:' + maEntry.value.toFixed(1);
        }
      } else {
        fsChart.clearCrosshairPosition();
      }
    } else {
      fsChart.clearCrosshairPosition();
      document.getElementById('fs-ohlc').style.display = 'none';
    }
    clearXhairSync();
  });

  new ResizeObserver(()=>{ if(rsiChart){const e=document.getElementById('fs-rsi-container');rsiChart.applyOptions({width:e.clientWidth,height:e.clientHeight});} }).observe(el);
}

function destroyRSIChart() {
  if (_fsSyncHandler && fsChart) {
    fsChart.timeScale().unsubscribeVisibleLogicalRangeChange(_fsSyncHandler);
    _fsSyncHandler = null;
  }
  if(rsiChart){rsiChart.remove();rsiChart=null;rsiSeries=null;rsiMaSeries=null;rsiOBLine=null;rsiOSLine=null;rsiMidLine=null;}
}

function renderRSI() {
  if (!rsiSeries||!fsAllCandles.length) return;
  rsiDataCache = calcRSI(fsAllCandles, rsiPeriod);
  rsiSeries.setData(rsiDataCache);

  rsiTimeMap.clear();
  rsiDataCache.forEach(d => rsiTimeMap.set(d.time, d.value));
  candleTimeMap.clear();
  fsAllCandles.forEach(c => candleTimeMap.set(c.time, c.close));

  rsiMaDataCache = [];
  if (rsiMaSeries && rsiDataCache.length) {
    rsiMaDataCache = calcSMAofRSI(rsiDataCache, rsiMaPeriod);
    rsiMaSeries.setData(rsiMaDataCache);
    const maEl = document.getElementById('rsi-ma-val');
    if (maEl && rsiMaDataCache.length) maEl.textContent = 'MA:'+rsiMaDataCache[rsiMaDataCache.length-1].value.toFixed(1);
  }

  if (rsiDataCache.length<2) return;
  const f=rsiDataCache[0].time, l=rsiDataCache[rsiDataCache.length-1].time;
  const flat=(v)=>[{time:f,value:v},{time:l,value:v}];
  rsiOBLine.setData(flat(rsiOB));
  rsiOSLine.setData(flat(rsiOS));
  rsiMidLine.setData(flat(50));

  const live=rsiDataCache[rsiDataCache.length-1].value;
  const el=document.getElementById('rsi-live-val');
  if(el){el.textContent='RSI:'+live.toFixed(1);el.className=live>=rsiOB?'ob':live<=rsiOS?'os':'mid';}
  document.getElementById('rsi-period-badge').textContent=rsiPeriod+'/'+rsiMaPeriod;

  if (!syncingRange && fsChart && rsiChart) {
    const range = fsChart.timeScale().getVisibleLogicalRange();
    if (range) {
      syncingRange = true;
      rsiChart.timeScale().setVisibleLogicalRange({
        from: range.from - rsiPeriod,
        to:   range.to   - rsiPeriod,
      });
      syncingRange = false;
    }
  }
}

function toggleRSI() {
  rsiOpen = !rsiOpen;
  const panel = document.getElementById('fs-rsi-panel');
  const btn   = document.getElementById('rsi-toggle-btn');
  if (rsiOpen) {
    panel.classList.add('rsi-open');
    btn.classList.add('active');
    if (!rsiChart) initRSIChart();
    if (fsAllCandles.length) renderRSI();
    setTimeout(()=>{
      const cel=document.getElementById('fs-chart-container');
      if(fsChart&&cel) fsChart.applyOptions({width:cel.clientWidth,height:cel.clientHeight});
      const rel=document.getElementById('fs-rsi-container');
      if(rsiChart&&rel) rsiChart.applyOptions({width:rel.clientWidth,height:rel.clientHeight});
    },60);
  } else {
    panel.classList.remove('rsi-open');
    btn.classList.remove('active');
    setTimeout(()=>{
      const cel=document.getElementById('fs-chart-container');
      if(fsChart&&cel) fsChart.applyOptions({width:cel.clientWidth,height:cel.clientHeight});
    },60);
  }
}

document.getElementById('rsi-toggle-btn').addEventListener('click', toggleRSI);

document.getElementById('rsi-settings-btn').addEventListener('click', e=>{
  e.stopPropagation();
  document.getElementById('rsi-settings-dialog').classList.toggle('dlg-open');
});

document.getElementById('rsi-dlg-close').addEventListener('click', ()=>{
  document.getElementById('rsi-settings-dialog').classList.remove('dlg-open');
});
document.getElementById('rsi-dlg-cancel').addEventListener('click', ()=>{
  document.getElementById('rsi-settings-dialog').classList.remove('dlg-open');
});

document.getElementById('rsi-apply-btn').addEventListener('click', ()=>{
  rsiPeriod   = Math.max(2, parseInt(document.getElementById('rsi-len-input').value)||14);
  rsiOB       = Math.max(51,parseInt(document.getElementById('rsi-ob-input').value)||70);
  rsiOS       = Math.min(49,parseInt(document.getElementById('rsi-os-input').value)||30);
  rsiMaPeriod = Math.max(1, parseInt(document.getElementById('rsi-ma-input').value)||14);
  document.getElementById('rsi-settings-dialog').classList.remove('dlg-open');
  if (rsiOpen&&fsAllCandles.length) renderRSI();
});

// ═══════════════════════════════════════
// DRAG RESIZER
// ═══════════════════════════════════════
(function(){
  const resizer=document.getElementById('fs-resizer');
  const tvWrap=document.getElementById('fs-tv-wrap');
  let dragging=false, startX=0, startW=0;
  resizer.addEventListener('mousedown',e=>{dragging=true;startX=e.clientX;startW=tvWrap.offsetWidth;resizer.classList.add('dragging');document.body.style.cursor='col-resize';document.body.style.userSelect='none';});
  document.addEventListener('mousemove',e=>{
    if(!dragging) return;
    const newW=Math.min(Math.max(startW+(startX-e.clientX),200),window.innerWidth-300);
    tvWrap.style.width=newW+'px';
    const ce=document.getElementById('fs-chart-container');
    if(fsChart&&ce) fsChart.applyOptions({width:ce.clientWidth,height:ce.clientHeight});
  });
  document.addEventListener('mouseup',()=>{if(!dragging)return;dragging=false;resizer.classList.remove('dragging');document.body.style.cursor='';document.body.style.userSelect='';});
})();

// ═══════════════════════════════════════
// TV SIDEBAR
// ═══════════════════════════════════════
function openTV(sym) {
  tvOpenPair = sym;
  const tvSym = sym.replace('/','');
  document.getElementById('tv-pair-label').textContent = sym;
  const loading = document.getElementById('tv-loading');
  const iframe  = document.getElementById('tv-iframe');
  loading.classList.remove('hidden');
  document.getElementById('tv-sidebar').classList.add('open');
  const url=`https://s.tradingview.com/widgetembed/?frameElementId=tv_side&symbol=FX:${tvSym}&interval=${currentInterval.tv}&hidesidetoolbar=1&symboledit=0&saveimage=1&toolbarbg=111418&studies=%5B%5D&theme=dark&style=1&timezone=Asia%2FKolkata`;
  iframe.onload=()=>loading.classList.add('hidden');
  iframe.src=url;
  setTimeout(resizeAllCharts,400);
}

function closeTV() {
  tvOpenPair=null;
  document.getElementById('tv-sidebar').classList.remove('open');
  document.getElementById('tv-iframe').src='';
  setTimeout(resizeAllCharts,400);
}
document.getElementById('tv-close-btn').addEventListener('click',closeTV);

// ═══════════════════════════════════════
// REMOVE PAIR
// ═══════════════════════════════════════
function removePair(sym) {
  if (activePairs.length<=1) return;
  if (focusedPair===sym) closeFSOverlay();
  if (tvOpenPair===sym)  closeTV();
  destroyCell(sym);
  activePairs=activePairs.filter(s=>s!==sym);
  const c=document.querySelector(`.chart-cell[data-symbol="${sym}"]`);
  if(c) c.remove();
  setTimeout(resizeAllCharts,50);
}

function destroyCell(sym) {
  stopPolling(sym);
  const st=pairState[sym];
  if(st){if(st.chart){st.chart.remove();st.chart=null;}delete pairState[sym];}
}

// ═══════════════════════════════════════
// LAYOUT BUTTONS
// ═══════════════════════════════════════
document.getElementById('layout-btns').addEventListener('click',e=>{
  const btn=e.target.closest('.layout-btn');
  if(!btn) return;
  currentLayout=btn.dataset.layout;
  document.querySelectorAll('.layout-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  closeFSOverlay();
  buildGrid();
});

// ═══════════════════════════════════════
// INTERVAL BUTTONS
// ═══════════════════════════════════════
document.getElementById('global-intervals').addEventListener('click',e=>{
  const btn=e.target.closest('.iv-btn');
  if(!btn) return;
  document.querySelectorAll('.iv-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  currentInterval={limit:parseInt(btn.dataset.limit),tv:btn.dataset.tv,label:btn.dataset.label};
  activePairs.forEach(sym=>{
    const el=document.getElementById('iv-'+idKey(sym));
    if(el) el.textContent=currentInterval.label;
  });
  stopAllPolling();
  activePairs.forEach(sym=>{const st=ensurePairState(sym);st.allCandles=[];st.latestTime=0;fetchFull(sym);});
  updateClockLabel();
  tickCandleClock();
  if(tvOpenPair) openTV(tvOpenPair);
});

// ═══════════════════════════════════════
// ADD PAIR MODAL
// ═══════════════════════════════════════
let selectedModalPair=null;

function renderModalPairs(filter) {
  const c=document.getElementById('modal-pairs');
  const list=filter?ALL_PAIRS.filter(p=>p.toLowerCase().includes(filter.toLowerCase())):ALL_PAIRS;
  c.innerHTML=list.map(p=>`<button class="modal-pair-btn ${p===selectedModalPair?'selected':''}" data-pair="${p}" ${activePairs.includes(p)?'disabled style="opacity:.3;cursor:not-allowed"':''}>${p}</button>`).join('');
  c.querySelectorAll('.modal-pair-btn:not([disabled])').forEach(btn=>{
    btn.addEventListener('click',()=>{selectedModalPair=btn.dataset.pair;renderModalPairs(document.getElementById('modal-search').value);});
  });
}

document.getElementById('add-pair-btn').addEventListener('click',()=>{selectedModalPair=null;renderModalPairs('');document.getElementById('modal-overlay').classList.add('open');});
document.getElementById('modal-search').addEventListener('input',e=>renderModalPairs(e.target.value));
document.getElementById('modal-cancel').addEventListener('click',()=>document.getElementById('modal-overlay').classList.remove('open'));
document.getElementById('modal-overlay').addEventListener('click',e=>{if(e.target.id==='modal-overlay')document.getElementById('modal-overlay').classList.remove('open');});
document.getElementById('modal-add').addEventListener('click',()=>{
  if(!selectedModalPair) return;
  const max=getMaxCells(currentLayout);
  if(activePairs.length>=max){
    const layouts=['1x1','1x2','2x2','2x3','3x3'];
    const idx=layouts.indexOf(currentLayout);
    if(idx<layouts.length-1){currentLayout=layouts[idx+1];document.querySelectorAll('.layout-btn').forEach(b=>b.classList.toggle('active',b.dataset.layout===currentLayout));}
    else return;
  }
  activePairs.push(selectedModalPair);
  document.getElementById('modal-overlay').classList.remove('open');
  buildGrid();
});

// ═══════════════════════════════════════
// CLOSE / ESCAPE
// ═══════════════════════════════════════
document.getElementById('fs-close-btn').addEventListener('click',closeFSOverlay);
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeFSOverlay();});

// ═══════════════════════════════════════
// VISIBILITY
// ═══════════════════════════════════════
document.addEventListener('visibilitychange',()=>{
  if(document.hidden) stopAllPolling();
  else activePairs.forEach(sym=>{fetchDelta(sym);startPolling(sym);});
});

window.addEventListener('resize',resizeAllCharts);

// ═══════════════════════════════════════
// BOOT
// ═══════════════════════════════════════
buildGrid();

// ✅ AUTO-OPEN FULLSCREEN IF ?symbol= IS IN URL
if (URL_SYMBOL) {
  // Wait for grid + chart to initialise before opening fullscreen
  setTimeout(() => {
    openFSOverlay(URL_SYMBOL);
  }, 800);
}
</script>
</body>
</html>