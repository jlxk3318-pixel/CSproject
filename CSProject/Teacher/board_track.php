<?php
// This file is included by Teacher.php AND fetched directly via AJAX
// (see script.js) to refresh the board without reloading the page.
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

// ------------------------------------------------------------------
// Neural-network board layout
// ------------------------------------------------------------------
// Arranged into 6 columns just like a real neural network diagram:
// Input Layer -> Hidden 1-4 -> Output Layer. A student's current_tile
// (0 to boardSize-1) maps 1:1 onto a node here. Epochs (full laps) wrap
// around via % $boardSize (see the dice-move logic in Teacher.php).
// Node 0 ("Start") is the only node with no question attached.
// getBoardColumnCounts() lives in Database.php (shared with
// getNodeColumnIndex()/difficultyForColumn(), which use the exact same
// column split to decide question difficulty by board depth).
$layerCounts = getBoardColumnCounts($boardSize);
$layerNames  = ['Input Layer', 'Hidden 1', 'Hidden 2', 'Hidden 3', 'Hidden 4', 'Output Layer'];

// Real topic per DB tile_number (see the SQL dump: 15 topics x 4 layers
// x 3 randomised variants = 180 questions, tile_number 1-15 only).
$defaultTopicNames = [
    1 => 'AI Basics', 2 => 'Neurons', 3 => 'Input Layer', 4 => 'Weights', 5 => 'Activation',
    6 => 'ReLU', 7 => 'Forward Pass', 8 => 'Loss Function', 9 => 'Gradient Descent', 10 => 'Backpropagation',
    11 => 'Epoch', 12 => 'Overfitting', 13 => 'Learning Rate', 14 => 'CNN', 15 => 'Output Layer',
];

// If this game has any custom questions (see Teacher/manage-questions.php),
// use ITS own topics/count for the board's wrap instead of the shared
// 15 — this MUST mirror Answer.php's pickQuestionForTile() exactly, or
// the tooltip shown here won't match the topic the student actually
// gets quizzed on.
$customTopicNames = [];
if ($gameSessionId) {
    $customTopicsStmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT tile_number, topic_label FROM questions WHERE game_session_id = ? ORDER BY tile_number ASC"
    );
    mysqli_stmt_bind_param($customTopicsStmt, "i", $gameSessionId);
    mysqli_stmt_execute($customTopicsStmt);
    $customTopicRows = mysqli_fetch_all(mysqli_stmt_get_result($customTopicsStmt), MYSQLI_ASSOC);
    foreach ($customTopicRows as $row) {
        $customTopicNames[(int) $row['tile_number']] = $row['topic_label'];
    }
}

$topicNames = !empty($customTopicNames) ? $customTopicNames : $defaultTopicNames;
$topicCount = count($topicNames);

// Node 0 is Start (no question). Power nodes (see isPowerNode() in
// Database.php) hand out a power card instead of a question. Everything
// else wraps onto however many topics apply to this game (custom count
// if this game has any, otherwise the shared bank's 15) — this MUST
// match the wrap formula used in Database.php's pickQuestionForTile(),
// so the label shown here is the topic the student will actually be
// quizzed on.
function topicLabelForNode(int $node, array $topicNames, int $topicCount, int $gameSessionId): string {
    if ($node === 0) return 'Start';
    if (isPowerNode($node, $gameSessionId)) return '⚡ Power';
    if ($topicCount <= 0) return 'Node ' . $node;
    $topicKeys = array_keys($topicNames);
    $index   = ($node - 1) % $topicCount;
    $dbTopic = $topicKeys[$index];
    $round   = intdiv($node - 1, $topicCount) + 1;
    return $topicNames[$dbTopic] . ($round > 1 ? ' (Round ' . $round . ')' : '');
}

$nodeLabels = [];
for ($n = 0; $n < $boardSize; $n++) {
    $nodeLabels[$n] = topicLabelForNode($n, $topicNames, $topicCount, $gameSessionId);
}

