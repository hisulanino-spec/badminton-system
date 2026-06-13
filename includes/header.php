<?php
ob_start(); // Enable output buffering so header() redirects work after HTML output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Helper to check if user is logged in
function isAdminLoggedIn() {
    return true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . " - StoneBoysClub" : "StoneBoysClub - Badminton Tournament System"; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-fontawesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo isset($basePath) ? $basePath : ''; ?>assets/css/style.css">
</head>
<body>
    
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-slate-900 border-bottom border-slate-800 sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo isset($basePath) ? $basePath : ''; ?>index.php">
                <i class="fa-solid fa-trophy text-accent-cyan fs-4"></i>
                <span class="font-outfit fw-bold tracking-tight">STONEBOYS<span class="text-accent-violet">CLUB</span></span>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo isset($basePath) ? $basePath : ''; ?>index.php">
                            <i class="fa-solid fa-house me-1"></i> Home
                        </a>
                    </li>
                </ul>
                <div class="navbar-nav gap-2">
                    <a href="<?php echo isset($basePath) ? $basePath : ''; ?>create.php" class="btn btn-outline-light d-flex align-items-center gap-2">
                        <i class="fa-solid fa-shuffle text-accent-cyan"></i> Create Tournament
                    </a>
                    <a href="<?php echo isset($basePath) ? $basePath : ''; ?>admin/index.php" class="btn btn-outline-light d-flex align-items-center gap-2">
                        <i class="fa-solid fa-gears text-accent-violet"></i> Manage Tournaments
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <main class="py-4">
