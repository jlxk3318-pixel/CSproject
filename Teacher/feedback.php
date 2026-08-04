<?php
session_start();

$isLoggedIn = isset($_SESSION['user']) && ($_SESSION['role'] ?? '') === 'teacher';
if (!$isLoggedIn) {
    header("Location: ../Admin/login.php");
    exit();
}

include 'Database.php';

$teacherName = 'Teacher';
$stmt = mysqli_prepare($conn, "SELECT user_fullname FROM users WHERE user_email = ? AND user_role = 'teacher'");
mysqli_stmt_bind_param($stmt, "s", $_SESSION['user']);
mysqli_stmt_execute($stmt);
$teacherRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!empty($teacherRow['user_fullname'])) {
    $teacherName = $teacherRow['user_fullname'];
}

$submitted = false;
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type    = trim($_POST['type'] ?? '');
    $content = trim($_POST['feedback'] ?? '');

    if ($type === '' || $content === '') {
        $errorMessage = 'Please select a type and enter your feedback.';
    } else {
        $status = 'Pending';
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO feedbacks (user_email, type, content, status, created_at) VALUES (?, ?, ?, ?, NOW())"
        );
        mysqli_stmt_bind_param($stmt, "ssss", $_SESSION['user'], $type, $content, $status);

        if (mysqli_stmt_execute($stmt)) {
            $submitted = true;
        } else {
            $errorMessage = 'Failed to submit feedback: ' . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NeuroNet Quest — Feedback</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
</head>
<body class="teacher-page">

<div class="header">
    <a href="teacher-dashboard.php" class="logo">
        <div class="logo-box">
            <video autoplay muted loop playsinline>
                <source src="../Shared/assets/logo.mp4" type="video/mp4">
            </video>
        </div>
        <span>TripleT Edu</span>
    </a>
    <div class="nav-btns">
        <button type="button" id="ttq-notif-toggle" class="btn outline" style="padding:8px 12px; margin-right:8px;" aria-label="Notifications">🔔</button>
        <span class="pill" style="margin-right:8px;">👋 <?php echo htmlspecialchars($teacherName); ?></span>
        <a href="../Admin/login.php" class="btn outline">Logout</a>
    </div>
</div>

<?php include 'notification.php'; ?>

<div class="dashboard">
    <div class="topbar">
        <div>
            <p class="eyebrow">Hybrid Learning · Board Game</p>
            <h1>Feedback</h1>
        </div>
    </div>

    <div class="card" style="max-width:640px;">
        <h2 class="panel-title" style="margin-bottom:12px;"><span class="dot"></span>Tell us what you liked or what felt confusing</h2>

        <?php if ($submitted): ?>
            <div class="banner banner-success">Thanks — your feedback has been recorded.</div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="banner banner-error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="type">Feedback Type</label>
            <select name="type" id="type" required>
                <option value="">Select a type</option>
                <option value="Bug">Bug</option>
                <option value="Feature Request">Feature Request</option>
                <option value="Other">Other</option>
            </select>

            <label for="feedback">Your Feedback</label>
            <textarea name="feedback" id="feedback" rows="4" required placeholder="Type your feedback here..." style="width:100%; padding:12px; border-radius:10px; background:rgba(6,11,31,0.4); border:1px solid var(--card-border); color:var(--text); font-family:var(--font-body); resize:vertical; margin-bottom:16px;"></textarea>

            <button type="submit" class="btn-primary">Send Feedback</button>
        </form>
    </div>
</div>

<?php $chatbotRole = 'teacher'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>