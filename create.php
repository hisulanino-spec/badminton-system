<?php
$pageTitle = "Create Tournament";
$basePath = "";
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$db = getDBConnection();
$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tournamentName = trim($_POST['tournament_name'] ?? '');
    $tournamentType = $_POST['tournament_type'] ?? 'single_elimination';

    // Collect player names - split by newline, filter empty lines
    $rawNames = trim($_POST['player_names'] ?? '');
    $playerNames = array_values(array_filter(
        array_map('trim', explode("\n", $rawNames)),
        fn($n) => $n !== ''
    ));

    // Remove duplicate names (case-insensitive)
    $seen = [];
    $uniqueNames = [];
    foreach ($playerNames as $name) {
        $key = strtolower($name);
        if (!in_array($key, $seen)) {
            $seen[] = $key;
            $uniqueNames[] = $name;
        }
    }
    $playerNames = $uniqueNames;

    $numRounds = intval($_POST['num_rounds'] ?? 5);
    if ($numRounds < 1) $numRounds = 5;

    if (empty($tournamentName)) {
        $error = "Please enter a tournament name.";
    } elseif ($tournamentType === 'random_doubles' && count($playerNames) < 4) {
        $error = "Please enter at least 4 player names for a Random Doubles tournament.";
    } elseif (count($playerNames) < 2) {
        $error = "Please enter at least 2 player names.";
    } else {
        try {
            $db->beginTransaction();

            // 1. Create tournament
            $stmt = $db->prepare("INSERT INTO tournaments (name, type, status, num_rounds) VALUES (?, ?, 'draft', ?)");
            $stmt->execute([$tournamentName, $tournamentType, $numRounds]);
            $tournamentId = $db->lastInsertId();

            // 2. Insert players (reuse existing name or create new)
            //    Shuffle names first for fully random matchups
            shuffle($playerNames);

            $stmtFind   = $db->prepare("SELECT id FROM players WHERE name = ? LIMIT 1");
            $stmtInsert = $db->prepare("INSERT INTO players (name) VALUES (?)");
            $stmtTP     = $db->prepare("INSERT INTO tournament_players (tournament_id, player_id, seed) VALUES (?, ?, ?)");

            foreach ($playerNames as $seed => $name) {
                $stmtFind->execute([$name]);
                $existingId = $stmtFind->fetchColumn();

                if ($existingId) {
                    $playerId = $existingId;
                } else {
                    $stmtInsert->execute([$name]);
                    $playerId = $db->lastInsertId();
                }

                $stmtTP->execute([$tournamentId, $playerId, $seed + 1]);
            }

            $db->commit();

            // 3. Generate bracket
            $ok = generateBracket($db, $tournamentId, $numRounds);
            if ($ok) {
                header("Location: tournament.php?id=" . $tournamentId);
                exit;
            } else {
                $error = "Bracket generation failed. Please try again.";
            }

        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">

            <!-- Hero -->
            <div class="text-center mb-5">
                <i class="fa-solid fa-shuffle text-accent-violet fs-1 mb-3"></i>
                <h1 class="font-outfit fw-bold tracking-tight text-white display-6 mb-2">
                    Create a <span class="text-accent-cyan">Random</span> Tournament
                </h1>
                <p class="text-muted">Enter the player names below. Matchups will be generated randomly — no login needed!</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger border-0 mb-4">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="card card-glass border border-slate-800 p-4">
                <form method="POST" action="create.php" id="createForm">

                    <!-- Tournament Name -->
                    <div class="mb-4">
                        <label for="tournament_name" class="form-label text-muted small fw-semibold">
                            <i class="fa-solid fa-trophy me-1 text-warning"></i> Tournament Name
                        </label>
                        <input type="text"
                               class="form-control bg-slate-900 border-slate-800 text-white py-2"
                               id="tournament_name" name="tournament_name"
                               placeholder="e.g. Badminton Summer Cup 2026"
                               value="<?php echo htmlspecialchars($_POST['tournament_name'] ?? ''); ?>"
                               required>
                    </div>

                    <!-- Format -->
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-semibold d-block">
                            <i class="fa-solid fa-sitemap me-1 text-accent-violet"></i> Tournament Format
                        </label>
                        <div class="d-flex gap-4 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tournament_type"
                                       id="fmt_robin" value="round_robin"
                                       <?php echo (!isset($_POST['tournament_type']) || $_POST['tournament_type'] === 'round_robin') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-white" for="fmt_robin">
                                    🔄 Round Robin <small class="text-accent-cyan">(Singles, everyone plays everyone)</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tournament_type"
                                       id="fmt_doubles" value="random_doubles"
                                       <?php echo (isset($_POST['tournament_type']) && $_POST['tournament_type'] === 'random_doubles') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-white" for="fmt_doubles">
                                    👥 Random Doubles <small class="text-accent-cyan">(Social, partners randomized)</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tournament_type"
                                       id="fmt_king_court" value="king_court"
                                       <?php echo (isset($_POST['tournament_type']) && $_POST['tournament_type'] === 'king_court') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-white" for="fmt_king_court">
                                    👑 King Court Doubles <small class="text-accent-cyan">(Social, partners rotate, win-win/lose-lose)</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tournament_type"
                                       id="fmt_single" value="single_elimination"
                                       <?php echo (isset($_POST['tournament_type']) && $_POST['tournament_type'] === 'single_elimination') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-white" for="fmt_single">
                                    Single Elimination
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tournament_type"
                                       id="fmt_double" value="double_elimination"
                                       <?php echo (isset($_POST['tournament_type']) && $_POST['tournament_type'] === 'double_elimination') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-white" for="fmt_double">
                                    Double Elimination
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Number of Rounds (for Random Doubles) -->
                    <div class="mb-4" id="rounds_container" style="display: none;">
                        <label for="num_rounds" class="form-label text-muted small fw-semibold">
                            <i class="fa-solid fa-rotate me-1 text-accent-cyan"></i> Number of Rounds
                        </label>
                        <input type="number"
                               class="form-control bg-slate-900 border-slate-800 text-white py-2"
                               id="num_rounds" name="num_rounds"
                               min="1" max="50"
                               value="<?php echo htmlspecialchars($_POST['num_rounds'] ?? '5'); ?>">
                        <small class="text-muted">Specify how many rounds of matches to generate.</small>
                    </div>

                    <!-- Player Names -->
                    <div class="mb-4">
                        <label for="player_names" class="form-label text-muted small fw-semibold">
                            <i class="fa-solid fa-users me-1 text-accent-cyan"></i> Player Names
                            <span class="text-muted fw-normal ms-1">(one name per line, minimum 2)</span>
                        </label>

                        <!-- Quick-add input row -->
                        <div class="input-group mb-2">
                            <input type="text" id="quickAddInput"
                                   class="form-control bg-slate-900 border-slate-800 text-white"
                                   placeholder="Type a name then press Enter or click Add…">
                            <button type="button" class="btn btn-outline-neon-cyan" onclick="quickAdd()">
                                <i class="fa-solid fa-plus me-1"></i> Add
                            </button>
                        </div>

                        <!-- Textarea that holds all names -->
                        <textarea
                            class="form-control bg-slate-900 border-slate-800 text-white"
                            id="player_names" name="player_names"
                            rows="8"
                            placeholder="Alice&#10;Bob&#10;Charlie&#10;David&#10;..."
                            style="font-family: monospace; resize: vertical;"><?php echo htmlspecialchars($_POST['player_names'] ?? ''); ?></textarea>

                        <!-- Live counter -->
                        <div class="d-flex justify-content-between mt-2">
                            <small class="text-muted">Each name on its own line. Duplicates are removed automatically.</small>
                            <small id="playerCount" class="fw-semibold text-accent-cyan">0 players</small>
                        </div>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="btn btn-primary-neon w-100 py-3 fs-5 font-outfit fw-bold">
                        <i class="fa-solid fa-shuffle me-2"></i> Randomize & Generate Bracket
                    </button>
                </form>
            </div>

            <!-- Back link -->
            <div class="text-center mt-4">
                <a href="index.php" class="text-muted text-decoration-none small">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Tournament List
                </a>
            </div>

        </div>
    </div>
</div>

<script>
// Quick-add name on Enter key
document.getElementById('quickAddInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        quickAdd();
    }
});

