<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fromName = htmlspecialchars($_POST['name'] ?? '');
    $fromEmail = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $to = filter_var($_POST['to_email'], FILTER_SANITIZE_EMAIL);
    $cc = filter_var($_POST['cc_email'], FILTER_SANITIZE_EMAIL);
    $bcc = filter_var($_POST['bcc_email'], FILTER_SANITIZE_EMAIL);
    $subject = trim($_POST['subject'] ?? 'New Message from Website');
    $message = $_POST['message'] ?? '';
    $isHTML = isset($_POST['is_html']);

    // Boundary for attachments
    $boundary = md5(time());

    // Headers
    $headers = "From: $fromName <$fromEmail>\r\n";
    $headers .= "Reply-To: $fromEmail\r\n";
    if (!empty($cc)) $headers .= "Cc: $cc\r\n";
    if (!empty($bcc)) $headers .= "Bcc: $bcc\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    // Check for attachment
    if (!empty($_FILES['attachment']['name'])) {
        $filename = basename($_FILES['attachment']['name']);
        $filedata = file_get_contents($_FILES['attachment']['tmp_name']);
        $filedata = chunk_split(base64_encode($filedata));

        // Simple MIME type detection fallback
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'zip' => 'application/zip'
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
        echo "<div style='padding:20px; background:#d4edda; color:#155724; border:1px solid #c3e6cb;'>
                ✅ Mail sent successfully to <b>$to</b>!
              </div>";
    } else {
        echo "<div style='padding:20px; background:#f8d7da; color:#721c24; border:1px solid #f5c6cb;'>
                ❌ Mail sending failed. Check server mail settings.
              </div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Send Mail</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
  <div class="card shadow-lg">
    <div class="card-header bg-primary text-white">
      <h3 class="mb-0">📧 Send Email</h3>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <div class="mb-3">
          <label class="form-label">Your Name</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Your Email</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Recipient Email</label>
          <input type="email" name="to_email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">CC</label>
          <input type="email" name="cc_email" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">BCC</label>
          <input type="email" name="bcc_email" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Subject</label>
          <input type="text" name="subject" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Message</label>
          <textarea name="message" class="form-control" rows="6" required></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Attachment</label>
          <input type="file" name="attachment" class="form-control">
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="is_html" id="is_html">
          <label class="form-check-label" for="is_html">
            Send as HTML Email
          </label>
        </div>
        <button type="submit" class="btn btn-success w-100">Send Mail</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
