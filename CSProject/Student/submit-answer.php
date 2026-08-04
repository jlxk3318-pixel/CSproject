<?php
// ------------------------------------------------------------------
// Called by game.php after the student picks an option for the question
// scan-token.php gave them (fetch POST, JSON body
// { question_id, selected_answer }). Grades the answer, awards points,
// logs it to `answers`, flags a winner if this crosses WINNING_SCORE,
// and burns the qr_token so the same scanned code can't be reused.
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Teacher/Database.php';

function respondAnswer($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'student') {
    respondAnswer(false, 'Please login first.');
}

if (empty($_SESSION['student_row_id']) || empty($_SESSION['game_session_id'])) {
    respondAnswer(false, 'Join a game first.');
}

if (empty($_SESSION['pending_question_id']) || empty($_SESSION['pending_qr_token'])) {
    respondAnswer(false, 'Scan your QR code first.');
}

$input      = json_decode(file_get_contents('php://input'), true);
$questionId = (int) ($input['question_id'] ?? 0);
$selected   = strtoupper(trim($input['selected_answer'] ?? ''));

if (!in_array($selected, ['A', 'B', 'C', 'D'], true)) {
    respondAnswer(false, 'Please select an answer.');
}

if ($questionId !== (int) $_SESSION['pending_question_id']) {
    respondAnswer(false, 'This question has expired, please scan again.');
}

$studentRowId  = (int) $_SESSION['student_row_id'];
$gameSessionId = (int) $_SESSION['game_session_id'];

$stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE id = ? AND game_session_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $studentRowId, $gameSessionId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student || empty($student['qr_token']) || !hash_equals((string) $student['qr_token'], (string) $_SESSION['pending_qr_token'])) {
    respondAnswer(false, 'Your QR code is no longer valid, please scan again.');
}

$stmt = mysqli_prepare($conn, "SELECT * FROM questions WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $questionId);
mysqli_stmt_execute($stmt);
$question = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$question) {
    respondAnswer(false, 'That question no longer exists.');
}

$isCorrect = (strtoupper($question['correct_answer']) === $selected);
$baseScore = (int) $student['score'];

// If a power is armed (see use-power.php), it applies to THIS answer —
// double_points/gamble both double a correct answer's points, but only
// gamble also punishes a wrong answer by taking double points away.
$armedPowerId   = !empty($student['active_power_id']) ? (int) $student['active_power_id'] : null;
$armedPowerType = null;
if ($armedPowerId) {
    $stmt = mysqli_prepare($conn, "SELECT power_type FROM student_powers WHERE id = ? AND student_id = ? AND status = 'armed'");
    mysqli_stmt_bind_param($stmt, "ii", $armedPowerId, $studentRowId);
    mysqli_stmt_execute($stmt);
    $armedRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $armedPowerType = $armedRow['power_type'] ?? null;
}

$pointsDelta = 0;
if ($isCorrect) {
    $pointsDelta = ($armedPowerType === 'double_points' || $armedPowerType === 'gamble')
        ? POINTS_PER_CORRECT * 2
        : POINTS_PER_CORRECT;
} else {
    // Every wrong answer costs points now — gamble costs double, Double
    // Points shields you from it entirely, otherwise it's the standard penalty.
    if ($armedPowerType === 'gamble') {
        $pointsDelta = -(POINTS_PER_CORRECT * 2);
    } elseif ($armedPowerType === 'double_points') {
        $pointsDelta = 0;
    } else {
        $pointsDelta = -WRONG_ANSWER_PENALTY;
    }
}

$newScore = max(0, $baseScore + $pointsDelta); // score can never drop below 0

if ($pointsDelta !== 0) {
    $stmt = mysqli_prepare($conn, "UPDATE students SET score = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $newScore, $studentRowId);
    mysqli_stmt_execute($stmt);
}

// An armed power only ever covers ONE question — consume it either way.
if ($armedPowerId) {
    $stmt = mysqli_prepare($conn, "UPDATE student_powers SET status = 'used' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $armedPowerId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "UPDATE students SET active_power_id = NULL WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentRowId);
    mysqli_stmt_execute($stmt);
}

// Log the attempt either way
$isCorrectInt = $isCorrect ? 1 : 0;
$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO answers (student_id, question_id, selected_answer, is_correct) VALUES (?, ?, ?, ?)"
);
mysqli_stmt_bind_param($stmt, "iisi", $studentRowId, $questionId, $selected, $isCorrectInt);
mysqli_stmt_execute($stmt);

// Crossing WINNING_SCORE claims the win, if nobody in this game has
// already won (shared with Teacher.php's epoch-completion bonus so the
// win check can't drift between the two places score can change).
maybeAwardWinner($conn, $gameSessionId, $studentRowId, $newScore);

// Burn the token — this exact QR code can't be scanned/answered again.
$stmt = mysqli_prepare($conn, "UPDATE students SET qr_token = NULL WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $studentRowId);
mysqli_stmt_execute($stmt);

unset($_SESSION['pending_question_id'], $_SESSION['pending_qr_token']);

respondAnswer(true, '', [
    'correct'        => $isCorrect,
    'correct_answer' => $question['correct_answer'],
    'score'          => $newScore,
    'points_delta'   => $pointsDelta,
    'power_applied'  => $armedPowerType,
]);
