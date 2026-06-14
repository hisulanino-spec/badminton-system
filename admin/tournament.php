<?php
$pageTitle = "Manage Bracket";
$basePath = "../";
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

// Auth Protection
if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

$tournamentId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$db = getDBConnection();

// Handle Delete Tournament
if (isset($_POST['delete_tournament'])) {
    $delId = intval($_POST['tournament_id'] ?? 0);
    if ($delId > 0) {
        try {
            $stmt = $db->prepare("DELETE FROM tournaments WHERE id = ?");
            $stmt->execute([$delId]);
        } catch (\Exception $e) {
            // Ignore errors, redirect anyway
        }
    }
    header("Location: index.php");
    exit;
}


// Fetch tournament details
$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch();

if (!$tournament) {
    echo "<div class='container'><div class='alert alert-danger'>Tournament not found.</div></div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch all matches for the tournament
$stmtMatches = $db->prepare("SELECT * FROM matches WHERE tournament_id = ? ORDER BY round ASC, match_number ASC");
$stmtMatches->execute([$tournamentId]);
$allMatches = $stmtMatches->fetchAll();

// Group matches by bracket type and round
$winnerRounds = [];
$loserRounds = [];
$grandFinals = [];

foreach ($allMatches as $m) {
    if ($m['bracket_type'] === 'winner') {
        $winnerRounds[$m['round']][$m['match_number']] = $m;
    } else if ($m['bracket_type'] === 'loser') {
        $loserRounds[$m['round']][$m['match_number']] = $m;
    } else {
        $grandFinals[$m['bracket_type']] = $m; // 'grand_final' or 'grand_final_reset'
    }
}

// Fetch all set scores
$stmtSets = $db->prepare("
    SELECT ms.* 
    FROM match_sets ms
    JOIN matches m ON ms.match_id = m.id
    WHERE m.tournament_id = ?
");
$stmtSets->execute([$tournamentId]);
$setsList = $stmtSets->fetchAll();

$matchSets = [];
foreach ($setsList as $s) {
    $matchSets[$s['match_id']][$s['set_number']] = [
        'p1' => $s['player1_score'],
        'p2' => $s['player2_score']
    ];
}

// Fetch all tournament players/seeds
$stmtTP = $db->prepare("
    SELECT p.name, tp.seed, p.id
    FROM players p
    JOIN tournament_players tp ON p.id = tp.player_id
    WHERE tp.tournament_id = ?
    ORDER BY tp.seed ASC
");
$stmtTP->execute([$tournamentId]);
$tournamentPlayers = $stmtTP->fetchAll();

$playerNamesMap = [];
foreach ($tournamentPlayers as $tp) {
    $playerNamesMap[$tp['id']] = $tp['name'];
}

// Determine maximum rounds in WB and LB
$maxRoundWB = empty($winnerRounds) ? 0 : max(array_keys($winnerRounds));
$maxRoundLB = empty($loserRounds)  ? 0 : max(array_keys($loserRounds));

// Round robin grouping and standings
$rrRounds = []; $rrStandings = [];
if ($tournament['type'] === 'round_robin') {
    foreach ($allMatches as $m) { $rrRounds[$m['round']][] = $m; }
    ksort($rrRounds);
    $rrStandings = getRoundRobinStandings($db, $tournamentId);
} else if ($tournament['type'] === 'random_doubles' || $tournament['type'] === 'king_court') {
    foreach ($allMatches as $m) { $rrRounds[$m['round']][] = $m; }
    ksort($rrRounds);
    $rrStandings = getRandomDoublesStandings($db, $tournamentId);
}

$formatLabel = match($tournament['type']) {
    'single_elimination' => 'Single Elimination',
    'double_elimination' => 'Double Elimination',
    'round_robin'        => 'Round Robin',
    'random_doubles'     => 'Random Doubles (Social)',
    'king_court'         => 'King Court Doubles',
    default              => $tournament['type']
};

// Retrieve session feedback messages
$feedbackMessage = isset($_SESSION['feedback_message']) ? $_SESSION['feedback_message'] : "";
$feedbackType = isset($_SESSION['feedback_type']) ? $_SESSION['feedback_type'] : "";
unset($_SESSION['feedback_message'], $_SESSION['feedback_type']);
?>

<div class="container-fluid px-4 py-3">
    <!-- Breadcrumbs & Header -->
    <div class="row align-items-center mb-4">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="index.php" class="text-accent-cyan text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active text-white" aria-current="page">Manage Bracket</li>
                </ol>
            </nav>
            <h1 class="font-outfit fw-bold tracking-tight text-white m-0">
                <?php echo htmlspecialchars($tournament['name']); ?>
            </h1>
            <div class="d-flex align-items-center gap-2 mt-1">
                <span class="badge badge-status badge-status-<?php echo $tournament['status']; ?>">
                    <?php echo $tournament['status']; ?>
                </span>
                <span class="text-muted small">|</span>
                <span class="text-muted small">Format: <?php echo $formatLabel; ?></span>
                <span class="text-muted small">|</span>
                <span class="text-muted small">Players: <?php echo count($tournamentPlayers); ?></span>
            </div>
        </div>
        <div class="col-auto d-flex gap-2 align-items-center flex-wrap">
            <a href="../tournament.php?id=<?php echo $tournamentId; ?>" target="_blank" class="btn btn-outline-neon-cyan">
                <i class="fa-solid fa-eye me-1"></i> Spectator View
            </a>
            <form method="POST" action="tournament.php?id=<?php echo $tournamentId; ?>" class="d-inline mb-0">
                <input type="hidden" name="tournament_id" value="<?php echo $tournamentId; ?>">
                <input type="hidden" name="delete_tournament" value="1">
                <button type="submit" class="btn btn-outline-danger"
                        onclick="return confirm('Delete this tournament and all its matches?')">
                    <i class="fa-solid fa-trash me-1"></i> Delete Tournament
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($feedbackMessage)): ?>
        <div class="alert alert-<?php echo $feedbackType; ?> alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="fa-solid <?php echo $feedbackType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-2"></i>
            <?php echo htmlspecialchars($feedbackMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

<?php if ($tournament['type'] === 'round_robin' || $tournament['type'] === 'random_doubles' || $tournament['type'] === 'king_court'): ?>
    <!-- ============================================================
         ROUND ROBIN / SOCIAL DOUBLES ADMIN VIEW
    ============================================================ -->

    <!-- Standings Table -->
    <div class="card card-glass border-slate-800 mb-4">
        <div class="card-body">
            <h4 class="font-outfit fw-bold text-white mb-3">
                <i class="fa-solid fa-ranking-star me-2 text-warning"></i> Standings
            </h4>
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0" style="background:transparent;">
                    <thead>
                        <tr class="text-muted small" style="border-color:rgba(255,255,255,0.06);">
                            <th>#</th><th>Player</th>
                            <th class="text-center">Played</th>
                            <th class="text-center text-success">W</th>
                            <th class="text-center text-danger">L</th>
                            <th class="text-center text-accent-cyan">Sets W</th>
                            <th class="text-center">Sets L</th>
                            <th class="text-center">Pts W</th>
                            <th class="text-center">Pts L</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rrStandings)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-3">No results yet — score some matches!</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rrStandings as $rank => $s): ?>
                        <tr style="border-color:rgba(255,255,255,0.04);" <?php echo $rank===0?'class="table-warning text-dark fw-bold"':''; ?>>
                            <td><?php echo $rank===0?'🥇':($rank===1?'🥈':($rank===2?'🥉':$rank+1)); ?></td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($s['name']); ?></td>
                            <td class="text-center"><?php echo $s['played']; ?></td>
                            <td class="text-center text-success fw-bold"><?php echo $s['won']; ?></td>
                            <td class="text-center text-danger"><?php echo $s['lost']; ?></td>
                            <td class="text-center text-accent-cyan fw-bold"><?php echo $s['sets_won']; ?></td>
                            <td class="text-center text-muted"><?php echo $s['sets_lost']; ?></td>
                            <td class="text-center"><?php echo $s['pts_won']; ?></td>
                            <td class="text-center text-muted"><?php echo $s['pts_lost']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Round Cards with clickable matches -->
    <?php foreach ($rrRounds as $roundNum => $matches): ?>
        <?php
        $done  = count(array_filter($matches, fn($m) => $m['status']==='completed'));
        $total = count($matches);
        $open  = ($done < $total);
        ?>
        <div class="card card-glass border-slate-800 mb-3">
            <div class="card-header bg-transparent border-slate-800 d-flex justify-content-between align-items-center"
                 style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#rrRound<?php echo $roundNum; ?>">
                <div class="d-flex align-items-center gap-3">
                    <span class="font-outfit fw-bold text-white">Round <?php echo $roundNum; ?></span>
                    <span class="badge bg-secondary small"><?php echo $done; ?>/<?php echo $total; ?> done</span>
                    <?php if ($done===$total&&$total>0): ?>
                        <span class="badge badge-status badge-status-completed">Completed</span>
                    <?php elseif ($done>0): ?>
                        <span class="badge badge-status badge-status-live">In Progress</span>
                    <?php else: ?>
                        <span class="badge badge-status badge-status-ready">Upcoming</span>
                    <?php endif; ?>
                </div>
                <i class="fa-solid fa-chevron-<?php echo $open?'up':'down'; ?> text-muted small"></i>
            </div>
            <div class="collapse <?php echo $open?'show':''; ?>" id="rrRound<?php echo $roundNum; ?>">
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($matches as $m): ?>
                            <div class="col-md-6 col-lg-4">
                                <?php renderMatchCard($m, $playerNamesMap, $matchSets); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    <!-- ============================================================
         ELIMINATION BRACKET VIEW
    ============================================================ -->
    <?php if ($tournament['type'] === 'double_elimination'): ?>
        <ul class="nav nav-tabs mb-4 border-slate-800" id="bracketTabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#winner-pane" type="button"><i class="fa-solid fa-medal me-1"></i> Winner's Bracket</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#loser-pane" type="button"><i class="fa-solid fa-skull-crossbones me-1"></i> Loser's Bracket</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#finals-pane" type="button"><i class="fa-solid fa-trophy me-1 text-warning"></i> Grand Finals</button></li>
        </ul>
    <?php endif; ?>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="winner-pane" role="tabpanel">
            <div class="card card-glass border-slate-800"><div class="card-body p-0">
                <div class="bracket-viewport">
                    <?php for ($r = 1; $r <= $maxRoundWB; $r++): ?>
                        <div class="round-column">
                            <div class="round-title-container">
                                <div class="round-title"><?php echo $r==$maxRoundWB?'WB Finals':($r==$maxRoundWB-1&&$maxRoundWB>2?'WB Semis':"Round $r"); ?></div>
                                <div class="round-subtitle"><?php echo count($winnerRounds[$r]); ?> Matches</div>
                            </div>
                            <?php foreach ($winnerRounds[$r] as $match): renderMatchCard($match, $playerNamesMap, $matchSets); endforeach; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div></div>
        </div>
        <?php if ($tournament['type'] === 'double_elimination'): ?>
            <div class="tab-pane fade" id="loser-pane" role="tabpanel">
                <div class="card card-glass border-slate-800"><div class="card-body p-0">
                    <div class="bracket-viewport">
                        <?php for ($r = 1; $r <= $maxRoundLB; $r++): ?>
                            <div class="round-column">
                                <div class="round-title-container">
                                    <div class="round-title"><?php echo $r==$maxRoundLB?'LB Finals':($r==$maxRoundLB-1?'LB Semis':"LB Round $r"); ?></div>
                                    <div class="round-subtitle"><?php echo count($loserRounds[$r]); ?> Matches</div>
                                </div>
                                <?php foreach ($loserRounds[$r] as $match): renderMatchCard($match, $playerNamesMap, $matchSets); endforeach; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div></div>
            </div>
            <div class="tab-pane fade" id="finals-pane" role="tabpanel">
                <div class="row justify-content-center py-4"><div class="col-md-5">
                    <div class="card card-glass border-slate-800 p-3"><div class="card-body">
                        <h3 class="font-outfit fw-bold text-center text-accent-cyan mb-4"><i class="fa-solid fa-crown text-warning me-2"></i>Grand Championship</h3>
                        <?php if (isset($grandFinals['grand_final'])): ?>
                            <div class="text-center w-100 mb-3">
                                <h5 class="text-muted small font-outfit fw-bold mb-2">Grand Final Match 1</h5>
                                <?php renderMatchCard($grandFinals['grand_final'], $playerNamesMap, $matchSets); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($grandFinals['grand_final_reset'])): ?>
                            <?php $rm=$grandFinals['grand_final_reset']; $hidden=$rm['status']==='pending'; ?>
                            <div class="text-center w-100 <?php echo $hidden?'d-none':''; ?>">
                                <h5 class="text-accent-violet small font-outfit fw-bold mb-2">Bracket Reset</h5>
                                <?php renderMatchCard($rm, $playerNamesMap, $matchSets); ?>
                            </div>
                        <?php endif; ?>
                    </div></div>
                </div></div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<!-- Score Editor Modal -->
<div class="modal fade" id="scoreEditorModal" tabindex="-1" aria-labelledby="scoreEditorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-slate-800 text-white border border-slate-700 rounded-3 shadow-lg">
            <div class="modal-header border-slate-700">
                <h5 class="modal-title font-outfit fw-bold d-flex align-items-center gap-2" id="scoreEditorModalLabel">
                    <i class="fa-solid fa-pen-to-square text-accent-cyan"></i> Update Live Score
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="scoreEditorForm" method="POST" action="score_submit.php">
                <input type="hidden" name="match_id" id="modal_match_id">
                <input type="hidden" name="tournament_id" value="<?php echo $tournamentId; ?>">
                
                <div class="modal-body">
                    <!-- Player Header Labels -->
                    <div class="row mb-3 font-outfit text-center fw-bold">
                        <div class="col-5 text-truncate" id="modal_p1_label" style="font-size: 0.95rem; color: #93c5fd;">Player 1</div>
                        <div class="col-2 text-muted">VS</div>
                        <div class="col-5 text-truncate" id="modal_p2_label" style="font-size: 0.95rem; color: #a7f3d0;">Player 2</div>
                    </div>
                    
                    <hr class="border-slate-700 mb-4">
                    
                    <!-- Scoring warning div -->
                    <div id="scoreWarning" class="alert alert-danger border-0 small py-2 px-3 mb-4 d-none" role="alert"></div>

                    <!-- Set 1 -->
                    <div class="row align-items-center mb-3 p-2 rounded" id="set1_row">
                        <div class="col-4 text-muted small fw-semibold">SET 1</div>
                        <div class="col-3 text-center">
                            <input type="number" name="set1_p1" id="set1_p1" min="0" max="40" class="score-input">
                        </div>
                        <div class="col-2 text-center text-muted small">&mdash;</div>
                        <div class="col-3 text-center">
                            <input type="number" name="set1_p2" id="set1_p2" min="0" max="40" class="score-input">
                        </div>
                        <div class="col-12 text-center">
                            <span class="small mt-1" id="set1_status" style="font-size: 0.75rem;"></span>
                        </div>
                    </div>

                    <!-- Set 2 -->
                    <div class="row align-items-center mb-3 p-2 rounded d-none" id="set2_row">
                        <div class="col-4 text-muted small fw-semibold">SET 2</div>
                        <div class="col-3 text-center">
                            <input type="number" name="set2_p1" id="set2_p1" min="0" max="40" class="score-input">
                        </div>
                        <div class="col-2 text-center text-muted small">&mdash;</div>
                        <div class="col-3 text-center">
                            <input type="number" name="set2_p2" id="set2_p2" min="0" max="40" class="score-input">
                        </div>
                        <div class="col-12 text-center">
                            <span class="small mt-1" id="set2_status" style="font-size: 0.75rem;"></span>
                        </div>
                    </div>

                    <!-- Set 3 -->
                    <div class="row align-items-center mb-3 p-2 rounded d-none" id="set3_row">
                        <div class="col-4 text-muted small fw-semibold">SET 3 (Decider)</div>
                        <div class="col-3 text-center">
                            <input type="number" name="set3_p1" id="set3_p1" min="0" max="40" class="score-input">
                        </div>
                        <div class="col-2 text-center text-muted small">&mdash;</div>
                        <div class="col-3 text-center">
                            <input type="number" name="set3_p2" id="set3_p2" min="0" max="40" class="score-input">
                        </div>
                        <div class="col-12 text-center">
                            <span class="small mt-1" id="set3_status" style="font-size: 0.75rem;"></span>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer border-slate-700 bg-slate-900 bg-opacity-30">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="submitScoreBtn" class="btn btn-primary-neon btn-sm px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Scores
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Script to pass data to modal on trigger
document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('scoreEditorModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            // Button that triggered the modal
            const card = event.relatedTarget;
            
            // Extract info from data-* attributes
            const matchId = card.getAttribute('data-match-id');
            const p1Name = card.getAttribute('data-p1-name') || "BYE";
            const p2Name = card.getAttribute('data-p2-name') || "BYE";
            
            const set1_p1 = card.getAttribute('data-set1-p1') || "";
            const set1_p2 = card.getAttribute('data-set1-p2') || "";
            const set2_p1 = card.getAttribute('data-set2-p1') || "";
            const set2_p2 = card.getAttribute('data-set2-p2') || "";
            const set3_p1 = card.getAttribute('data-set3-p1') || "";
            const set3_p2 = card.getAttribute('data-set3-p2') || "";
            
            // Update modal fields
            document.getElementById('modal_match_id').value = matchId;
            document.getElementById('modal_p1_label').innerText = p1Name;
            document.getElementById('modal_p2_label').innerText = p2Name;
            
            document.getElementById('set1_p1').value = set1_p1;
            document.getElementById('set1_p2').value = set1_p2;
            document.getElementById('set2_p1').value = set2_p1;
            document.getElementById('set2_p2').value = set2_p2;
            document.getElementById('set3_p1').value = set3_p1;
            document.getElementById('set3_p2').value = set3_p2;
            
            // Re-run JS live validations inside main.js helper
            if (typeof setupScoreValidation === 'function') {
                setupScoreValidation();
            }
        });
    }
});
</script>

