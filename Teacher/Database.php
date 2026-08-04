<?php
// ------------------------------------------------------------------
// Database connection
// ------------------------------------------------------------------
$host     = "localhost";
$user     = "root";
$password = "";
$database = "csproject"; // matches the schema you provided (USE csproject;)

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("Database Connection Failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

// ------------------------------------------------------------------
// Game settings (shared across all pages)
// ------------------------------------------------------------------
define("WINNING_SCORE", 50);     // First student to reach this score wins
define("BOARD_SIZE", 50);        // Default board size for 'default' games (nodes 0-49)
define("POINTS_PER_CORRECT", 5); // Points awarded for a correct answer
define("WRONG_ANSWER_PENALTY", 2); // Points lost for a wrong answer (score never drops below 0)
define("STEAL_POINTS_AMOUNT", 10); // How many points the Steal Points power takes (capped to target's score)
define("POWER_NODE_CHANCE_PERCENT", 30); // Roughly this % of non-Start nodes hand out a power card
define("EPOCH_COMPLETE_BONUS", 10); // Bonus points for completing a full pass around the board (Input -> Output -> wraps to Input)
define("MIN_CUSTOM_QUESTIONS", 20); // A 'custom' game needs at least this many questions before it can be published/started

// How many custom questions has this game written so far? Shared by
// manage-questions.php (the live counter + Publish gate) and
// teacher-dashboard.php (re-checked server-side when starting a game via
// Enter Game, so the 20-question rule can't be bypassed either way).
function countCustomQuestions($conn, $gameSessionId) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM questions WHERE game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return (int) ($row['cnt'] ?? 0);
}

