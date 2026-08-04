<?php
session_start();

$isLoggedIn = isset($_SESSION['user']) && ($_SESSION['role'] ?? '') === 'teacher';
if (!$isLoggedIn) {
    header("Location: ../Admin/login.php");
    exit();
}

include 'Database.php';

$stmt = mysqli_prepare(
    $conn,
    "SELECT user_fullname, user_email, user_phonenumber, user_profilePicture, user_reason, user_registerDate, user_status
     FROM users WHERE user_email = ? AND user_role = 'teacher'"
);
mysqli_stmt_bind_param($stmt, "s", $_SESSION['user']);
mysqli_stmt_execute($stmt);
$teacherRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$teacherName  = $teacherRow['user_fullname'] ?? 'Teacher';
$teacherEmail = $teacherRow['user_email'] ?? '';
$teacherPhone = $teacherRow['user_phonenumber'] ?? '';
$teacherReason = $teacherRow['user_reason'] ?? '';
$teacherStatus = $teacherRow['user_status'] ?? '';
$registerDate  = !empty($teacherRow['user_registerDate']) ? date("d M Y", strtotime($teacherRow['user_registerDate'])) : '';

$avatarPath = null;
$avatar_filename = !empty($teacherRow['user_profilePicture']) ? $teacherRow['user_profilePicture'] : '';
if (!empty($avatar_filename)) {
    $avatar_disk_path = $_SERVER['DOCUMENT_ROOT'] . '/csproject/Admin/uploads/' . $avatar_filename;
    if (file_exists($avatar_disk_path)) {
        $avatarPath = '/csproject/Admin/uploads/' . $avatar_filename;
    }
}

$teacherInitial = strtoupper(substr($teacherName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NeuroNet Quest — My Profile</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
<style>
    .profile-hero{ display:flex; align-items:center; gap:24px; }
    .profile-hero .avatar{
        width:84px; height:84px; border-radius:50%; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        font-family: var(--font-display); font-size:30px; font-weight:700;
        background: linear-gradient(135deg, var(--cyan), var(--violet));
        color:#06122B;
        box-shadow: 0 0 0 3px var(--bg-deep), 0 0 0 5px var(--cyan);
    }
    .profile-hero .avatar img{ width:100%; height:100%; object-fit:cover; border-radius:50%; }
    .profile-hero h2{ font-size:23px; margin-bottom:6px; }
    .role-pill{
        display:inline-block; font-size:12px; font-weight:600; letter-spacing:0.4px;
        text-transform:uppercase; padding:3px 10px; border-radius:999px;
        background: linear-gradient(135deg, var(--cyan), var(--violet)); color:#06122B;
    }
    .info-grid{ margin-top:24px; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; }
    .info-tile{ background: rgba(6,11,31,0.4); border:1px solid var(--card-border); border-radius:10px; padding:12px 14px; }
    .info-tile .info-label{ font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color: var(--cyan); font-weight:600; margin-bottom:4px; }
    .info-tile .info-value{ font-size:14px; color: var(--text); word-break: break-word; }
    .status-active{ color: var(--success); font-weight:600; text-transform:capitalize; }
    .status-pending{ color: var(--warn); font-weight:600; text-transform:capitalize; }
    .status-deactive{ color: var(--error); font-weight:600; text-transform:capitalize; }
</style>
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
            <h1>My Profile</h1>
        </div>
    </div>

    <div class="card">
        <div class="profile-hero">
            <div class="avatar">
                <?php if ($avatarPath): ?>
                    <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Avatar">
                <?php else: ?>
                    <?php echo htmlspecialchars($teacherInitial); ?>
                <?php endif; ?>
            </div>
            <div>
                <h2><?php echo htmlspecialchars($teacherName); ?></h2>
                <span class="role-pill">Teacher</span>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-tile">
                <div class="info-label">Email</div>
                <div class="info-value"><?php echo htmlspecialchars($teacherEmail); ?></div>
            </div>

            <?php if ($teacherPhone): ?>
                <div class="info-tile">
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($teacherPhone); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($registerDate): ?>
                <div class="info-tile">
                    <div class="info-label">Joined</div>
                    <div class="info-value"><?php echo htmlspecialchars($registerDate); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($teacherStatus): ?>
                <div class="info-tile">
                    <div class="info-label">Status</div>
                    <div class="info-value status-<?php echo htmlspecialchars(strtolower($teacherStatus)); ?>">
                        <?php echo htmlspecialchars($teacherStatus); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($teacherReason): ?>
                <div class="info-tile" style="grid-column: 1 / -1;">
                    <div class="info-label">Application Reason</div>
                    <div class="info-value"><?php echo htmlspecialchars($teacherReason); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php $chatbotRole = 'teacher'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>