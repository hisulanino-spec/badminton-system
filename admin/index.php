<?php
$pageTitle = "Admin Dashboard";
$basePath = "../";
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

// Auth Protection
if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDBConnection();
$message = "";
$messageType = "";

// 1. Handle Add Player Form Submit
if (isset($_POST['add_player'])) {
    $pname = trim($_POST['player_name']);
    if (!empty($pname)) {
        try {
            $stmt = $db->prepare("INSERT INTO players (name) VALUES (?)");
            $stmt->execute([$pname]);
            $message = "Player successfully added to database!";
            $messageType = "success";
        } catch (\Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = "Player name cannot be empty.";
        $messageType = "warning";
    }
}

// 2. Handle Create Tournament Form Submit
if (isset($_POST['create_tournament'])) {
    $tname = trim($_POST['tournament_name']);
    $ttype = $_POST['tournament_type'];
    $selectedPlayers = isset($_POST['selected_players']) ? $_POST['selected_players'] : [];
    $numRounds = intval($_POST['num_rounds'] ?? 5);
    if ($numRounds < 1) $numRounds = 5;
    
    if (empty($tname)) {
        $message = "Tournament name cannot be empty.";
        $messageType = "warning";
    } else if (($ttype === 'random_doubles' || $ttype === 'king_court') && count($selectedPlayers) < 4) {
        $message = "Please select at least 4 players for a Random Doubles or King Court tournament.";
        $messageType = "warning";
    } else if (count($selectedPlayers) < 2) {
        $message = "Please select at least 2 players to start a tournament.";
        $messageType = "warning";
    } else {
        try {
            $db->beginTransaction();
            
            // Insert tournament
            $stmt = $db->prepare("INSERT INTO tournaments (name, type, status, num_rounds) VALUES (?, ?, 'draft', ?)");
            $stmt->execute([$tname, $ttype, $numRounds]);
            $tournamentId = $db->lastInsertId();
            
            // Register players with seeds (automatic/shuffled)
            // Let's shuffle players to make seeding fun and fair!
            shuffle($selectedPlayers);
            $stmtTP = $db->prepare("INSERT INTO tournament_players (tournament_id, player_id, seed) VALUES (?, ?, ?)");
            for ($seedNum = 1; $seedNum <= count($selectedPlayers); $seedNum++) {
                $pid = $selectedPlayers[$seedNum - 1];
                $stmtTP->execute([$tournamentId, $pid, $seedNum]);
            }
            
            $db->commit();
            
            // Generate bracket structure
            $genRes = generateBracket($db, $tournamentId, $numRounds);
            if ($genRes) {
                header("Location: tournament.php?id=" . $tournamentId);
                exit;
            } else {
                $message = "Error generating bracket.";
                $messageType = "danger";
            }
            
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// 3. Handle Delete Tournament
if (isset($_POST['delete_tournament'])) {
    $delId = intval($_POST['tournament_id']);
    if ($delId > 0) {
        try {
            // Foreign keys with ON DELETE CASCADE handle matches, match_sets, and tournament_players
            $stmt = $db->prepare("DELETE FROM tournaments WHERE id = ?");
            $stmt->execute([$delId]);
            $message = "Tournament deleted successfully.";
            $messageType = "success";
        } catch (\Exception $e) {
            $message = "Error deleting tournament: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// 4. Handle Delete Player
if (isset($_POST['delete_player'])) {
    $delPlayerId = intval($_POST['player_id']);
    if ($delPlayerId > 0) {
        try {
            $stmt = $db->prepare("DELETE FROM players WHERE id = ?");
            $stmt->execute([$delPlayerId]);
            $message = "Player deleted successfully.";
            $messageType = "success";
        } catch (\Exception $e) {
            $message = "Error deleting player: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// Fetch all registered players
$stmtPlayers = $db->query("SELECT * FROM players ORDER BY name ASC");
$allPlayers = $stmtPlayers->fetchAll();

// Fetch all tournaments
$stmtTournaments = $db->query("SELECT * FROM tournaments ORDER BY created_at DESC");
$allTournaments = $stmtTournaments->fetchAll();
?>

<div class="container py-3">
    <div class="row align-items-center mb-4">
        <div class="col">
            <h1 class="font-outfit fw-bold tracking-tight text-white m-0">Admin Dashboard</h1>
            <p class="text-muted small m-0">Welcome back, Admin. Manage players, tournaments, and scoring.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Column 1: Manage Players & Create Tournament -->
        <div class="col-lg-6">
            <!-- Add Player Glass Card -->
            <div class="card card-glass border border-slate-800 p-3 mb-4">
                <div class="card-body">
                    <h4 class="font-outfit fw-bold text-accent-cyan mb-3">
                        <i class="fa-solid fa-user-plus me-2"></i>Add Player Profile
                    </h4>
                    <form method="POST" action="index.php">
                        <div class="input-group">
                            <input type="text" class="form-control bg-slate-900 border-slate-800 text-white" 
                                   name="player_name" placeholder="Enter full name (e.g. Lee Chong Wei)" required>
                            <button type="submit" name="add_player" class="btn btn-outline-neon-cyan">
                                <i class="fa-solid fa-plus me-1"></i> Add
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Create Tournament Glass Card -->
            <div class="card card-glass border border-slate-800 p-3">
                <div class="card-body">
                    <h4 class="font-outfit fw-bold text-accent-violet mb-3">
                        <i class="fa-solid fa-sitemap me-2"></i>Create New Tournament
                    </h4>
                    <form method="POST" action="index.php">
                        <div class="mb-3">
                            <label for="tournament_name" class="form-label text-muted small">Tournament Name</label>
                            <input type="text" class="form-control bg-slate-900 border-slate-800 text-white" 
                                   id="tournament_name" name="tournament_name" placeholder="e.g. Summer Smash Open 2026" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small d-block">Tournament Format</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tournament_type" 
                                       id="format_single" value="single_elimination" checked>
                                <label class="form-check-label text-white" for="format_single">
                                    Single Elimination
                                </label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tournament_type" 
                                       id="format_double" value="double_elimination">
                                <label class="form-check-label text-white" for="format_double">
                                    Double Elimination
                                </label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tournament_type" 
                                       id="format_robin" value="round_robin">
                                <label class="form-check-label text-white" for="format_robin">
                                    Round Robin
                                </label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tournament_type" 
                                       id="format_doubles" value="random_doubles">
                                <label class="form-check-label text-white" for="format_doubles">
                                    Random Doubles
                                </label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tournament_type" 
                                       id="format_king_court" value="king_court">
                                <label class="form-check-label text-white" for="format_king_court">
                                    King Court Doubles
                                </label>
                            </div>
                        </div>

                        <!-- Number of Rounds (for Random Doubles) -->
                        <div class="mb-3" id="rounds_container_admin" style="display: none;">
                            <label for="num_rounds" class="form-label text-muted small">Number of Rounds</label>
                            <input type="number" class="form-control bg-slate-900 border-slate-800 text-white" 
                                   id="num_rounds" name="num_rounds" value="5" min="1" max="50">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small d-block mb-1">Select Players (Must select 2+)</label>
                            <p class="text-muted" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info"></i> Players will be seeded randomly after tournament generation.</p>
                            
                            <div class="bg-slate-900 p-3 rounded border border-slate-800" style="max-height: 200px; overflow-y: auto;">
                                <?php if (empty($allPlayers)): ?>
                                    <div class="text-center text-muted small py-3">No players found in the database. Add player profiles first.</div>
                                <?php else: ?>
                                    <?php foreach ($allPlayers as $player): ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="selected_players[]" 
                                                   value="<?php echo $player['id']; ?>" id="player_<?php echo $player['id']; ?>">
                                            <label class="form-check-label text-white small" for="player_<?php echo $player['id']; ?>">
                                                <?php echo htmlspecialchars($player['name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <button type="submit" name="create_tournament" class="btn btn-primary-neon w-100 py-2">
                            <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Generate Bracket & Start
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Column 2: Tournament List & Registered Players -->
        <div class="col-lg-6">
            <!-- Tournament List Glass Card -->
            <div class="card card-glass border border-slate-800 p-3 mb-4">
                <div class="card-body">
                    <h4 class="font-outfit fw-bold text-white mb-3">
                        <i class="fa-solid fa-trophy me-2 text-warning"></i>Active & Completed Tournaments
                    </h4>
                    
                    <div class="bg-slate-900 rounded border border-slate-800" style="max-height: 300px; overflow-y: auto;">
                        <?php if (empty($allTournaments)): ?>
                            <div class="text-center text-muted small py-4">No tournaments created yet.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($allTournaments as $t): ?>
                                    <div class="list-group-item bg-transparent border-slate-800 d-flex justify-content-between align-items-center py-3">
                                        <div>
                                            <h6 class="text-white fw-bold mb-1"><?php echo htmlspecialchars($t['name']); ?></h6>
                                            <small class="text-muted d-block" style="font-size: 0.75rem;">
                                                Format: <?php 
                                                echo match($t['type']) {
                                                    'single_elimination' => 'Single Elimination',
                                                    'double_elimination' => 'Double Elimination',
                                                    'round_robin'        => 'Round Robin',
                                                    'random_doubles'     => 'Random Doubles (Social)',
                                                    default              => htmlspecialchars($t['type'])
                                                };
                                                ?>
                                            </small>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge badge-status badge-status-<?php echo $t['status']; ?>">
                                                <?php echo $t['status']; ?>
                                            </span>
                                            <a href="tournament.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-neon-cyan py-1 px-2">
                                                Manage <i class="fa-solid fa-gears ms-1"></i>
                                            </a>
                                            <form method="POST" action="index.php" class="d-inline">
                                                <input type="hidden" name="tournament_id" value="<?php echo $t['id']; ?>">
                                                <input type="hidden" name="delete_tournament" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Delete Tournament"
                                                        onclick="return confirm('Delete this tournament and all its matches?')">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Player Database Glass Card -->
            <div class="card card-glass border border-slate-800 p-3">
                <div class="card-body">
                    <h4 class="font-outfit fw-bold text-white mb-3">
                        <i class="fa-solid fa-users me-2 text-muted"></i>Player Database (<?php echo count($allPlayers); ?> Profiles)
                    </h4>
                    
                    <div class="bg-slate-900 rounded border border-slate-800" style="max-height: 200px; overflow-y: auto;">
                        <?php if (empty($allPlayers)): ?>
                            <div class="text-center text-muted small py-4">No registered players.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($allPlayers as $index => $player): ?>
                                    <div class="list-group-item bg-transparent border-slate-800 d-flex justify-content-between align-items-center py-2 px-3 text-white small">
                                        <span>
                                            <i class="fa-solid fa-user text-muted me-2"></i>
                                            <?php echo htmlspecialchars($player['name']); ?>
                                        </span>
                                        <form method="POST" action="index.php" class="d-inline mb-0">
                                            <input type="hidden" name="player_id" value="<?php echo $player['id']; ?>">
                                            <input type="hidden" name="delete_player" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1 border-0" title="Delete Player"
                                                    style="font-size: 0.75rem;"
                                                    onclick="return confirm('Delete this player?')">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleAdminRounds() {
    const isDoubles = document.getElementById('format_doubles').checked;
    const isKingCourt = document.getElementById('format_king_court') && document.getElementById('format_king_court').checked;
    document.getElementById('rounds_container_admin').style.display = (isDoubles || isKingCourt) ? 'block' : 'none';
}
document.querySelectorAll('input[name="tournament_type"]').forEach(radio => {
    radio.addEventListener('change', toggleAdminRounds);
});
toggleAdminRounds();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
