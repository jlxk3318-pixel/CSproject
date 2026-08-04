<?php
session_start();

// 数据库连接配置（跟 login.php / admin.php 保持一致）
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "csproject";

// ===== LOGIN CHECK（方案 A：允许 Guest 浏览，不强制跳转）=====
$isLoggedIn = isset($_SESSION['user']) && $_SESSION['role'] === 'student';

$avatarPath = null; // 头像图片的实际访问路径，找不到就保持 null

// Profile 相关默认值
$studentEmail = "";
$studentPhone = "";
$studentStatus = "";
$registerDate  = "";

if ($isLoggedIn) {
    // 已登录，去数据库拿这个学生的真实资料
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Database Connection Failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT user_fullname, user_email, user_phonenumber, user_profilePicture, user_registerDate, user_status FROM users WHERE user_email = ? AND user_role = 'student'");
    $stmt->bind_param("s", $_SESSION['user']);
    $stmt->execute();
    $result = $stmt->get_result();
    $studentRow = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    $studentName   = $studentRow['user_fullname'] ?? "Student";
    $studentEmail  = $studentRow['user_email'] ?? "";
    $studentPhone  = $studentRow['user_phonenumber'] ?? "";
    $studentStatus = $studentRow['user_status'] ?? "";
    $registerDate  = !empty($studentRow['user_registerDate']) ? date("d M Y", strtotime($studentRow['user_registerDate'])) : "";

    // 拼接头像真实文件路径，检查文件是否存在
    $avatar_filename = !empty($studentRow['user_profilePicture']) ? $studentRow['user_profilePicture'] : '';
    if (!empty($avatar_filename)) {
        $avatar_disk_path = $_SERVER['DOCUMENT_ROOT'] . '/csproject/Admin/uploads/' . $avatar_filename;
        if (file_exists($avatar_disk_path)) {
            $avatarPath = '/csproject/Admin/uploads/' . $avatar_filename;
        }
    }
} else {
    $studentName  = "Guest";
    $accessLevel  = "Not logged in";
}

$studentInitial = strtoupper(substr($studentName, 0, 1));

// login.php 的路径
$loginUrl = "/csproject/Admin/login.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile - NeuroNet Quest</title>
  <link rel="stylesheet" href="CSS/style.css">
  <style>
    /* ===== Profile page — scoped extra styling ===== */
    .profile-hero {
        display: flex;
        align-items: center;
        gap: 24px;
    }

    .profile-hero .avatar,
    .profile-hero img.avatar {
        width: 84px;
        height: 84px;
        font-size: 30px;
        flex-shrink: 0;
        box-shadow: 0 0 0 3px var(--bg-main), 0 0 0 5px var(--accent);
    }

    .profile-hero h3 {
        margin: 0 0 6px;
        font-size: 23px;
        color: var(--text-main);
    }

    .role-pill {
        display: inline-block;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        padding: 3px 10px;
        border-radius: 999px;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        color: #04111f;
    }

    .guest-note {
        margin-top: 10px;
    }

    .guest-note a {
        color: var(--accent);
        text-decoration: none;
        font-weight: 600;
    }

    .guest-note a:hover {
        text-decoration: underline;
    }

    .info-grid {
        margin-top: 24px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
    }

    .info-tile {
        background: var(--bg-light);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-sm);
        padding: 12px 14px;
    }

    .info-tile .info-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--accent);
        font-weight: 600;
        margin-bottom: 4px;
    }

    .info-tile .info-value {
        font-size: 14px;
        color: var(--text-main);
        word-break: break-word;
    }

    .status-active { color: var(--success); font-weight: 600; text-transform: capitalize; }
    .status-pending { color: var(--secondary); font-weight: 600; text-transform: capitalize; }
    .status-deactive { color: var(--danger); font-weight: 600; text-transform: capitalize; }
  </style>
</head>
<body>

    <video class="bg-video" autoplay muted loop playsinline>
        <source src="video/12421439_3840_2160_30fps.mp4" type="video/mp4">
    </video>
    <div class="bg-video-overlay"></div>

    <?php include 'header.php'; ?>

    <div class="dashboard-wrap">
        <div class="card">
            <div class="profile-hero">
                <?php if ($avatarPath): ?>
                    <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Avatar" class="avatar" style="object-fit: cover;">
                <?php else: ?>
                    <div class="avatar"><?php echo htmlspecialchars($studentInitial); ?></div>
                <?php endif; ?>

                <div>
                    <h3><?php echo htmlspecialchars($studentName); ?></h3>

                    <?php if (!$isLoggedIn): ?>
                        <p class="guest-note"><a href="<?php echo htmlspecialchars($loginUrl); ?>">Log in to see your profile →</a></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isLoggedIn): ?>
                <div class="info-grid">
                    <div class="info-tile">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($studentEmail); ?></div>
                    </div>

                    <?php if ($studentPhone): ?>
                        <div class="info-tile">
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($studentPhone); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($registerDate): ?>
                        <div class="info-tile">
                            <div class="info-label">Joined</div>
                            <div class="info-value"><?php echo htmlspecialchars($registerDate); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($studentStatus): ?>
                        <div class="info-tile">
                            <div class="info-label">Status</div>
                            <div class="info-value status-<?php echo htmlspecialchars(strtolower($studentStatus)); ?>">
                                <?php echo htmlspecialchars($studentStatus); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php $chatbotRole = 'student'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>