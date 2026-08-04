<?php
// ------------------------------------------------------------------
// Called by game.php's "Scan My QR" flow (fetch POST, JSON body
// { token: "<decoded QR text>" }). The QR the student scans is the one
// shown on the TEACHER's screen after they roll that student's dice
// (see Teacher.php's "Scan to Answer" panel, which encodes students.qr_token).
// Validates the token belongs to THIS logged-in student's own current
// row, then returns the question for their current tile + layer.
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Teacher/Database.php';

function respondScan($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'student') {
    respondScan(false, 'Please login first.');
}

if (empty($_SESSION['student_row_id']) || empty($_SESSION['game_session_id'])) {
    respondScan(false, 'Join a game first.');
}

$input = json_decode(file_get_contents('php://input'), true);
$scannedToken = trim($input['token'] ?? '');

if ($scannedToken === '') {
    respondScan(false, 'No QR data detected, please try again.');
}

$studentRowId  = (int) $_SESSION['student_row_id'];
$gameSessionId = (int) $_SESSION['game_session_id'];

$stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE id = ? AND game_session_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $studentRowId, $gameSessionId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student) {
    respondScan(false, 'Your session has ended, please rejoin.');
}

// This is the safety check that makes "only this student's QR code is
// valid" actually true: the scanned text must exactly match the token
// currently stored against THEIR OWN row (not just any student's).
if (empty($student['qr_token']) || !hash_equals((string) $student['qr_token'], $scannedToken)) {
    respondScan(false, 'This QR code is not yours, or it has already been used.');
}

$currentTile = (int) $student['current_tile'];

// Power nodes hand out a power card instead of a question — grant it
// immediately and burn the token, no "answer" step.
if (isPowerNode($currentTile, $gameSessionId)) {
    $powerTypes = getPowerTypes();
    $keys       = array_keys($powerTypes);
    $grantedType = $keys[array_rand($keys)];

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO student_powers (student_id, game_session_id, power_type, status) VALUES (?, ?, ?, 'unused')"
    );
    mysqli_stmt_bind_param($stmt, "iis", $studentRowId, $gameSessionId, $grantedType);
    mysqli_stmt_execute($stmt);

    // One-shot QR — clear it so this power can't be claimed twice.
    $stmt = mysqli_prepare($conn, "UPDATE students SET qr_token = NULL WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentRowId);
    mysqli_stmt_execute($stmt);

    respondScan(true, '', [
        'type' => 'power',
        'power' => [
            'type'        => $grantedType,
            'label'       => $powerTypes[$grantedType]['label'],
            'description' => $powerTypes[$grantedType]['description'],
        ],
    ]);
}

$question = pickQuestionForTile($conn, $gameSessionId, $currentTile, (int) $student['layer_number']);

if (!$question) {
    respondScan(false, "You're on Start — no question here yet. Wait for your teacher to roll your dice.");
}

// Remember exactly which question this scan unlocked, so submit-answer.php
// can never be tricked into grading a question_id the student wasn't shown.
$_SESSION['pending_question_id'] = (int) $question['id'];
$_SESSION['pending_qr_token']    = $student['qr_token'];

respondScan(true, '', [
    'type' => 'question',
    'question' => [
        'id'       => (int) $question['id'],
        'topic'    => $question['topic_label'],
        'text'     => $question['question_text'],
        'option_a' => $question['option_a'],
        'option_b' => $question['option_b'],
        'option_c' => $question['option_c'],
        'option_d' => $question['option_d'],
    ],
]);
