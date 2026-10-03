<?php
// Read maintenance data
$maintenanceFile = __DIR__ . '/maintenance.json';
$data = [];
if (file_exists($maintenanceFile)) {
    $data = json_decode(file_get_contents($maintenanceFile), true) ?? [];
}

// Allow overrides from query string (passed by maintenance_check.php)
$message = !empty($_GET['msg']) ? htmlspecialchars(urldecode($_GET['msg'])) : htmlspecialchars($data['message'] ?? 'We are currently performing scheduled maintenance.');
$eta     = !empty($_GET['eta']) ? htmlspecialchars(urldecode($_GET['eta'])) : htmlspecialchars($data['eta'] ?? '');
$etaUnix = !empty($eta) ? strtotime($eta) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta http-equiv="refresh" content="60">
  <title>Under Maintenance</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      background: #0a0f1e;
      color: #f1f5f9;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      position: relative;
    }

    /* Animated background */
    .bg-glow {
      position: fixed;
      inset: 0;
      z-index: 0;
      background: radial-gradient(ellipse 80% 60% at 50% 0%, rgba(56,189,248,0.08) 0%, transparent 70%),
                  radial-gradient(ellipse 60% 40% at 80% 80%, rgba(139,92,246,0.07) 0%, transparent 70%),
                  radial-gradient(ellipse 50% 50% at 20% 60%, rgba(16,185,129,0.05) 0%, transparent 70%);
    }

    .stars {
      position: fixed;
      inset: 0;
      z-index: 0;
      overflow: hidden;
    }
    .star {
      position: absolute;
      border-radius: 50%;
      background: white;
      animation: twinkle var(--d) ease-in-out infinite;
      opacity: 0;
    }
    @keyframes twinkle {
      0%, 100% { opacity: 0; transform: scale(0.8); }
      50% { opacity: var(--o); transform: scale(1.2); }
    }

    /* Main card */
    .card {
      position: relative;
      z-index: 10;
      text-align: center;
      padding: 60px 50px;
      max-width: 600px;
      width: 90%;
    }

    /* Gear icon animation */
    .gear-wrap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 32px;
      position: relative;
    }
    .gear-icon {
      font-size: 4rem;
      display: block;
      animation: spin 8s linear infinite;
    }
    .gear-icon-sm {
      font-size: 2rem;
      position: absolute;
      bottom: -4px;
      right: -10px;
      animation: spin-rev 5s linear infinite;
    }
    @keyframes spin     { from { transform: rotate(0deg);   } to { transform: rotate(360deg);  } }
    @keyframes spin-rev { from { transform: rotate(0deg);   } to { transform: rotate(-360deg); } }

    /* Status badge */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(239,68,68,0.15);
      border: 1px solid rgba(239,68,68,0.4);
      color: #fca5a5;
      padding: 6px 16px;
      border-radius: 100px;
      font-size: .8rem;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      margin-bottom: 24px;
    }
    .status-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #ef4444;
      animation: pulse-red 1.5s ease infinite;
    }
    @keyframes pulse-red {
      0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
      50%      { box-shadow: 0 0 0 6px rgba(239,68,68,0); }
    }

    h1 {
      font-size: 2.8rem;
      font-weight: 900;
      line-height: 1.1;
      margin-bottom: 16px;
      background: linear-gradient(135deg, #f1f5f9, #94a3b8);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .subtitle {
      font-size: 1.05rem;
      color: #94a3b8;
      line-height: 1.7;
      margin-bottom: 40px;
      font-weight: 400;
    }

    /* ETA Countdown */
    .countdown-wrap {
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 20px;
      padding: 28px 32px;
      margin-bottom: 36px;
    }
    .countdown-label {
      font-size: .75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: #64748b;
      margin-bottom: 16px;
    }
    .countdown-timer {
      display: flex;
      gap: 20px;
      justify-content: center;
      align-items: flex-end;
    }
    .countdown-unit {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
    }
    .countdown-num {
      font-size: 2.8rem;
      font-weight: 800;
      color: #38bdf8;
      font-variant-numeric: tabular-nums;
      line-height: 1;
      min-width: 60px;
      text-align: center;
    }
    .countdown-sep {
      font-size: 2rem;
      font-weight: 700;
      color: #334155;
      margin-bottom: 22px;
    }
    .countdown-unit-label {
      font-size: .7rem;
      font-weight: 600;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    .eta-date {
      font-size: .9rem;
      color: #64748b;
      margin-top: 14px;
    }

    /* Progress bar */
    .progress-bar-wrap {
      margin-bottom: 36px;
    }
    .progress-track {
      background: rgba(255,255,255,0.06);
      height: 4px;
      border-radius: 4px;
      overflow: hidden;
    }
    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #38bdf8, #818cf8);
      border-radius: 4px;
      animation: progress-anim 3s ease-in-out infinite;
      width: 30%;
    }
    @keyframes progress-anim {
      0%   { width: 10%; margin-left: 0; }
      50%  { width: 40%; }
      100% { width: 10%; margin-left: 90%; }
    }

    /* Footer links */
    .footer-links {
      display: flex;
      gap: 24px;
      justify-content: center;
      flex-wrap: wrap;
    }
    .footer-link {
      color: #475569;
      text-decoration: none;
      font-size: .85rem;
      font-weight: 500;
      transition: color .2s;
    }
    .footer-link:hover { color: #94a3b8; }

    .admin-link {
      position: fixed;
      bottom: 24px;
      right: 24px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.08);
      color: #475569;
      padding: 8px 16px;
      border-radius: 8px;
      font-size: .75rem;
      font-weight: 600;
      text-decoration: none;
      transition: all .2s;
      z-index: 20;
    }
    .admin-link:hover {
      background: rgba(255,255,255,0.08);
      color: #94a3b8;
    }

    .no-eta-text {
      font-size: 1rem;
      color: #475569;
      font-weight: 500;
    }
  </style>
</head>
<body>

<div class="bg-glow"></div>
<div class="stars" id="stars"></div>

<div class="card">
  <div class="gear-wrap">
    <span class="gear-icon">⚙️</span>
    <span class="gear-icon-sm">⚙️</span>
  </div>

  <div class="status-badge">
    <span class="status-dot"></span>
    Maintenance In Progress
  </div>

  <h1>We'll be back<br>very soon.</h1>

  <p class="subtitle"><?= $message ?></p>

  <?php if ($etaUnix > time()): ?>
  <div class="countdown-wrap">
    <div class="countdown-label">⏳ Estimated Time Remaining</div>
    <div class="countdown-timer" id="countdown">
      <div class="countdown-unit">
        <div class="countdown-num" id="cd-h">--</div>
        <div class="countdown-unit-label">Hours</div>
      </div>
      <div class="countdown-sep">:</div>
      <div class="countdown-unit">
        <div class="countdown-num" id="cd-m">--</div>
        <div class="countdown-unit-label">Minutes</div>
      </div>
      <div class="countdown-sep">:</div>
      <div class="countdown-unit">
        <div class="countdown-num" id="cd-s">--</div>
        <div class="countdown-unit-label">Seconds</div>
      </div>
    </div>
    <div class="eta-date">📅 Back online by: <strong><?= date('D, d M Y g:i A', $etaUnix) ?></strong></div>
  </div>
  <script>
  (function(){
    const target = <?= $etaUnix ?> * 1000;
    function tick(){
      const diff = target - Date.now();
      if (diff <= 0) { location.reload(); return; }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      document.getElementById('cd-h').textContent = String(h).padStart(2,'0');
      document.getElementById('cd-m').textContent = String(m).padStart(2,'0');
      document.getElementById('cd-s').textContent = String(s).padStart(2,'0');
    }
    tick(); setInterval(tick, 1000);
  })();
  </script>
  <?php else: ?>
  <div class="countdown-wrap">
    <div class="countdown-label">Status</div>
    <div class="no-eta-text">🔧 Our team is working hard to get things back online.</div>
  </div>
  <?php endif; ?>

  <div class="progress-bar-wrap">
    <div class="progress-track">
      <div class="progress-fill"></div>
    </div>
  </div>

  <div class="footer-links">
    <span class="footer-link">This page auto-refreshes every 60 seconds</span>
  </div>
</div>

<a href="/login.php" class="admin-link">🔐 Admin Login</a>

<script>
// Generate stars
const starsEl = document.getElementById('stars');
for (let i = 0; i < 120; i++) {
  const s = document.createElement('div');
  s.className = 'star';
  const size = Math.random() * 2.5 + 0.5;
  s.style.cssText = `
    width:${size}px; height:${size}px;
    left:${Math.random()*100}%;
    top:${Math.random()*100}%;
    --d:${(Math.random()*4+2).toFixed(1)}s;
    --o:${(Math.random()*0.5+0.1).toFixed(2)};
    animation-delay:${(Math.random()*5).toFixed(1)}s;
  `;
  starsEl.appendChild(s);
}
</script>
</body>
</html>