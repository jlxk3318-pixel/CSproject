<?php
// ------------------------------------------------------------------
// Polled every few seconds from game.php. Tells a joined student if
// their class has ended — either the teacher hit "Reset This Game"
// (their row gets deleted), "Mark Game Finished" (row kept but flipped
// Offline, game_status leaves 'Running'), or anything else knocked them
// Offline — so game.php can pop a "Class Ended" message instead of the
// student just sitting on a dead page.
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Teacher/Database.php';

function respondStatus($ended, $message = '') {
    echo json_encode(['ended' => $ended, 'message' => $message]);
    exit();
}

if (
    !isset($_SESSION['user']) ||
    ($_SESSION['role'] ?? '') !== 'student' ||
    empty($_SESSION['student_row_id']) ||
    empty($_SESSION['game_session_id'])
) {
    // Nothing to check yet (not logged in / hasn't joined a game).
    respondStatus(false);
}

$studentRowId  = (int) $_SESSION['student_row_id'];
$gameSessionId = (int) $_SESSION['game_session_id'];

$stmt = mysqli_prepare($conn, "SELECT game_status FROM game_session WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
mysqli_stmt_execute($stmt);
$gameSession = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$gameSession) {
    respondStatus(true, 'This game was reset — class has ended.');
}

if ($gameSession['game_status'] !== 'Running') {
    respondStatus(true, 'Class ended — your teacher finished this game.');
}

$stmt = mysqli_prepare($conn, "SELECT STATUS FROM students WHERE id = ? AND game_session_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $studentRowId, $gameSessionId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student) {
    respondStatus(true, 'This game was reset — class has ended.');
}

if (($student['STATUS'] ?? 'Offline') !== 'Online') {
    respondStatus(true, 'You were removed from this game — class has ended.');
}

respondStatus(false);
