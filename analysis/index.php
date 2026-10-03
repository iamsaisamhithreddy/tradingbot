<?php
/**
 * Trading Strategy Research Dashboard
 * Simple navigation page for all analysis reports.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Trading Strategy Research Dashboard</title>

<style>
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: #f4f6f8;
        color: #222;
    }

    .header {
        background: #1f2937;
        color: #fff;
        padding: 35px 20px;
        text-align: center;
    }

    .header h1 {
        margin: 0 0 10px;
        font-size: 30px;
    }

    .header p {
        margin: 0;
        color: #d1d5db;
        font-size: 15px;
    }

    .container {
        max-width: 1200px;
        margin: 35px auto;
        padding: 0 20px;
    }

    .intro {
        background: #fff;
        border-left: 5px solid #2563eb;
        padding: 20px;
        margin-bottom: 30px;
        border-radius: 6px;
    }

    .intro h2 {
        margin-top: 0;
        font-size: 20px;
    }

    .intro p {
        margin-bottom: 0;
        color: #555;
        line-height: 1.6;
    }

    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 22px;
        transition: 0.2s;
        display: flex;
        flex-direction: column;
    }

    .card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    }

    .number {
        width: 38px;
        height: 38px;
        background: #2563eb;
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        margin-bottom: 15px;
    }

    .card h3 {
        margin: 0 0 10px;
        font-size: 19px;
        color: #1f2937;
    }

    .card p {
        color: #666;
        font-size: 14px;
        line-height: 1.6;
        flex-grow: 1;
    }

    .tags {
        margin: 12px 0 18px;
    }

    .tag {
        display: inline-block;
        background: #eff6ff;
        color: #2563eb;
        padding: 5px 9px;
        margin: 3px 3px 0 0;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
    }

    .btn {
        display: inline-block;
        text-decoration: none;
        text-align: center;
        background: #2563eb;
        color: white;
        padding: 11px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: bold;
    }

    .btn:hover {
        background: #1d4ed8;
    }

    .footer {
        text-align: center;
        color: #777;
        padding: 30px 20px;
        font-size: 13px;
    }

    @media (max-width: 600px) {
        .header h1 {
            font-size: 24px;
        }

        .container {
            margin-top: 20px;
        }
    }
</style>
</head>

<body>

<div class="header">
    <h1>Trading Strategy Research Dashboard</h1>
    <p>Explore statistical patterns and performance across historical trading data</p>
</div>

<div class="container">

    <div class="intro">
        <h2>About This Research</h2>

        <p>
            This dashboard provides access to multiple statistical analyses
            performed on historical trading signals. Each analysis examines a
            different market or trade-related factor to identify patterns,
            strengths, weaknesses and possible areas for further research.
        </p>
    </div>

    <div class="grid">

        <!-- ADVANCED TRADE ANALYSIS -->
        <div class="card">

            <div class="number">1</div>

            <h3>Trade Behaviour Analysis</h3>

            <p>
                Examines multiple characteristics of historical trades,
                including trade duration, re-entry timing, confirmation candle
                behaviour and rolling win-rate stability.
            </p>

            <div class="tags">
                <span class="tag">Duration</span>
                <span class="tag">Re-entry</span>
                <span class="tag">Rolling WR</span>
            </div>

            <a href="advance.php" class="btn">
                Open Analysis →
            </a>

        </div>


        <!-- CANDLE BODY ANALYSIS -->
        <div class="card">

            <div class="number">2</div>

            <h3>Candle Body Analysis</h3>

            <p>
                Studies the relationship between candle-body characteristics
                and trading outcomes to identify whether candle strength or
                price-action structure influences strategy performance.
            </p>

            <div class="tags">
                <span class="tag">Candles</span>
                <span class="tag">Price Action</span>
                <span class="tag">Confirmation</span>
            </div>

            <a href="candle_body.php" class="btn">
                Open Analysis →
            </a>

        </div>


        <!-- PAIR CORRELATION -->
        <div class="card">

            <div class="number">3</div>

            <h3>Pair Correlation Analysis</h3>

            <p>
                Examines signals occurring across different currency pairs
                and evaluates whether simultaneous or correlated pair
                movements influence trade performance.
            </p>

            <div class="tags">
                <span class="tag">Currency Pairs</span>
                <span class="tag">Correlation</span>
                <span class="tag">Clusters</span>
            </div>

            <a href="pair.php" class="btn">
                Open Analysis →
            </a>

        </div>


        <!-- VOLATILITY ANALYSIS -->
        <div class="card">

            <div class="number">4</div>

            <h3>Volatility & News Analysis</h3>

            <p>
                Evaluates strategy performance across different market
                conditions and examines the relationship between trade
                outcomes, volatility and economic news events.
            </p>

            <div class="tags">
                <span class="tag">Volatility</span>
                <span class="tag">News</span>
                <span class="tag">Market Regimes</span>
            </div>

            <a href="volatility.php" class="btn">
                Open Analysis →
            </a>

        </div>

    </div>

</div>

<div class="footer">
    Trading Strategy Research Dashboard &nbsp;•&nbsp;
    Statistical Analysis of Historical Trading Data
</div>

</body>
</html>