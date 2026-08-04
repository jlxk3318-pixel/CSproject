<?php
session_start();

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "csproject";

$submitted = false;
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type    = trim($_POST['type'] ?? '');
    $content = trim($_POST['feedback'] ?? '');
    $user_email = $_SESSION['user'] ?? 'guest@unknown.com';

    if (empty($type) || empty($content)) {
        $error_message = "Please select a type and enter your feedback.";
    } else {
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) {
            $error_message = "Database Connection Failed: " . $conn->connect_error;
        } else {
            $status = "Pending";
            $stmt = $conn->prepare("INSERT INTO `feedbacks` (`user_email`, `type`, `content`, `status`, `created_at`) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssss", $user_email, $type, $content, $status);

            if ($stmt->execute()) {
                $submitted = true;
            } else {
                $error_message = "Failed to submit feedback: " . $stmt->error;
            }
            $stmt->close();
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Feedback - NeuroNet Quest</title>
  <link rel="stylesheet" href="CSS/style.css">
</head>
<body>
    <video class="bg-video" autoplay muted loop playsinline>
        <source src="video/12421439_3840_2160_30fps.mp4" type="video/mp4">
    </video>
    <div class="bg-video-overlay"></div>
    <?php include 'header.php'; ?>
    <div class="dashboard-wrap">
        <div class="card">
            <h3>Feedback</h3>
            <p>Tell us what you liked or what felt confusing about NeuroNet Quest.</p>
            <?php if ($submitted): ?>
                <div class="alert">Thanks — your feedback has been recorded.</div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="alert" style="color:#ff6b6b;"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <form method="POST">
                <label>Feedback Type</label>
                <br>
                <input type="text" name="type" placeholder="e.g. Bug Report, Suggestion, UI Feedback..." required>
                <textarea name="feedback" placeholder="Type your feedback here..." required></textarea>
                <br>
                <button type="submit" class="submit-btn">Send Feedback</button>
            </form>
        </div>
    </div>

    <?php $chatbotRole = 'student'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>