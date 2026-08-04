<?php
session_start();

// ------------------------------------------------------------------
// Require a logged-in teacher. Uses the SAME shared login as
// Admin/Student (Admin/login.php, csproject.users, user_role/user_status)
// — there is no separate Teacher-only login page.
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
$teacherStmt = mysqli_prepare($conn, "SELECT user_id, user_fullname FROM users WHERE user_email = ? AND user_role = 'teacher'");
mysqli_stmt_bind_param($teacherStmt, "s", $_SESSION['user']);
mysqli_stmt_execute($teacherStmt);
$teacherRow = mysqli_fetch_assoc(mysqli_stmt_get_result($teacherStmt));
if ($teacherRow) {
    $teacherId = (int) $teacherRow['user_id'];
    if (!empty($teacherRow['user_fullname'])) {
        $teacherName = $teacherRow['user_fullname'];
    }
}

// ------------------------------------------------------------------
// This dashboard now always operates on ONE specific game (picked from
// teacher-dashboard.php's Create New Game / Enter Game flow), instead
// of always grabbing "whatever's latest". Every query below is scoped
// to $gameSessionId so multiple games can run side by side without
// stepping on each other's students.
// ------------------------------------------------------------------
$gameSessionId = (int) ($_GET['session'] ?? 0);

if ($gameSessionId <= 0) {
    header("Location: teacher-dashboard.php");
    exit();
}

$stmt = mysqli_prepare($conn, "SELECT * FROM game_session WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
mysqli_stmt_execute($stmt);
$gameSession = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$gameSession) {
    header("Location: teacher-dashboard.php");
    exit();
}

// Only the teacher who created this game can open its control panel —
// no more opening another teacher's game via a guessed/shared URL.
if ((int) $gameSession['teacher_id'] !== $teacherId) {
    header("Location: teacher-dashboard.php?error=not_your_game");
    exit();
}

// This game's actual board size — fixed 50 for 'default' games, or
// (topic count + 1) for 'custom' games. Used everywhere below instead
// of the BOARD_SIZE constant, so a custom game's smaller/larger board
// stays consistent across dice movement, stats, and the visual board.
$boardSize = getEffectiveBoardSize($conn, $gameSession);

// ------------------------------------------------------------------
// Actions (all scoped to $gameSessionId)
// ------------------------------------------------------------------

// Reset this game only: clear its students/answers, keep other games untouched.
// Every currently-connected student is polling session-status.php on their
// own game.php — flipping them to Offline first (even though their row is
// about to be deleted anyway) is what that poll picks up to pop the
// "Class Ended" message before bouncing them out.
if (isset($_POST['reset_game'])) {
    $stmt = mysqli_prepare($conn, "UPDATE students SET STATUS = 'Offline' WHERE game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM answers WHERE student_id IN (SELECT id FROM students WHERE game_session_id = ?)"
    );
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "UPDATE game_session SET winner_student = NULL, game_status = 'Running' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    header("Location: Teacher.php?session=" . $gameSessionId);
    exit();
}

