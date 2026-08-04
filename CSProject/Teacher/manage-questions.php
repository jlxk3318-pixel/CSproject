<?php
session_start();

$isLoggedIn = isset($_SESSION['user']) && ($_SESSION['role'] ?? '') === 'teacher';
if (!$isLoggedIn) {
    header("Location: ../Admin/login.php");
    exit();
}

include 'Database.php';

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

$errorMessage = '';
$successMessage = '';

// ------------------------------------------------------------------
// Action: Add a custom question for this game
// ------------------------------------------------------------------
if (isset($_POST['add_question'])) {
    $topicLabel    = trim($_POST['topic_label'] ?? '');
    $layerNumber   = (int) ($_POST['layer_number'] ?? 0);
    $questionText  = trim($_POST['question_text'] ?? '');
    $optionA       = trim($_POST['option_a'] ?? '');
    $optionB       = trim($_POST['option_b'] ?? '');
    $optionC       = trim($_POST['option_c'] ?? '');
    $optionD       = trim($_POST['option_d'] ?? '');
    $correctAnswer = strtoupper(trim($_POST['correct_answer'] ?? ''));

    $maxLayers = (int) $gameSession['num_layers'];

    // Duplicate check — same question text already added to THIS game
    // (case/whitespace-insensitive), regardless of topic or layer.
    // Only bother querying once the basic fields are actually valid.
    $isDuplicate = false;
    if ($topicLabel !== '' && $layerNumber >= 1 && $layerNumber <= $maxLayers
        && $questionText !== '' && $optionA !== '' && $optionB !== '' && $optionC !== '' && $optionD !== ''
        && in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)) {
        $dupStmt = mysqli_prepare(
            $conn,
            "SELECT id FROM questions WHERE game_session_id = ? AND LOWER(TRIM(question_text)) = LOWER(TRIM(?)) LIMIT 1"
        );
        mysqli_stmt_bind_param($dupStmt, "is", $gameSessionId, $questionText);
        mysqli_stmt_execute($dupStmt);
        $isDuplicate = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
    }

    if ($topicLabel === '') {
        $errorMessage = 'Please enter a topic name.';
    } elseif ($layerNumber < 1 || $layerNumber > $maxLayers) {
        $errorMessage = 'Layer must be between 1 and ' . $maxLayers . ' for this game.';
    } elseif ($questionText === '' || $optionA === '' || $optionB === '' || $optionC === '' || $optionD === '') {
        $errorMessage = 'Please fill in the question and all four options.';
    } elseif (!in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)) {
        $errorMessage = 'Please select which option is correct.';
    } elseif ($isDuplicate) {
        $errorMessage = 'This question already exists in this game. Please enter a different question.';
    } else {
        // Reuse the same topic "slot" if this game already has a topic
        // with this exact name (case-insensitive) — so multiple question
        // variants under the same topic group together. Otherwise this
        // becomes a brand new topic, and the board's wrap count grows
        // by one automatically.
        $stmt = mysqli_prepare(
            $conn,
            "SELECT tile_number FROM questions WHERE game_session_id = ? AND LOWER(topic_label) = LOWER(?) LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, "is", $gameSessionId, $topicLabel);
        mysqli_stmt_execute($stmt);
        $existingTopic = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($existingTopic) {
            $topicNumber = (int) $existingTopic['tile_number'];
        } else {
            $stmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(tile_number), 0) + 1 AS next_num FROM questions WHERE game_session_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
            mysqli_stmt_execute($stmt);
            $nextRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $topicNumber = (int) $nextRow['next_num'];
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO questions (game_session_id, layer_number, tile_number, topic_label, question_text, option_a, option_b, option_c, option_d, correct_answer)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt, "iiisssssss",
            $gameSessionId, $layerNumber, $topicNumber, $topicLabel, $questionText, $optionA, $optionB, $optionC, $optionD, $correctAnswer
        );
        mysqli_stmt_execute($stmt);

        // Direct, immediate feedback on the 20-question minimum — this is
        // the moment a teacher most wants to know how close they are.
        $countAfterAdd = countCustomQuestions($conn, $gameSessionId);
        $successMessage = 'Question added under topic "' . htmlspecialchars($topicLabel) . '". '
            . 'You now have ' . $countAfterAdd . ' / ' . MIN_CUSTOM_QUESTIONS . ' questions'
            . ($countAfterAdd >= MIN_CUSTOM_QUESTIONS ? ' — enough to publish!' : '.');
    }
}

