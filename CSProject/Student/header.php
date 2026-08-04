<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If this student has actually joined a game, route Exit through
// leave-game.php first so their `students` row gets flipped to Offline
// before they're sent back to the login page.
$exitUrl = !empty($_SESSION['student_row_id']) ? 'leave-game.php' : '../Admin/login.php';
?>
<div class="header">
    <a href="student-dashboard.php" class="logo" style="text-decoration:none;">
        <div class="logo-box">
            <video autoplay muted loop playsinline>
                <source src="../Shared/assets/logo.mp4" type="video/mp4">
            </video>
        </div>
        <span>TripleT Edu</span>
    </a>

    <div class="nav-btns">
        <button type="button" id="ttq-notif-toggle" class="btn outline" style="padding:8px 12px;" aria-label="Notifications">🔔</button>
        <a href="<?php echo htmlspecialchars($exitUrl); ?>" class="btn outline">Exit</a>
    </div>
</div>

<?php include __DIR__ . '/notification.php'; ?>