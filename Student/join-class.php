<?php
// ------------------------------------------------------------------
// Called by student-dashboard.php's QR scanner (fetch POST, JSON body
// { session_code: "<decoded QR text>" }). Validates the scanned code
// against game_session, enforces student_limit, and creates/reuses this
// student's row in `students` — then hands off to game.php.
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Teacher/Database.php';

function respond($success, $message = '') {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit();
}

// Must be logged in as a student to join a game.
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'student') {
    respond(false, 'Please login first.');
}

$input = json_decode(file_get_contents('php://input'), true);
$scannedCode = trim($input['session_code'] ?? '');

if ($scannedCode === '') {
    respond(false, 'No class code detected, please try again.');
}

// Look up the logged-in student's real profile (name/email come from
// their account, not from a manual form).
$stmt = mysqli_prepare($conn, "SELECT user_fullname, user_email FROM users WHERE user_email = ? AND user_role = 'student'");
mysqli_stmt_bind_param($stmt, "s", $_SESSION['user']);
mysqli_stmt_execute($stmt);
$userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$userRow) {
    respond(false, 'Student account not found.');
}

$studentName  = $userRow['user_fullname'];
$studentEmail = $userRow['user_email'];

// Find a currently Running game_session with this exact code.
$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM game_session WHERE UPPER(game_code) = UPPER(?) AND game_status = 'Running' LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "s", $scannedCode);
mysqli_stmt_execute($stmt);
$gameSession = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$gameSession) {
    respond(false, 'Invalid class code, please try again.');
}

$gameSessionId = (int) $gameSession['id'];

// Does this student already have a row in THIS game? (e.g. reconnecting
// after a dropped connection, or scanning the same code twice)
$stmt = mysqli_prepare(
    $conn,
    "SELECT id, STATUS FROM students WHERE game_session_id = ? AND student_email = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "is", $gameSessionId, $studentEmail);
mysqli_stmt_execute($stmt);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// This join will only change the Online headcount if the student isn't
// already marked Online — that's the only case the student_limit needs
// to guard against.
$willGoOnline = !$existing || ($existing['STATUS'] ?? 'Offline') !== 'Online';

if ($willGoOnline && $gameSession['student_limit'] !== null) {
    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS cnt FROM students WHERE game_session_id = ? AND STATUS = 'Online'"
    );
    mysqli_stmt_bind_param($countStmt, "i", $gameSessionId);
    mysqli_stmt_execute($countStmt);
    $countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
    $onlineCount = (int) $countRow['cnt'];

    if ($onlineCount >= (int) $gameSession['student_limit']) {
        respond(false, 'Classroom full — please wait for a spot to open up.');
    }
}

if ($existing) {
    $studentRowId = (int) $existing['id'];
    if ($willGoOnline) {
        $stmt = mysqli_prepare($conn, "UPDATE students SET STATUS = 'Online' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $studentRowId);
        mysqli_stmt_execute($stmt);
    }
} else {
    // Everyone starts on Node 0 (Start), which is always the Input
    // column — so difficulty always begins at tier 1 regardless of how
    // many question layers this game has. From here on, layer_number
    // gets recomputed on every dice move based on board depth (see
    // Teacher.php's move handler / getDepthLayer()) instead of staying
    // fixed at whatever it's set to here.
    $layer = 1;

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO students (game_session_id, student_name, student_email, layer_number, STATUS)
         VALUES (?, ?, ?, ?, 'Online')"
    );
    mysqli_stmt_bind_param($stmt, "issi", $gameSessionId, $studentName, $studentEmail, $layer);
    mysqli_stmt_execute($stmt);
    $studentRowId = mysqli_insert_id($conn);
}

// Remember which student row + game this browser session is tied to,
// so game.php (and the Exit/offline logic) know what to load and update.
$_SESSION['student_row_id']  = $studentRowId;
$_SESSION['game_session_id'] = $gameSessionId;

respond(true);
