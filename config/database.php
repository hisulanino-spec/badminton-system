<?php
// Database Configuration
// Supports both Railway (production) and XAMPP (local development)

// Railway injects these environment variables automatically
// If they exist, use them. Otherwise, fall back to local XAMPP settings.
define('DB_HOST', getenv('MYSQLHOST') ?: '127.0.0.1');
define('DB_PORT', getenv('MYSQLPORT') ?: '3307');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'badminton_bracket');
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a secure PDO database connection.
     * @return PDO
     */
function getDBConnection() {
        static $pdo = null;

    if ($pdo !== null) {
                return $pdo;
    }

    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=%s", DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);

    $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

    try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

                // Auto-import database schema on first run (for Railway deployment)
                autoImportSchema($pdo);

                // Auto-migrate: add any missing columns from schema updates
                autoMigrateSchema($pdo);

                return $pdo;
    } catch (\PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
    }
}

/**
 * Automatically imports db.sql schema if tables don't exist yet.
     * This runs only once on fresh Railway deployments.
     */
function autoImportSchema($pdo) {
        try {
                    // Check if the 'users' table exists
                    $result = $pdo->query("SHOW TABLES LIKE 'users'");
                    if ($result->rowCount() > 0) {
                                    return; // Tables already exist, skip import
                    }

                    // Find db.sql relative to this config file
                    $sqlFile = dirname(__DIR__) . '/db.sql';
                    if (!file_exists($sqlFile)) {
                                    return; // No schema file found
                    }

                    $sql = file_get_contents($sqlFile);

                    // Remove CREATE DATABASE and USE statements (Railway creates the DB for us)
                    $sql = preg_replace('/CREATE DATABASE.*?;\s*/i', '', $sql);
                    $sql = preg_replace('/USE\s+`[^`]+`\s*;\s*/i', '', $sql);

                    // Execute the schema
                    $pdo->exec($sql);

        } catch (\PDOException $e) {
                    // Silently fail - schema might already exist partially
            error_log("Auto-import schema notice: " . $e->getMessage());
        }
}

/**
 * Automatically adds missing columns to existing tables.
     * This handles cases where db.sql was updated after the initial schema import.
     */
function autoMigrateSchema($pdo) {
        try {
                    // Check if 'tournaments' table exists before migrating
                    $result = $pdo->query("SHOW TABLES LIKE 'tournaments'");
                    if ($result->rowCount() === 0) {
                                    return; // Table doesn't exist yet, autoImportSchema will handle it
                    }

                    // Migration: Add 'num_rounds' column to tournaments if missing
                    $cols = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'num_rounds'");
                    if ($cols->rowCount() === 0) {
                                    $pdo->exec("ALTER TABLE tournaments ADD COLUMN `num_rounds` INT DEFAULT 5");
                    }

        } catch (\PDOException $e) {
                    error_log("Auto-migrate schema notice: " . $e->getMessage());
        }
}