// ------------------------------------------------------------------
// Action: Delete a custom question (only ones belonging to THIS game)
// ------------------------------------------------------------------
if (isset($_POST['delete_question'])) {
    $questionId = (int) $_POST['delete_question'];
    $stmt = mysqli_prepare($conn, "DELETE FROM questions WHERE id = ? AND game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $questionId, $gameSessionId);
    mysqli_stmt_execute($stmt);
    $successMessage = 'Question deleted.';
}

// ------------------------------------------------------------------
// Action: Publish & Start Game — flips this game from Draft to Running
// so students can actually join it. Gated on MIN_CUSTOM_QUESTIONS,
// re-checked here server-side (never trust the disabled-button state
// alone — see also the same check in teacher-dashboard.php's Start Game).
// ------------------------------------------------------------------
if (isset($_POST['publish_game'])) {
    $questionCountForPublish = countCustomQuestions($conn, $gameSessionId);

    if ($questionCountForPublish < MIN_CUSTOM_QUESTIONS) {
        $errorMessage = 'You need at least ' . MIN_CUSTOM_QUESTIONS . ' questions before publishing this game. '
            . 'You currently have ' . $questionCountForPublish . '.';
    } else {
        // Only one game stays "Running" at a time — publishing this one
        // automatically finishes whichever game was previously active.
        mysqli_query(
            $conn,
            "UPDATE game_session SET game_status = 'Finished' WHERE game_status = 'Running' AND id != " . $gameSessionId
        );

        $stmt = mysqli_prepare($conn, "UPDATE game_session SET game_status = 'Running' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
        mysqli_stmt_execute($stmt);

        header("Location: Teacher.php?session=" . $gameSessionId);
        exit();
    }
}

// ------------------------------------------------------------------
// Load this game's custom questions, grouped by topic then layer
// ------------------------------------------------------------------
$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM questions WHERE game_session_id = ? ORDER BY tile_number ASC, layer_number ASC, id ASC"
);
mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
mysqli_stmt_execute($stmt);
$customQuestions = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Distinct topic count — this is exactly how many "slots" the board
// will now cycle through for this game (instead of the default 15)
$distinctTopics = [];
foreach ($customQuestions as $q) {
    $distinctTopics[(int) $q['tile_number']] = $q['topic_label'];
}
$topicCount = count($distinctTopics);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NeuroNet Quest — Manage Questions</title>
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
        <a href="Teacher.php?session=<?php echo $gameSessionId; ?>" class="btn outline" style="margin-right:8px;">◀ Back to Board</a>
        <button type="button" id="ttq-notif-toggle" class="btn outline" style="padding:8px 12px;" aria-label="Notifications">🔔</button>
    </div>
</div>

<?php include 'notification.php'; ?>

<div class="dashboard">

    <div class="topbar">
        <div>
            <p class="eyebrow">Custom Content · <?php echo htmlspecialchars($gameSession['game_name'] ?: 'Untitled Game'); ?></p>
            <h1>Manage Questions</h1>
        </div>
    </div>

    <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px; max-width:720px;">
        <?php if ($topicCount > 0): ?>
            You've written <strong><?php echo $topicCount; ?></strong> custom topic<?php echo $topicCount == 1 ? '' : 's'; ?> for this game —
            the board's 50 nodes will cycle through <strong>your <?php echo $topicCount; ?> topic<?php echo $topicCount == 1 ? '' : 's'; ?></strong> instead
            of the default 15, repeating as needed to fill the board.
        <?php else: ?>
            No custom topics yet — this game is currently using the shared default question bank (15 topics).
            Add your first question below to switch this game over to your own content.
        <?php endif; ?>
        This game has <strong><?php echo (int) $gameSession['num_layers']; ?></strong> layer<?php echo $gameSession['num_layers'] == 1 ? '' : 's'; ?> configured.
    </p>

    <?php
        $totalQuestionCount = count($customQuestions);
        $questionsStillNeeded = max(0, MIN_CUSTOM_QUESTIONS - $totalQuestionCount);
        $canPublish = $totalQuestionCount >= MIN_CUSTOM_QUESTIONS;
        $progressPct = min(100, (int) round(($totalQuestionCount / MIN_CUSTOM_QUESTIONS) * 100));
    ?>
    <div class="panel" style="margin-bottom:20px;">
        <h2 class="panel-title"><span class="dot"></span>Question Bank Progress</h2>
        <div class="progress-track" style="height:10px; margin-bottom:10px;">
            <div class="progress-fill" style="width: <?php echo $progressPct; ?>%;"></div>
        </div>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:14px;">
            <strong><?php echo $totalQuestionCount; ?></strong> / <?php echo MIN_CUSTOM_QUESTIONS; ?> questions added
            — a game needs at least <?php echo MIN_CUSTOM_QUESTIONS; ?> questions before it can be published.
            <?php if (!$canPublish): ?>
                Add <strong><?php echo $questionsStillNeeded; ?></strong> more question<?php echo $questionsStillNeeded == 1 ? '' : 's'; ?> to unlock publishing.
            <?php endif; ?>
        </p>

        <?php if ($gameSession['game_status'] === 'Running'): ?>
            <p style="font-size:13px; color: var(--success); margin:0;">✅ This game is published and running — students can join right now.</p>
        <?php else: ?>
            <form method="POST">
                <button
                    type="submit"
                    name="publish_game"
                    class="btn-primary"
                    style="width:auto; padding:12px 22px; <?php echo $canPublish ? '' : 'opacity:0.5; cursor:not-allowed;'; ?>"
                    <?php echo $canPublish ? '' : 'disabled'; ?>
                >
                    <?php echo $canPublish
                        ? '🚀 Publish & Start Game'
                        : '🔒 Need ' . $questionsStillNeeded . ' more question' . ($questionsStillNeeded == 1 ? '' : 's') . ' to publish'; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($errorMessage): ?>
        <div class="banner banner-error" style="margin-bottom:20px;"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="banner banner-success" style="margin-bottom:20px;"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <div class="main-grid">

        <!-- Add question form -->
        <div class="panel">
            <h2 class="panel-title"><span class="dot"></span>Add a Custom Question</h2>
            <form method="POST">
                <label for="topic_label">Topic Name</label>
                <input type="text" name="topic_label" id="topic_label" list="existingTopics" required placeholder="e.g. Sorting Algorithms" autocomplete="off">
                <datalist id="existingTopics">
                    <?php foreach ($distinctTopics as $label): ?>
                        <option value="<?php echo htmlspecialchars($label); ?>">
                    <?php endforeach; ?>
                </datalist>
                <p class="modal-hint" style="margin-bottom:12px;">
                    Type an existing topic name exactly to add another variant to it, or a brand new name to create a new topic.
                </p>

                <label for="layer_number">Layer</label>
                <select name="layer_number" id="layer_number" required>
                    <?php for ($l = 1; $l <= (int) $gameSession['num_layers']; $l++): ?>
                        <option value="<?php echo $l; ?>">Layer <?php echo $l; ?></option>
                    <?php endfor; ?>
                </select>

                <label for="question_text">Question</label>
                <textarea name="question_text" id="question_text" rows="2" required placeholder="e.g. Which sorting algorithm has O(n log n) average time?" style="width:100%; padding:12px; border-radius:10px; background:rgba(6,11,31,0.4); border:1px solid var(--card-border); color:var(--text); font-family:var(--font-body); resize:vertical;"></textarea>

                <label for="option_a">Option A</label>
                <input type="text" name="option_a" id="option_a" required>
                <label for="option_b">Option B</label>
                <input type="text" name="option_b" id="option_b" required>
                <label for="option_c">Option C</label>
                <input type="text" name="option_c" id="option_c" required>
                <label for="option_d">Option D</label>
                <input type="text" name="option_d" id="option_d" required>

                <label for="correct_answer">Correct Answer</label>
                <select name="correct_answer" id="correct_answer" required>
                    <option value="">Select correct option</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                </select>

                <button type="submit" name="add_question" class="btn-primary" style="margin-top:14px;">Add Question</button>
            </form>
        </div>

        <!-- Existing custom questions -->
        <div class="panel">
            <h2 class="panel-title"><span class="dot"></span>This Game's Custom Questions</h2>
            <?php if (empty($customQuestions)): ?>
                <p style="color:var(--text-muted); font-size:13px;">
                    No custom questions yet — every topic is currently using the shared default question bank.
                </p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px; max-height:520px; overflow-y:auto;">
                    <?php foreach ($customQuestions as $q): ?>
                        <div style="border:1px solid var(--card-border); border-radius:10px; padding:12px;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                                <div style="min-width:0;">
                                    <div style="font-family:var(--font-mono); font-size:11px; color:var(--text-muted); margin-bottom:4px;">
                                        <?php echo htmlspecialchars($q['topic_label'] ?: ('Topic ' . $q['tile_number'])); ?>
                                        · Layer <?php echo (int) $q['layer_number']; ?>
                                    </div>
                                    <div style="font-size:13.5px; font-weight:600;">
                                        <?php echo htmlspecialchars($q['question_text']); ?>
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:6px; line-height:1.6;">
                                        A) <?php echo htmlspecialchars($q['option_a']); ?><br>
                                        B) <?php echo htmlspecialchars($q['option_b']); ?><br>
                                        C) <?php echo htmlspecialchars($q['option_c']); ?><br>
                                        D) <?php echo htmlspecialchars($q['option_d']); ?><br>
                                        <span style="color:var(--success);">Correct: <?php echo htmlspecialchars($q['correct_answer']); ?></span>
                                    </div>
                                </div>
                                <form method="POST" onsubmit="return confirm('Delete this question?');">
                                    <button type="submit" name="delete_question" value="<?php echo (int) $q['id']; ?>" class="btn-danger" style="width:auto; padding:6px 12px; font-size:11px;">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php $chatbotRole = 'teacher'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html><?php
