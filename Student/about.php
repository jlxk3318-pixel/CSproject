<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>About - NeuroNet Quest</title>
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
            <h3>About NeuroNet Quest</h3>
            <p>NeuroNet Quest is a semantic-network board game that teaches Deep Learning and Neural Network concepts. Each tile represents a concept node — land on it, answer a question, and earn points toward your final score.</p>
            <p>Build streaks by answering correctly in a row to unlock bonus rewards. Your teacher controls dice rolls and session timing from the teacher dashboard.</p>
        </div>
    </div>

    <?php $chatbotRole = 'student'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>
