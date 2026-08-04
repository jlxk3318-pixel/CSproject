<?php
session_start();

// ------------------------------------------------------------------
// Require a logged-in teacher — same shared login as Admin/Student.
// ------------------------------------------------------------------
$isLoggedIn = isset($_SESSION['user']) && ($_SESSION['role'] ?? '') === 'teacher';
if (!$isLoggedIn) {
    header("Location: ../Admin/login.php");
    exit();
}

include 'Database.php';

// Look up the logged-in teacher's id + display name
$teacherId   = 0;
$teacherName = 'Teacher';
$stmt = mysqli_prepare($conn, "SELECT user_id, user_fullname FROM users WHERE user_email = ? AND user_role = 'teacher'");
mysqli_stmt_bind_param($stmt, "s", $_SESSION['user']);
mysqli_stmt_execute($stmt);
$teacherRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if ($teacherRow) {
    $teacherId   = (int) $teacherRow['user_id'];
    $teacherName = $teacherRow['user_fullname'] ?: $teacherName;
}

$errorMessage = '';
if (($_GET['error'] ?? '') === 'not_your_game') {
    $errorMessage = "You can only open or manage games you created yourself.";
}

// ------------------------------------------------------------------
// Action: Create New Game
// ------------------------------------------------------------------
if (isset($_POST['create_game'])) {
    $gameName  = trim($_POST['game_name'] ?? '');
    $numLayers = (int) ($_POST['num_layers'] ?? 4);
    $numLayers = max(1, min(4, $numLayers)); // clamp to the 1-4 range the question bank supports

    $studentLimitRaw = trim($_POST['student_limit'] ?? '');
    $studentLimit = ($studentLimitRaw === '') ? null : max(1, (int) $studentLimitRaw);

    $questionMode = (isset($_POST['fully_custom']) && $_POST['fully_custom'] === '1') ? 'custom' : 'default';

    if ($gameName === '') {
        $gameName = 'Untitled Game';
    }

    // Only one game stays "Running" at a time — starting a new one
    // automatically finishes whichever game was previously active. A
    // brand-new 'custom' game doesn't touch this at all: it starts as
    // 'Draft' (not joinable) until it has at least MIN_CUSTOM_QUESTIONS
    // questions and the teacher explicitly publishes it from
    // manage-questions.php. 'default' games always have the full shared
    // question bank, so they can go straight to Running as before.
    $initialStatus = ($questionMode === 'custom') ? 'Draft' : 'Running';
    if ($initialStatus === 'Running') {
        mysqli_query($conn, "UPDATE game_session SET game_status = 'Finished' WHERE game_status = 'Running'");
    }

    $code = 'TT-' . random_int(1000, 9999);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO game_session (teacher_id, game_name, num_layers, student_limit, question_mode, game_code, game_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "isiisss", $teacherId, $gameName, $numLayers, $studentLimit, $questionMode, $code, $initialStatus);
    mysqli_stmt_execute($stmt);
    $newSessionId = mysqli_insert_id($conn);

    header("Location: " . ($questionMode === 'custom' ? "manage-questions.php?session=" . $newSessionId : "Teacher.php?session=" . $newSessionId));
    exit();
}