// Group students by the node they currently sit on, and rank them for
// token colour/number (rank order matches the leaderboard).
$tileOccupants = array_fill(0, $boardSize, []);
$rank = 1;
foreach ($leaderboard as $row) {
    $tile = (int) $row['current_tile'];
    if ($tile < 0) $tile = 0;
    if ($tile >= $boardSize) $tile = $tile % $boardSize;

    $tileOccupants[$tile][] = [
        'name'   => $row['student_name'],
        'rank'   => $rank,
        'winner' => $row['winner'] === 'Yes',
        'laps'   => (int) ($row['laps_completed'] ?? 0),
    ];
    $rank++;
}

$tokenColors = ['#5EE6FF', '#FF6B81', '#3DDC97', '#FFC658', '#8B7CFF', '#FF9F6B', '#6BCBFF', '#E0AE7E'];

// ------------------------------------------------------------------
// Layout math — map each node index (0-49) to an (x, y) position inside
// the SVG viewBox, laid out as columns (layers) of evenly-spaced circles.
// Because the SVG scales via viewBox + width:100%, this stays crisp and
// fits inside the page at any screen size without extra media queries.
// ------------------------------------------------------------------
$svgW = 1300;
$svgH = 640;
$plotLeft   = 150;
$plotRight  = 1150;
$plotTop    = 150;
$plotBottom = 610;
$numLayers  = count($layerCounts);

// Node numbering walks each column top-to-bottom before moving to the
// next column — with every column going the SAME direction, the path a
// token actually travels (0, 1, 2, 3...) jumps from the bottom of one
// column straight back up to the top of the next, over and over
// (down, down, down, then a big jump up; repeat). Flipping every other
// column's vertical order turns that into a proper serpentine/snake
// path — like a Snakes & Ladders board — so node N and node N+1 are
// always physically next to each other on screen, down-up-down-up
// smoothly instead of down-down-down-JUMP.
$layerNodeIndices = [];
$coords = [];
$nodeIndex = 0;
foreach ($layerCounts as $li => $count) {
    $x = $numLayers === 1
        ? ($plotLeft + $plotRight) / 2
        : $plotLeft + $li * ($plotRight - $plotLeft) / ($numLayers - 1);

    $reversed = ($li % 2 === 1); // odd columns run bottom-to-top

    $layerNodeIndices[$li] = [];
    for ($j = 0; $j < $count; $j++) {
        $visualJ = $reversed ? ($count - 1 - $j) : $j;
        $y = $count === 1
            ? ($plotTop + $plotBottom) / 2
            : $plotTop + $visualJ * ($plotBottom - $plotTop) / ($count - 1);

        $coords[$nodeIndex] = ['x' => $x, 'y' => $y];
        $layerNodeIndices[$li][] = $nodeIndex;
        $nodeIndex++;
    }
}

function svgEsc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES);
}

// ------------------------------------------------------------------
// Build the SVG markup: synapse links, layer labels, then nodes on top.
// ------------------------------------------------------------------
$svg = '<svg class="nn-svg" viewBox="0 0 ' . $svgW . ' ' . $svgH . '" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">';

