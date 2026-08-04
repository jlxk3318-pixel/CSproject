<?php
// ------------------------------------------------------------------
// Polled/fetched by game.php to render the "My Powers" card: this
// student's unused power cards (ready to use), whichever card is
// currently armed (waiting on their next graded answer), and the list
// of opponents they could target with Steal Points.
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Teacher/Database.php';

function respondPowers($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'student') {
    respondPowers(false, 'Please login first.');
}

if (empty($_SESSION['student_row_id']) || empty($_SESSION['game_session_id'])) {
    respondPowers(false, 'Join a game first.');
}

$studentRowId  = (int) $_SESSION['student_row_id'];
$gameSessionId = (int) $_SESSION['game_session_id'];
$powerTypes    = getPowerTypes();

$stmt = mysqli_prepare($conn, "SELECT id, score, active_power_id FROM students WHERE id = ? AND game_session_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $studentRowId, $gameSessionId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student) {
    respondPowers(false, 'Your session has ended, please rejoin.');
}

// Unused cards ready to use
$stmt = mysqli_prepare(
    $conn,
    "SELECT id, power_type FROM student_powers WHERE student_id = ? AND status = 'unused' ORDER BY created_at ASC"
);
mysqli_stmt_bind_param($stmt, "i", $studentRowId);
mysqli_stmt_execute($stmt);
$unusedRows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

$powers = array_map(function ($row) use ($powerTypes) {
    $meta = $powerTypes[$row['power_type']] ?? ['label' => $row['power_type'], 'description' => ''];
    return [
        'id'          => (int) $row['id'],
        'type'        => $row['power_type'],
        'label'       => $meta['label'],
        'description' => $meta['description'],
    ];
}, $unusedRows);

// Whichever card is currently armed (if any)
$armed = null;
if (!empty($student['active_power_id'])) {
    $stmt = mysqli_prepare($conn, "SELECT id, power_type FROM student_powers WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $student['active_power_id']);
    mysqli_stmt_execute($stmt);
    $armedRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($armedRow) {
        $meta = $powerTypes[$armedRow['power_type']] ?? ['label' => $armedRow['power_type']];
        $armed = ['id' => (int) $armedRow['id'], 'type' => $armedRow['power_type'], 'label' => $meta['label']];
    }
}

// Opponents in this game (for Steal Points targeting)
$stmt = mysqli_prepare(
    $conn,
    "SELECT id, student_name, score FROM students WHERE game_session_id = ? AND id != ? ORDER BY student_name ASC"
);
mysqli_stmt_bind_param($stmt, "ii", $gameSessionId, $studentRowId);
mysqli_stmt_execute($stmt);
$opponentRows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
$opponents = array_map(function ($row) {
    return ['id' => (int) $row['id'], 'name' => $row['student_name'], 'score' => (int) $row['score']];
}, $opponentRows);

respondPowers(true, '', [
    'my_score'  => (int) $student['score'],
    'powers'    => $powers,
    'armed'     => $armed,
    'opponents' => $opponents,
]);