session_start();

$isLoggedIn = isset($_SESSION['user']) && ($_SESSION['role'] ?? '') === 'teacher';
if (!$isLoggedIn) {
    header("Location: ../Admin/login.php");
    exit();
}

include 'Database.php';

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

$errorMessage = '';
$successMessage = '';

// ------------------------------------------------------------------
// Action: Add a custom question for this game
// ------------------------------------------------------------------
if (isset($_POST['add_question'])) {
    $topicLabel    = trim($_POST['topic_label'] ?? '');
    $layerNumber   = (int) ($_POST['layer_number'] ?? 0);
    $questionText  = trim($_POST['question_text'] ?? '');
    $optionA       = trim($_POST['option_a'] ?? '');
    $optionB       = trim($_POST['option_b'] ?? '');
    $optionC       = trim($_POST['option_c'] ?? '');
    $optionD       = trim($_POST['option_d'] ?? '');
    $correctAnswer = strtoupper(trim($_POST['correct_answer'] ?? ''));

    $maxLayers = (int) $gameSession['num_layers'];

    // Duplicate check — same question text already added to THIS game
    // (case/whitespace-insensitive), regardless of topic or layer.
    // Only bother querying once the basic fields are actually valid.
    $isDuplicate = false;
    if ($topicLabel !== '' && $layerNumber >= 1 && $layerNumber <= $maxLayers
        && $questionText !== '' && $optionA !== '' && $optionB !== '' && $optionC !== '' && $optionD !== ''
        && in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)) {
        $dupStmt = mysqli_prepare(
            $conn,
            "SELECT id FROM questions WHERE game_session_id = ? AND LOWER(TRIM(question_text)) = LOWER(TRIM(?)) LIMIT 1"
        );
        mysqli_stmt_bind_param($dupStmt, "is", $gameSessionId, $questionText);
        mysqli_stmt_execute($dupStmt);
        $isDuplicate = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
    }

    if ($topicLabel === '') {
        $errorMessage = 'Please enter a topic name.';
    } elseif ($layerNumber < 1 || $layerNumber > $maxLayers) {
        $errorMessage = 'Layer must be between 1 and ' . $maxLayers . ' for this game.';
    } elseif ($questionText === '' || $optionA === '' || $optionB === '' || $optionC === '' || $optionD === '') {
        $errorMessage = 'Please fill in the question and all four options.';
    } elseif (!in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)) {
        $errorMessage = 'Please select which option is correct.';
    } elseif ($isDuplicate) {
        $errorMessage = 'This question already exists in this game. Please enter a different question.';
    } else {
        // Reuse the same topic "slot" if this game already has a topic
        // with this exact name (case-insensitive) — so multiple question
        // variants under the same topic group together. Otherwise this
        // becomes a brand new topic, and the board's wrap count grows
        // by one automatically.
        $stmt = mysqli_prepare(
            $conn,
            "SELECT tile_number FROM questions WHERE game_session_id = ? AND LOWER(topic_label) = LOWER(?) LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, "is", $gameSessionId, $topicLabel);
        mysqli_stmt_execute($stmt);
        $existingTopic = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($existingTopic) {
            $topicNumber = (int) $existingTopic['tile_number'];
        } else {
            $stmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(tile_number), 0) + 1 AS next_num FROM questions WHERE game_session_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
            mysqli_stmt_execute($stmt);
            $nextRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $topicNumber = (int) $nextRow['next_num'];
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO questions (game_session_id, layer_number, tile_number, topic_label, question_text, option_a, option_b, option_c, option_d, correct_answer)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt, "iiisssssss",
            $gameSessionId, $layerNumber, $topicNumber, $topicLabel, $questionText, $optionA, $optionB, $optionC, $optionD, $correctAnswer
        );
        mysqli_stmt_execute($stmt);

        // Direct, immediate feedback on the 20-question minimum — this is
        // the moment a teacher most wants to know how close they are.
        $countAfterAdd = countCustomQuestions($conn, $gameSessionId);
        $successMessage = 'Question added under topic "' . htmlspecialchars($topicLabel) . '". '
            . 'You now have ' . $countAfterAdd . ' / ' . MIN_CUSTOM_QUESTIONS . ' questions'
            . ($countAfterAdd >= MIN_CUSTOM_QUESTIONS ? ' — enough to publish!' : '.');
    }
}