// Mark this game as Finished. Unlike Reset, student rows/scores are kept
// (so the final leaderboard stays visible) — but everyone is still flipped
// to Offline so the leaderboard's status column is accurate and every
// student's game.php pops the "Class Ended" message via session-status.php.
if (isset($_POST['finish_game'])) {
    $stmt = mysqli_prepare($conn, "UPDATE game_session SET game_status = 'Finished' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "UPDATE students SET STATUS = 'Offline' WHERE game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    header("Location: Teacher.php?session=" . $gameSessionId);
    exit();
}

// Move a student by a dice roll — only if that student actually
// belongs to THIS game (prevents cross-game tampering via the form)
if (isset($_POST['move'])) {
    $studentID     = (int) $_POST['student_id'];
    $dice          = (int) $_POST['dice'];
    $movedOk       = false;
    $completedEpoch = false;

    if ($studentID > 0 && $dice >= 1 && $dice <= 6) {

        $stmt = mysqli_prepare($conn, "SELECT current_tile, laps_completed, score FROM students WHERE id = ? AND game_session_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $studentID, $gameSessionId);
        mysqli_stmt_execute($stmt);
        $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($student) {
            $rawTile = $student['current_tile'] + $dice;
            $newTile = $rawTile % $boardSize;
            $newLaps = $student['laps_completed'] + intdiv($rawTile, $boardSize);
            $completedEpoch = $newLaps > (int) $student['laps_completed'];

            // Board depth now drives question difficulty instead of a
            // fixed value randomly assigned at join — standing near Input
            // (low node numbers) gives easier questions, deeper into the
            // Hidden/Output columns gives harder ones. Recomputed on every
            // move so it always reflects where they actually are right now.
            $newLayerNumber = getDepthLayer($newTile, $boardSize, (int) $gameSession['num_layers']);

            // Completing a full pass around the board (Input -> ... ->
            // Output -> wraps back to Input) is "finishing an epoch" —
            // award a bonus, same idea as training for another epoch.
            $newScore = (int) $student['score'] + ($completedEpoch ? EPOCH_COMPLETE_BONUS : 0);

            // Generate a fresh single-use token for this tile visit.
            // The student's QR code (shown below, on this page) encodes this
            // token — they scan it on their own device (game.php) to pull up
            // their question for this tile.
            $newToken = bin2hex(random_bytes(16));

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE students SET current_tile = ?, laps_completed = ?, qr_token = ?, layer_number = ?, score = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "iisiii", $newTile, $newLaps, $newToken, $newLayerNumber, $newScore, $studentID);
            mysqli_stmt_execute($stmt);
            $movedOk = true;

            if ($completedEpoch) {
                maybeAwardWinner($conn, $gameSessionId, $studentID, $newScore);
            }
        }
    }

    // Only tag ?moved= onto the redirect if a student was actually moved,
    // so the QR panel below only ever shows up after a real dice roll.
    $redirectUrl = "Teacher.php?session=" . $gameSessionId
        . ($movedOk ? "&moved=" . $studentID : "")
        . ($completedEpoch ? "&epoch=1" : "");
    header("Location: " . $redirectUrl);
    exit();
}

// ------------------------------------------------------------------
// Data for the page (scoped to this game only)
// ------------------------------------------------------------------

$leaderboardStmt = mysqli_prepare(
    $conn,
    "SELECT * FROM students WHERE game_session_id = ? ORDER BY score DESC, current_tile DESC, student_name ASC"
);
mysqli_stmt_bind_param($leaderboardStmt, "i", $gameSessionId);
mysqli_stmt_execute($leaderboardStmt);
$leaderboard = mysqli_fetch_all(mysqli_stmt_get_result($leaderboardStmt), MYSQLI_ASSOC);
$totalStudents = count($leaderboard);

// Winner name (if the game has ended)
$winnerName = null;
if ($gameSession['game_status'] === 'Finished' && $gameSession['winner_student']) {
    foreach ($leaderboard as $row) {
        if ((int) $row['id'] === (int) $gameSession['winner_student']) {
            $winnerName = $row['student_name'];
            break;
        }
    }
}

// The student who was just moved (if any) — used to show their personal
// question QR code below the "Move a Student" panel. Re-reads fresh from
// the DB (rather than trusting the POST) so a stale/duplicated ?moved=
// param can never show a token that's already been used.
$movedStudentId = (int) ($_GET['moved'] ?? 0);
$movedCompletedEpoch = ($_GET['epoch'] ?? '') === '1';
$movedStudent = null;
if ($movedStudentId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id, student_name, current_tile, qr_token FROM students WHERE id = ? AND game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $movedStudentId, $gameSessionId);
    mysqli_stmt_execute($stmt);
    $movedStudent = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}
$moveQrUrl = ($movedStudent && !empty($movedStudent['qr_token']))
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($movedStudent['qr_token'])
    : null;

// Status pill class
$statusClass = 'status-draft';
$statusLabel = htmlspecialchars($gameSession['game_status']);
if ($gameSession['game_status'] === 'Running') {
    $statusClass = 'status-running';
} elseif ($gameSession['game_status'] === 'Finished') {
    $statusClass = 'status-finished';
}

