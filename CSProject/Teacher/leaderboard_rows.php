<?php
// This file is included by Teacher.php AND fetched directly via AJAX
// (see script.js) to refresh the leaderboard without reloading the page.
// It must be able to run standalone, so it sets up its own data.

if (!isset($conn)) {
    include 'Database.php';
}

// $gameSessionId is set by Teacher.php when this is include()'d inline;
// when fetched standalone via AJAX, script.js appends ?session=ID instead.
if (!isset($gameSessionId)) {
    $gameSessionId = (int) ($_GET['session'] ?? 0);
}

if (!isset($leaderboard)) {
    $leaderboardStmt = mysqli_prepare(
        $conn,
        "SELECT * FROM students WHERE game_session_id = ? ORDER BY score DESC, current_tile DESC, student_name ASC"
    );
    mysqli_stmt_bind_param($leaderboardStmt, "i", $gameSessionId);
    mysqli_stmt_execute($leaderboardStmt);
    $leaderboard = mysqli_fetch_all(mysqli_stmt_get_result($leaderboardStmt), MYSQLI_ASSOC);
}

// $boardSize is set by Teacher.php when this is include()'d inline;
// when fetched standalone via AJAX, compute it fresh for this game.
if (!isset($boardSize)) {
    $sessionStmt = mysqli_prepare($conn, "SELECT * FROM game_session WHERE id = ?");
    mysqli_stmt_bind_param($sessionStmt, "i", $gameSessionId);
    mysqli_stmt_execute($sessionStmt);
    $sessionRow = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStmt));
    $boardSize = getEffectiveBoardSize($conn, $sessionRow);
}

if (empty($leaderboard)) {
    echo '<tr><td colspan="7" style="text-align:center; color: var(--text-muted);">
            No students have joined yet — share the QR code to get started.
          </td></tr>';
    return;
}

$rank = 1;
foreach ($leaderboard as $row) {

    $rankClass = '';
    if ($rank === 1) $rankClass = 'rank-1';
    elseif ($rank === 2) $rankClass = 'rank-2';
    elseif ($rank === 3) $rankClass = 'rank-3';

    $progress = (int) round((((int) $row['current_tile']) / max(1, $boardSize - 1)) * 100);
    $laps     = (int) ($row['laps_completed'] ?? 0);

    echo '<tr data-student-id="' . (int) $row['id'] . '" data-student-name="' . htmlspecialchars($row['student_name'], ENT_QUOTES) . '" data-node="' . (int) $row['current_tile'] . '" data-laps="' . $laps . '" data-score="' . (int) $row['score'] . '">';
    $hasArmedPower = !empty($row['active_power_id']);

    echo '<td class="rank-cell ' . $rankClass . '">#' . $rank . '</td>';
    echo '<td>' . htmlspecialchars($row['student_name'])
       . ($hasArmedPower ? ' <span title="Has a power armed for their next answer">⚡</span>' : '')
       . '</td>';
    echo '<td>' . (int) $row['layer_number'] . '</td>';
    echo '<td>
            <div class="progress-track">
                <div class="progress-fill" style="width: ' . $progress . '%;"></div>
            </div>
          </td>';
    echo '<td>Epoch ' . ($laps + 1) . '</td>';
    echo '<td>' . (int) $row['score'] . ' / ' . WINNING_SCORE . '</td>';
    echo '<td>';

    // The "status" column was created as STATUS (uppercase) in the schema,
    // so check both possible key cases just in case.
    $onlineStatus = $row['STATUS'] ?? $row['status'] ?? 'Offline';

    if ($row['winner'] === 'Yes') {
        echo '<span class="badge badge-winner">🏆 Winner</span>';
    } elseif ($onlineStatus === 'Online') {
        echo '<span class="badge badge-online">● Online</span>';
    } else {
        echo '<span class="badge badge-offline">○ Offline</span>';
    }

    echo '</td>';
    echo '</tr>';

    $rank++;
}
?>