// Build a "banded" set of links between two layers: each node only
// connects to the handful of nodes roughly at its own relative height
// in the next layer, instead of every single node (a full mesh gets
// very messy once you have 50 nodes). A fallback pass guarantees every
// node still gets at least one connection either way.
function buildLayerLinks(array $indicesA, array $indicesB, array $coords, float $plotTop, float $plotBottom): array {
    $spanY = ($plotBottom - $plotTop) ?: 1;
    $relA = [];
    $relB = [];
    foreach ($indicesA as $i) { $relA[$i] = ($coords[$i]['y'] - $plotTop) / $spanY; }
    foreach ($indicesB as $j) { $relB[$j] = ($coords[$j]['y'] - $plotTop) / $spanY; }

    $bandWidth = 0.18; // how close (0-1 relative height) two nodes must be to link
    $links = [];
    $used  = [];
    foreach ($indicesA as $i) {
        foreach ($indicesB as $j) {
            if (abs($relA[$i] - $relB[$j]) <= $bandWidth) {
                $links[] = [$i, $j];
                $used[$i] = true;
                $used[$j] = true;
            }
        }
    }

    // Fallback: connect any node that ended up with zero links to its
    // single nearest node in the adjacent layer, so nothing looks orphaned.
    foreach ($indicesA as $i) {
        if (empty($used[$i])) {
            $bestJ = null; $bestDiff = INF;
            foreach ($indicesB as $j) {
                $diff = abs($relA[$i] - $relB[$j]);
                if ($diff < $bestDiff) { $bestDiff = $diff; $bestJ = $j; }
            }
            if ($bestJ !== null) { $links[] = [$i, $bestJ]; $used[$i] = true; $used[$bestJ] = true; }
        }
    }
    foreach ($indicesB as $j) {
        if (empty($used[$j])) {
            $bestI = null; $bestDiff = INF;
            foreach ($indicesA as $i) {
                $diff = abs($relA[$i] - $relB[$j]);
                if ($diff < $bestDiff) { $bestDiff = $diff; $bestI = $i; }
            }
            if ($bestI !== null) { $links[] = [$bestI, $j]; $used[$bestI] = true; $used[$j] = true; }
        }
    }

    return $links;
}

// Node circles are drawn with this radius further down — links are
// trimmed to stop right at this edge (see trimmedLinkPoints below) so
// they visually plug into the node instead of running through its
// number/fill.
$nodeRadius = 15;
$linkGap    = 2; // small gap so the line doesn't touch the stroke border

function trimmedLinkPoints(float $xa, float $ya, float $xb, float $yb, float $trim): array {
    $dx = $xb - $xa;
    $dy = $yb - $ya;
    $dist = sqrt($dx * $dx + $dy * $dy);
    if ($dist <= $trim * 2) {
        // Nodes are too close to trim both ends without crossing — just
        // draw the raw segment rather than collapsing it to nothing.
        return [$xa, $ya, $xb, $yb];
    }
    $ux = $dx / $dist;
    $uy = $dy / $dist;
    return [
        $xa + $ux * $trim, $ya + $uy * $trim,
        $xb - $ux * $trim, $yb - $uy * $trim,
    ];
}

for ($li = 0; $li < $numLayers - 1; $li++) {
    $layerLinks = buildLayerLinks($layerNodeIndices[$li], $layerNodeIndices[$li + 1], $coords, $plotTop, $plotBottom);
    foreach ($layerLinks as $pair) {
        [$a, $b] = $pair;
        [$x1, $y1, $x2, $y2] = trimmedLinkPoints(
            $coords[$a]['x'], $coords[$a]['y'],
            $coords[$b]['x'], $coords[$b]['y'],
            $nodeRadius + $linkGap
        );
        $svg .= '<line class="nn-link" x1="' . $x1 . '" y1="' . $y1
              . '" x2="' . $x2 . '" y2="' . $y2 . '"></line>';
    }
}

// "Input Layer" / "Output Layer" labels above their columns
$inputX  = $coords[$layerNodeIndices[0][0]]['x'];
$outputX = $coords[$layerNodeIndices[$numLayers - 1][0]]['x'];
$svg .= '<text class="nn-layer-label" x="' . $inputX  . '" y="' . ($plotTop - 40) . '">Input Layer</text>';
$svg .= '<text class="nn-layer-label" x="' . $outputX . '" y="' . ($plotTop - 40) . '">Output Layer</text>';

