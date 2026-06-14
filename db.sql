-- Badminton Tournament Bracket and Scoring System Database Schema

CREATE DATABASE IF NOT EXISTS `badminton_bracket` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `badminton_bracket`;

-- Users / Admins Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) DEFAULT 'admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin account (Username: admin, Password: admin123)
INSERT INTO `users` (`username`, `password`, `role`) VALUES
('admin', '$2y$10$Mub8WEMvbTQkeJn55qW4pepo6eDH9Y7nisTT5fXykfHMwLkF1r27O', 'admin')
ON DUPLICATE KEY UPDATE `username`=`username`;

-- Players Table
CREATE TABLE IF NOT EXISTS `players` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tournaments Table
CREATE TABLE IF NOT EXISTS `tournaments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `status` ENUM('draft', 'active', 'completed') DEFAULT 'draft',
  `num_rounds` INT DEFAULT 5,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tournament Players Mapping Table (For Seeding)
CREATE TABLE IF NOT EXISTS `tournament_players` (
  `tournament_id` INT NOT NULL,
  `player_id` INT NOT NULL,
  `seed` INT NOT NULL,
  PRIMARY KEY (`tournament_id`, `player_id`),
  FOREIGN KEY (`tournament_id`) REFERENCES `tournaments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`player_id`) REFERENCES `players`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Matches Table
CREATE TABLE IF NOT EXISTS `matches` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tournament_id` INT NOT NULL,
  `bracket_type` ENUM('winner', 'loser', 'grand_final', 'grand_final_reset') NOT NULL DEFAULT 'winner',
  `round` INT NOT NULL,
  `match_number` INT NOT NULL,
  `player1_id` INT NULL,
  `player1b_id` INT NULL,
  `player2_id` INT NULL,
  `player2b_id` INT NULL,
  `winner_id` INT NULL,
  `winner_b_id` INT NULL,
  `loser_id` INT NULL,
  `loser_b_id` INT NULL,
  `status` ENUM('pending', 'ready', 'live', 'completed') DEFAULT 'pending',
  `next_match_id` INT NULL,
  `next_match_slot` TINYINT NULL COMMENT '1 = Player 1, 2 = Player 2 in next match',
  `loser_match_id` INT NULL,
  `loser_match_slot` TINYINT NULL COMMENT '1 = Player 1, 2 = Player 2 in loser match',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tournament_id`) REFERENCES `tournaments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`player1_id`) REFERENCES `players`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`player1b_id`) REFERENCES `players`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`player2_id`) REFERENCES `players`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`player2b_id`) REFERENCES `players`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`winner_id`) REFERENCES `players`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`winner_b_id`) REFERENCES `players`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`loser_id`) REFERENCES `players`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`loser_b_id`) REFERENCES `players`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`next_match_id`) REFERENCES `matches`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`loser_match_id`) REFERENCES `matches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Match Sets Table (Best of 3 Sets)
CREATE TABLE IF NOT EXISTS `match_sets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `match_id` INT NOT NULL,
  `set_number` TINYINT NOT NULL COMMENT '1, 2, or 3',
  `player1_score` INT DEFAULT 0,
  `player2_score` INT DEFAULT 0,
  UNIQUE KEY `match_set_uniq` (`match_id`, `set_number`),
  FOREIGN KEY (`match_id`) REFERENCES `matches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
