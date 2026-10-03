<?php
// Enable error reporting to debug any issues instantly
error_reporting(E_ALL);
ini_set('display_errors', 1);
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

// Domain derived from $WebsiteURL (used in dropdowns & forwarder list below)
$default_domain = parse_url($WebsiteURL, PHP_URL_HOST);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_forwarder') {
        $email_prefix = trim($_POST['email']);
        $domain       = trim($_POST['domain']);
        $full_email   = $email_prefix . '@' . $domain;
        $fwdemail     = trim($_POST['fwdemail']);
        
        $params = [
            'domain'   => $domain,
            'email'    => $full_email,
            'fwdopt'   => 'fwd',
            'fwdemail' => $fwdemail
        ];
        
        $result = call_cpanel_api('Email/add_forwarder', $params, $cpanel_user, $cpanel_token, $WebsiteURL);
        
        if (isset($result['status']) && $result['status'] == 1) {
            $response_message = "<div class='alert success'>Success! Forwarder added for " . htmlspecialchars($full_email) . " -> " . htmlspecialchars($fwdemail) . "!</div>";
        } else {
            $error_msg = $result['errors'][0] ?? 'Unknown error occurred.';
            $response_message = "<div class='alert error'>Error: " . htmlspecialchars($error_msg) . "</div>";
        }
    } elseif ($action === 'add_domain_forwarder') {
        $domain      = trim($_POST['domain']);
        $destdomain  = trim($_POST['destdomain']);
        
        $params = [
            'domain'     => $domain,
            'destdomain' => $destdomain
        ];
        
        $result = call_cpanel_api('Email/add_domain_forwarder', $params, $cpanel_user, $cpanel_token, $WebsiteURL);
        
        if (isset($result['status']) && $result['status'] == 1) {
            $response_message = "<div class='alert success'>Success! Domain forwarder added for " . htmlspecialchars($domain) . " -> " . htmlspecialchars($destdomain) . "!</div>";
        } else {
            $error_msg = $result['errors'][0] ?? 'Unknown error occurred.';
            $response_message = "<div class='alert error'>Error: " . htmlspecialchars($error_msg) . "</div>";
        }
    } elseif ($action === 'delete_forwarder') {
        $address   = trim($_POST['address']);
        $forwarder = trim($_POST['forwarder']);
        
        $params = [
            'address'   => $address,
            'forwarder' => $forwarder
        ];
        
        $result = call_cpanel_api('Email/delete_forwarder', $params, $cpanel_user, $cpanel_token, $WebsiteURL);
        
        if (isset($result['status']) && $result['status'] == 1) {
            $response_message = "<div class='alert success'>Success! Forwarder " . htmlspecialchars($address) . " has been deleted.</div>";
        } else {
            $error_msg = $result['errors'][0] ?? 'Unknown error occurred.';
            $response_message = "<div class='alert error'>Error: " . htmlspecialchars($error_msg) . "</div>";
        }
    } elseif ($action === 'delete_domain_forwarder') {
        // cPanel's delete_domain_forwarder requires BOTH the source domain
        // and the destination domain to identify which mapping to remove
        // (confirmed: passing 'domain' alone triggered "domain cannot be
        // equivalent to destdomain", since destdomain came through empty).
        $domain     = trim($_POST['domain']);
        $destdomain = trim($_POST['destdomain']);

        $params = [
            'domain'     => $domain,
            'destdomain' => $destdomain
        ];

        $result = call_cpanel_api('Email/delete_domain_forwarder', $params, $cpanel_user, $cpanel_token, $WebsiteURL);

        if (isset($result['status']) && $result['status'] == 1) {
            $response_message = "<div class='alert success'>Success! Domain forwarder for " . htmlspecialchars($domain) . " has been deleted.</div>";
        } else {
            $error_msg = $result['errors'][0] ?? 'Unknown error occurred.';
            $response_message = "<div class='alert error'>Error: " . htmlspecialchars($error_msg) . "</div>";
        }
    }
}

// Fetch forwarders list
$search_query = trim($_GET['search'] ?? '');
$list_params = ['domain' => $default_domain];
if (!empty($search_query)) {
    $list_params['regex'] = $search_query;
}

$list_result = call_cpanel_api('Email/list_forwarders', $list_params, $cpanel_user, $cpanel_token, $WebsiteURL);
$forwarders_list = [];
if (isset($list_result['status']) && $list_result['status'] == 1 && isset($list_result['data'])) {
    $forwarders_list = $list_result['data'];
}

