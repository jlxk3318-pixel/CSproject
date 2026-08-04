<?php
// ------------------------------------------------------------------
// Called when the student taps "Use" on a power card in game.php
// (fetch POST, JSON body { power_id, target_student_id? }).
//   - double_points / gamble: ARMS the card — it takes effect on
//     whatever question they answer next (see submit-answer.php).
//   - steal_points: resolves INSTANTLY against target_student_id,
//     no arming/waiting involved.
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Teacher/Database.php';

function respondUse($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'student') {
    respondUse(false, 'Please login first.');
}

if (empty($_SESSION['student_row_id']) || empty($_SESSION['game_session_id'])) {
    respondUse(false, 'Join a game first.');
}

$studentRowId  = (int) $_SESSION['student_row_id'];
$gameSessionId = (int) $_SESSION['game_session_id'];

$input       = json_decode(file_get_contents('php://input'), true);
$powerId     = (int) ($input['power_id'] ?? 0);
$targetId    = (int) ($input['target_student_id'] ?? 0);

if ($powerId <= 0) {
    respondUse(false, 'Pick a power card first.');
}

$stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE id = ? AND game_session_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $studentRowId, $gameSessionId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student) {
    respondUse(false, 'Your session has ended, please rejoin.');
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM student_powers WHERE id = ? AND student_id = ? AND game_session_id = ? AND status = 'unused'"
);
mysqli_stmt_bind_param($stmt, "iii", $powerId, $studentRowId, $gameSessionId);
mysqli_stmt_execute($stmt);
$power = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$power) {
    respondUse(false, 'That power card is no longer available.');
}

$powerTypes = getPowerTypes();

if ($power['power_type'] === 'steal_points') {
    if ($targetId <= 0) {
        respondUse(false, 'Pick a student to steal from.');
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE id = ? AND game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $targetId, $gameSessionId);
    mysqli_stmt_execute($stmt);
    $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$target || $targetId === $studentRowId) {
        respondUse(false, 'Pick a valid opponent in this game.');
    }

    $stolen = min(STEAL_POINTS_AMOUNT, (int) $target['score']);

    $newTargetScore = (int) $target['score'] - $stolen;
    $newOwnScore    = (int) $student['score'] + $stolen;

    $stmt = mysqli_prepare($conn, "UPDATE students SET score = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $newTargetScore, $targetId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "UPDATE students SET score = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $newOwnScore, $studentRowId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "UPDATE student_powers SET status = 'used', target_student_id = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $targetId, $powerId);
    mysqli_stmt_execute($stmt);

    respondUse(true, '', [
        'effect'   => 'stolen',
        'stolen'   => $stolen,
        'from'     => $target['student_name'],
        'score'    => $newOwnScore,
    ]);
}

// double_points / gamble — arm it for the next graded answer.
if (!empty($student['active_power_id'])) {
    respondUse(false, 'You already have a power armed — answer your next question first.');
}

$stmt = mysqli_prepare($conn, "UPDATE students SET active_power_id = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, "ii", $powerId, $studentRowId);
mysqli_stmt_execute($stmt);

$stmt = mysqli_prepare($conn, "UPDATE student_powers SET status = 'armed' WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $powerId);
mysqli_stmt_execute($stmt);

$meta = $powerTypes[$power['power_type']] ?? ['label' => $power['power_type']];

respondUse(true, '', [
    'effect' => 'armed',
    'label'  => $meta['label'],
]);
