<?php
$pageTitle = "Tournament Portal";
$basePath = "";
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/header.php';

$db = getDBConnection();

// Handle Search Query
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'all';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build SQL
$sql = "SELECT * FROM tournaments WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND name LIKE ?";
    $params[] = "%$search%";
}

if ($format !== 'all') {
    $sql .= " AND type = ?";
    $params[] = $format;
}

if ($status !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tournaments = $stmt->fetchAll();
?>

<div class="container py-4">
    <!-- Hero Section -->
    <div class="row justify-content-center text-center mb-5">
        <div class="col-lg-8">
            <i class="fa-solid fa-ranking-stars text-accent-violet fs-1 mb-3 animate-bounce"></i>
            <h1 class="font-outfit fw-extrabold tracking-tight text-white display-5 mb-2">
                Tournament <span class="text-accent-cyan">Portal</span>
            </h1>
            <p class="text-muted fs-5">
                Track live scores, view brackets, and follow your favorite players in real time.
            </p>
            <a href="create.php" class="btn btn-primary-neon px-5 py-3 fs-5 font-outfit fw-bold mt-2">
                <i class="fa-solid fa-shuffle me-2"></i> Create Random Tournament
            </a>
        </div>
    </div>

    <!-- Search & Filters Glass Card -->
    <div class="card card-glass border border-slate-800 p-3 mb-5">
        <div class="card-body">
            <form method="GET" action="index.php" class="row g-3">
                <div class="col-md-5">
                    <label for="search" class="form-label text-muted small">Search Tournaments</label>
                    <div class="input-group">
                        <span class="input-group-text bg-slate-900 border-slate-800 text-muted">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>
                        <input type="text" class="form-control bg-slate-900 border-slate-800 text-white" 
                               id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. Summer Smash Open">
                    </div>
                </div>
                
                <div class="col-md-3">
                    <label for="format" class="form-label text-muted small">Format</label>
                    <select class="form-select bg-slate-900 border-slate-800 text-white" id="format" name="format">
                        <option value="all" <?php echo $format === 'all' ? 'selected' : ''; ?>>All Formats</option>
                        <option value="single_elimination" <?php echo $format === 'single_elimination' ? 'selected' : ''; ?>>Single Elimination</option>
                        <option value="double_elimination" <?php echo $format === 'double_elimination' ? 'selected' : ''; ?>>Double Elimination</option>
                        <option value="round_robin" <?php echo $format === 'round_robin' ? 'selected' : ''; ?>>Round Robin</option>
                        <option value="random_doubles" <?php echo $format === 'random_doubles' ? 'selected' : ''; ?>>Random Doubles</option>
                        <option value="king_court" <?php echo $format === 'king_court' ? 'selected' : ''; ?>>King Court Doubles</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="status" class="form-label text-muted small">Status</label>
                    <select class="form-select bg-slate-900 border-slate-800 text-white" id="status" name="status">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Live / Active</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    </select>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary-neon w-100 py-2">
                        <i class="fa-solid fa-filter me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tournament List -->
    <div class="row g-4">
        <?php if (empty($tournaments)): ?>
            <div class="col-12 text-center py-5">
                <i class="fa-regular fa-folder-open text-muted fs-2 mb-3"></i>
                <h4 class="text-white">No tournaments found</h4>
                <p class="text-muted small">Try adjusting your filters or search keywords.</p>
            </div>
        <?php else: ?>
            <?php foreach ($tournaments as $t): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card card-glass border-slate-800 h-100 p-2">
                        <div class="card-body d-flex flex-column justify-content-between h-100">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <span class="badge badge-status badge-status-<?php echo $t['status']; ?>">
                                        <?php echo $t['status']; ?>
                                    </span>
                                    <small class="text-muted" style="font-size: 0.75rem;">
                                        <i class="fa-regular fa-calendar-days me-1"></i>
                                        <?php echo date('M d, Y', strtotime($t['created_at'])); ?>
                                    </small>
                                </div>
                                
                                <h4 class="font-outfit fw-bold text-white mb-2"><?php echo htmlspecialchars($t['name']); ?></h4>
                                
                                <div class="text-muted small mb-4">
                                    <div class="mb-1">
                                        <i class="fa-solid fa-sitemap me-2 text-accent-violet"></i>
                                        <strong>Format:</strong> <?php 
                                        echo match($t['type']) {
                                            'single_elimination' => 'Single Elimination',
                                            'double_elimination' => 'Double Elimination',
                                            'round_robin'        => 'Round Robin',
                                            'random_doubles'     => 'Random Doubles (Social)',
                                            default              => htmlspecialchars($t['type'])
                                        };
                                        ?>
                                    </div>
                                    <div>
                                        <i class="fa-solid fa-users me-2 text-accent-cyan"></i>
                                        <strong>Players:</strong> 
                                        <?php
                                        // Count players in tournament
                                        $stmtCount = $db->prepare("SELECT COUNT(*) FROM tournament_players WHERE tournament_id = ?");
                                        $stmtCount->execute([$t['id']]);
                                        echo $stmtCount->fetchColumn();
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <a href="tournament.php?id=<?php echo $t['id']; ?>" class="btn btn-outline-neon-cyan w-100 py-2 mt-auto d-flex align-items-center justify-content-center gap-2">
                                    Spectate Bracket <i class="fa-solid fa-circle-play"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