// ------------------------------------------------------------------
// Power-ups — self-migrating schema additions, so nothing needs to be
// run manually in phpMyAdmin. Safe to run on every request: both
// statements are no-ops once the table/column already exist.
//   - student_powers: one row per power card a student has picked up.
//     status moves unused -> armed (double_points/gamble, waiting on
//     their next question) or straight to used (steal_points, instant)
//     -> used (double_points/gamble, once that next question is graded).
//   - students.active_power_id: which armed card (if any) applies to
//     this student's NEXT graded answer. NULL when nothing is armed.
// ------------------------------------------------------------------
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS student_powers (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        game_session_id INT NOT NULL,
        power_type VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'unused',
        target_student_id INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
mysqli_query($conn, "ALTER TABLE students ADD COLUMN IF NOT EXISTS active_power_id INT DEFAULT NULL");

// Metadata for the 3 power-ups this board supports — one shared function
// so the label/description text can't drift between the endpoints that
// grant, list, and apply them. A function (not a global var) so it's
// safe to call from inside any other function without a `global` import.
function getPowerTypes(): array {
    return [
        'double_points' => [
            'label' => 'Double Points',
            'description' => 'Safe bet — your next correct answer is worth double points, and you\'re shielded from the usual -' . WRONG_ANSWER_PENALTY . ' penalty if you get it wrong.',
        ],
        'gamble' => [
            'label' => 'Double or Nothing',
            'description' => 'High risk — your next answer is worth double points if correct, but you LOSE double points (worse than the usual -' . WRONG_ANSWER_PENALTY . ') if wrong.',
        ],
        'steal_points' => [
            'label' => 'Steal Points',
            'description' => 'Instantly steal ' . STEAL_POINTS_AMOUNT . ' points from another student in this game.',
        ],
    ];
}

// ------------------------------------------------------------------
// Is this board node a Power node? ~POWER_NODE_CHANCE_PERCENT of nodes
// (excluding Start) hand out a power card instead of a question.
//
// Instead of a fixed "every 5th tile" rule, each node's fate is decided
// by hashing (game_session_id, node) — so which nodes are Power nodes
// looks random and differs from game to game, but stays PERFECTLY
// consistent within one game every time it's checked (board display,
// QR grant, the teacher's move panel all agree, with no extra DB column
// needed to remember the layout).
// ------------------------------------------------------------------
function isPowerNode(int $node, int $gameSessionId): bool {
    if ($node <= 0) {
        return false; // Start never gives a power
    }
    $hash = crc32($gameSessionId . ':' . $node);
    return ($hash % 100) < POWER_NODE_CHANCE_PERCENT;
}

// ------------------------------------------------------------------
// Compute the EFFECTIVE board size for a specific game.
//   - 'default' mode games always use the fixed BOARD_SIZE (50) — this
//     is today's existing behaviour, completely unchanged.
//   - 'custom' mode games are sized to (however many topics the teacher
//     has written) + 1 (node 0 = Start). If they haven't written any
//     questions yet, we fall back to BOARD_SIZE so the board doesn't
//     break down to a single node before they've added content.
// Every page that cares about board size (dice movement, progress bars,
// the board's visual layout) calls this instead of using BOARD_SIZE
// directly, so a custom game's size stays consistent everywhere.
// ------------------------------------------------------------------
function getEffectiveBoardSize($conn, $gameSession) {
    if (!$gameSession || ($gameSession['question_mode'] ?? 'default') !== 'custom') {
        return BOARD_SIZE;
    }

    $stmt = mysqli_prepare($conn, "SELECT COUNT(DISTINCT tile_number) AS cnt FROM questions WHERE game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSession['id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $topicCount = (int) ($row['cnt'] ?? 0);

    if ($topicCount <= 0) {
        return BOARD_SIZE; // no custom questions written yet
    }

    return $topicCount + 1; // +1 for the Start node
}

// ------------------------------------------------------------------
// Distribute $total nodes across 6 columns (Input -> Hidden 1-4 ->
// Output) proportionally to the original 6/10/10/10/8/6 shape (which
// sums to 50, the default board). A 'custom' game with a smaller or
// larger board still gets the same visual silhouette, just scaled — e.g.
// an 11-node board (10 topics + Start) still reads as a proper
// neural-net diagram instead of losing the layered look entirely. Every
// column is guaranteed at least 1 node.
//
// Shared (not just board_track.php's problem) because pickQuestionForTile()
// now needs to know which column a node falls in too, to decide how hard
// a question to give — see getNodeColumnIndex()/difficultyForColumn() below.
// ------------------------------------------------------------------
function distributeNodesAcrossLayers(int $total, array $weights): array {
    $numWeights = count($weights);

    // Not enough nodes for every layer to get at least one (e.g. a custom
    // game with only 2-5 topics). Rather than force every layer to 1 and
    // then subtract the overflow back off one layer -- which can leave a
    // layer with 0 nodes and break the rest of this file -- just keep as
    // many layers as we have nodes for, always keeping the first (Input)
    // and last (Output) layers so the board still reads as a proper
    // network, and filling remaining slots from the heaviest-weighted
    // hidden layers.
    if ($total < $numWeights) {
        if ($total <= 0) $total = 1;
        $keys = array_keys($weights);

        if ($total === 1) {
            return [$keys[0] => 1];
        }

        $keep = [$keys[0], $keys[$numWeights - 1]]; // Input + Output
        $middleKeys = array_slice($keys, 1, -1);
        usort($middleKeys, fn($a, $b) => $weights[$b] <=> $weights[$a]);
        foreach ($middleKeys as $k) {
            if (count($keep) >= $total) break;
            $keep[] = $k;
        }
        sort($keep);

        $counts = [];
        foreach ($keep as $k) {
            $counts[$k] = 1;
        }
        return $counts;
    }

    $weightSum = array_sum($weights);
    $counts = [];
    foreach ($weights as $i => $w) {
        $counts[$i] = (int) round($w / $weightSum * $total);
    }
    foreach ($counts as $i => $c) {
        if ($c < 1) $counts[$i] = 1;
    }
    $delta = $total - array_sum($counts);
    if ($delta !== 0) {
        arsort($counts);
        $keys = array_keys($counts);
        $counts[$keys[0]] += $delta;
        ksort($counts);
    }
    return $counts;
}

// How many nodes are in each of the 6 visual columns (Input, Hidden 1-4,
// Output) for a board of this size. board_track.php uses this to draw
// the columns; getNodeColumnIndex() below uses it to figure out which
// column a given node sits in.
function getBoardColumnCounts(int $boardSize): array {
    $weights = [6, 10, 10, 10, 8, 6];
    if ($boardSize === BOARD_SIZE) {
        return $weights; // the default board's original hand-tuned shape
    }
    return array_values(distributeNodesAcrossLayers($boardSize, $weights));
}

// Which of the 6 columns (0 = Input ... 5 = Output) does this node fall in?
function getNodeColumnIndex(int $node, int $boardSize): int {
    $counts = getBoardColumnCounts($boardSize);
    $seen = 0;
    foreach ($counts as $columnIndex => $count) {
        if ($node < $seen + $count) {
            return $columnIndex;
        }
        $seen += $count;
    }
    return count($counts) - 1; // fallback: treat as Output
}

// Map a column (0 = Input ... 5 = Output) to a difficulty tier from 1 up
// to however many question layers this game actually has (1-4) — so
// standing deeper in the "network" always means a harder question,
// however many tiers the teacher configured.
function difficultyForColumn(int $columnIndex, int $numLayers): int {
    $numLayers = max(1, $numLayers);
    $tier = 1 + intdiv($columnIndex * $numLayers, 6);
    return max(1, min($numLayers, $tier));
}

// Convenience wrapper: given a node + this game's board size/layer count,
// what difficulty tier applies there? This is what a student's
// students.layer_number gets set to on every move (see Teacher.php),
// replacing the old "randomly assigned once at join" behaviour — board
// depth now drives difficulty instead.
function getDepthLayer(int $node, int $boardSize, int $numLayers): int {
    return difficultyForColumn(getNodeColumnIndex($node, $boardSize), $numLayers);
}

// ------------------------------------------------------------------
// Notifications broadcast by the admin (see Admin/admin.php's Notify
// tab). A notification's `target_audience` is 'all', 'teacher', or
// 'student' — this returns whatever applies to $role, newest first.
// Shared by Teacher/notification.php and Student/notification.php so
// the same query backs the bell dropdown on both sides.
// ------------------------------------------------------------------
function getNotificationsForRole($conn, string $role, int $limit = 30): array {
    if (!in_array($role, ['teacher', 'student'], true)) {
        return [];
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, title, content, target_audience, created_at
         FROM notifications
         WHERE target_audience = 'all' OR target_audience = ?
         ORDER BY created_at DESC
         LIMIT ?"
    );
    mysqli_stmt_bind_param($stmt, "si", $role, $limit);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) ?: [];
}

// ------------------------------------------------------------------
// First correct answer (or epoch-completion bonus) that pushes a
// student to WINNING_SCORE claims the win — but only if nobody in this
// game has already won. Shared by submit-answer.php (question answers)
// and Teacher.php (epoch-completion bonus), so the win check can never
// drift between the two places score can change.
// ------------------------------------------------------------------
function maybeAwardWinner($conn, int $gameSessionId, int $studentRowId, int $newScore): void {
    if ($newScore < WINNING_SCORE) {
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT winner_student FROM game_session WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);
    $gs = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($gs && !$gs['winner_student']) {
        $stmt = mysqli_prepare($conn, "UPDATE game_session SET winner_student = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $studentRowId, $gameSessionId);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn, "UPDATE students SET winner = 'Yes' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $studentRowId);
        mysqli_stmt_execute($stmt);
    }
}

// ------------------------------------------------------------------
// Given a student's current tile + assigned layer, resolve exactly which
// `questions` row they should be quizzed on after scanning their QR code.
// This MUST mirror board_track.php's topicLabelForNode() wrap-around math
// exactly, so the topic label shown on the teacher's board always matches
// the question the student is actually given for that node.
//   - Node 0 (Start) has no question -> returns null.
//   - If this game has any of its own custom questions (manage-questions.php),
//     ONLY those are used, wrapped over however many topics it has.
//   - Otherwise falls back to the shared default bank (15 topics, tile
//     numbers 1-15, game_session_id IS NULL), where each topic/layer
//     combination has a few randomised variants to pick from.
// ------------------------------------------------------------------
function pickQuestionForTile($conn, $gameSessionId, $currentTile, $layerNumber) {
    if ($currentTile <= 0) {
        return null; // Start tile — nothing to answer here
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT tile_number FROM questions WHERE game_session_id = ? ORDER BY tile_number ASC"
    );
    mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
    mysqli_stmt_execute($stmt);
    $customRows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    if (!empty($customRows)) {
        $topicKeys = array_map(fn($r) => (int) $r['tile_number'], $customRows);
        $isCustom  = true;
    } else {
        $topicKeys = range(1, 15); // shared default bank's 15 topics
        $isCustom  = false;
    }

    $topicCount = count($topicKeys);
    if ($topicCount <= 0) {
        return null;
    }

    $index        = ($currentTile - 1) % $topicCount;
    $dbTileNumber = (int) $topicKeys[$index];

    if ($isCustom) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT * FROM questions WHERE game_session_id = ? AND tile_number = ? AND layer_number = ? ORDER BY RAND() LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, "iii", $gameSessionId, $dbTileNumber, $layerNumber);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT * FROM questions WHERE game_session_id IS NULL AND tile_number = ? AND layer_number = ? ORDER BY RAND() LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, "ii", $dbTileNumber, $layerNumber);
    }
    mysqli_stmt_execute($stmt);
    $question = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return $question ?: null;
}
?>