// ------------------------------------------------------------------
// Action: Delete a custom question (only ones belonging to THIS game)
// ------------------------------------------------------------------
if (isset($_POST['delete_question'])) {
    $questionId = (int) $_POST['delete_question'];
    $stmt = mysqli_prepare($conn, "DELETE FROM questions WHERE id = ? AND game_session_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $questionId, $gameSessionId);
    mysqli_stmt_execute($stmt);
    $successMessage = 'Question deleted.';
}

// ------------------------------------------------------------------
// Action: Publish & Start Game — flips this game from Draft to Running
// so students can actually join it. Gated on MIN_CUSTOM_QUESTIONS,
// re-checked here server-side (never trust the disabled-button state
// alone — see also the same check in teacher-dashboard.php's Start Game).
// ------------------------------------------------------------------
if (isset($_POST['publish_game'])) {
    $questionCountForPublish = countCustomQuestions($conn, $gameSessionId);

    if ($questionCountForPublish < MIN_CUSTOM_QUESTIONS) {
        $errorMessage = 'You need at least ' . MIN_CUSTOM_QUESTIONS . ' questions before publishing this game. '
            . 'You currently have ' . $questionCountForPublish . '.';
    } else {
        // Only one game stays "Running" at a time — publishing this one
        // automatically finishes whichever game was previously active.
        mysqli_query(
            $conn,
            "UPDATE game_session SET game_status = 'Finished' WHERE game_status = 'Running' AND id != " . $gameSessionId
        );

        $stmt = mysqli_prepare($conn, "UPDATE game_session SET game_status = 'Running' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
        mysqli_stmt_execute($stmt);

        header("Location: Teacher.php?session=" . $gameSessionId);
        exit();
    }
}

// ------------------------------------------------------------------
// Load this game's custom questions, grouped by topic then layer
// ------------------------------------------------------------------
$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM questions WHERE game_session_id = ? ORDER BY tile_number ASC, layer_number ASC, id ASC"
);
mysqli_stmt_bind_param($stmt, "i", $gameSessionId);
mysqli_stmt_execute($stmt);
$customQuestions = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Distinct topic count — this is exactly how many "slots" the board
// will now cycle through for this game (instead of the default 15)
$distinctTopics = [];
foreach ($customQuestions as $q) {
    $distinctTopics[(int) $q['tile_number']] = $q['topic_label'];
}
$topicCount = count($distinctTopics);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NeuroNet Quest — Manage Questions</title>
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
        <a href="Teacher.php?session=<?php echo $gameSessionId; ?>" class="btn outline">◀ Back to Board</a>
    </div>
</div>

<div class="dashboard">

    <div class="topbar">
        <div>
            <p class="eyebrow">Custom Content · <?php echo htmlspecialchars($gameSession['game_name'] ?: 'Untitled Game'); ?></p>
            <h1>Manage Questions</h1>
        </div>
    </div>

    <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px; max-width:720px;">
        <?php if ($topicCount > 0): ?>
            You've written <strong><?php echo $topicCount; ?></strong> custom topic<?php echo $topicCount == 1 ? '' : 's'; ?> for this game —
            the board's 50 nodes will cycle through <strong>your <?php echo $topicCount; ?> topic<?php echo $topicCount == 1 ? '' : 's'; ?></strong> instead
            of the default 15, repeating as needed to fill the board.
        <?php else: ?>
            No custom topics yet — this game is currently using the shared default question bank (15 topics).
            Add your first question below to switch this game over to your own content.
        <?php endif; ?>
        This game has <strong><?php echo (int) $gameSession['num_layers']; ?></strong> layer<?php echo $gameSession['num_layers'] == 1 ? '' : 's'; ?> configured.
    </p>

    <?php
        $totalQuestionCount = count($customQuestions);
        $questionsStillNeeded = max(0, MIN_CUSTOM_QUESTIONS - $totalQuestionCount);
        $canPublish = $totalQuestionCount >= MIN_CUSTOM_QUESTIONS;
        $progressPct = min(100, (int) round(($totalQuestionCount / MIN_CUSTOM_QUESTIONS) * 100));
    ?>
    <div class="panel" style="margin-bottom:20px;">
        <h2 class="panel-title"><span class="dot"></span>Question Bank Progress</h2>
        <div class="progress-track" style="height:10px; margin-bottom:10px;">
            <div class="progress-fill" style="width: <?php echo $progressPct; ?>%;"></div>
        </div>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:14px;">
            <strong><?php echo $totalQuestionCount; ?></strong> / <?php echo MIN_CUSTOM_QUESTIONS; ?> questions added
            — a game needs at least <?php echo MIN_CUSTOM_QUESTIONS; ?> questions before it can be published.
            <?php if (!$canPublish): ?>
                Add <strong><?php echo $questionsStillNeeded; ?></strong> more question<?php echo $questionsStillNeeded == 1 ? '' : 's'; ?> to unlock publishing.
            <?php endif; ?>
        </p>

        <?php if ($gameSession['game_status'] === 'Running'): ?>
            <p style="font-size:13px; color: var(--success); margin:0;">✅ This game is published and running — students can join right now.</p>
        <?php else: ?>
            <form method="POST">
                <button
                    type="submit"
                    name="publish_game"
                    class="btn-primary"
                    style="width:auto; padding:12px 22px; <?php echo $canPublish ? '' : 'opacity:0.5; cursor:not-allowed;'; ?>"
                    <?php echo $canPublish ? '' : 'disabled'; ?>
                >
                    <?php echo $canPublish
                        ? '🚀 Publish & Start Game'
                        : '🔒 Need ' . $questionsStillNeeded . ' more question' . ($questionsStillNeeded == 1 ? '' : 's') . ' to publish'; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($errorMessage): ?>
        <div class="banner banner-error" style="margin-bottom:20px;"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="banner banner-success" style="margin-bottom:20px;"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <div class="main-grid">

        <!-- Add question form -->
        <div class="panel">
            <h2 class="panel-title"><span class="dot"></span>Add a Custom Question</h2>
            <form method="POST">
                <label for="topic_label">Topic Name</label>
                <input type="text" name="topic_label" id="topic_label" list="existingTopics" required placeholder="e.g. Sorting Algorithms" autocomplete="off">
                <datalist id="existingTopics">
                    <?php foreach ($distinctTopics as $label): ?>
                        <option value="<?php echo htmlspecialchars($label); ?>">
                    <?php endforeach; ?>
                </datalist>
                <p class="modal-hint" style="margin-bottom:12px;">
                    Type an existing topic name exactly to add another variant to it, or a brand new name to create a new topic.
                </p>

                <label for="layer_number">Layer</label>
                <select name="layer_number" id="layer_number" required>
                    <?php for ($l = 1; $l <= (int) $gameSession['num_layers']; $l++): ?>
                        <option value="<?php echo $l; ?>">Layer <?php echo $l; ?></option>
                    <?php endfor; ?>
                </select>

                <label for="question_text">Question</label>
                <textarea name="question_text" id="question_text" rows="2" required placeholder="e.g. Which sorting algorithm has O(n log n) average time?" style="width:100%; padding:12px; border-radius:10px; background:rgba(6,11,31,0.4); border:1px solid var(--card-border); color:var(--text); font-family:var(--font-body); resize:vertical;"></textarea>

                <label for="option_a">Option A</label>
                <input type="text" name="option_a" id="option_a" required>
                <label for="option_b">Option B</label>
                <input type="text" name="option_b" id="option_b" required>
                <label for="option_c">Option C</label>
                <input type="text" name="option_c" id="option_c" required>
                <label for="option_d">Option D</label>
                <input type="text" name="option_d" id="option_d" required>

                <label for="correct_answer">Correct Answer</label>
                <select name="correct_answer" id="correct_answer" required>
                    <option value="">Select correct option</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                </select>

                <button type="submit" name="add_question" class="btn-primary" style="margin-top:14px;">Add Question</button>
            </form>
        </div>

        <!-- Existing custom questions -->
        <div class="panel">
            <h2 class="panel-title"><span class="dot"></span>This Game's Custom Questions</h2>
            <?php if (empty($customQuestions)): ?>
                <p style="color:var(--text-muted); font-size:13px;">
                    No custom questions yet — every topic is currently using the shared default question bank.
                </p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px; max-height:520px; overflow-y:auto;">
                    <?php foreach ($customQuestions as $q): ?>
                        <div style="border:1px solid var(--card-border); border-radius:10px; padding:12px;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                                <div style="min-width:0;">
                                    <div style="font-family:var(--font-mono); font-size:11px; color:var(--text-muted); margin-bottom:4px;">
                                        <?php echo htmlspecialchars($q['topic_label'] ?: ('Topic ' . $q['tile_number'])); ?>
                                        · Layer <?php echo (int) $q['layer_number']; ?>
                                    </div>
                                    <div style="font-size:13.5px; font-weight:600;">
                                        <?php echo htmlspecialchars($q['question_text']); ?>
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:6px; line-height:1.6;">
                                        A) <?php echo htmlspecialchars($q['option_a']); ?><br>
                                        B) <?php echo htmlspecialchars($q['option_b']); ?><br>
                                        C) <?php echo htmlspecialchars($q['option_c']); ?><br>
                                        D) <?php echo htmlspecialchars($q['option_d']); ?><br>
                                        <span style="color:var(--success);">Correct: <?php echo htmlspecialchars($q['correct_answer']); ?></span>
                                    </div>
                                </div>
                                <form method="POST" onsubmit="return confirm('Delete this question?');">
                                    <button type="submit" name="delete_question" value="<?php echo (int) $q['id']; ?>" class="btn-danger" style="width:auto; padding:6px 12px; font-size:11px;">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php $chatbotRole = 'teacher'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>