<?php
// ------------------------------------------------------------------
// Reached via the shared header.php "Exit" link whenever a student has
// an active joined game. Flips their students.STATUS to Offline (so the
// teacher's leaderboard and the student_limit headcount both update),
// then sends them back to the login page like a normal logout.
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['student_row_id'])) {
    require_once __DIR__ . '/../Teacher/Database.php';

    $studentRowId = (int) $_SESSION['student_row_id'];
    $stmt = mysqli_prepare($conn, "UPDATE students SET STATUS = 'Offline' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentRowId);
    mysqli_stmt_execute($stmt);
}

unset($_SESSION['student_row_id'], $_SESSION['game_session_id']);

header('Location: ../Admin/login.php');
exit();