// Build the join QR code for students.
// Students log into their own account first (Admin/login.php), then on
// their dashboard tap "Enter Game" and scan THIS QR code. It encodes the
// game's plain game_code, which join-class.php looks up directly against
// game_session (matching code + game_status = 'Running').
$canJoin = $gameSession['game_status'] === 'Running';
$joinCode = $canJoin ? $gameSession['game_code'] : null;
$qrUrl = $joinCode
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($joinCode)
    : null;
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
        <a href="teacher-dashboard.php" class="btn outline" style="margin-right:8px;">◀ My Games</a>
        <button type="button" id="ttq-notif-toggle" class="btn outline" style="padding:8px 12px; margin-right:8px;" aria-label="Notifications">🔔</button>
        <span class="pill" style="margin-right:8px;">👋 <?php echo htmlspecialchars($teacherName); ?></span>
        <a href="../Admin/login.php" class="btn outline">Logout</a>
    </div>
</div>

<?php include 'notification.php'; ?>

<div class="dashboard">

    <!-- Top bar -->
    <div class="topbar">
        <div>
            <p class="eyebrow">Hybrid Learning · Board Game</p>
            <h1><?php echo htmlspecialchars($gameSession['game_name'] ?: 'NeuroNet Quest'); ?></h1>
        </div>

        <div class="topbar-meta">
            <span class="pill">
                Game Code
                <strong><?php echo htmlspecialchars($gameSession['game_code']); ?></strong>
            </span>
            <span class="pill <?php echo $statusClass; ?>">
                <span class="status-dot"></span>
                <?php echo $statusLabel; ?>
            </span>
        </div>
    </div>

    <?php if ($winnerName): ?>
        <div class="winner-banner">
            🏆 <strong><?php echo htmlspecialchars($winnerName); ?></strong> reached <?php echo WINNING_SCORE; ?> points first! Other students can keep playing to see who finishes next.
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="card stat-card">
            <div class="stat-label">Students Joined</div>
            <div class="stat-value"><?php echo $totalStudents; ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Target Score</div>
            <div class="stat-value"><?php echo WINNING_SCORE; ?> <span>pts</span></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Question Layers</div>
            <div class="stat-value"><?php echo (int) $gameSession['num_layers']; ?> <span>layers</span></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Board Size</div>
            <div class="stat-value"><?php echo $boardSize; ?> <span>nodes · loops</span></div>
        </div>
    </div>

    <!-- Game board -->
    <div class="panel" style="margin-bottom: 24px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
            <h2 class="panel-title" style="margin-bottom:0;"><span class="dot"></span>Game Board</h2>
            <button type="button" id="manualRefreshBtn" class="btn-refresh">
                ⟳ Refresh Now
            </button>
        </div>
        <div id="boardTrack">
            <?php include 'board_track.php'; ?>
        </div>
    </div>

    <!-- Main grid -->
    <div class="main-grid">

        <!-- Left column -->
        <div class="stack">

            <!-- Game controls -->
            <div class="panel">
                <h2 class="panel-title"><span class="dot"></span>Game Controls</h2>
                <a href="manage-questions.php?session=<?php echo $gameSessionId; ?>" class="btn-secondary" style="display:block; text-align:center; text-decoration:none; margin-bottom: 12px;">
                    ✎ Manage Questions
                </a>
                <form method="POST" style="margin-bottom: 12px;">
                    <button type="submit" name="finish_game" class="btn-secondary">Mark Game Finished</button>
                </form>
                <form method="POST" onsubmit="return confirm('This will erase all students and scores for THIS game only. Continue?');">
                    <button type="submit" name="reset_game" class="btn-danger">Reset This Game</button>
                </form>
            </div>

            <!-- Join code / QR -->
            <div class="panel">
                <h2 class="panel-title"><span class="dot"></span>Join the Game</h2>
                <?php if ($joinCode): ?>
                <div class="qr-card">
                    <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR code to join the game">
                    <div style="flex:1; min-width:0;">
                        <p style="font-size:13px; color:var(--text-muted); margin-bottom:6px;">
                            Students log in, then tap <strong>Enter Game</strong> on their dashboard and
                            scan this code (or type it in manually):
                        </p>
                        <div class="join-link" id="joinLink"><?php echo htmlspecialchars($joinCode); ?></div>
                        <button type="button" class="btn-secondary" id="copyLinkBtn" data-link="<?php echo htmlspecialchars($joinCode); ?>">
                            Copy Code
                        </button>
                    </div>
                </div>
                <?php elseif ($gameSession['game_status'] === 'Draft'): ?>
                    <p style="color:var(--text-muted); font-size:13px;">
                        This game is still in Draft — it needs at least <?php echo MIN_CUSTOM_QUESTIONS; ?> questions before
                        it can be published. <a href="manage-questions.php?session=<?php echo $gameSessionId; ?>" style="color:var(--cyan);">Add more questions</a> to unlock publishing.
                    </p>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:13px;">
                        This game is marked Finished, so no new students can join. Reset it or create a new one to keep playing.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Move a student -->
            <div class="panel">
                <h2 class="panel-title"><span class="dot"></span>Move a Student</h2>
                <form method="POST">
                    <label for="student_id">Student</label>
                    <select name="student_id" id="student_id" required>
                        <option value="">Select Student</option>
                        <?php foreach ($leaderboard as $row): ?>
                            <option value="<?php echo (int) $row['id']; ?>">
                                <?php echo htmlspecialchars($row['student_name']); ?>
                                (Node <?php echo (int) $row['current_tile']; ?><?php echo $row['laps_completed'] > 0 ? ', Epoch ' . ((int) $row['laps_completed'] + 1) : ''; ?> — <?php echo (int) $row['score']; ?> pts)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="dice">Dice Roll (1–6)</label>
                    <input type="number" id="dice" name="dice" min="1" max="6" required>

                    <button type="submit" name="move" class="btn-primary">Move Student</button>
                </form>
            </div>

            <!-- QR for the student who was just moved -->
            <?php if ($movedStudent):
                $movedIsPower = isPowerNode((int) $movedStudent['current_tile'], $gameSessionId);
            ?>
            <div class="panel">
                <h2 class="panel-title"><span class="dot"></span><?php echo $movedIsPower ? 'Scan to Claim Power ⚡' : 'Scan to Answer'; ?></h2>
                <?php if ($movedCompletedEpoch): ?>
                    <div class="banner banner-success" style="margin-bottom:14px;">
                        🎉 <strong><?php echo htmlspecialchars($movedStudent['student_name']); ?></strong> completed a full epoch
                        (a full pass around the board) — +<?php echo EPOCH_COMPLETE_BONUS; ?> bonus points!
                    </div>
                <?php endif; ?>
                <?php if ($moveQrUrl): ?>
                    <div class="qr-card">
                        <img src="<?php echo htmlspecialchars($moveQrUrl); ?>" alt="QR code for this student's <?php echo $movedIsPower ? 'power card' : 'question'; ?>">
                        <div style="flex:1; min-width:0;">
                            <p style="font-size:13px; margin-bottom:4px;">
                                <strong><?php echo htmlspecialchars($movedStudent['student_name']); ?></strong>
                                landed on Node <?php echo (int) $movedStudent['current_tile']; ?><?php echo $movedIsPower ? ' — a Power node!' : '.'; ?>
                            </p>
                            <p style="font-size:13px; color:var(--text-muted);">
                                <?php if ($movedIsPower): ?>
                                    Hand them this screen (or their own device) to scan with <strong>Scan My QR</strong>
                                    on their board page and claim a random power card.
                                <?php else: ?>
                                    Hand them this screen (or their own device) to scan with
                                    <strong>Scan My QR</strong> on their board page and answer their question.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:13px;">
                        <strong><?php echo htmlspecialchars($movedStudent['student_name']); ?></strong> already scanned this move
                        — roll again to give them a new node.
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right column: leaderboard -->
        <div class="panel">
            <h2 class="panel-title"><span class="dot"></span>Live Leaderboard</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Layer</th>
                            <th>Progress</th>
                            <th>Epochs</th>
                            <th>Score</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="leaderboardBody">
                        <?php include 'leaderboard_rows.php'; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    // script.js needs to know which game to poll — every AJAX refresh
    // appends this so it never accidentally shows a different game's board.
    window.GAME_SESSION_ID = <?php echo (int) $gameSessionId; ?>;
