<?php
// Enable error reporting to debug any issues instantly
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Only admins can access this page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Pull cPanel credentials & base URL from db.php ($cpanel_user, $cpanel_token, $WebsiteURL)
require_once 'db.php';

$response_message = "";

// Helper function to handle cPanel API cURL requests cleanly
function call_cpanel_api($endpoint, $params, $cpanel_user, $cpanel_token, $website_url) {
    $cpanel_url = $website_url . ":2083/execute/" . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $cpanel_url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: cpanel " . $cpanel_user . ":" . $cpanel_token
    ]);

    $result = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        return ['status' => 0, 'errors' => ["cURL Connection Error: " . $curl_error]];
    }
    
    return json_decode($result, true);
}

// Domain derived from $WebsiteURL (used in dropdowns below)
$domain_only = parse_url($WebsiteURL, PHP_URL_HOST);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $email_prefix = trim($_POST['email']);
        $domain       = trim($_POST['domain']);
        $full_email   = $email_prefix . '@' . $domain;
        $password     = $_POST['password'];
        $quota        = intval($_POST['quota']);
        
        $email_params = [
            'email'    => $email_prefix,
            'domain'   => $domain,
            'password' => $password,
            'quota'    => $quota
        ];
        
        $email_result = call_cpanel_api('Email/add_pop', $email_params, $cpanel_user, $cpanel_token, $WebsiteURL);
        
        if (isset($email_result['status']) && $email_result['status'] == 1) {
            $response_message = "<div class='alert success'>Success! Email account created for " . htmlspecialchars($full_email) . "!</div>";
        } else {
            $error_msg = $email_result['errors'][0] ?? 'Unknown error occurred.';
            if (stripos($error_msg, 'already exists') !== false || stripos($error_msg, 'exist') !== false) {
                $response_message = "<div class='alert notice'>Notice: The email account " . htmlspecialchars($full_email) . " already exists.</div>";
            } else {
                $response_message = "<div class='alert error'>Email Creation Error: " . htmlspecialchars($error_msg) . "</div>";
            }
        }
    } elseif ($action === 'delete') {
        $full_email = trim($_POST['full_email'] ?? '');
        if (strpos($full_email, '@') !== false) {
            list($email_prefix, $domain) = explode('@', $full_email, 2);
        } else {
            $email_prefix = trim($_POST['del_email'] ?? '');
            $domain       = trim($_POST['del_domain'] ?? '');
            $full_email   = $email_prefix . '@' . $domain;
        }
        
        $email_params = [
            'email'  => $email_prefix,
            'domain' => $domain
        ];
        
        $email_result = call_cpanel_api('Email/delete_pop', $email_params, $cpanel_user, $cpanel_token, $WebsiteURL);
        
        if (isset($email_result['status']) && $email_result['status'] == 1) {
            $response_message = "<div class='alert success'>Success! Email account " . htmlspecialchars($full_email) . " has been deleted.</div>";
        } else {
            $error_msg = $email_result['errors'][0] ?? 'Unknown error occurred.';
            $response_message = "<div class='alert error'>Email Deletion Error: " . htmlspecialchars($error_msg) . "</div>";
        }
    }
}

// Fetch and filter email list via cPanel API
$search_query = trim($_GET['search'] ?? '');
$list_params = [];
if (!empty($search_query)) {
    $list_params['regex'] = $search_query;
}