function quickAdd() {
    const input = document.getElementById('quickAddInput');
    const name  = input.value.trim();
    if (!name) return;

    const textarea = document.getElementById('player_names');
    const existing = textarea.value.trim();
    textarea.value = existing ? existing + '\n' + name : name;
    input.value = '';
    input.focus();
    updateCount();
}

// Live player count
function updateCount() {
    const textarea = document.getElementById('player_names');
    const names = textarea.value.split('\n').filter(n => n.trim() !== '');
    const unique = [...new Set(names.map(n => n.trim().toLowerCase()))].length;
    const el = document.getElementById('playerCount');
    el.textContent = unique + ' player' + (unique !== 1 ? 's' : '');
    el.style.color = unique < 2 ? '#f87171' : '';
}

document.getElementById('player_names').addEventListener('input', updateCount);
updateCount();

// Toggle rounds container based on tournament type
function toggleRoundsInput() {
    const isDoubles = document.getElementById('fmt_doubles').checked;
    const isKingCourt = document.getElementById('fmt_king_court') && document.getElementById('fmt_king_court').checked;
    document.getElementById('rounds_container').style.display = (isDoubles || isKingCourt) ? 'block' : 'none';
}
document.querySelectorAll('input[name="tournament_type"]').forEach(radio => {
    radio.addEventListener('change', toggleRoundsInput);
});
toggleRoundsInput();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