</script>
<script src="script.js"></script>

<?php $chatbotRole = 'teacher'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html><?php
session_start();

// ------------------------------------------------------------------
// Require a logged-in teacher. Uses the SAME shared login as
// Admin/Student (Admin/login.php, csproject.users, user_role/user_status)
// — there is no separate Teacher-only login page.
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
$teacherStmt = mysqli_prepare($conn, "SELECT user_id, user_fullname FROM users WHERE user_email = ? AND user_role = 'teacher'");
mysqli_stmt_bind_param($teacherStmt, "s", $_SESSION['user']);
mysqli_stmt_execute($teacherStmt);
$teacherRow = mysqli_fetch_assoc(mysqli_stmt_get_result($teacherStmt));
if ($teacherRow) {
    $teacherId = (int) $teacherRow['user_id'];
    if (!empty($teacherRow['user_fullname'])) {
        $teacherName = $teacherRow['user_fullname'];
    }
}

// ------------------------------------------------------------------
// This dashboard now always operates on ONE specific game (picked from
// teacher-dashboard.php's Create New Game / Enter Game flow), instead
// of always grabbing "whatever's latest". Every query below is scoped
// to $gameSessionId so multiple games can run side by side without
// stepping on each other's students.
// ------------------------------------------------------------------
$gameSessionId = (int) ($_GET['session'] ?? 0);