// "Hidden Layers" bracket spanning the hidden columns (layers 1..n-2)
if ($numLayers > 2) {
    $hiddenLeftX  = $coords[$layerNodeIndices[1][0]]['x'];
    $hiddenRightX = $coords[$layerNodeIndices[$numLayers - 2][0]]['x'];
    $bracketY     = $plotTop - 55;
    $svg .= '<path class="nn-bracket" d="M ' . $hiddenLeftX . ' ' . ($bracketY + 10)
          . ' L ' . $hiddenLeftX . ' ' . $bracketY
          . ' L ' . $hiddenRightX . ' ' . $bracketY
          . ' L ' . $hiddenRightX . ' ' . ($bracketY + 10) . '"></path>';
    $svg .= '<text class="nn-layer-label" x="' . (($hiddenLeftX + $hiddenRightX) / 2) . '" y="' . ($bracketY - 12) . '">Hidden Layers</text>';
}

// Nodes (drawn after links so they sit visually on top)
foreach ($coords as $idx => $pos) {
    $occupants    = $tileOccupants[$idx] ?? [];
    $hasOccupants = !empty($occupants);
    $hasWinner    = false;
    foreach ($occupants as $o) {
        if ($o['winner']) { $hasWinner = true; break; }
    }

    $isPower = isPowerNode($idx, $gameSessionId);

    $class = 'nn-node';
    if ($idx === 0) $class .= ' nn-node-start';
    if ($isPower) $class .= ' nn-node-power';
    if ($hasOccupants) $class .= ' nn-node-active';
    if ($hasWinner) $class .= ' nn-node-winner';

    $names = array_map(function ($o) {
        return $o['name'] . ' (#' . $o['rank'] . ')';
    }, $occupants);

    $tooltip = svgEsc($nodeLabels[$idx] ?? ('Node ' . $idx)) . ' · Node ' . $idx
             . ($names ? ' — ' . svgEsc(implode(', ', $names)) : '');

    $svg .= '<g>';
    $svg .= '<circle class="' . $class . '" cx="' . $pos['x'] . '" cy="' . $pos['y'] . '" r="' . $nodeRadius . '">';
    $svg .= '<title>' . $tooltip . '</title>';
    $svg .= '</circle>';
    $nodeGlyph = $isPower ? '⚡' : (string) $idx;
    $svg .= '<text class="nn-node-idx' . ($isPower ? ' nn-node-idx-power' : '') . '" x="' . $pos['x'] . '" y="' . ($pos['y'] + 3.5) . '">' . $nodeGlyph . '</text>';

    if ($hasOccupants) {
        $first      = $occupants[0];
        $badgeColor = $tokenColors[($first['rank'] - 1) % count($tokenColors)];
        $badgeLabel = $hasWinner ? '★' : (count($occupants) > 1 ? '+' . count($occupants) : (string) $first['rank']);
        $bx = $pos['x'] + 12;
        $by = $pos['y'] - 12;
        $svg .= '<circle class="nn-badge-circle" cx="' . $bx . '" cy="' . $by . '" r="9" fill="' . $badgeColor . '"></circle>';
        $svg .= '<text class="nn-badge-text" x="' . $bx . '" y="' . ($by + 3) . '">' . svgEsc($badgeLabel) . '</text>';
    }

    $svg .= '</g>';
}

$svg .= '</svg>';
?>
<div class="nn-board-wrap">
    <?php echo $svg; ?>
    <p class="nn-board-caption">Hover any node to see its topic · ⚡ nodes hand out a power card · First to <?php echo WINNING_SCORE; ?> pts wins</p>
</div>

<?php if (!empty($leaderboard)): ?>
<div class="board-legend">
    <?php $rank = 1; foreach ($leaderboard as $row):
        $color = $tokenColors[($rank - 1) % count($tokenColors)];
        $laps  = (int) ($row['laps_completed'] ?? 0);
    ?>
        <div class="legend-item">
            <span class="legend-dot" style="background: <?php echo $color; ?>;"><?php echo $rank; ?></span>
            <span><?php echo htmlspecialchars($row['student_name']); ?><?php echo $laps > 0 ? ' (Epoch ' . ($laps + 1) . ')' : ''; ?> — Node <?php echo (int) $row['current_tile']; ?></span>
        </div>
    <?php $rank++; endforeach; ?>
</div>
<?php endif; ?>