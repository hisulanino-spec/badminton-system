<?php
$pageTitle = "Spectate Bracket";
$basePath = "";
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$tournamentId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$db = getDBConnection();

// Fetch tournament details
$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch();

if (!$tournament) {
    echo "<div class='container py-5'><div class='alert alert-danger'>Tournament not found.</div></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Fetch all matches
$stmtMatches = $db->prepare("SELECT * FROM matches WHERE tournament_id = ? ORDER BY round ASC, match_number ASC");
$stmtMatches->execute([$tournamentId]);
$allMatches = $stmtMatches->fetchAll();

// Fetch all set scores
$stmtSets = $db->prepare("SELECT ms.* FROM match_sets ms JOIN matches m ON ms.match_id = m.id WHERE m.tournament_id = ?");
$stmtSets->execute([$tournamentId]);
$matchSets = [];
foreach ($stmtSets->fetchAll() as $s) {
    $matchSets[$s['match_id']][$s['set_number']] = ['p1' => $s['player1_score'], 'p2' => $s['player2_score']];
}

// Fetch tournament players
$stmtTP = $db->prepare("SELECT p.name, tp.seed, p.id FROM players p JOIN tournament_players tp ON p.id = tp.player_id WHERE tp.tournament_id = ? ORDER BY tp.seed ASC");
$stmtTP->execute([$tournamentId]);
$tournamentPlayers = $stmtTP->fetchAll();
$playerNamesMap = [];
foreach ($tournamentPlayers as $tp) { $playerNamesMap[$tp['id']] = $tp['name']; }

// Group matches for elimination formats
$winnerRounds = []; $loserRounds = []; $grandFinals = [];
foreach ($allMatches as $m) {
    if ($m['bracket_type'] === 'winner')        $winnerRounds[$m['round']][$m['match_number']] = $m;
    elseif ($m['bracket_type'] === 'loser')     $loserRounds[$m['round']][$m['match_number']] = $m;
    else                                        $grandFinals[$m['bracket_type']] = $m;
}
$maxRoundWB = empty($winnerRounds) ? 0 : max(array_keys($winnerRounds));
$maxRoundLB = empty($loserRounds)  ? 0 : max(array_keys($loserRounds));

// Round robin grouping and standings
$rrRounds = []; $rrStandings = [];
if ($tournament['type'] === 'round_robin') {
    foreach ($allMatches as $m) { $rrRounds[$m['round']][] = $m; }
    ksort($rrRounds);
    $rrStandings = getRoundRobinStandings($db, $tournamentId);
} elseif ($tournament['type'] === 'random_doubles' || $tournament['type'] === 'king_court') {
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
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="row align-items-center mb-4 g-3">
        <div class="col-md">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="index.php" class="text-accent-cyan text-decoration-none">Home</a></li>
                    <li class="breadcrumb-item active text-white">View Tournament</li>
                </ol>
            </nav>
            <h1 class="font-outfit fw-bold tracking-tight text-white m-0"><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                <span class="badge badge-status badge-status-<?php echo $tournament['status']; ?>"><?php echo $tournament['status']; ?></span>
                <span class="text-muted small">| Format: <?php echo $formatLabel; ?> | Players: <?php echo count($tournamentPlayers); ?></span>
            </div>
        </div>
        <div class="col-md-auto d-flex align-items-center gap-3">
            <?php if ($tournament['status'] === 'active'): ?>
                <div class="form-check form-switch bg-slate-800 border border-slate-700 px-3 py-2 rounded d-flex align-items-center gap-2 shadow-sm">
                    <input class="form-check-input ms-0" type="checkbox" role="switch" id="autoRefreshSwitch" checked>
                    <label class="form-check-label text-muted small fw-semibold" for="autoRefreshSwitch" id="refreshLabel">Auto Refresh: ON (30s)</label>
                </div>
            <?php endif; ?>
            <?php if (isAdminLoggedIn()): ?>
                <a href="admin/tournament.php?id=<?php echo $tournamentId; ?>" class="btn btn-primary-neon">
                    <i class="fa-solid fa-gears me-1"></i> Admin Manager
                </a>
            <?php endif; ?>
        </div>
    </div>

<?php if ($tournament['type'] === 'round_robin' || $tournament['type'] === 'random_doubles' || $tournament['type'] === 'king_court'): ?>
    <!-- ============================================================
         ROUND ROBIN / SOCIAL DOUBLES VIEW
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
                            <tr><td colspan="9" class="text-center text-muted py-3">No results yet.</td></tr>
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

    <!-- Round Cards -->
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
                    <?php if ($done===$total && $total>0): ?>
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
                            <?php
                            $p1n=$playerNamesMap[$m['player1_id']]??'BYE';
                            if ($m['player1b_id'] !== null) {
                                $p1n .= ' & ' . ($playerNamesMap[$m['player1b_id']] ?? 'BYE');
                            }
                            $p2n=$playerNamesMap[$m['player2_id']]??'BYE';
                            if ($m['player2b_id'] !== null) {
                                $p2n .= ' & ' . ($playerNamesMap[$m['player2b_id']] ?? 'BYE');
                            }
                            $sets=$matchSets[$m['id']]??[];
                            $s1p1=$sets[1]['p1']??''; $s1p2=$sets[1]['p2']??'';
                            $s2p1=$sets[2]['p1']??''; $s2p2=$sets[2]['p2']??'';
                            $s3p1=$sets[3]['p1']??''; $s3p2=$sets[3]['p2']??'';
                            $p1w=($m['status']==='completed'&&$m['winner_id']==$m['player1_id']);
                            $p2w=($m['status']==='completed'&&$m['winner_id']==$m['player2_id']);
                             $done2 = ($m['status'] === 'completed');
                             $courtLabel = "Match #" . $m['match_number'];
                             if ($tournament['type'] === 'king_court') {
                                if (count($matches) === 1) {
                                    $courtLabel = "Winner Court 🏆";
                                } else if ($m['match_number'] === 1) {
                                    $courtLabel = "Winner Court 🏆";
                                } else if ($m['match_number'] === count($matches)) {
                                    $courtLabel = "Loser Court 💀";
                                } else {
                                    $courtLabel = "Court " . $m['match_number'];
                                }
                            }
                            ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="match-card-main status-<?php echo $m['status']; ?> h-100">
                                    <div class="match-info-bar">
                                        <span class="fw-bold small"><?php echo $courtLabel; ?></span>
                                        <span class="badge badge-status badge-status-<?php echo $m['status']; ?>"><?php echo $m['status']; ?></span>
                                    </div>
                                    <div class="match-player-row <?php echo $p1w?'winner':($done2?'loser':''); ?>">
                                        <div class="text-truncate fw-semibold"><?php echo htmlspecialchars($p1n); ?></div>
                                        <div class="player-score-container">
                                            <span class="player-set-score <?php echo ($s1p1!==''&&$s1p1>$s1p2)?'winner-set':''; ?>"><?php echo $s1p1!==''?$s1p1:'&nbsp;'; ?></span>
                                            <span class="player-set-score <?php echo ($s2p1!==''&&$s2p1>$s2p2)?'winner-set':''; ?>"><?php echo $s2p1!==''?$s2p1:'&nbsp;'; ?></span>
                                            <span class="player-set-score <?php echo ($s3p1!==''&&$s3p1>$s3p2)?'winner-set':''; ?>"><?php echo $s3p1!==''?$s3p1:'&nbsp;'; ?></span>
                                        </div>
                                    </div>
                                    <div class="match-player-row <?php echo $p2w?'winner':($done2?'loser':''); ?>">
                                        <div class="text-truncate fw-semibold"><?php echo htmlspecialchars($p2n); ?></div>
                                        <div class="player-score-container">
                                            <span class="player-set-score <?php echo ($s1p2!==''&&$s1p2>$s1p1)?'winner-set':''; ?>"><?php echo $s1p2!==''?$s1p2:'&nbsp;'; ?></span>
                                            <span class="player-set-score <?php echo ($s2p2!==''&&$s2p2>$s2p1)?'winner-set':''; ?>"><?php echo $s2p2!==''?$s2p2:'&nbsp;'; ?></span>
                                            <span class="player-set-score <?php echo ($s3p2!==''&&$s3p2>$s3p1)?'winner-set':''; ?>"><?php echo $s3p2!==''?$s3p2:'&nbsp;'; ?></span>
                                        </div>
                                    </div>
                                </div>
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
                                <div class="round-title"><?php echo $r==$maxRoundWB?'Finals':($r==$maxRoundWB-1&&$maxRoundWB>2?'Semifinals':"Round $r"); ?></div>
                                <div class="round-subtitle"><?php echo count($winnerRounds[$r]); ?> Matches</div>
                            </div>
                            <?php foreach ($winnerRounds[$r] as $match): renderSpectatorMatchCard($match, $playerNamesMap, $matchSets); endforeach; ?>
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
                                    <div class="round-title"><?php echo $r==$maxRoundLB?'LB Finals':($r==$maxRoundLB-1?'LB Semifinals':"LB Round $r"); ?></div>
                                    <div class="round-subtitle"><?php echo count($loserRounds[$r]); ?> Matches</div>
                                </div>
                                <?php foreach ($loserRounds[$r] as $match): renderSpectatorMatchCard($match, $playerNamesMap, $matchSets); endforeach; ?>
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
                                <?php renderSpectatorMatchCard($grandFinals['grand_final'], $playerNamesMap, $matchSets); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($grandFinals['grand_final_reset'])): ?>
                            <?php $rm=$grandFinals['grand_final_reset']; ?>
                            <div class="text-center w-100 <?php echo $rm['status']==='pending'?'d-none':''; ?>">
                                <h5 class="text-accent-violet small font-outfit fw-bold mb-2">Bracket Reset</h5>
                                <?php renderSpectatorMatchCard($rm, $playerNamesMap, $matchSets); ?>
                            </div>
                        <?php endif; ?>
                    </div></div>
                </div></div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sw = document.getElementById('autoRefreshSwitch');
    const lb = document.getElementById('refreshLabel');
    let iv = null;
    if (sw) {
        const on = localStorage.getItem('auto_refresh') !== 'false';
        sw.checked = on; toggle(on);
        sw.addEventListener('change', function() { localStorage.setItem('auto_refresh', this.checked); toggle(this.checked); });
    }
    function toggle(on) {
        if (!lb) return;
        if (on) { lb.textContent='Auto Refresh: ON (30s)'; iv=iv||setInterval(()=>location.reload(),30000); }
        else { lb.textContent='Auto Refresh: OFF'; clearInterval(iv); iv=null; }
    }
});
</script>

