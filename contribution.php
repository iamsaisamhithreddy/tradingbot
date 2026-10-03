<?php
// ─── GitHub Contributor Viewer ────────────────────────────────────────────────
// Paste any public GitHub repo URL → see who contributed what and when
// Uses GitHub REST API (no auth needed for public repos, 60 req/hr limit)
// Optional: set GITHUB_TOKEN env var or edit $token below for 5000 req/hr
// ─────────────────────────────────────────────────────────────────────────────

$token = getenv('GITHUB_TOKEN') ?: ''; // Optional: paste your PAT here for higher rate limits

function gh_fetch(string $url, string $token): array {
    $headers = [
        'User-Agent: GitHubContributorViewer/1.0',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if ($token) $headers[] = "Authorization: Bearer $token";

    $ctx = stream_context_create(['http' => [
        'header'  => implode("\r\n", $headers),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['error' => 'Network error fetching ' . $url];
    $data = json_decode($body, true);
    if (isset($data['message'])) return ['error' => $data['message']];
    return $data;
}

function parse_repo(string $input): ?array {
    $input = trim($input);
    // Strip trailing slashes, .git suffix, query strings, fragments
    $input = preg_replace('/[?#].*$/', '', $input);
    $input = rtrim($input, '/');
    $input = preg_replace('/\.git$/', '', $input);

    // Support full URL: https://github.com/owner/repo (anything after ignored)
    if (preg_match('~github\.com[/:]([A-Za-z0-9_.\-]+)/([A-Za-z0-9_.\-]+)~i', $input, $m)) {
        return ['owner' => $m[1], 'repo' => $m[2]];
    }
    // Support shorthand: owner/repo
    if (preg_match('~^([A-Za-z0-9_.\-]+)/([A-Za-z0-9_.\-]+)$~', $input, $m)) {
        return ['owner' => $m[1], 'repo' => $m[2]];
    }
    return null;
}

function time_ago(string $date): string {
    $diff = time() - strtotime($date);
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff/60) . 'm ago';
    if ($diff < 86400)     return floor($diff/3600) . 'h ago';
    if ($diff < 2592000)   return floor($diff/86400) . 'd ago';
    if ($diff < 31536000)  return floor($diff/2592000) . 'mo ago';
    return floor($diff/31536000) . 'y ago';
}

function format_date(string $date): string {
    return date('M j, Y · H:i', strtotime($date));
}

$error   = '';
$repo    = null;
$info    = null;
$contribs = [];
$commits  = [];
$repoInput = $_POST['repo'] ?? '';

if ($repoInput) {
    $parsed = parse_repo($repoInput);
    if (!$parsed) {
        $error = 'Could not parse repo. Use format: https://github.com/owner/repo';
    } else {
        ['owner' => $owner, 'repo' => $repoName] = $parsed;
        $base = "https://api.github.com/repos/$owner/$repoName";

        // Fetch repo info
        $info = gh_fetch($base, $token);
        if (isset($info['error'])) {
            $error = 'GitHub API: ' . $info['error'];
            $info  = null;
        } else {
            // Fetch contributors (top 30)
            $contribData = gh_fetch("$base/contributors?per_page=30&anon=false", $token);
            if (!isset($contribData['error'])) {
                $contribs = $contribData;
            }

            // Fetch recent commits (up to 100)
            $commitData = gh_fetch("$base/commits?per_page=100", $token);
            if (!isset($commitData['error'])) {
                $commits = $commitData;
            }

            // Build per-contributor commit list
            $byAuthor = [];
            foreach ($commits as $c) {
                $login = $c['author']['login'] ?? ($c['commit']['author']['name'] ?? 'Unknown');
                $avatar = $c['author']['avatar_url'] ?? '';
                $html   = $c['author']['html_url'] ?? '';
                $byAuthor[$login][] = [
                    'sha'     => substr($c['sha'], 0, 7),
                    'message' => $c['commit']['message'],
                    'date'    => $c['commit']['author']['date'],
                    'avatar'  => $avatar,
                    'profile' => $html,
                    'url'     => $c['html_url'],
                ];
            }
            $repo = ['owner' => $owner, 'name' => $repoName, 'by_author' => $byAuthor];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GitHub Contributor Viewer</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0d1117;
    --surface:   #161b22;
    --border:    #30363d;
    --subtle:    #21262d;
    --text:      #e6edf3;
    --muted:     #8b949e;
    --accent:    #58a6ff;
    --green:     #3fb950;
    --orange:    #d29922;
    --red:       #f85149;
    --tag-bg:    #388bfd1a;
    --tag-text:  #58a6ff;
    --radius:    8px;
    --mono:      'JetBrains Mono', monospace;
  }

  html { scroll-behavior: smooth; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.6;
    min-height: 100vh;
  }

  /* ── Header ── */
  header {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 14px 24px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  header svg { flex-shrink: 0; }
  header .brand {
    font-size: 16px;
    font-weight: 600;
    letter-spacing: -.3px;
  }
  header .brand span { color: var(--accent); }

  /* ── Search form ── */
  .search-wrap {
    max-width: 720px;
    margin: 36px auto 28px;
    padding: 0 16px;
  }
  .search-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted);
    margin-bottom: 8px;
    letter-spacing: .2px;
  }
  .search-row {
    display: flex;
    gap: 8px;
  }
  .search-row input {
    flex: 1;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 14px;
    color: var(--text);
    font-family: var(--mono);
    font-size: 13px;
    outline: none;
    transition: border-color .15s;
  }
  .search-row input:focus { border-color: var(--accent); }
  .search-row input::placeholder { color: var(--muted); }
  .search-row button {
    background: var(--accent);
    color: #0d1117;
    border: none;
    border-radius: var(--radius);
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: opacity .15s;
  }
  .search-row button:hover { opacity: .85; }

  /* ── Error ── */
  .error-box {
    max-width: 720px;
    margin: 0 auto 20px;
    padding: 12px 16px;
    background: #f851491a;
    border: 1px solid var(--red);
    border-radius: var(--radius);
    color: var(--red);
    font-size: 13px;
  }

  /* ── Main layout ── */
  .container { max-width: 960px; margin: 0 auto; padding: 0 16px 60px; }

  /* ── Repo info card ── */
  .repo-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px 22px;
    margin-bottom: 28px;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: flex-start;
  }
  .repo-card .repo-main { flex: 1; min-width: 200px; }
  .repo-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--accent);
    text-decoration: none;
  }
  .repo-title:hover { text-decoration: underline; }
  .repo-desc {
    color: var(--muted);
    margin-top: 6px;
    font-size: 13px;
    max-width: 540px;
  }
  .repo-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 12px;
  }
  .meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: var(--muted);
  }
  .meta-item svg { flex-shrink: 0; }
  .lang-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    background: var(--green);
    display: inline-block;
  }
  .repo-stats {
    display: flex;
    gap: 12px;
    flex-shrink: 0;
  }
  .stat-pill {
    background: var(--subtle);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 4px 12px;
    font-size: 12px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 5px;
  }

  /* ── Tabs ── */
  .tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--border);
    margin-bottom: 24px;
  }
  .tab-btn {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 16px;
    color: var(--muted);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    margin-bottom: -1px;
    transition: color .15s;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .tab-btn.active {
    color: var(--text);
    border-bottom-color: var(--orange);
  }
  .tab-btn:hover:not(.active) { color: var(--text); }
  .badge {
    background: var(--subtle);
    border-radius: 20px;
    padding: 1px 7px;
    font-size: 11px;
    color: var(--muted);
  }

  /* ── Tab panels ── */
  .panel { display: none; }
  .panel.active { display: block; }

  /* ── Contributors grid ── */
  .contrib-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
    gap: 14px;
  }
  .contrib-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
    cursor: pointer;
    transition: border-color .15s, background .15s;
  }
  .contrib-card:hover { border-color: var(--accent); background: var(--subtle); }
  .contrib-card.selected { border-color: var(--accent); background: #58a6ff0d; }
  .contrib-avatar {
    width: 44px; height: 44px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--border);
    flex-shrink: 0;
  }
  .contrib-avatar-placeholder {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: var(--subtle);
    border: 2px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    font-size: 18px;
    font-weight: 700;
    flex-shrink: 0;
  }
  .contrib-info { flex: 1; min-width: 0; }
  .contrib-login {
    font-weight: 600;
    color: var(--text);
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .contrib-count {
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }
  .contrib-bar-wrap {
    margin-top: 8px;
    background: var(--subtle);
    border-radius: 3px;
    height: 5px;
    overflow: hidden;
  }
  .contrib-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--green), var(--accent));
    border-radius: 3px;
    transition: width .4s ease;
  }
  .contrib-rank {
    font-size: 11px;
    font-weight: 700;
    color: var(--orange);
    flex-shrink: 0;
    min-width: 24px;
    text-align: right;
  }

  /* ── Commits timeline ── */
  .filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    flex-wrap: wrap;
  }
  .filter-bar label { font-size: 12px; color: var(--muted); }
  .filter-bar select {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: 5px 10px;
    font-size: 13px;
    outline: none;
  }
  .filter-bar select:focus { border-color: var(--accent); }
  .clear-filter {
    background: var(--subtle);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--muted);
    padding: 5px 10px;
    font-size: 12px;
    cursor: pointer;
  }
  .clear-filter:hover { color: var(--text); }

  .commit-group { margin-bottom: 28px; }
  .commit-group-header {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .8px;
    padding: 8px 0 8px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 0;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .commit-group-header .commit-day-count {
    font-weight: 400;
    color: var(--muted);
    font-size: 11px;
    text-transform: none;
    letter-spacing: 0;
  }

  .commit-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    position: relative;
  }
  .commit-item:last-child { border-bottom: none; }

  .commit-avatar {
    width: 30px; height: 30px;
    border-radius: 50%;
    border: 1px solid var(--border);
    flex-shrink: 0;
    object-fit: cover;
    margin-top: 1px;
  }
  .commit-avatar-placeholder {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: var(--subtle);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    flex-shrink: 0;
  }

  .commit-body { flex: 1; min-width: 0; }
  .commit-message {
    font-size: 13px;
    font-weight: 500;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 560px;
  }
  .commit-message a {
    color: var(--text);
    text-decoration: none;
  }
  .commit-message a:hover { color: var(--accent); }
  .commit-sub {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 3px;
    flex-wrap: wrap;
  }
  .commit-author {
    font-size: 12px;
    color: var(--accent);
    font-weight: 500;
    text-decoration: none;
  }
  .commit-author:hover { text-decoration: underline; }
  .commit-time {
    font-size: 12px;
    color: var(--muted);
  }

  .commit-sha-wrap { flex-shrink: 0; display: flex; align-items: center; gap: 6px; }
  .commit-sha {
    font-family: var(--mono);
    font-size: 11px;
    color: var(--accent);
    background: var(--tag-bg);
    padding: 2px 7px;
    border-radius: 4px;
    text-decoration: none;
    letter-spacing: .3px;
  }
  .commit-sha:hover { background: #58a6ff2a; }

  /* ── Empty / loading ── */
  .empty {
    text-align: center;
    padding: 48px 24px;
    color: var(--muted);
  }
  .empty .icon { font-size: 36px; margin-bottom: 10px; }
  .empty h3 { font-size: 16px; color: var(--text); margin-bottom: 6px; }

  /* ── Responsive ── */
  @media (max-width: 600px) {
    .repo-stats { display: none; }
    .contrib-grid { grid-template-columns: 1fr; }
    .commit-sha-wrap { display: none; }
  }
</style>
</head>
<body>

<header>
  <svg width="22" height="22" viewBox="0 0 16 16" fill="var(--text)">
    <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>
  </svg>
  <span class="brand">Contributor <span>Viewer</span></span>
</header>

<div class="search-wrap">
  <form method="POST" action="">
    <label class="search-label">Enter any public GitHub repository URL</label>
    <div class="search-row">
      <input
        type="text"
        name="repo"
        placeholder="https://github.com/owner/repo  or  owner/repo"
        value="<?= htmlspecialchars($repoInput) ?>"
        autocomplete="off"
        autofocus
      >
      <button type="submit">Fetch →</button>
    </div>
  </form>
</div>

<?php if ($error): ?>
<div class="search-wrap" style="margin-top:0">
  <div class="error-box">⚠ <?= htmlspecialchars($error) ?></div>
</div>
<?php endif; ?>

<?php if ($info && $repo): ?>
<div class="container">

  <!-- ── Repo card ── -->
  <div class="repo-card">
    <div class="repo-main">
      <a class="repo-title" href="<?= htmlspecialchars($info['html_url']) ?>" target="_blank">
        <?= htmlspecialchars($info['full_name']) ?>
      </a>
      <?php if (!empty($info['description'])): ?>
        <p class="repo-desc"><?= htmlspecialchars($info['description']) ?></p>
      <?php endif; ?>
      <div class="repo-meta">
        <?php if (!empty($info['language'])): ?>
          <span class="meta-item"><span class="lang-dot"></span><?= htmlspecialchars($info['language']) ?></span>
        <?php endif; ?>
        <?php if (!empty($info['license']['name'])): ?>
          <span class="meta-item">
            <svg width="14" height="14" fill="var(--muted)" viewBox="0 0 16 16"><path d="M8.75.75V2h.985c.304 0 .603.08.867.231l1.29.736c.038.022.08.033.124.033h2.234a.75.75 0 010 1.5h-.427l2.111 4.692a.75.75 0 01-.154.838l-.53-.53.529.531-.001.002-.002.002-.006.006-.006.005-.01.01-.045.04c-.21.199-.45.336-.711.433a2.25 2.25 0 01-.686.115.75.75 0 01-.686-.427L11.25 9.5l-.25.3-1.5-3.3.5.3-1 .5.25-.3-.5-.3-.5.3.25.3-1-5H7V.75a.75.75 0 011.5 0zm0 0"/></svg>
            <?= htmlspecialchars($info['license']['name']) ?>
          </span>
        <?php endif; ?>
        <span class="meta-item">
          Updated <?= time_ago($info['updated_at']) ?>
        </span>
        <?php if (!empty($info['default_branch'])): ?>
          <span class="meta-item">
            <svg width="13" height="13" fill="var(--muted)" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M11.75 2.5a.75.75 0 100 1.5.75.75 0 000-1.5zm-2.25.75a2.25 2.25 0 113 2.122V6A2.5 2.5 0 019.5 8.5H6a1 1 0 00-1 1v1.128a2.251 2.251 0 11-1.5 0V5.372a2.25 2.25 0 111.5 0v1.836A2.492 2.492 0 016 7h3.5A1 1 0 0010 6V5.372A2.25 2.25 0 019.5 3.25zM4.25 12a.75.75 0 100 1.5.75.75 0 000-1.5zM3.5 3.25a.75.75 0 111.5 0 .75.75 0 01-1.5 0z"/></svg>
            <?= htmlspecialchars($info['default_branch']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
    <div class="repo-stats">
      <span class="stat-pill">
        <svg width="14" height="14" fill="var(--muted)" viewBox="0 0 16 16"><path d="M8 .25a.75.75 0 01.673.418l1.882 3.815 4.21.612a.75.75 0 01.416 1.279l-3.046 2.97.719 4.192a.75.75 0 01-1.088.791L8 12.347l-3.766 1.98a.75.75 0 01-1.088-.79l.72-4.194L.818 6.374a.75.75 0 01.416-1.28l4.21-.611L7.327.668A.75.75 0 018 .25z"/></svg>
        <?= number_format($info['stargazers_count']) ?>
      </span>
      <span class="stat-pill">
        <svg width="14" height="14" fill="var(--muted)" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M5 3.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm0 2.122a2.25 2.25 0 10-1.5 0v.878A2.25 2.25 0 005.75 8.5h4.5a.75.75 0 01.75.75v1.372a2.25 2.25 0 101.5 0V9.25a2.25 2.25 0 00-2.25-2.25h-4.5A.75.75 0 015 6.25v-.878zm3.75 7.378a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm3-8.75a.75.75 0 100 1.5.75.75 0 000-1.5z"/></svg>
        <?= number_format($info['forks_count']) ?>
      </span>
      <span class="stat-pill">
        <svg width="14" height="14" fill="var(--muted)" viewBox="0 0 16 16"><path d="M8 9.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/><path fill-rule="evenodd" d="M8 0a8 8 0 100 16A8 8 0 008 0zM1.5 8a6.5 6.5 0 1113 0 6.5 6.5 0 01-13 0z"/></svg>
        <?= number_format($info['open_issues_count']) ?> issues
      </span>
    </div>
  </div>

  <!-- ── Tabs ── -->
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('contributors', this)">
      <svg width="15" height="15" fill="currentColor" viewBox="0 0 16 16"><path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1H7zm4-6a3 3 0 100-6 3 3 0 000 6z"/><path fill-rule="evenodd" d="M5.216 14A2.238 2.238 0 015 13c0-1.355.68-2.75 1.936-3.72A6.325 6.325 0 005 9c-4 0-5 3-5 4s1 1 1 1h4.216z"/><path d="M4.5 8a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/></svg>
      Contributors
      <span class="badge"><?= count($contribs) ?></span>
    </button>
    <button class="tab-btn" onclick="switchTab('commits', this)">
      <svg width="15" height="15" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10.5 7.75a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0zm1.43.75a4.002 4.002 0 01-7.86 0H.75a.75.75 0 110-1.5h3.32a4.001 4.001 0 017.86 0h3.32a.75.75 0 110 1.5h-3.32z"/></svg>
      Commits
      <span class="badge"><?= count($commits) ?></span>
    </button>
  </div>

  <!-- ── Contributors panel ── -->
  <div id="panel-contributors" class="panel active">
    <?php if (empty($contribs)): ?>
      <div class="empty"><div class="icon">👥</div><h3>No contributors found</h3><p>This repo may have no commits yet.</p></div>
    <?php else: ?>
      <?php $maxContribs = max(array_column($contribs, 'contributions')); ?>
      <div class="contrib-grid">
        <?php foreach ($contribs as $i => $c): ?>
          <?php
            $pct = round(($c['contributions'] / $maxContribs) * 100);
            $login = htmlspecialchars($c['login']);
          ?>
          <div
            class="contrib-card"
            data-login="<?= $login ?>"
            onclick="filterByContributor('<?= $login ?>', this)"
            title="Click to filter commits"
          >
            <?php if (!empty($c['avatar_url'])): ?>
              <img class="contrib-avatar" src="<?= htmlspecialchars($c['avatar_url']) ?>" alt="<?= $login ?>">
            <?php else: ?>
              <div class="contrib-avatar-placeholder"><?= strtoupper($login[0]) ?></div>
            <?php endif; ?>
            <div class="contrib-info">
              <div class="contrib-login"><?= $login ?></div>
              <div class="contrib-count"><?= number_format($c['contributions']) ?> commit<?= $c['contributions'] != 1 ? 's' : '' ?></div>
              <div class="contrib-bar-wrap">
                <div class="contrib-bar" style="width:<?= $pct ?>%"></div>
              </div>
            </div>
            <div class="contrib-rank">#<?= $i + 1 ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p style="margin-top:16px; font-size:12px; color:var(--muted)">
        Click any contributor to filter their commits in the Commits tab.
      </p>
    <?php endif; ?>
  </div>

  <!-- ── Commits panel ── -->
  <div id="panel-commits" class="panel">
    <?php if (empty($commits)): ?>
      <div class="empty"><div class="icon">📭</div><h3>No commits found</h3></div>
    <?php else: ?>
      <!-- Build author list for filter dropdown -->
      <?php
        $authors = [];
        foreach ($commits as $c) {
          $login = $c['author']['login'] ?? ($c['commit']['author']['name'] ?? 'Unknown');
          $authors[$login] = true;
        }
        ksort($authors);
      ?>
      <div class="filter-bar">
        <label>Filter by author:</label>
        <select id="author-filter" onchange="renderCommits()">
          <option value="">— All contributors —</option>
          <?php foreach (array_keys($authors) as $a): ?>
            <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="clear-filter" onclick="clearFilter()">✕ Clear</button>
      </div>

      <div id="commits-list">
        <!-- Rendered by JS below -->
      </div>
    <?php endif; ?>
  </div>

</div><!-- /container -->

<?php
// Build JS commit data
$jsCommits = [];
foreach ($commits as $c) {
    $login  = $c['author']['login'] ?? ($c['commit']['author']['name'] ?? 'Unknown');
    $avatar = $c['author']['avatar_url'] ?? '';
    $profile = $c['author']['html_url'] ?? '';
    $msg    = $c['commit']['message'] ?? '';
    $firstLine = explode("\n", $msg)[0];
    $jsCommits[] = [
        'sha'     => substr($c['sha'], 0, 7),
        'url'     => $c['html_url'] ?? '',
        'message' => $firstLine,
        'author'  => $login,
        'avatar'  => $avatar,
        'profile' => $profile,
        'date'    => $c['commit']['author']['date'] ?? '',
    ];
}
?>

<script>
const COMMITS = <?= json_encode($jsCommits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function switchTab(tab, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('panel-' + tab).classList.add('active');
}

function filterByContributor(login, card) {
  const filter = document.getElementById('author-filter');
  if (!filter) return;
  // Toggle: if already selected, clear
  if (filter.value === login) {
    filter.value = '';
    document.querySelectorAll('.contrib-card').forEach(c => c.classList.remove('selected'));
  } else {
    filter.value = login;
    document.querySelectorAll('.contrib-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
  }
  renderCommits();
  // Switch to commits tab
  const commitsBtn = document.querySelectorAll('.tab-btn')[1];
  switchTab('commits', commitsBtn);
}

function clearFilter() {
  const filter = document.getElementById('author-filter');
  if (filter) filter.value = '';
  document.querySelectorAll('.contrib-card').forEach(c => c.classList.remove('selected'));
  renderCommits();
}

function timeAgo(dateStr) {
  const diff = (Date.now() - new Date(dateStr).getTime()) / 1000;
  if (diff < 60)       return 'just now';
  if (diff < 3600)     return Math.floor(diff/60) + 'm ago';
  if (diff < 86400)    return Math.floor(diff/3600) + 'h ago';
  if (diff < 2592000)  return Math.floor(diff/86400) + 'd ago';
  if (diff < 31536000) return Math.floor(diff/2592000) + 'mo ago';
  return Math.floor(diff/31536000) + 'y ago';
}

function formatDate(dateStr) {
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' });
}

function groupByDay(commits) {
  const groups = {};
  commits.forEach(c => {
    const day = c.date ? new Date(c.date).toDateString() : 'Unknown date';
    if (!groups[day]) groups[day] = [];
    groups[day].push(c);
  });
  return groups;
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderCommits() {
  const filter = document.getElementById('author-filter');
  const selected = filter ? filter.value : '';
  const list = document.getElementById('commits-list');
  if (!list) return;

  let filtered = selected ? COMMITS.filter(c => c.author === selected) : COMMITS;

  if (filtered.length === 0) {
    list.innerHTML = '<div class="empty"><div class="icon">🔍</div><h3>No commits found</h3><p>Try a different filter.</p></div>';
    return;
  }

  const groups = groupByDay(filtered);
  let html = '';
  for (const [day, dayCommits] of Object.entries(groups)) {
    html += `<div class="commit-group">
      <div class="commit-group-header">
        <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M1 2.5A2.5 2.5 0 013.5 0h8.75a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0V1.5h-8a1 1 0 00-1 1v6.708A2.492 2.492 0 013.5 9h3.25a.75.75 0 010 1.5H3.5a1 1 0 100 2h5.75a.75.75 0 010 1.5H3.5A2.5 2.5 0 011 11.5v-9zm13.23 7.79a.75.75 0 00-1.06-1.06l-2.894 2.893-1.018-1.018a.75.75 0 00-1.06 1.061l1.548 1.548a.75.75 0 001.06 0l3.424-3.424z"/></svg>
        ${escHtml(day)}
        <span class="commit-day-count">${dayCommits.length} commit${dayCommits.length !== 1 ? 's' : ''}</span>
      </div>`;
    dayCommits.forEach(c => {
      const avatarHtml = c.avatar
        ? `<img class="commit-avatar" src="${escHtml(c.avatar)}" alt="${escHtml(c.author)}">`
        : `<div class="commit-avatar-placeholder">${escHtml(c.author[0]||'?').toUpperCase()}</div>`;
      const profileTag = c.profile ? `href="${escHtml(c.profile)}" target="_blank"` : '';
      const msgLink = c.url ? `<a href="${escHtml(c.url)}" target="_blank">${escHtml(c.message)}</a>` : escHtml(c.message);
      html += `<div class="commit-item">
        ${avatarHtml}
        <div class="commit-body">
          <div class="commit-message">${msgLink}</div>
          <div class="commit-sub">
            ${c.profile
              ? `<a class="commit-author" ${profileTag}>${escHtml(c.author)}</a>`
              : `<span class="commit-author">${escHtml(c.author)}</span>`}
            <span class="commit-time" title="${escHtml(c.date)}">${c.date ? timeAgo(c.date) : ''}</span>
          </div>
        </div>
        <div class="commit-sha-wrap">
          <a class="commit-sha" href="${escHtml(c.url)}" target="_blank" title="View commit">${escHtml(c.sha)}</a>
        </div>
      </div>`;
    });
    html += '</div>';
  }
  list.innerHTML = html;
}

// Initial render
renderCommits();
</script>

<?php else: ?>

<?php if (!$repoInput): ?>
<!-- Landing empty state -->
<div class="container" style="max-width:600px; text-align:center; padding-top:40px">
  <div style="font-size:48px; margin-bottom:16px">🔍</div>
  <h2 style="font-size:20px; font-weight:600; color:var(--text); margin-bottom:8px">Paste any public GitHub repo URL</h2>
  <p style="color:var(--muted); max-width:400px; margin:0 auto; font-size:13px; line-height:1.7">
    See all contributors ranked by commits, browse the full commit history, and filter by author — all from one page.
  </p>
  <div style="margin-top:24px; display:flex; gap:8px; flex-wrap:wrap; justify-content:center">
    <?php foreach ([
      'torvalds/linux',
      'facebook/react',
      'django/django',
      'vuejs/vue',
    ] as $example): ?>
      <form method="POST">
        <input type="hidden" name="repo" value="<?= $example ?>">
        <button type="submit" style="background:var(--surface); border:1px solid var(--border); border-radius:6px; color:var(--muted); padding:6px 14px; font-size:12px; cursor:pointer; font-family:var(--mono)">
          <?= $example ?>
        </button>
      </form>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

</body>
</html>