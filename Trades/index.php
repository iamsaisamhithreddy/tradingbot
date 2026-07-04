<?php
// Load backend logic
require_once 'includes/config.php';
require_once 'includes/actions.php';
require_once 'includes/analyzer.php';
?>

<?php include 'views/header.php'; ?>

<?php if (!isset($_SESSION['current_file'])): ?>
    <?php include 'views/upload.php'; ?>
<?php else: ?>
    <?php include 'views/dashboard.php'; ?>
<?php endif; ?>

<?php include 'views/footer.php'; ?>