<?php
function renderSpectatorMatchCard($match, $playerNamesMap, $matchSets) {
    $p1=$playerNamesMap[$match['player1_id']]??'BYE';
    $p2=$playerNamesMap[$match['player2_id']]??'BYE';
    $sets=$matchSets[$match['id']]??[];
    $s1p1=$sets[1]['p1']??''; $s1p2=$sets[1]['p2']??'';
    $s2p1=$sets[2]['p1']??''; $s2p2=$sets[2]['p2']??'';
    $s3p1=$sets[3]['p1']??''; $s3p2=$sets[3]['p2']??'';
    $p1w=($match['status']==='completed'&&$match['winner_id']==$match['player1_id']);
    $p2w=($match['status']==='completed'&&$match['winner_id']==$match['player2_id']);
    $done=($match['status']==='completed');
    ?>
    <div class="match-card-wrapper">
        <div class="match-card-main status-<?php echo $match['status']; ?>">
            <div class="match-info-bar">
                <span class="fw-bold">Match #<?php echo $match['match_number']; ?></span>
                <span class="badge badge-status badge-status-<?php echo $match['status']; ?>"><?php echo $match['status']; ?></span>
            </div>
            <div class="match-player-row <?php echo $p1w?'winner':($done?'loser':''); ?>">
                <div class="text-truncate"><?php echo htmlspecialchars($p1); ?></div>
                <div class="player-score-container">
                    <span class="player-set-score <?php echo ($s1p1!==''&&$s1p1>$s1p2)?'winner-set':''; ?>"><?php echo $s1p1!==''?$s1p1:'&nbsp;'; ?></span>
                    <span class="player-set-score <?php echo ($s2p1!==''&&$s2p1>$s2p2)?'winner-set':''; ?>"><?php echo $s2p1!==''?$s2p1:'&nbsp;'; ?></span>
                    <span class="player-set-score <?php echo ($s3p1!==''&&$s3p1>$s3p2)?'winner-set':''; ?>"><?php echo $s3p1!==''?$s3p1:'&nbsp;'; ?></span>
                </div>
            </div>
            <div class="match-player-row <?php echo $p2w?'winner':($done?'loser':''); ?>">
                <div class="text-truncate"><?php echo htmlspecialchars($p2); ?></div>
                <div class="player-score-container">
                    <span class="player-set-score <?php echo ($s1p2!==''&&$s1p2>$s1p1)?'winner-set':''; ?>"><?php echo $s1p2!==''?$s1p2:'&nbsp;'; ?></span>
                    <span class="player-set-score <?php echo ($s2p2!==''&&$s2p2>$s2p1)?'winner-set':''; ?>"><?php echo $s2p2!==''?$s2p2:'&nbsp;'; ?></span>
                    <span class="player-set-score <?php echo ($s3p2!==''&&$s3p2>$s3p1)?'winner-set':''; ?>"><?php echo $s3p2!==''?$s3p2:'&nbsp;'; ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php
}
require_once __DIR__ . '/includes/footer.php';
?>