// Domain-level forwarders (Forward All Email for a Domain)
$domain_list_result = call_cpanel_api('Email/list_domain_forwarders', ['domain' => $default_domain], $cpanel_user, $cpanel_token, $WebsiteURL);
$domain_forwarders_list = [];
if (isset($domain_list_result['status']) && $domain_list_result['status'] == 1 && isset($domain_list_result['data'])) {
    $domain_forwarders_list = $domain_list_result['data'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email Account Forwarders</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f8f9fa; margin: 0; padding: 30px; color: #333; }
        .container { max-width: 900px; background: #fff; padding: 30px; margin: auto; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        h1 { font-size: 24px; color: #222; margin-top: 0; margin-bottom: 20px; font-weight: normal; }
        h2 { font-size: 22px; color: #222; margin-top: 30px; margin-bottom: 10px; font-weight: normal; }
        p { color: #555; font-size: 14px; line-height: 1.5; margin-bottom: 20px; }
        
        .top-buttons { margin-bottom: 25px; display: flex; gap: 10px; }
        .btn { background-color: #3273dc; color: white; padding: 10px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; font-weight: bold; }
        .btn:hover { background-color: #276cda; }
        .btn-go { background-color: #3273dc; padding: 8px 16px; }
        .btn-delete { background: none; border: none; color: #3273dc; cursor: pointer; padding: 0; font-size: 14px; text-decoration: underline; }
        .btn-delete:hover { color: #1d4ed8; }
        .btn-trace { background: none; border: none; color: #3273dc; cursor: pointer; padding: 0; font-size: 14px; text-decoration: underline; margin-right: 15px; }
        
        .search-bar { display: flex; gap: 10px; margin-bottom: 20px; max-width: 500px; }
        .search-bar input { flex: 1; padding: 8px 12px; border: 1px solid #dbdbdb; border-radius: 4px; font-size: 14px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; }
        th, td { padding: 12px 15px; border-bottom: 1px solid #dbdbdb; text-align: left; font-size: 14px; }
        th { background-color: #f5f5f5; color: #363636; font-weight: bold; border-top: 2px solid #dbdbdb; }
        
        .alert { padding: 12px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; font-size: 14px; }
        .alert.success { background-color: #effaf3; color: #257953; border: 1px solid #b7ebc5; }
        .alert.error { background-color: #feecf0; color: #cc0f35; border: 1px solid #f8b9c7; }
        
        .form-section { background: #f9fafb; padding: 20px; border-radius: 6px; border: 1px solid #e5e7eb; margin-bottom: 25px; display: none; }
        .form-section.active { display: block; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 13px; color: #374151; }
        input[type="text"], select { width: 100%; max-width: 400px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; }
        .inline-group { display: flex; gap: 8px; align-items: center; max-width: 450px; }

        /* Trace modal (shows the forwarder's own config - no external data) */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 8px;
            padding: 25px 30px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .modal-box h3 { margin-top: 0; margin-bottom: 15px; font-size: 18px; color: #222; }
        .modal-row { margin-bottom: 12px; font-size: 14px; }
        .modal-row .label { display: block; font-weight: bold; color: #6b7280; font-size: 12px; text-transform: uppercase; margin-bottom: 2px; }
        .modal-row .value { color: #111; word-break: break-all; }
        .modal-close-btn { margin-top: 10px; background: #6b7280; }
        .modal-close-btn:hover { background: #4b5563; }
    </style>
    <script>
        function toggleForm(formId) {
            document.getElementById('add-forwarder-form').classList.remove('active');
            document.getElementById('add-domain-form').classList.remove('active');
            if(formId) {
                document.getElementById(formId).classList.add('active');
            }
        }

        // Shows the forwarder's own config (source -> destination) in a modal.
        // This is data cPanel already returned to us in the table row - not a
        // live delivery trace (cPanel's Track Delivery tool isn't reachable
        // via the account API token, so we don't pretend otherwise here).
        function showTrace(source, destination) {
            document.getElementById('trace-source').innerText = source;
            document.getElementById('trace-destination').innerText = destination;
            document.getElementById('trace-modal').classList.add('active');
        }

        function closeTrace() {
            document.getElementById('trace-modal').classList.remove('active');
        }
    </script>
</head>
<body>

<div class="container">
    <h1>Create an Email Account Forwarder</h1>
    
    <?php echo $response_message; ?>

    <div class="top-buttons">
        <button class="btn" onclick="toggleForm('add-forwarder-form')">Add Forwarder</button>
        <button class="btn" onclick="toggleForm('add-domain-form')">Add Domain Forwarder</button>
    </div>

    <!-- ADD FORWARDER FORM -->
    <div id="add-forwarder-form" class="form-section">
        <h3>Add a New Email Forwarder</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_forwarder">
            <div class="form-group">
                <label>Address to Forward:</label>
                <div class="inline-group">
                    <input type="text" name="email" placeholder="username" required>
                    <span>@</span>
                    <select name="domain">
                        <option value="<?php echo htmlspecialchars($default_domain); ?>"><?php echo htmlspecialchars($default_domain); ?></option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Destination Email Address:</label>
                <input type="text" name="fwdemail" placeholder="destination@example.com" required style="max-width: 450px;">
            </div>
            <button type="submit" class="btn">Add Forwarder</button>
        </form>
    </div>

    <!-- ADD DOMAIN FORWARDER FORM -->
    <div id="add-domain-form" class="form-section">
        <h3>Add a New Domain Forwarder</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_domain_forwarder">
            <div class="form-group">
                <label>Domain:</label>
                <select name="domain">
                    <option value="<?php echo htmlspecialchars($default_domain); ?>"><?php echo htmlspecialchars($default_domain); ?></option>
                </select>
            </div>
            <div class="form-group">
                <label>Destination Domain:</label>
                <input type="text" name="destdomain" placeholder="example.com" required style="max-width: 450px;">
            </div>
            <button type="submit" class="btn">Add Domain Forwarder</button>
        </form>
    </div>

    <h2>Email Account Forwarders</h2>
    <p>Send a copy of any incoming email from one address to another. For example, forward <strong>joe@example.com</strong> to <strong>joseph@example.com</strong> so that you only have one inbox to check.</p>

    <!-- SEARCH BAR -->
    <form method="GET" action="" class="search-bar">
        <input type="text" name="search" placeholder="Search" value="<?php echo htmlspecialchars($search_query); ?>">
        <button type="submit" class="btn btn-go">Go</button>
        <?php if (!empty($search_query)): ?>
            <a href="?" style="text-decoration: none;"><button type="button" class="btn" style="background-color: #6b7280;">Reset</button></a>
        <?php endif; ?>
    </form>

    <!-- FORWARDERS TABLE -->
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Email Address</th>
                    <th>Forward To</th>
                    <th style="width: 180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($forwarders_list)): ?>
                    <?php foreach ($forwarders_list as $fwd): ?>
                        <?php 
                        $email_addr = $fwd['dest'] ?? ($fwd['email'] ?? '');
                        $forward_to = $fwd['forward'] ?? '';
                        if (empty($email_addr) || strpos($email_addr, '@') === false) continue;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($email_addr); ?></td>
                            <td><?php echo htmlspecialchars($forward_to); ?></td>
                            <td>
                                <a href="#" onclick="showTrace('<?php echo htmlspecialchars($email_addr, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($forward_to, ENT_QUOTES); ?>'); return false;" class="btn-trace">Trace</a>
                                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this forwarder?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_forwarder">
                                    <input type="hidden" name="address" value="<?php echo htmlspecialchars($email_addr); ?>">
                                    <input type="hidden" name="forwarder" value="<?php echo htmlspecialchars($forward_to); ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" style="text-align: center; color: #6b7280;">No email forwarders found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2>Forward All Email for a Domain</h2>
    <p>Forward every incoming message for one domain to another domain. For example, forward all mail for <strong>olddomain.com</strong> to <strong>newdomain.com</strong>.</p>

    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Domain</th>
                    <th>Forward To Domain</th>
                    <th style="width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($domain_forwarders_list)): ?>
                    <?php foreach ($domain_forwarders_list as $dfwd): ?>
                        <?php
                        $source_domain = $dfwd['dest'] ?? '';
                        $dest_domain   = $dfwd['forward'] ?? '';
                        if (empty($source_domain)) continue;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($source_domain); ?></td>
                            <td><?php echo htmlspecialchars($dest_domain); ?></td>
                            <td>
                                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this domain forwarder?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_domain_forwarder">
                                    <input type="hidden" name="domain" value="<?php echo htmlspecialchars($source_domain); ?>">
                                    <input type="hidden" name="destdomain" value="<?php echo htmlspecialchars($dest_domain); ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" style="text-align: center; color: #6b7280;">No domain forwarders found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- TRACE MODAL: shows the forwarder's own config (no live delivery data) -->
<div id="trace-modal" class="modal-overlay" onclick="if(event.target === this) closeTrace();">
    <div class="modal-box">
        <h3>Forwarder Configuration</h3>
        <div class="modal-row">
            <span class="label">Source Address</span>
            <span class="value" id="trace-source"></span>
        </div>
        <div class="modal-row">
            <span class="label">Forwards To</span>
            <span class="value" id="trace-destination"></span>
        </div>
        <button type="button" class="btn modal-close-btn" onclick="closeTrace()">Close</button>
    </div>
</div>

</body>
</html>