// ------------------------------------------------------------------
// Action: Enter Game -> Start Game (after picking one from the list)
// ------------------------------------------------------------------
if (isset($_POST['start_selected_game'])) {
    $sessionId = (int) ($_POST['session_id'] ?? 0);

    if ($sessionId > 0) {
        // Only allow starting a game THIS teacher actually created
        $ownerStmt = mysqli_prepare($conn, "SELECT teacher_id, question_mode FROM game_session WHERE id = ?");
        mysqli_stmt_bind_param($ownerStmt, "i", $sessionId);
        mysqli_stmt_execute($ownerStmt);
        $ownerRow = mysqli_fetch_assoc(mysqli_stmt_get_result($ownerStmt));

        if (!$ownerRow || (int) $ownerRow['teacher_id'] !== $teacherId) {
            $errorMessage = "You can only enter games you created yourself.";
        } elseif ($ownerRow['question_mode'] === 'custom' && countCustomQuestions($conn, $sessionId) < MIN_CUSTOM_QUESTIONS) {
            // Same 20-question rule as manage-questions.php's Publish
            // button — re-checked here too, since this is the OTHER path
            // that can flip a game to Running (picking an existing Draft
            // game from the list instead of publishing it directly).
            $errorMessage = "This game only has " . countCustomQuestions($conn, $sessionId) . " of the "
                . MIN_CUSTOM_QUESTIONS . " questions required before it can be started. Add more questions first.";
        } else {
            // Only one game stays "Running" at a time — entering a different
            // game automatically finishes whichever one was previously active.
            mysqli_query(
                $conn,
                "UPDATE game_session SET game_status = 'Finished' WHERE game_status = 'Running' AND id != " . $sessionId
            );

            $stmt = mysqli_prepare($conn, "UPDATE game_session SET game_status = 'Running' WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $sessionId);
            mysqli_stmt_execute($stmt);

            header("Location: Teacher.php?session=" . $sessionId);
            exit();
        }
    } else {
        $errorMessage = "Please select a game first.";
    }
}

// ------------------------------------------------------------------
// Action: Delete a Finished (or still-Draft) game and its students/
// answers permanently. Never allowed on a currently Running one, to
// avoid accidentally wiping an active class. Draft is included so an
// abandoned custom game that never hit MIN_CUSTOM_QUESTIONS doesn't
// clutter the list forever with no way to remove it.
// ------------------------------------------------------------------
if (isset($_POST['delete_game'])) {
    $deleteId = (int) $_POST['delete_game'];

    $stmt = mysqli_prepare($conn, "SELECT game_status, teacher_id FROM game_session WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $deleteId);
    mysqli_stmt_execute($stmt);
    $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$target) {
        $errorMessage = "That game no longer exists.";
    } elseif ((int) $target['teacher_id'] !== $teacherId) {
        $errorMessage = "You can only delete games you created yourself.";
    } elseif (!in_array($target['game_status'], ['Finished', 'Draft'], true)) {
        $errorMessage = "Only Finished or Draft games can be deleted — finish this game first.";
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM answers WHERE student_id IN (SELECT id FROM students WHERE game_session_id = ?)"
        );
        mysqli_stmt_bind_param($stmt, "i", $deleteId);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE game_session_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $deleteId);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn, "DELETE FROM game_session WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $deleteId);
        mysqli_stmt_execute($stmt);

        header("Location: teacher-dashboard.php");
        exit();
    }
}

