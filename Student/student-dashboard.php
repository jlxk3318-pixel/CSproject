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

if ($isLoggedIn) {
    // 已登录，去数据库拿这个学生的真实资料
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Database Connection Failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT user_fullname, user_profilePicture FROM users WHERE user_email = ? AND user_role = 'student'");
    $stmt->bind_param("s", $_SESSION['user']);
    $stmt->execute();
    $result = $stmt->get_result();
    $studentRow = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    $studentName  = $studentRow['user_fullname'] ?? "Student";

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
  <title>Student Dashboard - NeuroNet Quest</title>
  <link rel="stylesheet" href="CSS/style.css">
</head>
<body>

    <video class="bg-video" autoplay muted loop playsinline>
        <source src="video/background.mp4" type="video/mp4">
    </video>
    <div class="bg-video-overlay"></div>

    <?php include 'header.php'; ?>

    <div class="dashboard-wrap">

        <div class="dashboard-hero">
            <div class="avatar">
                <?php if ($avatarPath): ?>
                    <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                <?php else: ?>
                    <?php echo $studentInitial; ?>
                <?php endif; ?>
            </div>
            <div>
                <h2><?php echo htmlspecialchars($studentName); ?></h2>
            </div>
        </div>

        <div class="dashboard-grid">

            <!-- ENTER GAME: 现在用 handleRestrictedClick 统一检查登录状态 -->
            <div class="node-card enter-game" onclick="handleRestrictedClick(event, 'game')" style="cursor:pointer;">
                <div class="node-icon">▶</div>
                <h3>Enter Game</h3>
                <p>Scan your teacher's class QR code to join and enter your board.</p>
            </div>

            <a href="about.php" class="node-card">
                <div class="node-icon">i</div>
                <h3>About</h3>
                <p>Learn how NeuroNet Quest works and what each tile means.</p>
            </a>

            <!-- VIEW PROFILE: 改成 div + onclick，不再是直接的 <a href> -->
            <div class="node-card" onclick="handleRestrictedClick(event, 'profile')" style="cursor:pointer;">
                <div class="node-icon"><?php echo $studentInitial; ?></div>
                <h3>View Profile</h3>
                <p>See your name, avatar, score, and badges.</p>
            </div>

            <a href="feedback.php" class="node-card">
                <div class="node-icon">✎</div>
                <h3>Feedback</h3>
                <p>Tell us what you liked or what felt confusing.</p>
            </a>

        </div>

    </div>

    <!-- JOIN CLASS QR SCANNER MODAL -->
    <div class="qr-modal-overlay" id="join-modal-overlay">
        <div class="qr-modal">
            <div class="qr-modal-header">
                <h3>Join Class</h3>
                <button class="qr-close-btn" onclick="closeJoinScanner()" aria-label="Close scanner">✕</button>
            </div>

            <div id="join-qr-reader"></div>

            <p class="qr-modal-hint" id="join-qr-status">Point your camera at your teacher's class QR code.</p>
        </div>
    </div>

    <script src="https://unpkg.com/html5-qrcode"></script>
    <script>
        // PHP 传过来的登录状态
        const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        const loginUrl = <?php echo json_encode($loginUrl); ?>;

        // 统一处理需要登录才能用的功能 (Enter Game / View Profile)
        function handleRestrictedClick(e, target) {
            if (!isLoggedIn) {
                alert("Please login first");
                window.location.href = loginUrl;
                return;
            }

            if (target === 'game') {
                openJoinScanner();
            } else if (target === 'profile') {
                window.location.href = "profile.php";
            }
        }

        let joinQrCode;
        let joinInProgress = false;

        function openJoinScanner() {
            joinInProgress = false;
            document.getElementById("join-qr-status").textContent = "Point your camera at your teacher's class QR code.";
            document.getElementById("join-modal-overlay").classList.add("active");

            joinQrCode = new Html5Qrcode("join-qr-reader");

            Html5Qrcode.getCameras().then(devices => {
                if (devices && devices.length) {
                    joinQrCode.start(
                        devices[0].id,
                        { fps: 10, qrbox: 220 },
                        onJoinScanSuccess
                    ).catch(err => {
                        console.error("Unable to start camera:", err);
                        document.getElementById("join-qr-status").textContent = "Camera error — please allow camera access and try again.";
                    });
                }
            }).catch(err => {
                console.error("Unable to list cameras:", err);
                document.getElementById("join-qr-status").textContent = "No camera found on this device.";
            });
        }

        function closeJoinScanner() {
            if (joinQrCode) {
                joinQrCode.stop().then(() => {
                    joinQrCode.clear();
                    document.getElementById("join-modal-overlay").classList.remove("active");
                }).catch(() => {
                    document.getElementById("join-modal-overlay").classList.remove("active");
                });
            } else {
                document.getElementById("join-modal-overlay").classList.remove("active");
            }
        }

        function onJoinScanSuccess(decodedText) {
            // 防止同一个 QR 被重复触发多次
            if (joinInProgress) return;
            joinInProgress = true;

            document.getElementById("join-qr-status").textContent = "Verifying class code...";

            fetch("join-class.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ session_code: decodedText })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById("join-qr-status").textContent = "Joined! Entering game...";
                    // 停掉摄像头再跳转
                    if (joinQrCode) {
                        joinQrCode.stop().then(() => {
                            joinQrCode.clear();
                            window.location.href = "game.php";
                        }).catch(() => {
                            window.location.href = "game.php";
                        });
                    } else {
                        window.location.href = "game.php";
                    }
                } else {
                    document.getElementById("join-qr-status").textContent = data.message || "Invalid class code, please try again.";
                    joinInProgress = false;
                }
            })
            .catch(err => {
                console.error("Join request failed:", err);
                document.getElementById("join-qr-status").textContent = "Network error, please try again.";
                joinInProgress = false;
            });
        }

        document.getElementById("join-modal-overlay").addEventListener("click", function (e) {
            if (e.target === this) {
                closeJoinScanner();
            }
        });
    </script>

    <?php $chatbotRole = 'student'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>