if ($gameSessionId <= 0) {
    header("Location: teacher-dashboard.php");
    exit();
}

$stmt = mysqli_prepare($conn, "SELECT * FROM game_session WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
mysqli_stmt_execute($stmt);
$gameSession = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$gameSession) {
    header("Location: teacher-dashboard.php");
    exit();
}

// Only the teacher who created this game can open its control panel —
// no more opening another teacher's game via a guessed/shared URL.
if ((int) $gameSession['teacher_id'] !== $teacherId) {
    header("Location: teacher-dashboard.php?error=not_your_game");
    exit();
}

// This game's actual board size — fixed 50 for 'default' games, or
// (topic count + 1) for 'custom' games. Used everywhere below instead
// of the BOARD_SIZE constant, so a custom game's smaller/larger board
// stays consistent across dice movement, stats, and the visual board.
$boardSize = getEffectiveBoardSize($conn, $gameSession);

// ------------------------------------------------------------------
// Actions (all scoped to $gameSessionId)
// ------------------------------------------------------------------

// Reset this game only: clear its students/answers, keep other games untouched.
// Every currently-connected student is polling session-status.php on their
// own game.php — flipping them to Offline first (even though their row is
// about to be deleted anyway) is what that poll picks up to pop the
// "Class Ended" message before bouncing them out.
if (isset($_POST['reset_game'])) {
    $stmt = mysqli_prepare($conn, "UPDATE students SET STATUS = 'Offline' WHERE game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM answers WHERE student_id IN (SELECT id FROM students WHERE game_session_id = ?)"
    );
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "UPDATE game_session SET winner_student = NULL, game_status = 'Running' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    header("Location: Teacher.php?session=" . $gameSessionId);
    exit();
}

// Mark this game as Finished. Unlike Reset, student rows/scores are kept
// (so the final leaderboard stays visible) — but everyone is still flipped
// to Offline so the leaderboard's status column is accurate and every
// student's game.php pops the "Class Ended" message via session-status.php.
if (isset($_POST['finish_game'])) {
    $stmt = mysqli_prepare($conn, "UPDATE game_session SET game_status = 'Finished' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "UPDATE students SET STATUS = 'Offline' WHERE game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);

    header("Location: Teacher.php?session=" . $gameSessionId);
    exit();
}

