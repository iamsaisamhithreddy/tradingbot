<?php
// Enable error reporting to debug any issues instantly
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Pull cPanel credentials from db.php ($cpanel_user, $cpanel_token)
require_once 'db.php';

$response_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Collect and sanitize inputs from the form
    $email_prefix = trim($_POST['email']);
    $domain = trim($_POST['domain']);
    $full_email = $email_prefix . '@' . $domain;
    
    $charset = trim($_POST['charset']);
    $interval = intval($_POST['interval']);
    $from = trim($_POST['from']);
    $subject = trim($_POST['subject']);
    $is_html = isset($_POST['html']) ? 1 : 0;
    $body = trim($_POST['body']);
    
    // cPanel Details (credentials & base URL now come from db.php)
    $cpanel_url  = $WebsiteURL . ":2083/execute/Email/add_auto_responder";

    $params = [
        'email'    => $full_email,
        'charset'  => $charset,
        'interval' => $interval,
        'from'     => $from,
        'subject'  => $subject,
        'html'     => $is_html,
        'body'     => $body,
        'start'    => $_POST['start'],
        'stop'     => $_POST['stop']
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $cpanel_url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // Correct Authorization Header format: cpanel username:token
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: cpanel " . $cpanel_user . ":" . $cpanel_token
    ]);

    $result = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        $response_message = "<div style='color: red; margin-bottom: 15px;'>cURL Connection Error: " . htmlspecialchars($curl_error) . "</div>";
    } else {
        $json = json_decode($result, true);
        
        if (isset($json['status']) && $json['status'] == 1) {
            $response_message = "<div style='color: green; margin-bottom: 15px;'>Autoresponder created successfully for " . htmlspecialchars($full_email) . "!</div>";
        } else {
            $error_reason = $json['errors'][0] ?? $json['error'] ?? $json['reason'] ?? 'Raw Response: ' . htmlspecialchars($result);
            $response_message = "<div style='color: red; margin-bottom: 15px;'>cPanel Error: " . $error_reason . "</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Custom Autoresponder</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 600px; background: #fff; padding: 30px; margin: auto; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h2 { color: #1a202c; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-top: 0; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; color: #4a5568; }
        input[type="text"], input[type="number"], select, textarea { width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box; }
        textarea { resize: vertical; height: 120px; }
        .inline-group { display: flex; gap: 10px; align-items: center; }
        button { background-color: #3182ce; color: white; padding: 12px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        button:hover { background-color: #2b6cb0; }
    </style>
</head>
<body>

<div class="container">
    <h2>Create Custom Autoresponder</h2>
    <?php echo $response_message; ?>
    
    <form method="POST" action="">
        <div class="form-group">
            <label>Character Set:</label>
            <select name="charset">
                <option value="utf-8" selected>utf-8</option>
                <option value="iso-8859-1">iso-8859-1</option>
            </select>
        </div>

        <div class="form-group">
            <label>Interval (hours):</label>
            <input type="number" name="interval" value="0">
            <small style="color: #718096;">Hours to wait between responses to the same address, or zero to always respond.</small>
        </div>

        <div class="form-group">
            <label>Email Address:</label>
            <div class="inline-group">
                <input type="text" name="email" placeholder="username" required>
                <span>@</span>
                <select name="domain">
                    <?php $domain_only = parse_url($WebsiteURL, PHP_URL_HOST); ?>
                    <option value="<?php echo htmlspecialchars($domain_only); ?>"><?php echo htmlspecialchars($domain_only); ?></option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>From:</label>
            <input type="text" name="from" value="autoresponse" required>
        </div>

        <div class="form-group">
            <label>Subject:</label>
            <input type="text" name="subject" value="HI %from%" required>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="html" value="1"> This message contains HTML.
            </label>
        </div>

        <div class="form-group">
            <label>Body:</label>
            <textarea name="body" required>hi %from% , how are you %from%!</textarea>
            <small style="color: #718096;">Tags available: %subject%, %from%, %email%</small>
        </div>

        <div class="form-group">
            <label>Start:</label>
            <select name="start">
                <option value="intersects">Immediately</option>
            </select>
        </div>

        <div class="form-group">
            <label>Stop:</label>
            <select name="stop">
                <option value="never">Never</option>
            </select>
        </div>

        <button type="submit">Create Autoresponder</button>
    </form>
</div>

</body>
</html>