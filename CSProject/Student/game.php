<?php
session_start();
require_once __DIR__ . '/../Teacher/Database.php';

// Must be logged in as a student.
$isLoggedIn = isset($_SESSION['user']) && ($_SESSION['role'] ?? '') === 'student';
if (!$isLoggedIn) {
    header('Location: ../Admin/login.php');
    exit();
}

// Must have actually joined a game via the QR scanner on student-dashboard.php.
if (empty($_SESSION['student_row_id']) || empty($_SESSION['game_session_id'])) {
    header('Location: student-dashboard.php');
    exit();
}

$studentRowId  = (int) $_SESSION['student_row_id'];
$gameSessionId = (int) $_SESSION['game_session_id'];

$stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE id = ? AND game_session_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $studentRowId, $gameSessionId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$stmt = mysqli_prepare($conn, "SELECT * FROM game_session WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
mysqli_stmt_execute($stmt);
$gameSession = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Row got reset/deleted (e.g. teacher hit "Reset This Game") — send the
// student back to scan in again instead of showing a broken board.
if (!$student || !$gameSession) {
    unset($_SESSION['student_row_id'], $_SESSION['game_session_id']);
    header('Location: student-dashboard.php');
    exit();
}

$boardSize      = getEffectiveBoardSize($conn, $gameSession);
$studentName    = $student['student_name'];
$studentInitial = strtoupper(substr($studentName, 0, 1));
$layerNumber    = (int) $student['layer_number'];
$score          = (int) $student['score'];
$currentTile    = (int) $student['current_tile'];
$scoreProgress  = (int) round(min(100, ($score / WINNING_SCORE) * 100));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NeuroNet Quest - Game</title>
  <link rel="stylesheet" href="CSS/style.css">
</head>
<body>

    <video class="bg-video" autoplay muted loop playsinline>
        <source src="video/background.mp4" type="video/mp4">
    </video>
    <div class="bg-video-overlay"></div>

    <?php include 'header.php'; ?>

    <div class="container">

        <!-- LEFT SIDE -->
        <div>
            <!-- PROFILE -->
            <div class="card">
                <div class="profile">
                    <div class="avatar"><?php echo htmlspecialchars($studentInitial); ?></div>
                    <div>
                        <h3><?php echo htmlspecialchars($studentName); ?></h3>
                        <p>Layer <?php echo $layerNumber; ?> access</p>
                    </div>
                </div>

                <p>Score progress</p>
                <div class="progress-bar" id="score-progress-bar" style="--progress: <?php echo $scoreProgress; ?>%;"></div>
                <p><span id="score-value"><?php echo $score; ?></span> / <?php echo WINNING_SCORE; ?></p>

                <div class="badges">
                    <span>Tile <?php echo $currentTile; ?></span>
                    <span>Streak 0</span>
                    <span>Reward ready: No</span>
                </div>
            </div>

            <!-- MY POWERS -->
            <div class="card" id="powers-card">
                <h3>My Powers</h3>
                <p>Land on a ⚡ node to pick one up, then use it whenever you want.</p>

                <div id="armed-power-banner" class="alert" style="display:none; margin:10px 0;"></div>

                <div id="powers-list">
                    <p style="color:var(--text-muted, #8aa); font-size:13px;">No power cards yet.</p>
                </div>
            </div>

        </div>

        <!-- RIGHT SIDE -->
        <div>
            <!-- QUESTION -->
            <div class="card question-box">
                <div class="question-header">
                    <h3>Question</h3>
                    <button class="scan" onclick="openScanner()">Scan My QR</button>
                </div>
                <p>Answer correctly to earn points and build a streak.</p>

                <div class="alert" id="question-placeholder">
                    Scan your QR code after the teacher rolls your dice.
                </div>

                <div id="question-panel" style="display:none;">
                    <p id="question-topic" style="font-size:12px; color:var(--text-muted, #8aa); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.4px;"></p>
                    <p id="question-text" style="font-weight:600; margin-bottom:12px;"></p>
                    <div id="question-options"></div>
                    <button type="button" id="submit-answer-btn" class="scan" style="margin-top:14px;">Submit Answer</button>
                </div>
            </div>

        </div>

    </div>

    <!-- QR SCANNER MODAL -->
    <div class="qr-modal-overlay" id="qr-modal-overlay">
        <div class="qr-modal">
            <div class="qr-modal-header">
                <h3>Scan My QR</h3>
                <button class="qr-close-btn" onclick="closeScanner()" aria-label="Close scanner">✕</button>
            </div>

            <div id="qr-reader"></div>

            <p class="qr-modal-hint">Point your camera at your personal QR code.</p>
        </div>
    </div>

    <script src="https://unpkg.com/html5-qrcode"></script>
    <script>
        let html5QrCode;

        function openScanner() {
            document.getElementById("qr-modal-overlay").classList.add("active");

            html5QrCode = new Html5Qrcode("qr-reader");

            Html5Qrcode.getCameras().then(devices => {
                if (devices && devices.length) {
                    html5QrCode.start(
                        devices[0].id,
                        {
                            fps: 10,
                            qrbox: 220
                        },
                        onScanSuccess
                    ).catch(err => {
                        console.error("Unable to start camera:", err);
                    });
                }
            }).catch(err => {
                console.error("Unable to list cameras:", err);
            });
        }

        function closeScanner() {
            if (html5QrCode) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                    document.getElementById("qr-modal-overlay").classList.remove("active");
                }).catch(() => {
                    // camera may already be stopped
                    document.getElementById("qr-modal-overlay").classList.remove("active");
                });
            } else {
                document.getElementById("qr-modal-overlay").classList.remove("active");
            }
        }

        let currentQuestionId = null;
        let selectedAnswer = null;

        function onScanSuccess(decodedText, decodedResult) {
            closeScanner();

            const placeholder = document.getElementById("question-placeholder");
            const panel = document.getElementById("question-panel");

            placeholder.style.display = "block";
            placeholder.textContent = "Checking your QR code...";
            panel.style.display = "none";

            fetch("scan-token.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ token: decodedText })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    placeholder.textContent = data.message || "Invalid QR code, please try again.";
                    return;
                }

                // Power nodes hand out a power card instead of a question —
                // no options to answer, just refresh the My Powers card.
                if (data.type === "power") {
                    alert("⚡ You landed on a Power node!\n\n" + data.power.label + " — " + data.power.description);
                    placeholder.textContent = "Scan your QR code after the teacher rolls your dice.";
                    panel.style.display = "none";
                    loadPowers();
                    return;
                }

                currentQuestionId = data.question.id;
                selectedAnswer = null;

                document.getElementById("question-topic").textContent = data.question.topic || "";
                document.getElementById("question-text").textContent = data.question.text;

                const letters = ["a", "b", "c", "d"];
                document.getElementById("question-options").innerHTML = letters.map(letter => {
                    const label = letter.toUpperCase();
                    const text = data.question["option_" + letter] || "";
                    return '<label class="option" data-answer="' + label + '" style="display:block; margin-bottom:8px; cursor:pointer;">'
                         + '<input type="radio" name="answer" value="' + label + '" style="margin-right:8px;">' + label + ') ' + text
                         + '</label>';
                }).join("");

                placeholder.style.display = "none";
                panel.style.display = "block";
            })
            .catch(() => {
                placeholder.textContent = "Network error, please try again.";
            });
        }

        // Track which option is selected (also drives the .selected highlight
        // via the existing script.js-style option click handler below).
        document.addEventListener("click", function (e) {
            const option = e.target.closest("#question-options .option");
            if (!option) return;
            selectedAnswer = option.getAttribute("data-answer");
            document.querySelectorAll("#question-options .option").forEach(el => el.classList.remove("selected"));
            option.classList.add("selected");
        });

        const submitAnswerBtn = document.getElementById("submit-answer-btn");
        if (submitAnswerBtn) {
            submitAnswerBtn.addEventListener("click", function () {
                if (!currentQuestionId || !selectedAnswer) {
                    alert("Please select an answer first.");
                    return;
                }

                fetch("submit-answer.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ question_id: currentQuestionId, selected_answer: selectedAnswer })
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        alert(data.message || "Could not submit your answer, please try again.");
                        return;
                    }

                    const delta = data.points_delta || 0;
                    let message;
                    if (data.correct) {
                        message = "Correct! +" + delta + " points.";
                        if (data.power_applied === "double_points" || data.power_applied === "gamble") {
                            message += " (Power doubled it!)";
                        }
                    } else if (data.power_applied === "gamble") {
                        message = "Not quite — the correct answer was " + data.correct_answer + ". Your gamble backfired: " + delta + " points.";
                    } else if (data.power_applied === "double_points") {
                        message = "Not quite — the correct answer was " + data.correct_answer + ". Your Double Points shield protected you — no points lost!";
                    } else {
                        message = "Not quite — the correct answer was " + data.correct_answer + ". " + delta + " points.";
                    }
                    alert(message);

                    // Refresh so the profile card (score/tile) reflects the
                    // just-graded answer straight from the database.
                    window.location.reload();
                })
                .catch(() => {
                    alert("Network error, please try again.");
                });
            });
        }

        // allow closing by clicking outside the modal box
        document.getElementById("qr-modal-overlay").addEventListener("click", function (e) {
            if (e.target === this) {
                closeScanner();
            }
        });

        // Poll for "Class Ended" — the teacher may Reset or Mark Finished
        // this game at any moment from their dashboard, so this page has
        // to find out on its own instead of just going stale.
        let classEndedShown = false;
        function checkSessionStatus() {
            if (classEndedShown) return;
            fetch("session-status.php", { cache: "no-store" })
                .then(res => res.json())
                .then(data => {
                    if (data.ended) {
                        classEndedShown = true;
                        alert(data.message || "Class ended.");
                        window.location.href = "leave-game.php";
                    }
                })
                .catch(() => {
                    // Silently ignore network hiccups; next interval will retry
                });
        }
        setInterval(checkSessionStatus, 5000);

        // ------------------------------------------------------------------
        // My Powers — cards this student picked up from ⚡ nodes. They're
        // stored (not auto-applied), so the student picks when to use one.
        // Polled every 5s too, so a Steal Points hit from another student
        // shows up on your score/opponent list without a manual refresh.
        // ------------------------------------------------------------------
        function loadPowers() {
            fetch("my-powers.php", { cache: "no-store" })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) return;
                    renderPowers(data);
                })
                .catch(() => {
                    // Silently ignore network hiccups; next interval will retry
                });
        }

        function renderPowers(data) {
            const scoreEl = document.getElementById("score-value");
            const barEl = document.getElementById("score-progress-bar");
            if (scoreEl && typeof data.my_score === "number") {
                scoreEl.textContent = data.my_score;
                if (barEl) {
                    const pct = Math.max(0, Math.min(100, Math.round((data.my_score / <?php echo (int) WINNING_SCORE; ?>) * 100)));
                    barEl.style.setProperty("--progress", pct + "%");
                }
            }

            const banner = document.getElementById("armed-power-banner");
            if (data.armed) {
                banner.style.display = "block";
                banner.textContent = "⚡ " + data.armed.label + " is armed — it applies to your next graded answer.";
            } else {
                banner.style.display = "none";
                banner.textContent = "";
            }

            const list = document.getElementById("powers-list");
            const powers = data.powers || [];
            if (!powers.length) {
                list.innerHTML = '<p style="color:var(--text-muted, #8aa); font-size:13px;">No power cards yet.</p>';
                return;
            }

            const opponents = data.opponents || [];
            list.innerHTML = powers.map(function (p) {
                let targetPicker = "";
                if (p.type === "steal_points") {
                    const options = opponents.map(function (o) {
                        return '<option value="' + o.id + '">' + o.name + ' (' + o.score + ' pts)</option>';
                    }).join("");
                    targetPicker = '<select class="steal-target" data-power-id="' + p.id + '" style="width:100%; margin-bottom:8px;">'
                                 + '<option value="">Choose a target...</option>' + options + '</select>';
                }
                return '<div class="power-card-item" style="border:1px solid rgba(255,255,255,0.15); border-radius:10px; padding:10px; margin-bottom:10px;">'
                     + '<strong>' + p.label + '</strong>'
                     + '<p style="font-size:12px; color:var(--text-muted, #8aa); margin:4px 0 8px;">' + p.description + '</p>'
                     + targetPicker
                     + '<button type="button" class="scan use-power-btn" data-power-id="' + p.id + '" data-power-type="' + p.type + '">Use</button>'
                     + '</div>';
            }).join("");
        }

        document.addEventListener("click", function (e) {
            const btn = e.target.closest(".use-power-btn");
            if (!btn) return;

            const powerId = btn.getAttribute("data-power-id");
            const powerType = btn.getAttribute("data-power-type");
            let targetId = "";

            if (powerType === "steal_points") {
                const select = document.querySelector('.steal-target[data-power-id="' + powerId + '"]');
                targetId = select ? select.value : "";
                if (!targetId) {
                    alert("Choose who to steal from first.");
                    return;
                }
            }

            btn.disabled = true;

            fetch("use-power.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ power_id: powerId, target_student_id: targetId })
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                if (!data.success) {
                    alert(data.message || "Could not use that power.");
                    return;
                }
                if (data.effect === "stolen") {
                    alert("You stole " + data.stolen + " points from " + data.from + "!");
                } else {
                    alert(data.label + " is now armed for your next question!");
                }
                loadPowers();
            })
            .catch(() => {
                btn.disabled = false;
                alert("Network error, please try again.");
            });
        });

        loadPowers();
        setInterval(loadPowers, 5000);
    </script>

    <?php $chatbotRole = 'student'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>
