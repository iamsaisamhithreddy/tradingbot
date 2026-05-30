<?php
$pairs = [
    "AUDCAD","AUDCHF","AUDJPY","AUDUSD",
    "CADJPY","CHFJPY",
    "EURAUD","EURCAD","EURCHF","EURGBP","EURJPY","EURUSD",
    "GBPAUD","GBPCAD","GBPCHF","GBPJPY","GBPUSD",
    "USDCAD","USDCHF","USDJPY"
];

function tv($pair) {
    return "https://www.tradingview.com/chart/?symbol=FX:$pair&interval=5";
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>FX Pairs (5m)</title>

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
a.btn {
    background: #1e293b;
    color: #fff;
    text-decoration: none;
    padding: 12px;
    text-align: center;
    border-radius: 6px;
    font-weight: bold;
}
a.btn:hover {
    background: #2563eb;
}
</style>
</head>

<body>

<h2>📊 Forex Pairs (5-Minute Charts)</h2>

<div class="grid">
<?php foreach ($pairs as $p): ?>
    <a class="btn" href="<?= tv($p) ?>" target="_blank">
        <?= $p ?>
    </a>
<?php endforeach; ?>
</div>

</body>
</html>
