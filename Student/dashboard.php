<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Educational Game</title>

    <link rel="stylesheet" href="CSS/style.css">
</head>
<script>
let html5QrCode;

function startScan() {
    document.getElementById("qr-reader").style.display = "block";

    html5QrCode = new Html5Qrcode("qr-reader");

    Html5Qrcode.getCameras().then(devices => {
        if (devices && devices.length) {
            html5QrCode.start(
                devices[0].id,
                {
                    fps: 10,
                    qrbox: 250
                },
                onScanSuccess
            );
        }
    });
}

function onScanSuccess(decodedText, decodedResult) {
    alert("QR Scanned: " + decodedText);

    // stop camera after scan
    html5QrCode.stop().then(() => {
        document.getElementById("qr-reader").style.display = "none";
    });
}
</script>

<body>

    <?php include 'header.php';?>

  <div class="container">

    <!-- LEFT SIDE -->
    <div>
        <!-- PROFILE -->
        <div class="card">
            <div class="profile">
                <div class="avatar">A</div>
                <div>
                    <h3>Alya</h3>
                    <p>Layer 1 access</p>
                </div>
            </div>

            <p>Score progress</p>
            <div class="progress-bar"></div>
            <p>0 / 50</p>

            <div class="badges">
                <span>Tile 0</span>
                <span>Streak 0</span>
                <span>Reward ready: No</span>
            </div>
        </div>

        <!-- QR -->
        <div class="card">
            <h3>Scan QR for Question</h3>
            <p>Only this student's QR code is valid for this layer.</p>

            <div class="qr-box">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=test" alt="QR">

                <div class="qr-buttons">
                    <<button class="scan" onclick="startScan()">Scan My QR</button>
                    <button class="other">Scan Other QR</button>
                </div>
            </div>

            <div class="alert">
                Waiting for the teacher to start the game.
            </div>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div>
        <!-- BOARD -->
        <div class="card">
            <h3>My Board</h3>
            <p>Student sees their current Monopoly position.</p>

            <div class="board">
                <?php
                $tiles = [
                    "Start","Math","Science","Chance","English","History",
                    "Bonus","Geography","Tax","Coding","Art","Jail Visit",
                    "Physics","Community","Biology","Quiz Duel","Chemistry","Library",
                    "Music","Final Gate"
                ];

                foreach ($tiles as $index => $tile) {
                    echo "<div class='tile'>$index<br>$tile</div>";
                }
                ?>
            </div>
        </div>

        <!-- QUESTION -->
        <div class="card question-box">
            <h3>Question</h3>
            <p>Answer correctly to earn points and build a streak.</p>

            <div class="alert">
                Scan your QR code after the teacher enters your dice number.
            </div>
        </div>
    </div>

</div>
<div id="qr-reader" style="width:300px; display:none;"></div>
<script src="https://unpkg.com/html5-qrcode"></script>

<?php $chatbotRole = 'student'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>