// ------------------------------------------------------------------
// Only THIS teacher's own games — reversing the earlier "shared with
// everyone" design. Older games created before teacher_id existed (or
// created by a different account) will no longer appear here for
// anyone; ask if you need one of those reassigned to a specific
// teacher account.
// ------------------------------------------------------------------
$gamesStmt = mysqli_prepare(
    $conn,
    "SELECT gs.*,
            (SELECT COUNT(*) FROM students s WHERE s.game_session_id = gs.id) AS student_count
     FROM game_session gs
     WHERE gs.teacher_id = ?
     ORDER BY gs.id DESC"
);
mysqli_stmt_bind_param($gamesStmt, "i", $teacherId);
mysqli_stmt_execute($gamesStmt);
$games = mysqli_fetch_all(mysqli_stmt_get_result($gamesStmt), MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NeuroNet Quest — Teacher Dashboard</title>
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
            <h1>NeuroNet Quest</h1>
        </div>
    </div>

    <?php if ($errorMessage): ?>
        <div class="banner banner-error" style="margin-bottom:20px;"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- Action cards -->
    <div class="hub-grid">
        <div class="hub-card" onclick="openModal('createModal')">
            <div class="hub-icon">✚</div>
            <h3>Create New Game</h3>
            <p>Start a brand-new NeuroNet Quest board with its own game code and question layers.</p>
        </div>

        <div class="hub-card" onclick="openModal('enterModal')">
            <div class="hub-icon">▶</div>
            <h3>Enter Game</h3>
            <p>Pick from an existing game (new or previous) and jump into its live dashboard.</p>
        </div>

        <a href="profile.php" class="hub-card" style="display:block; text-decoration:none; color:inherit;">
            <div class="hub-icon">👤</div>
            <h3>My Profile</h3>
            <p>See your name, email, phone, and account status.</p>
        </a>

        <a href="feedback.php" class="hub-card" style="display:block; text-decoration:none; color:inherit;">
            <div class="hub-icon">✎</div>
            <h3>Feedback</h3>
            <p>Tell us what you liked or what felt confusing.</p>
        </a>
    </div>

</div>

<!-- ============================================================ -->
<!-- CREATE NEW GAME MODAL                                         -->
<!-- ============================================================ -->
<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Create New Game</h3>
            <button type="button" class="modal-close" onclick="closeModal('createModal')" aria-label="Close">✕</button>
        </div>

        <form method="POST">
            <label for="game_name">Game Name</label>
            <input type="text" id="game_name" name="game_name" placeholder="e.g. Period 3 — AI Basics" maxlength="100">

            <label for="num_layers">Number of Question Layers</label>
            <select id="num_layers" name="num_layers">
                <option value="4" selected>4 layers (full variety, default)</option>
                <option value="3">3 layers</option>
                <option value="2">2 layers</option>
                <option value="1">1 layer (easiest to test)</option>
            </select>
            <p class="modal-hint">Each student is randomly assigned one of these layers when they join — more layers means more question variety per topic.</p>

            <label for="student_limit" style="margin-top:14px; display:block;">Student Limit (optional)</label>
            <input type="number" id="student_limit" name="student_limit" min="1" placeholder="Leave blank for unlimited">
            <p class="modal-hint">Once this many students have joined, StudentLogin.php will block further joins with a "class is full" message.</p>

            <label style="margin-top:14px; display:flex; align-items:flex-start; gap:8px; cursor:pointer;">
                <input type="checkbox" name="fully_custom" value="1" style="margin-top:3px; accent-color: var(--cyan);">
                <span>
                    <strong style="display:block; font-size:13px;">Build this game entirely from my own questions</strong>
                    <span class="modal-hint" style="display:block; margin-top:2px;">
                        No fallback to the shared default bank — you write every question yourself, and the
                        board is sized to match however many topics you write (e.g. 10 topics = an 11-node board).
                        You'll be taken straight to Manage Questions after creating.
                    </span>
                </span>
            </label>

            <button type="submit" name="create_game" class="btn-primary" style="margin-top:14px;">Create &amp; Open Board</button>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- ENTER GAME MODAL (pick a game, then Start Game)               -->
<!-- ============================================================ -->
<div class="modal-overlay" id="enterModal">
    <div class="modal-box modal-box-wide">
        <div class="modal-header">
            <h3>Enter Game</h3>
            <button type="button" class="modal-close" onclick="closeModal('enterModal')" aria-label="Close">✕</button>
        </div>

        <?php if (empty($games)): ?>
            <p style="color:var(--text-muted); font-size:13px;">No games yet — create one first.</p>
        <?php else: ?>
            <form method="POST">
                <div class="game-pick-list">
                    <?php foreach ($games as $g):
                        $statusClass = $g['game_status'] === 'Running' ? 'status-running' : ($g['game_status'] === 'Finished' ? 'status-finished' : 'status-draft');
                    ?>
                        <div class="game-pick-row">
                            <label class="game-pick-label">
                                <input type="radio" name="session_id" value="<?php echo (int) $g['id']; ?>" required>
                                <div class="game-pick-info">
                                    <div class="game-pick-name">
                                        <?php echo htmlspecialchars($g['game_name'] ?: 'Untitled Game'); ?>
                                        <span class="pill <?php echo $statusClass; ?>" style="margin-left:8px;">
                                            <span class="status-dot"></span><?php echo htmlspecialchars($g['game_status']); ?>
                                        </span>
                                    </div>
                                    <div class="game-pick-meta">
                                        Code <strong><?php echo htmlspecialchars($g['game_code']); ?></strong>
                                        · <?php echo (int) $g['student_count']; ?> student<?php echo $g['student_count'] == 1 ? '' : 's'; ?>
                                        · <?php echo (int) $g['num_layers']; ?> layer<?php echo $g['num_layers'] == 1 ? '' : 's'; ?>
                                        <?php if (!empty($g['created_at'])): ?>
                                            · <?php echo htmlspecialchars(date('M j, g:ia', strtotime($g['created_at']))); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </label>
                            <?php if (in_array($g['game_status'], ['Finished', 'Draft'], true)): ?>
                                <button
                                    type="submit"
                                    name="delete_game"
                                    value="<?php echo (int) $g['id']; ?>"
                                    formnovalidate
                                    class="btn-danger game-pick-delete"
                                    onclick="return confirm('Permanently delete this game and all its students/answers? This cannot be undone.');"
                                >Delete</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" name="start_selected_game" class="btn-primary" style="margin-top:14px;">Start Game</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    // Click outside the box to close
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.classList.remove('active');
        });
    });
</script>

<?php $chatbotRole = 'teacher'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>