$list_result = call_cpanel_api('Email/list_pops', $list_params, $cpanel_user, $cpanel_token, $WebsiteURL);
$emails_list = [];
if (isset($list_result['status']) && $list_result['status'] == 1 && isset($list_result['data'])) {
    $emails_list = $list_result['data'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Email Accounts</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 750px; background: #fff; padding: 30px; margin: auto; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h2 { color: #1a202c; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-top: 0; }
        h3 { color: #2d3748; margin-top: 25px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; color: #4a5568; }
        input[type="text"], input[type="password"], input[type="number"], select { width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box; }
        .inline-group { display: flex; gap: 10px; align-items: center; }
        button { background-color: #3182ce; color: white; padding: 10px 18px; border: none; border-radius: 4px; cursor: pointer; font-size: 15px; }
        button:hover { background-color: #2b6cb0; }
        .full-width-btn { width: 100%; padding: 12px; font-size: 16px; }
        .delete-btn { background-color: #e53e3e; }
        .delete-btn:hover { background-color: #c53030; }
        .section-box { background: #f8fafc; padding: 20px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        .alert { padding: 12px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        .alert.success { background-color: #c6f6d5; color: #22543d; border: 1px solid #9ae6b4; }
        .alert.notice { background-color: #feebc8; color: #744210; border: 1px solid #fbd38d; }
        .alert.error { background-color: #fed7d7; color: #742a2a; border: 1px solid #feb2b2; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; }
        th, td { padding: 10px 12px; border: 1px solid #e2e8f0; text-align: left; font-size: 14px; }
        th { background-color: #edf2f7; color: #2d3748; }
        .search-bar { display: flex; gap: 10px; margin-bottom: 15px; }
        .search-bar input { flex: 1; }
    </style>
</head>
<body>

<div class="container">
    <h2>Manage Email Accounts</h2>
    <?php echo $response_message; ?>
    
    <!-- SEARCH & EMAIL LIST SECTION -->
    <div class="section-box">
        <h3>Existing Email Accounts & Search</h3>
        <form method="GET" action="" class="search-bar">
            <input type="text" name="search" placeholder="Search email (e.g. user)" value="<?php echo htmlspecialchars($search_query); ?>">
            <button type="submit">Search</button>
            <?php if (!empty($search_query)): ?>
                <a href="?" style="text-decoration: none;"><button type="button" style="background-color: #718096;">Reset</button></a>
            <?php endif; ?>
        </form>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Email Address</th>
                        <th style="width: 100px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($emails_list)): ?>
                        <?php foreach ($emails_list as $account): ?>
                            <?php 
                            // Skip main cPanel username entry if it appears without an email symbol
                            $email_addr = $account['email'] ?? '';
                            if (empty($email_addr) || strpos($email_addr, '@') === false) continue;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($email_addr); ?></td>
                                <td style="text-align: center;">
                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($email_addr); ?>?');" style="margin: 0;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="full_email" value="<?php echo htmlspecialchars($email_addr); ?>">
                                        <button type="submit" class="delete-btn" style="padding: 6px 12px; font-size: 13px;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" style="text-align: center; color: #718096;">No email accounts found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- CREATE EMAIL FORM -->
    <div class="section-box">
        <h3>Create Email Account</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create">
            
            <div class="form-group">
                <label>Email Address:</label>
                <div class="inline-group">
                    <input type="text" name="email" placeholder="username" required>
                    <span>@</span>
                    <select name="domain">
                        <option value="<?php echo htmlspecialchars($domain_only); ?>"><?php echo htmlspecialchars($domain_only); ?></option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Email Password:</label>
                <input type="password" name="password" placeholder="Secure mailbox password" required>
            </div>

            <div class="form-group">
                <label>Mailbox Quota (MB):</label>
                <input type="number" name="quota" value="250">
                <small style="color: #718096;">Enter size in MB, or set to 0 for unlimited.</small>
            </div>

            <button type="submit" class="full-width-btn">Create Email Account</button>
        </form>
    </div>

    <!-- DELETE EMAIL FORM BY TYPING -->
    <div class="section-box">
        <h3>Delete Email Account (Manual)</h3>
        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this email account and all of its data?');">
            <input type="hidden" name="action" value="delete">
            
            <div class="form-group">
                <label>Email Address to Delete:</label>
                <div class="inline-group">
                    <input type="text" name="del_email" placeholder="username" required>
                    <span>@</span>
                    <select name="del_domain">
                        <option value="<?php echo htmlspecialchars($domain_only); ?>"><?php echo htmlspecialchars($domain_only); ?></option>
                    </select>
                </div>
            </div>

            <button type="submit" class="delete-btn full-width-btn">Delete Email Account</button>
        </form>
    </div>
</div>

</body>
</html>