<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MANDATORY DISCLAIMER | TradEedify Academic Project</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --bg-dark: #07090e;
      --glass-bg: rgba(17, 22, 33, 0.7);
      --glass-border: rgba(255, 255, 255, 0.08);
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --accent-blue: #3b82f6;
      --accent-red: #ef4444;
      --accent-orange: #f59e0b;
      --gradient-active: linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg-dark);
      /* Modern dark mesh gradient background */
      background-image: 
        radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.15) 0px, transparent 50%),
        radial-gradient(at 100% 100%, rgba(239, 68, 68, 0.1) 0px, transparent 50%);
      background-attachment: fixed;
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      line-height: 1.6;
    }

    .container {
      max-width: 840px;
      width: 100%;
      background: var(--glass-bg);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--glass-border);
      border-radius: 20px;
      padding: 48px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
      animation: fadeUp 0.6s ease-out forwards;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: var(--accent-red);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      padding: 8px 16px;
      border-radius: 30px;
      margin-bottom: 24px;
    }

    .badge::before {
      content: '';
      display: block;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--accent-red);
      box-shadow: 0 0 8px var(--accent-red);
    }

    h1 {
      font-size: 32px;
      font-weight: 700;
      letter-spacing: -0.5px;
      margin-bottom: 16px;
      line-height: 1.2;
    }

    h1 span {
      background: var(--gradient-active);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .subtitle {
      font-size: 16px;
      color: var(--text-muted);
      margin-bottom: 40px;
      max-width: 650px;
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      margin-bottom: 32px;
    }

    @media (max-width: 768px) {
      .grid { grid-template-columns: 1fr; }
      .container { padding: 32px 24px; }
      h1 { font-size: 26px; }
    }

    .card {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid var(--glass-border);
      border-radius: 12px;
      padding: 24px;
      transition: transform 0.3s ease, border-color 0.3s ease;
    }

    .card:hover {
      transform: translateY(-2px);
      border-color: rgba(255, 255, 255, 0.15);
    }

    .card h3 {
      font-size: 15px;
      font-weight: 600;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .card.is-blue h3 { color: var(--accent-blue); }
    .card.is-red h3 { color: var(--accent-red); }

    .card ul {
      list-style: none;
    }

    .card ul li {
      font-size: 14px;
      color: var(--text-muted);
      margin-bottom: 12px;
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }

    .card.is-blue ul li::before {
      content: '✓';
      color: var(--accent-blue);
      font-weight: bold;
    }
    
    .card.is-red ul li::before {
      content: '✕';
      color: var(--accent-red);
      font-weight: bold;
    }

    .legal-box {
      background: rgba(245, 158, 11, 0.05);
      border-left: 4px solid var(--accent-orange);
      padding: 20px;
      border-radius: 4px 8px 8px 4px;
      margin-bottom: 32px;
      font-size: 14px;
      color: #cbd5e1;
    }

    .legal-box strong {
      color: var(--accent-orange);
    }

    /* Custom Checkbox Styling */
    .agreement-wrapper {
      background: rgba(0, 0, 0, 0.2);
      border: 1px solid var(--glass-border);
      padding: 20px;
      border-radius: 12px;
      margin-bottom: 24px;
      transition: all 0.3s ease;
    }

    .agreement-wrapper:hover {
      border-color: rgba(255, 255, 255, 0.2);
    }

    .checkbox-label {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      cursor: pointer;
      user-select: none;
    }

    .checkbox-input {
      display: none;
    }

    .checkbox-box {
      width: 24px;
      height: 24px;
      border: 2px solid var(--text-muted);
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: all 0.2s ease;
      margin-top: 2px;
    }

    .checkbox-box svg {
      width: 14px;
      height: 14px;
      fill: none;
      stroke: white;
      stroke-width: 3;
      stroke-linecap: round;
      stroke-linejoin: round;
      opacity: 0;
      transform: scale(0.5);
      transition: all 0.2s ease;
    }

    .checkbox-input:checked + .checkbox-label .checkbox-box {
      background: var(--gradient-active);
      border-color: transparent;
    }

    .checkbox-input:checked + .checkbox-label .checkbox-box svg {
      opacity: 1;
      transform: scale(1);
    }

    .checkbox-text {
      font-size: 14.5px;
      color: var(--text-main);
      font-weight: 500;
    }

    /* Button Styling */
    .btn {
      display: block;
      width: 100%;
      padding: 16px;
      text-align: center;
      font-size: 16px;
      font-weight: 600;
      color: #fff;
      background: #1e293b;
      border: 1px solid var(--glass-border);
      border-radius: 10px;
      text-decoration: none;
      cursor: not-allowed;
      transition: all 0.3s ease;
    }

    .btn.active {
      background: var(--gradient-active);
      border: none;
      cursor: pointer;
      box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.4);
    }

    .btn.active:hover {
      transform: translateY(-2px);
      box-shadow: 0 15px 30px -5px rgba(59, 130, 246, 0.6);
    }

    .footer {
      margin-top: 32px;
      text-align: center;
      font-size: 13px;
      color: #64748b;
      font-weight: 500;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="badge">Strictly Academic Use</div>
    <h1>Platform for <span>Research & Simulation</span></h1>
    <p class="subtitle">
      TradEedifyis a student-built engineering project developed for a B.Tech final year curriculum. It operates exclusively on <strong>simulated data</strong> for educational visualization.
    </p>

    <div class="grid">
      <!-- Blue Card -->
      <div class="card is-blue">
        <h3>What this project IS</h3>
        <ul>
          <li>A university-level computer science research project.</li>
          <li>A "paper-trading" environment using delayed or mock data.</li>
          <li>A demonstration of data visualization & IoT hardware integration.</li>
          <li>100% free, non-commercial, and open for academic grading.</li>
        </ul>
      </div>

      <!-- Red Card -->
      <div class="card is-red">
        <h3>What this project is NOT</h3>
        <ul>
          <li>Not a SEBI-registered broker or investment advisory.</li>
          <li>Not providing real financial signals or trading tips.</li>
          <li>Not accepting any real money, deposits, or investments.</li>
          <li>Not connected to any real banking or brokerage accounts.</li>
        </ul>
      </div>
    </div>

    <div class="legal-box">
      <strong>Regulatory Notice to Authorities:</strong> This website does not facilitate real trading in Binary Options or Foreign Exchange (restricted by RBI/FEMA). All metrics, "win rates," and signals displayed on the following pages are strictly fabricated for software testing and UI demonstration purposes. We do not collect financial data.
    </div>

    <div class="agreement-wrapper">
      <input type="checkbox" id="agreeCheck" class="checkbox-input">
      <label for="agreeCheck" class="checkbox-label">
        <div class="checkbox-box">
          <!-- SVG Checkmark -->
          <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <span class="checkbox-text">I acknowledge that this is an academic simulation, no real money is involved, and nothing on this platform constitutes financial advice.</span>
      </label>
    </div>

    <!-- Ensure the href points to your actual main application page (e.g., /dashboard or /home) -->
    <a href="/login.php" class="btn" id="continueBtn" onclick="return checkAgreement(event)">Acknowledge & Continue to Simulation</a>

    <div class="footer">
      TradEedify Academic Project &nbsp;•&nbsp; B.Tech Final Year &nbsp;•&nbsp; Not SEBI Registered
    </div>
  </div>

  <script>
    const checkbox = document.getElementById('agreeCheck');
    const btn = document.getElementById('continueBtn');
    const wrapper = document.querySelector('.agreement-wrapper');

    checkbox.addEventListener('change', function() {
      if (this.checked) {
        btn.classList.add('active');
        wrapper.style.borderColor = 'rgba(59, 130, 246, 0.5)';
      } else {
        btn.classList.remove('active');
        wrapper.style.borderColor = 'rgba(255, 255, 255, 0.08)';
      }
    });

    function checkAgreement(e) {
      if (!checkbox.checked) {
        e.preventDefault();
        
        // Add a little shake animation to the checkbox if they try to click without checking
        wrapper.style.transform = 'translateX(-10px)';
        setTimeout(() => wrapper.style.transform = 'translateX(10px)', 50);
        setTimeout(() => wrapper.style.transform = 'translateX(-10px)', 100);
        setTimeout(() => wrapper.style.transform = 'translateX(0)', 150);
        
        return false;
      }
      return true;
    }
  </script>
</body>
</html>