<?php
$pairs = [
    "AUDCAD","AUDCHF","AUDJPY","AUDUSD",
    "CADJPY","CHFJPY",
    "EURAUD","EURCAD","EURCHF","EURGBP","EURJPY","EURUSD",
    "GBPAUD","GBPCAD","GBPCHF","GBPJPY","GBPUSD",
    "USDCAD","USDCHF","USDJPY"
];

function tv($pair) {
    // IMPORTANT: use embed-safe chart URL
    return "https://www.tradingview.com/chart/?symbol=FX:$pair&interval=5";
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>FX Pairs (Web Only)</title>

<style>
body {
    font-family: Arial, sans-serif;
    background: #0f172a;
    color: #fff;
    padding: 20px;
}
h2 {
    text-align: center;
}
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 12px;
}
button {
    background: #1e293b;
    color: #fff;
    padding: 12px;
    border: none;
    border-radius: 6px;
    font-weight: bold;
    cursor: pointer;
}
button:hover {
    background: #2563eb;
}
</style>

<script>
function openChart(url) {
    // 🚫 Prevent TradingView APP opening
    // ✅ Force WEB inside Telegram
    window.open(url, '_self');
}
</script>
</head>

<body>

<h2>📊 Forex Pairs (Web Charts Only)</h2>

<div class="grid">
<?php foreach ($pairs as $p): ?>
    <button onclick="openChart('<?= tv($p) ?>')">
        <?= $p ?>
    </button>
<?php endforeach; ?>
</div>

</body>
</html>