<?php
/**
 * Renders a single match card in the bracket.
 */
function renderMatchCard($match, $playerNamesMap, $matchSets) {
    $matchId = $match['id'];
    $p1Name = isset($playerNamesMap[$match['player1_id']]) ? $playerNamesMap[$match['player1_id']] : "BYE";
    if ($match['player1b_id'] !== null) {
        $p1Name .= ' & ' . ($playerNamesMap[$match['player1b_id']] ?? 'BYE');
    }
    $p2Name = isset($playerNamesMap[$match['player2_id']]) ? $playerNamesMap[$match['player2_id']] : "BYE";
    if ($match['player2b_id'] !== null) {
        $p2Name .= ' & ' . ($playerNamesMap[$match['player2b_id']] ?? 'BYE');
    }
    
    // Check if match status allows scoring (must have both players and not be completed already)
    $isClickable = ($match['player1_id'] !== null && $match['player2_id'] !== null && $match['status'] !== 'completed');
    
    // Get saved set scores
    $sets = isset($matchSets[$matchId]) ? $matchSets[$matchId] : [];
    
    $set1_p1 = isset($sets[1]['p1']) ? $sets[1]['p1'] : "";
    $set1_p2 = isset($sets[1]['p2']) ? $sets[1]['p2'] : "";
    $set2_p1 = isset($sets[2]['p1']) ? $sets[2]['p1'] : "";
    $set2_p2 = isset($sets[2]['p2']) ? $sets[2]['p2'] : "";
    $set3_p1 = isset($sets[3]['p1']) ? $sets[3]['p1'] : "";
    $set3_p2 = isset($sets[3]['p2']) ? $sets[3]['p2'] : "";
    
    // Determine winner/loser layout
    $p1WinnerClass = ($match['status'] === 'completed' && $match['winner_id'] == $match['player1_id']) ? 'winner' : '';
    $p2WinnerClass = ($match['status'] === 'completed' && $match['winner_id'] == $match['player2_id']) ? 'winner' : '';
    
    if ($match['status'] === 'completed') {
        if ($match['winner_id'] == $match['player1_id']) {
            $p2WinnerClass .= ' loser';
        } else if ($match['winner_id'] == $match['player2_id']) {
            $p1WinnerClass .= ' loser';
        }
    }
    
    $courtLabel = "Match #" . $match['match_number'];
    $tType = isset($GLOBALS['tournament']['type']) ? $GLOBALS['tournament']['type'] : '';
    if ($tType === 'king_court') {
        $db = getDBConnection();
        $stmtC = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round = ?");
        $stmtC->execute([$match['tournament_id'], $match['round']]);
        $totalMatches = intval($stmtC->fetchColumn() ?: 1);

        if ($totalMatches === 1) {
            $courtLabel = "Winner Court 🏆";
        } else if ($match['match_number'] === 1) {
            $courtLabel = "Winner Court 🏆";
        } else if ($match['match_number'] === $totalMatches) {
            $courtLabel = "Loser Court 💀";
        } else {
            $courtLabel = "Court " . $match['match_number'];
        }
    }
    ?>
    <div class="match-card-wrapper">
        <div class="match-card-main status-<?php echo $match['status']; ?> <?php echo $isClickable ? 'clickable' : ''; ?>"
             <?php if ($isClickable): ?>
                 data-bs-toggle="modal" 
                 data-bs-target="#scoreEditorModal"
                 data-match-id="<?php echo $matchId; ?>"
                 data-p1-name="<?php echo htmlspecialchars($p1Name); ?>"
                 data-p2-name="<?php echo htmlspecialchars($p2Name); ?>"
                 data-set1-p1="<?php echo $set1_p1; ?>"
                 data-set1-p2="<?php echo $set1_p2; ?>"
                 data-set2-p1="<?php echo $set2_p1; ?>"
                 data-set2-p2="<?php echo $set2_p2; ?>"
                 data-set3-p1="<?php echo $set3_p1; ?>"
                 data-set3-p2="<?php echo $set3_p2; ?>"
             <?php endif; ?>
        >
            <div class="match-info-bar">
                <span class="match-id fw-bold"><?php echo $courtLabel; ?></span>
                <span class="badge badge-status badge-status-<?php echo $match['status']; ?>">
                    <?php echo $match['status']; ?>
                </span>
            </div>
            
            <div class="match-player-row <?php echo $p1WinnerClass; ?>">
                <div class="text-truncate">
                    <?php if ($match['player1_id'] !== null && $match['player1b_id'] === null): ?>
                        <span class="player-seed">#<?php echo getPlayerSeed($match['tournament_id'], $match['player1_id']); ?></span>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($p1Name); ?>
                </div>
                <div class="player-score-container">
                    <span class="player-set-score <?php echo getSetWinnerClass($set1_p1, $set1_p2, 1); ?>"><?php echo $set1_p1 !== "" ? $set1_p1 : "&nbsp;"; ?></span>
                    <span class="player-set-score <?php echo getSetWinnerClass($set2_p1, $set2_p2, 1); ?>"><?php echo $set2_p1 !== "" ? $set2_p1 : "&nbsp;"; ?></span>
                    <span class="player-set-score <?php echo getSetWinnerClass($set3_p1, $set3_p2, 1); ?>"><?php echo $set3_p1 !== "" ? $set3_p1 : "&nbsp;"; ?></span>
                </div>
            </div>
            
            <div class="match-player-row <?php echo $p2WinnerClass; ?>">
                <div class="text-truncate">
                    <?php if ($match['player2_id'] !== null && $match['player2b_id'] === null): ?>
                        <span class="player-seed">#<?php echo getPlayerSeed($match['tournament_id'], $match['player2_id']); ?></span>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($p2Name); ?>
                </div>
                <div class="player-score-container">
                    <span class="player-set-score <?php echo getSetWinnerClass($set1_p1, $set1_p2, 2); ?>"><?php echo $set1_p2 !== "" ? $set1_p2 : "&nbsp;"; ?></span>
                    <span class="player-set-score <?php echo getSetWinnerClass($set2_p1, $set2_p2, 2); ?>"><?php echo $set2_p2 !== "" ? $set2_p2 : "&nbsp;"; ?></span>
                    <span class="player-set-score <?php echo getSetWinnerClass($set3_p1, $set3_p2, 2); ?>"><?php echo $set3_p2 !== "" ? $set3_p2 : "&nbsp;"; ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Returns the seed of a player in a tournament.
 */
function getPlayerSeed($tournamentId, $playerId) {
    static $seedsMap = [];
    $key = $tournamentId . "_" . $playerId;
    if (isset($seedsMap[$key])) return $seedsMap[$key];
    
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT seed FROM tournament_players WHERE tournament_id = ? AND player_id = ?");
    $stmt->execute([$tournamentId, $playerId]);
    $seed = $stmt->fetchColumn() ?: "";
    $seedsMap[$key] = $seed;
    return $seed;
}

/**
 * Highlights the winning set score.
 */
function getSetWinnerClass($p1, $p2, $playerNum) {
    if ($p1 === "" || $p2 === "") return "empty-set";
    $p1 = intval($p1);
    $p2 = intval($p2);
    
    if ($playerNum == 1 && $p1 > $p2) return "winner-set";
    if ($playerNum == 2 && $p2 > $p1) return "winner-set";
    return "";
}

require_once __DIR__ . '/../includes/footer.php';
?>