// Move a student by a dice roll — only if that student actually
// belongs to THIS game (prevents cross-game tampering via the form)
if (isset($_POST['move'])) {
    $studentID     = (int) $_POST['student_id'];
    $dice          = (int) $_POST['dice'];
    $movedOk       = false;
    $completedEpoch = false;

    if ($studentID > 0 && $dice >= 1 && $dice <= 6) {

        $stmt = mysqli_prepare($conn, "SELECT current_tile, laps_completed, score FROM students WHERE id = ? AND game_session_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $studentID, $gameSessionId);
        mysqli_stmt_execute($stmt);
        $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($student) {
            $rawTile = $student['current_tile'] + $dice;
            $newTile = $rawTile % $boardSize;
            $newLaps = $student['laps_completed'] + intdiv($rawTile, $boardSize);
            $completedEpoch = $newLaps > (int) $student['laps_completed'];

            // Board depth now drives question difficulty instead of a
            // fixed value randomly assigned at join — standing near Input
            // (low node numbers) gives easier questions, deeper into the
            // Hidden/Output columns gives harder ones. Recomputed on every
            // move so it always reflects where they actually are right now.
            $newLayerNumber = getDepthLayer($newTile, $boardSize, (int) $gameSession['num_layers']);

            // Completing a full pass around the board (Input -> ... ->
            // Output -> wraps back to Input) is "finishing an epoch" —
            // award a bonus, same idea as training for another epoch.
            $newScore = (int) $student['score'] + ($completedEpoch ? EPOCH_COMPLETE_BONUS : 0);

            // Generate a fresh single-use token for this tile visit.
            // The student's QR code (shown below, on this page) encodes this
            // token — they scan it on their own device (game.php) to pull up
            // their question for this tile.
            $newToken = bin2hex(random_bytes(16));

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE students SET current_tile = ?, laps_completed = ?, qr_token = ?, layer_number = ?, score = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "iisiii", $newTile, $newLaps, $newToken, $newLayerNumber, $newScore, $studentID);
            mysqli_stmt_execute($stmt);
            $movedOk = true;

            if ($completedEpoch) {
                maybeAwardWinner($conn, $gameSessionId, $studentID, $newScore);
            }
        }
    }

    // Only tag ?moved= onto the redirect if a student was actually moved,
    // so the QR panel below only ever shows up after a real dice roll.
    $redirectUrl = "Teacher.php?session=" . $gameSessionId
        . ($movedOk ? "&moved=" . $studentID : "")
        . ($completedEpoch ? "&epoch=1" : "");
    header("Location: " . $redirectUrl);
    exit();
}

// ------------------------------------------------------------------
// Data for the page (scoped to this game only)
// ------------------------------------------------------------------

$leaderboardStmt = mysqli_prepare(
    $conn,
    "SELECT * FROM students WHERE game_session_id = ? ORDER BY score DESC, current_tile DESC, student_name ASC"
);
mysqli_stmt_bind_param($leaderboardStmt, "i", $gameSessionId);
mysqli_stmt_execute($leaderboardStmt);
$leaderboard = mysqli_fetch_all(mysqli_stmt_get_result($leaderboardStmt), MYSQLI_ASSOC);
$totalStudents = count($leaderboard);

// Winner name (if the game has ended)
$winnerName = null;
if ($gameSession['game_status'] === 'Finished' && $gameSession['winner_student']) {
    foreach ($leaderboard as $row) {
        if ((int) $row['id'] === (int) $gameSession['winner_student']) {
            $winnerName = $row['student_name'];
            break;
        }
    }
}

// The student who was just moved (if any) — used to show their personal
// question QR code below the "Move a Student" panel. Re-reads fresh from
// the DB (rather than trusting the POST) so a stale/duplicated ?moved=
// param can never show a token that's already been used.
$movedStudentId = (int) ($_GET['moved'] ?? 0);
$movedCompletedEpoch = ($_GET['epoch'] ?? '') === '1';
$movedStudent = null;
if ($movedStudentId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id, student_name, current_tile, qr_token FROM students WHERE id = ? AND game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $movedStudentId, $gameSessionId);
    mysqli_stmt_execute($stmt);
    $movedStudent = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}
$moveQrUrl = ($movedStudent && !empty($movedStudent['qr_token']))
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($movedStudent['qr_token'])
    : null;

// Status pill class
$statusClass = 'status-draft';
$statusLabel = htmlspecialchars($gameSession['game_status']);
if ($gameSession['game_status'] === 'Running') {
    $statusClass = 'status-running';
} elseif ($gameSession['game_status'] === 'Finished') {
    $statusClass = 'status-finished';
}

