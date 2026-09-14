<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

$sent_status = null;
$sent_message = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fromName = htmlspecialchars($_POST['name'] ?? '');
    $fromEmail = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $to = filter_var($_POST['to_email'], FILTER_SANITIZE_EMAIL);
    $cc = filter_var($_POST['cc_email'], FILTER_SANITIZE_EMAIL);
    $bcc = filter_var($_POST['bcc_email'], FILTER_SANITIZE_EMAIL);
    $subject = trim($_POST['subject'] ?? 'New Message from Website');
    $subject = str_replace(["\r", "\n"], '', $subject);
    $message = $_POST['message'] ?? '';
    $isHTML = isset($_POST['is_html']);

    $boundary = md5((string) microtime(true));

    $headers = "From: $fromName <$fromEmail>\r\n";
    $headers .= "Reply-To: $fromEmail\r\n";
    if (!empty($cc)) $headers .= "Cc: $cc\r\n";
    if (!empty($bcc)) $headers .= "Bcc: $bcc\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if (!empty($_FILES['attachment']['name'])) {
        $filename = basename($_FILES['attachment']['name']);
        $filedata = file_get_contents($_FILES['attachment']['tmp_name']);
        $filedata = chunk_split(base64_encode($filedata));

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'pdf' => 'application/pdf', 'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip'
        ];
        $filetype = $mimeTypes[$ext] ?? 'application/octet-stream';

        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

        $body = "--$boundary\r\n";
        $body .= "Content-Type: " . ($isHTML ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= $message . "\r\n\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: $filetype; name=\"$filename\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
        $body .= $filedata . "\r\n\r\n";
        $body .= "--$boundary--";
    } else {
        $headers .= "Content-Type: " . ($isHTML ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
        $body = $message;
    }

    if (mail($to, $subject, $body, $headers)) {
        $sent_status = 'ok';
        $sent_message = "Delivered to $to";
    } else {
        $sent_status = 'fail';
        $sent_message = "Mail server rejected the send. Check server mail settings.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mail Composer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#0b0e13;
    --panel:#12161d;
    --panel-2:#171c25;
    --border:#232a35;
    --text:#e7ebf1;
    --muted:#6e7889;
    --accent:#2dd4a7;
    --accent-dim:#173229;
    --warn:#f0b429;
    --danger:#f2545b;
    --mono:'IBM Plex Mono', ui-monospace, monospace;
    --sans:'Inter', -apple-system, sans-serif;
  }
  *{box-sizing:border-box;}
  body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:var(--sans);
    min-height:100vh;
    padding:32px 20px;
  }
  .wrap{ max-width:960px; margin:0 auto; }

  .topbar{
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid var(--border);
  }
  .brand{ display:flex; align-items:center; gap:10px; }
  .brand-mark{
    width:10px; height:10px; border-radius:2px; background:var(--accent);
    box-shadow:0 0 12px rgba(45,212,167,.7);
  }
  .brand-name{ font-family:var(--mono); font-size:13px; letter-spacing:.12em; color:var(--muted); text-transform:uppercase; }
  .status-line{ font-family:var(--mono); font-size:12px; color:var(--muted); display:flex; align-items:center; gap:8px; }
  .status-dot{ width:6px; height:6px; border-radius:50%; background:var(--accent); animation:pulse 2s infinite; }
  @keyframes pulse{ 0%,100%{opacity:1;} 50%{opacity:.3;} }

  .grid{ display:grid; grid-template-columns:1.3fr 1fr; gap:20px; }
  @media (max-width:800px){ .grid{ grid-template-columns:1fr; } }

  .panel{
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:10px;
    overflow:hidden;
  }

  .ticket-header{
    padding:16px 20px;
    border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:between;
  }
  .ticket-header h1{
    font-family:var(--mono); font-size:14px; font-weight:600;
    letter-spacing:.06em; margin:0; color:var(--text);
  }
  .ticket-header .tag{
    font-family:var(--mono); font-size:10px; color:var(--muted);
    border:1px solid var(--border); padding:2px 8px; border-radius:20px; margin-left:auto;
  }

  .field-row{ display:grid; grid-template-columns:1fr 1fr; gap:0; }
  .field{ padding:14px 20px; border-bottom:1px solid var(--border); }
  .field.full{ grid-column:1 / -1; }
  .field.split{ border-right:1px solid var(--border); }

  label{
    display:block; font-family:var(--mono); font-size:10px; letter-spacing:.1em;
    text-transform:uppercase; color:var(--muted); margin-bottom:6px;
  }
  label .req{ color:var(--accent); }

  input[type=text], input[type=email], textarea{
    width:100%; background:transparent; border:none; outline:none;
    color:var(--text); font-family:var(--sans); font-size:14px;
    padding:4px 0; border-bottom:1px solid transparent;
  }
  input:focus, textarea:focus{ border-bottom:1px solid var(--accent); }
  input::placeholder, textarea::placeholder{ color:#3d4552; }
  textarea{ resize:vertical; min-height:110px; line-height:1.5; font-family:var(--sans); }

  .file-drop{
    border:1px dashed var(--border); border-radius:8px; padding:16px;
    display:flex; align-items:center; gap:10px; cursor:pointer;
    font-family:var(--mono); font-size:12px; color:var(--muted);
    transition:border-color .15s;
  }
  .file-drop:hover{ border-color:var(--accent); color:var(--text); }
  .file-drop input{ display:none; }

  .toggle-row{ display:flex; align-items:center; justify-content:space-between; padding:14px 20px; }
  .toggle-label{ font-family:var(--mono); font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; }
  .switch{ position:relative; width:38px; height:20px; }
  .switch input{ opacity:0; width:0; height:0; }
  .slider{
    position:absolute; inset:0; background:#232a35; border-radius:20px; cursor:pointer; transition:.15s;
  }
  .slider:before{
    content:''; position:absolute; width:14px; height:14px; left:3px; top:3px;
    background:#8a94a6; border-radius:50%; transition:.15s;
  }
  input:checked + .slider{ background:var(--accent-dim); }
  input:checked + .slider:before{ transform:translateX(18px); background:var(--accent); }

  .send-bar{ padding:16px 20px; display:flex; align-items:center; gap:12px; }
  button.execute{
    flex:1; background:var(--accent); color:#04140f; border:none; border-radius:6px;
    font-family:var(--mono); font-weight:700; font-size:13px; letter-spacing:.08em;
    text-transform:uppercase; padding:13px; cursor:pointer; transition:.15s;
  }
  button.execute:hover{ background:#3fe4b8; }
  .hint{ font-family:var(--mono); font-size:10px; color:var(--muted); }

  /* Preview panel */
  .preview-header{
    padding:16px 20px; border-bottom:1px solid var(--border);
    font-family:var(--mono); font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:var(--muted);
  }
  .preview-body{ padding:16px 20px; font-family:var(--mono); font-size:12.5px; line-height:1.85; }
  .kv{ display:flex; gap:8px; }
  .kv .k{ color:var(--muted); width:64px; flex-shrink:0; }
  .kv .v{ color:var(--text); word-break:break-all; }
  .kv .v.empty{ color:#3d4552; }
  .divider{ height:1px; background:var(--border); margin:12px 0; }
  .preview-msg{
    color:#a9b2c0; white-space:pre-wrap; max-height:140px; overflow-y:auto;
    border-left:2px solid var(--border); padding-left:10px;
  }
  .badge{
    display:inline-block; font-family:var(--mono); font-size:10px; padding:2px 7px;
    border-radius:4px; margin-top:10px;
  }
  .badge.html{ background:rgba(45,212,167,.12); color:var(--accent); }
  .badge.plain{ background:rgba(110,120,137,.15); color:var(--muted); }

  .preview-tabs{ display:flex; align-items:center; gap:14px; margin-bottom:10px; }
  .preview-tab{
    font-family:var(--mono); font-size:10px; text-transform:uppercase; letter-spacing:.08em;
    color:var(--muted); cursor:pointer; padding-bottom:4px; border-bottom:2px solid transparent;
  }
  .preview-tab.active{ color:var(--text); border-bottom-color:var(--accent); }
  .preview-tab:hover{ color:var(--text); }
  .badge{ margin-left:auto; margin-top:0; }

  .preview-frame-wrap{
    display:none; border:1px solid var(--border); border-radius:6px;
    background:#ffffff; overflow:hidden;
  }
  .preview-frame-wrap.visible{ display:block; }
  .preview-frame{
    display:block; width:100%; border:none; background:#ffffff;
    transform-origin:top left;
  }
  .preview-msg.hidden{ display:none; }

  .alert{
    font-family:var(--mono); font-size:12px; padding:12px 16px; border-radius:8px;
    margin-bottom:16px; border:1px solid;
  }
  .alert.ok{ background:rgba(45,212,167,.08); border-color:rgba(45,212,167,.4); color:var(--accent); }
  .alert.fail{ background:rgba(242,84,91,.08); border-color:rgba(242,84,91,.4); color:var(--danger); }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div class="brand">
      <div class="brand-mark"></div>
      <div class="brand-name">Mail Composer</div>
    </div>
    <div class="status-line"><span class="status-dot"></span> READY</div>
  </div>

  <?php if ($sent_status): ?>
    <div class="alert <?= $sent_status === 'ok' ? 'ok' : 'fail' ?>">
      <?= $sent_status === 'ok' ? '✓ SENT — ' : '✗ FAILED — ' ?><?= htmlspecialchars($sent_message) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="mailForm">
  <div class="grid">

    <!-- LEFT: composer -->
    <div class="panel">
      <div class="ticket-header">
        <h1>Compose message</h1>
        <span class="tag">POST → mail()</span>
      </div>

      <div class="field-row">
        <div class="field split">
          <label>Your name <span class="req">*</span></label>
          <input type="text" name="name" id="f_name" placeholder="Sai Samhith" required>
        </div>
        <div class="field">
          <label>Your email <span class="req">*</span></label>
          <input type="email" name="email" id="f_email" placeholder="you@domain.com" required>
        </div>
      </div>

      <div class="field-row">
        <div class="field split">
          <label>To <span class="req">*</span></label>
          <input type="email" name="to_email" id="f_to" placeholder="recipient@domain.com" required>
        </div>
        <div class="field">
          <label>Subject <span class="req">*</span></label>
          <input type="text" name="subject" id="f_subject" placeholder="New message from website" required>
        </div>
      </div>

      <div class="field-row">
        <div class="field split">
          <label>CC</label>
          <input type="email" name="cc_email" id="f_cc" placeholder="optional">
        </div>
        <div class="field">
          <label>BCC</label>
          <input type="email" name="bcc_email" id="f_bcc" placeholder="optional">
        </div>
      </div>

      <div class="field full">
        <label>Message <span class="req">*</span></label>
        <textarea name="message" id="f_message" placeholder="Write your message…" required></textarea>
      </div>

      <div class="field full">
        <label>Attachment</label>
        <label class="file-drop" for="f_file">
          <span id="fileLabel">Click to attach a file — none selected</span>
          <input type="file" name="attachment" id="f_file">
        </label>
      </div>

      <div class="toggle-row">
        <span class="toggle-label">Send as HTML</span>
        <label class="switch">
          <input type="checkbox" name="is_html" id="f_html">
          <span class="slider"></span>
        </label>
      </div>

      <div class="send-bar">
        <button type="submit" class="execute">Send message</button>
        <span class="hint">⌘ + Enter</span>
      </div>
    </div>

    <!-- RIGHT: live preview -->
    <div class="panel">
      <div class="preview-header">Payload preview</div>
      <div class="preview-body">
        <div class="kv"><span class="k">From</span><span class="v empty" id="p_from">—</span></div>
        <div class="kv"><span class="k">To</span><span class="v empty" id="p_to">—</span></div>
        <div class="kv"><span class="k">Cc</span><span class="v empty" id="p_cc">—</span></div>
        <div class="kv"><span class="k">Bcc</span><span class="v empty" id="p_bcc">—</span></div>
        <div class="kv"><span class="k">Subject</span><span class="v empty" id="p_subject">—</span></div>
        <div class="divider"></div>

        <div class="preview-tabs">
          <span class="preview-tab active" id="tab_rendered" data-tab="rendered">Rendered</span>
          <span class="preview-tab" id="tab_source" data-tab="source">Source</span>
          <span class="badge plain" id="p_type">PLAIN TEXT</span>
        </div>

        <div class="preview-msg" id="p_message">Message body will appear here…</div>
        <div class="preview-frame-wrap" id="p_frame_wrap">
          <iframe class="preview-frame" id="p_frame" sandbox="allow-same-origin" scrolling="no"></iframe>
        </div>
      </div>
    </div>

  </div>
  </form>
</div>

<script>
  const bind = (inputId, previewId, fallback) => {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    input.addEventListener('input', () => {
      const val = input.value.trim();
      preview.textContent = val || fallback;
      preview.classList.toggle('empty', !val);
    });
  };
  bind('f_name', 'p_from', '—');
  bind('f_to', 'p_to', '—');
  bind('f_cc', 'p_cc', '—');
  bind('f_bcc', 'p_bcc', '—');
  bind('f_subject', 'p_subject', '—');

  const msgInput = document.getElementById('f_message');
  const msgPreview = document.getElementById('p_message');
  const frame = document.getElementById('p_frame');
  const frameWrap = document.getElementById('p_frame_wrap');
  const htmlToggle = document.getElementById('f_html');
  const tabRendered = document.getElementById('tab_rendered');
  const tabSource = document.getElementById('tab_source');

  let activeTab = 'rendered';

  function fitFrameToContent() {
    const doc = frame.contentDocument;
    if (!doc || !doc.body) return;

    // natural size of the actual content (e.g. a 600px-wide email table)
    const naturalWidth = Math.max(doc.documentElement.scrollWidth, doc.body.scrollWidth, 1);
    const naturalHeight = Math.max(doc.documentElement.scrollHeight, doc.body.scrollHeight, 1);
    const wrapWidth = frameWrap.clientWidth;

    // scale down to fit the panel width, but never scale up past 1
    const scale = Math.min(wrapWidth / naturalWidth, 1);

    frame.style.width = naturalWidth + 'px';
    frame.style.height = naturalHeight + 'px';
    frame.style.transform = `scale(${scale})`;
    // reserve the full scaled height, no cap — nothing gets clipped
    frameWrap.style.height = (naturalHeight * scale) + 'px';
  }

  function renderFrame() {
    frame.srcdoc = msgInput.value || '<body style="font-family:sans-serif;color:#999;padding:16px;">Nothing to render yet…</body>';
    frame.onload = fitFrameToContent;
  }

  function updateView() {
    const isHtml = htmlToggle.checked;

    // tabs only make sense in HTML mode
    document.querySelectorAll('.preview-tab').forEach(t => t.style.display = isHtml ? 'inline-block' : 'none');

    if (!isHtml) {
      // plain text mode: just show raw text, no iframe
      msgPreview.classList.remove('hidden');
      msgPreview.textContent = msgInput.value || 'Message body will appear here…';
      frameWrap.classList.remove('visible');
      return;
    }

    if (activeTab === 'rendered') {
      renderFrame();
      frameWrap.classList.add('visible');
      msgPreview.classList.add('hidden');
    } else {
      msgPreview.classList.remove('hidden');
      msgPreview.textContent = msgInput.value || 'Message body will appear here…';
      frameWrap.classList.remove('visible');
    }
  }

  window.addEventListener('resize', () => { if (frameWrap.classList.contains('visible')) fitFrameToContent(); });

  msgInput.addEventListener('input', updateView);

  tabRendered.addEventListener('click', () => {
    activeTab = 'rendered';
    tabRendered.classList.add('active');
    tabSource.classList.remove('active');
    updateView();
  });
  tabSource.addEventListener('click', () => {
    activeTab = 'source';
    tabSource.classList.add('active');
    tabRendered.classList.remove('active');
    updateView();
  });

  htmlToggle.addEventListener('change', (e) => {
    const badge = document.getElementById('p_type');
    badge.textContent = e.target.checked ? 'HTML' : 'PLAIN TEXT';
    badge.className = 'badge ' + (e.target.checked ? 'html' : 'plain');
    activeTab = 'rendered';
    tabRendered.classList.add('active');
    tabSource.classList.remove('active');
    updateView();
  });

  // initialize (tabs hidden, plain view) on load
  updateView();

  document.getElementById('f_file').addEventListener('change', (e) => {
    const label = document.getElementById('fileLabel');
    label.textContent = e.target.files.length
      ? '📎 ' + e.target.files[0].name
      : 'Click to attach a file — none selected';
  });

  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
      document.getElementById('mailForm').requestSubmit();
    }
  });
</script>
</body>
</html>