// Build the join QR code for students.
// Students log into their own account first (Admin/login.php), then on
// their dashboard tap "Enter Game" and scan THIS QR code. It encodes the
// game's plain game_code, which join-class.php looks up directly against
// game_session (matching code + game_status = 'Running').
$canJoin = $gameSession['game_status'] === 'Running';
$joinCode = $canJoin ? $gameSession['game_code'] : null;
$qrUrl = $joinCode
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($joinCode)
    : null;
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
        <a href="teacher-dashboard.php" class="btn outline" style="margin-right:8px;">◀ My Games</a>
        <span class="pill" style="margin-right:8px;">👋 <?php echo htmlspecialchars($teacherName); ?></span>
        <a href="../Admin/login.php" class="btn outline">Logout</a>
    </div>
</div>

<div class="dashboard">

    <!-- Top bar -->
    <div class="topbar">
        <div>
            <p class="eyebrow">Hybrid Learning · Board Game</p>
            <h1><?php echo htmlspecialchars($gameSession['game_name'] ?: 'NeuroNet Quest'); ?></h1>
        </div>

        <div class="topbar-meta">
            <span class="pill">
                Game Code
                <strong><?php echo htmlspecialchars($gameSession['game_code']); ?></strong>
            </span>
            <span class="pill <?php echo $statusClass; ?>">
                <span class="status-dot"></span>
                <?php echo $statusLabel; ?>
            </span>
        </div>
    </div>

    <?php if ($winnerName): ?>
        <div class="winner-banner">
            🏆 <strong><?php echo htmlspecialchars($winnerName); ?></strong> reached <?php echo WINNING_SCORE; ?> points first! Other students can keep playing to see who finishes next.
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="card stat-card">
            <div class="stat-label">Students Joined</div>
            <div class="stat-value"><?php echo $totalStudents; ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Target Score</div>
            <div class="stat-value"><?php echo WINNING_SCORE; ?> <span>pts</span></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Question Layers</div>
            <div class="stat-value"><?php echo (int) $gameSession['num_layers']; ?> <span>layers</span></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Board Size</div>
            <div class="stat-value"><?php echo $boardSize; ?> <span>nodes · loops</span></div>
        </div>
    </div>

    <!-- Game board -->
    <div class="panel" style="margin-bottom: 24px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
            <h2 class="panel-title" style="margin-bottom:0;"><span class="dot"></span>Game Board</h2>
            <button type="button" id="manualRefreshBtn" class="btn-refresh">
                ⟳ Refresh Now
            </button>
        </div>
        <div id="boardTrack">
            <?php include 'board_track.php'; ?>
        </div>
    </div>

    <!-- Main grid -->
    <div class="main-grid">

        <!-- Left column -->
        <div class="stack">

            <!-- Game controls -->
            <div class="panel">
                <h2 class="panel-title"><span class="dot"></span>Game Controls</h2>
                <a href="manage-questions.php?session=<?php echo $gameSessionId; ?>" class="btn-secondary" style="display:block; text-align:center; text-decoration:none; margin-bottom: 12px;">
                    ✎ Manage Questions
                </a>
                <form method="POST" style="margin-bottom: 12px;">
                    <button type="submit" name="finish_game" class="btn-secondary">Mark Game Finished</button>
                </form>
                <form method="POST" onsubmit="return confirm('This will erase all students and scores for THIS game only. Continue?');">
                    <button type="submit" name="reset_game" class="btn-danger">Reset This Game</button>
                </form>
            </div>

            <!-- Join code / QR -->
            <div class="panel">
                <h2 class="panel-title"><span class="dot"></span>Join the Game</h2>
                <?php if ($joinCode): ?>
                <div class="qr-card">
                    <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR code to join the game">
                    <div style="flex:1; min-width:0;">
                        <p style="font-size:13px; color:var(--text-muted); margin-bottom:6px;">
                            Students log in, then tap <strong>Enter Game</strong> on their dashboard and
                            scan this code (or type it in manually):
                        </p>
                        <div class="join-link" id="joinLink"><?php echo htmlspecialchars($joinCode); ?></div>
                        <button type="button" class="btn-secondary" id="copyLinkBtn" data-link="<?php echo htmlspecialchars($joinCode); ?>">
                            Copy Code
                        </button>
                    </div>
                </div>
                <?php elseif ($gameSession['game_status'] === 'Draft'): ?>
                    <p style="color:var(--text-muted); font-size:13px;">
                        This game is still in Draft — it needs at least <?php echo MIN_CUSTOM_QUESTIONS; ?> questions before
                        it can be published. <a href="manage-questions.php?session=<?php echo $gameSessionId; ?>" style="color:var(--cyan);">Add more questions</a> to unlock publishing.
                    </p>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:13px;">
                        This game is marked Finished, so no new students can join. Reset it or create a new one to keep playing.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Move a student -->
            <div class="panel">
                <h2 class="panel-title"><span class="dot"></span>Move a Student</h2>
                <form method="POST">
                    <label for="student_id">Student</label>
                    <select name="student_id" id="student_id" required>
                        <option value="">Select Student</option>
                        <?php foreach ($leaderboard as $row): ?>
                            <option value="<?php echo (int) $row['id']; ?>">
                                <?php echo htmlspecialchars($row['student_name']); ?>
                                (Node <?php echo (int) $row['current_tile']; ?><?php echo $row['laps_completed'] > 0 ? ', Epoch ' . ((int) $row['laps_completed'] + 1) : ''; ?> — <?php echo (int) $row['score']; ?> pts)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="dice">Dice Roll (1–6)</label>
                    <input type="number" id="dice" name="dice" min="1" max="6" required>

                    <button type="submit" name="move" class="btn-primary">Move Student</button>
                </form>
            </div>

            <!-- QR for the student who was just moved -->
            <?php if ($movedStudent):
                $movedIsPower = isPowerNode((int) $movedStudent['current_tile'], $gameSessionId);
            ?>
            <div class="panel">
                <h2 class="panel-title"><span class="dot"></span><?php echo $movedIsPower ? 'Scan to Claim Power ⚡' : 'Scan to Answer'; ?></h2>
                <?php if ($movedCompletedEpoch): ?>
                    <div class="banner banner-success" style="margin-bottom:14px;">
                        🎉 <strong><?php echo htmlspecialchars($movedStudent['student_name']); ?></strong> completed a full epoch
                        (a full pass around the board) — +<?php echo EPOCH_COMPLETE_BONUS; ?> bonus points!
                    </div>
                <?php endif; ?>
                <?php if ($moveQrUrl): ?>
                    <div class="qr-card">
                        <img src="<?php echo htmlspecialchars($moveQrUrl); ?>" alt="QR code for this student's <?php echo $movedIsPower ? 'power card' : 'question'; ?>">
                        <div style="flex:1; min-width:0;">
                            <p style="font-size:13px; margin-bottom:4px;">
                                <strong><?php echo htmlspecialchars($movedStudent['student_name']); ?></strong>
                                landed on Node <?php echo (int) $movedStudent['current_tile']; ?><?php echo $movedIsPower ? ' — a Power node!' : '.'; ?>
                            </p>
                            <p style="font-size:13px; color:var(--text-muted);">
                                <?php if ($movedIsPower): ?>
                                    Hand them this screen (or their own device) to scan with <strong>Scan My QR</strong>
                                    on their board page and claim a random power card.
                                <?php else: ?>
                                    Hand them this screen (or their own device) to scan with
                                    <strong>Scan My QR</strong> on their board page and answer their question.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:13px;">
                        <strong><?php echo htmlspecialchars($movedStudent['student_name']); ?></strong> already scanned this move
                        — roll again to give them a new node.
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right column: leaderboard -->
        <div class="panel">
            <h2 class="panel-title"><span class="dot"></span>Live Leaderboard</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Layer</th>
                            <th>Progress</th>
                            <th>Epochs</th>
                            <th>Score</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="leaderboardBody">
                        <?php include 'leaderboard_rows.php'; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    // script.js needs to know which game to poll — every AJAX refresh
    // appends this so it never accidentally shows a different game's board.
    window.GAME_SESSION_ID = <?php echo (int) $gameSessionId; ?>;
</script>
<script src="script.js"></script>

<?php $chatbotRole = 'teacher'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>