<?php
// Core System Functions
require_once __DIR__ . '/../config/database.php';

/**
 * Generates a standard seeding order for a given power of 2.
 */
function getSeedingOrder($powerOfTwo) {
    $order = [1];
    while (count($order) < $powerOfTwo) {
        $nextOrder = [];
        $target = count($order) * 2 + 1;
        foreach ($order as $seed) {
            $nextOrder[] = $seed;
            $nextOrder[] = $target - $seed;
        }
        $order = $nextOrder;
    }
    return $order;
}

/**
 * Generates a Round Robin schedule using the circle method.
 * Everyone plays everyone exactly once.
 * Rounds are shuffled so the order is random (preventing same early matchups).
 *
 * @param array $playerIds
 * @return array Array of rounds, each round = array of [p1_id, p2_id] pairs
 */
function generateRoundRobinSchedule($playerIds) {
    $players = array_values($playerIds);
    $n = count($players);

    // Add a BYE slot if odd number of players
    if ($n % 2 !== 0) {
        $players[] = null; // BYE
        $n++;
    }

    $half     = $n / 2;
    $fixed    = $players[0];          // First player is always fixed
    $rotating = array_slice($players, 1); // Rest rotate each round
    $rounds   = [];

    for ($round = 0; $round < $n - 1; $round++) {
        $pairs = [];

        // Top row: fixed + first (half-1) of rotating
        $top    = array_merge([$fixed], array_slice($rotating, 0, $half - 1));
        // Bottom row: last (half) of rotating, reversed
        $bottom = array_reverse(array_slice($rotating, $half - 1));

        for ($i = 0; $i < $half; $i++) {
            $p1 = $top[$i];
            $p2 = $bottom[$i];
            if ($p1 !== null && $p2 !== null) {
                $pairs[] = [$p1, $p2];
            }
        }

        if (!empty($pairs)) {
            $rounds[] = $pairs;
        }

        // Rotate: move last element of rotating to front
        $last = array_pop($rotating);
        array_unshift($rotating, $last);
    }

    // Shuffle round order so players don't always face the same opponent early
    shuffle($rounds);

    return $rounds;
}

/**
 * Generates matches for a Round Robin tournament.
 * @param PDO $db
 * @param int $tournamentId
 * @return bool
 */
function generateRoundRobinBracket($db, $tournamentId) {
    // Fetch players
    $stmt = $db->prepare("
        SELECT p.id
        FROM players p
        JOIN tournament_players tp ON p.id = tp.player_id
        WHERE tp.tournament_id = ?
        ORDER BY RAND()
    ");
    $stmt->execute([$tournamentId]);
    $playerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($playerIds) < 2) return false;

    // Delete any existing matches
    $stmt = $db->prepare("DELETE FROM matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);

    // Generate schedule
    $rounds = generateRoundRobinSchedule($playerIds);

    // Insert all matches
    $stmtIns = $db->prepare("
        INSERT INTO matches (tournament_id, bracket_type, round, match_number, player1_id, player2_id, status)
        VALUES (?, 'winner', ?, ?, ?, ?, 'ready')
    ");

    foreach ($rounds as $roundIndex => $pairs) {
        foreach ($pairs as $matchIndex => $pair) {
            $stmtIns->execute([
                $tournamentId,
                $roundIndex + 1,
                $matchIndex + 1,
                $pair[0],
                $pair[1]
            ]);
        }
    }

    // Activate tournament
    $stmt = $db->prepare("UPDATE tournaments SET status = 'active' WHERE id = ?");
    $stmt->execute([$tournamentId]);

    return true;
}

/**
 * Computes Round Robin standings from completed matches.
 * @param PDO $db
 * @param int $tournamentId
 * @return array Sorted standings array
 */
function getRoundRobinStandings($db, $tournamentId) {
    // All players
    $stmt = $db->prepare("
        SELECT p.id, p.name
        FROM players p
        JOIN tournament_players tp ON p.id = tp.player_id
        WHERE tp.tournament_id = ?
        ORDER BY p.name ASC
    ");
    $stmt->execute([$tournamentId]);
    $players = $stmt->fetchAll();

    $stats = [];
    foreach ($players as $p) {
        $stats[$p['id']] = [
            'id'         => $p['id'],
            'name'       => $p['name'],
            'played'     => 0,
            'won'        => 0,
            'lost'       => 0,
            'sets_won'   => 0,
            'sets_lost'  => 0,
            'pts_won'    => 0,
            'pts_lost'   => 0,
        ];
    }

    // All completed matches
    $stmt = $db->prepare("SELECT * FROM matches WHERE tournament_id = ? AND status = 'completed'");
    $stmt->execute([$tournamentId]);
    $matches = $stmt->fetchAll();

    foreach ($matches as $m) {
        $p1 = $m['player1_id'];
        $p2 = $m['player2_id'];
        if (!$p1 || !$p2) continue;

        if (isset($stats[$p1])) $stats[$p1]['played']++;
        if (isset($stats[$p2])) $stats[$p2]['played']++;
        if ($m['winner_id'] && isset($stats[$m['winner_id']])) $stats[$m['winner_id']]['won']++;
        if ($m['loser_id']  && isset($stats[$m['loser_id']]))  $stats[$m['loser_id']]['lost']++;

        // Set scores
        $stmtS = $db->prepare("SELECT * FROM match_sets WHERE match_id = ?");
        $stmtS->execute([$m['id']]);
        foreach ($stmtS->fetchAll() as $s) {
            if (isset($stats[$p1])) {
                $stats[$p1]['sets_won']  += ($s['player1_score'] > $s['player2_score']) ? 1 : 0;
                $stats[$p1]['sets_lost'] += ($s['player1_score'] < $s['player2_score']) ? 1 : 0;
                $stats[$p1]['pts_won']   += $s['player1_score'];
                $stats[$p1]['pts_lost']  += $s['player2_score'];
            }
            if (isset($stats[$p2])) {
                $stats[$p2]['sets_won']  += ($s['player2_score'] > $s['player1_score']) ? 1 : 0;
                $stats[$p2]['sets_lost'] += ($s['player2_score'] < $s['player1_score']) ? 1 : 0;
                $stats[$p2]['pts_won']   += $s['player2_score'];
                $stats[$p2]['pts_lost']  += $s['player1_score'];
            }
        }
    }

    // Sort: wins desc → sets_won desc → pts_won desc
    usort($stats, function($a, $b) {
        if ($b['won'] !== $a['won'])         return $b['won'] - $a['won'];
        if ($b['sets_won'] !== $a['sets_won']) return $b['sets_won'] - $a['sets_won'];
        return $b['pts_won'] - $a['pts_won'];
    });

    return array_values($stats);
}

/**
 * Generates a Random Doubles schedule where partners and opponents are randomized,
 * minimizing repeating partners/opponents.
 * 
 * @param array $playerIds
 * @param int $numRounds
 * @return array
 */
function generateRandomDoublesSchedule($playerIds, $numRounds) {
    $n = count($playerIds);
    if ($n < 4) return [];

    $playCount = array_fill_keys($playerIds, 0);
    $partnerMatrix = [];
    $opponentMatrix = [];
    foreach ($playerIds as $p1) {
        foreach ($playerIds as $p2) {
            $partnerMatrix[$p1][$p2] = 0;
            $opponentMatrix[$p1][$p2] = 0;
        }
    }

    $rounds = [];

    for ($r = 1; $r <= $numRounds; $r++) {
        // Group players by play count and shuffle within groups to randomize fairly
        $grouped = [];
        foreach ($playerIds as $pid) {
            $grouped[$playCount[$pid]][] = $pid;
        }
        ksort($grouped);

        $sortedPlayers = [];
        foreach ($grouped as $count => $pids) {
            shuffle($pids);
            $sortedPlayers = array_merge($sortedPlayers, $pids);
        }

        // Pair up in groups of 4 for doubles
        $numMatches = floor($n / 4);
        $selectedPlayers = array_slice($sortedPlayers, 0, $numMatches * 4);

        $roundMatches = [];
        for ($m = 0; $m < $numMatches; $m++) {
            $four = array_slice($selectedPlayers, $m * 4, 4);

            // Three configurations for dividing 4 players into 2 teams
            $configs = [
                [['p1' => $four[0], 'p2' => $four[1]], ['p1' => $four[2], 'p2' => $four[3]]],
                [['p1' => $four[0], 'p2' => $four[2]], ['p1' => $four[1], 'p2' => $four[3]]],
                [['p1' => $four[0], 'p2' => $four[3]], ['p1' => $four[1], 'p2' => $four[2]]]
            ];

            $bestConfigIdx = 0;
            $minPenalty = INF;

            foreach ($configs as $idx => $conf) {
                $t1_p1 = $conf[0]['p1'];
                $t1_p2 = $conf[0]['p2'];
                $t2_p1 = $conf[1]['p1'];
                $t2_p2 = $conf[1]['p2'];

                // Partnership repetition penalty
                $p_penalty = $partnerMatrix[$t1_p1][$t1_p2] + $partnerMatrix[$t2_p1][$t2_p2];
                // Opponent repetition penalty
                $o_penalty = $opponentMatrix[$t1_p1][$t2_p1] + $opponentMatrix[$t1_p1][$t2_p2] +
                             $opponentMatrix[$t1_p2][$t2_p1] + $opponentMatrix[$t1_p2][$t2_p2];

                $penalty = $p_penalty * 10 + $o_penalty;
                if ($penalty < $minPenalty) {
                    $minPenalty = $penalty;
                    $bestConfigIdx = $idx;
                }
            }

            $bestConf = $configs[$bestConfigIdx];
            $t1_p1 = $bestConf[0]['p1'];
            $t1_p2 = $bestConf[0]['p2'];
            $t2_p1 = $bestConf[1]['p1'];
            $t2_p2 = $bestConf[1]['p2'];

            // Update state
            $partnerMatrix[$t1_p1][$t1_p2]++; $partnerMatrix[$t1_p2][$t1_p1]++;
            $partnerMatrix[$t2_p1][$t2_p2]++; $partnerMatrix[$t2_p2][$t2_p1]++;

            $opponentMatrix[$t1_p1][$t2_p1]++; $opponentMatrix[$t2_p1][$t1_p1]++;
            $opponentMatrix[$t1_p1][$t2_p2]++; $opponentMatrix[$t2_p2][$t1_p1]++;
            $opponentMatrix[$t1_p2][$t2_p1]++; $opponentMatrix[$t2_p1][$t1_p2]++;
            $opponentMatrix[$t1_p2][$t2_p2]++; $opponentMatrix[$t2_p2][$t1_p2]++;

            $playCount[$t1_p1]++;
            $playCount[$t1_p2]++;
            $playCount[$t2_p1]++;
            $playCount[$t2_p2]++;

            $roundMatches[] = [
                't1_p1' => $t1_p1,
                't1_p2' => $t1_p2,
                't2_p1' => $t2_p1,
                't2_p2' => $t2_p2
            ];
        }

        $rounds[$r] = $roundMatches;
    }

    return $rounds;
}

/**
 * Generates matches for a Random Doubles tournament.
 * @param PDO $db
 * @param int $tournamentId
 * @param int $numRounds
 * @return bool
 */
function generateRandomDoublesBracket($db, $tournamentId, $numRounds = 5) {
    $stmt = $db->prepare("
        SELECT p.id
        FROM players p
        JOIN tournament_players tp ON p.id = tp.player_id
        WHERE tp.tournament_id = ?
        ORDER BY RAND()
    ");
    $stmt->execute([$tournamentId]);
    $playerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($playerIds) < 4) return false;

    $stmt = $db->prepare("DELETE FROM matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);

    $rounds = generateRandomDoublesSchedule($playerIds, $numRounds);

    $stmtIns = $db->prepare("
        INSERT INTO matches (tournament_id, bracket_type, round, match_number, player1_id, player1b_id, player2_id, player2b_id, status)
        VALUES (?, 'winner', ?, ?, ?, ?, ?, ?, 'ready')
    ");

    foreach ($rounds as $roundNumber => $matches) {
        foreach ($matches as $matchIndex => $m) {
            $stmtIns->execute([
                $tournamentId,
                $roundNumber,
                $matchIndex + 1,
                $m['t1_p1'],
                $m['t1_p2'],
                $m['t2_p1'],
                $m['t2_p2']
            ]);
        }
    }

    $stmt = $db->prepare("UPDATE tournaments SET status = 'active' WHERE id = ?");
    $stmt->execute([$tournamentId]);

    return true;
}

/**
 * Computes individual player standings for a Random Doubles tournament.
 * @param PDO $db
 * @param int $tournamentId
 * @return array
 */
function getRandomDoublesStandings($db, $tournamentId) {
    $stmt = $db->prepare("
        SELECT p.id, p.name
        FROM players p
        JOIN tournament_players tp ON p.id = tp.player_id
        WHERE tp.tournament_id = ?
        ORDER BY p.name ASC
    ");
    $stmt->execute([$tournamentId]);
    $players = $stmt->fetchAll();

    $stats = [];
    foreach ($players as $p) {
        $stats[$p['id']] = [
            'id'         => $p['id'],
            'name'       => $p['name'],
            'played'     => 0,
            'won'        => 0,
            'lost'       => 0,
            'sets_won'   => 0,
            'sets_lost'  => 0,
            'pts_won'    => 0,
            'pts_lost'   => 0,
        ];
    }

    $stmt = $db->prepare("SELECT * FROM matches WHERE tournament_id = ? AND status = 'completed'");
    $stmt->execute([$tournamentId]);
    $matches = $stmt->fetchAll();

    foreach ($matches as $m) {
        $p1a = $m['player1_id'];
        $p1b = $m['player1b_id'];
        $p2a = $m['player2_id'];
        $p2b = $m['player2b_id'];

        $w_a = $m['winner_id'];
        $w_b = $m['winner_b_id'];

        $stmtS = $db->prepare("SELECT * FROM match_sets WHERE match_id = ?");
        $stmtS->execute([$m['id']]);
        $sets = $stmtS->fetchAll();

        // Check Team 1 players
        foreach ([$p1a, $p1b] as $p) {
            if ($p !== null && isset($stats[$p])) {
                $stats[$p]['played']++;
                if ($p == $w_a || $p == $w_b) {
                    $stats[$p]['won']++;
                } else {
                    $stats[$p]['lost']++;
                }

                foreach ($sets as $s) {
                    if ($s['player1_score'] > $s['player2_score']) {
                        $stats[$p]['sets_won']++;
                    } else {
                        $stats[$p]['sets_lost']++;
                    }
                    $stats[$p]['pts_won'] += $s['player1_score'];
                    $stats[$p]['pts_lost'] += $s['player2_score'];
                }
            }
        }

        // Check Team 2 players
        foreach ([$p2a, $p2b] as $p) {
            if ($p !== null && isset($stats[$p])) {
                $stats[$p]['played']++;
                if ($p == $w_a || $p == $w_b) {
                    $stats[$p]['won']++;
                } else {
                    $stats[$p]['lost']++;
                }

                foreach ($sets as $s) {
                    if ($s['player2_score'] > $s['player1_score']) {
                        $stats[$p]['sets_won']++;
                    } else {
                        $stats[$p]['sets_lost']++;
                    }
                    $stats[$p]['pts_won'] += $s['player2_score'];
                    $stats[$p]['pts_lost'] += $s['player1_score'];
                }
            }
        }
    }

    usort($stats, function($a, $b) {
        if ($b['won'] !== $a['won'])         return $b['won'] - $a['won'];
        if ($b['sets_won'] !== $a['sets_won']) return $b['sets_won'] - $a['sets_won'];
        return $b['pts_won'] - $a['pts_won'];
    });

    return array_values($stats);
}

/**
 * Main bracket generator — dispatches to the correct function by tournament type.
 * @param PDO $db
 * @param int $tournamentId
 * @param int $numRounds
 * @return bool
 */
function generateBracket($db, $tournamentId, $numRounds = 5) {
    // Check tournament type
    $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch();

    if (!$tournament) return false;

    // Dispatch to round robin generator if needed
    if ($tournament['type'] === 'round_robin') {
        return generateRoundRobinBracket($db, $tournamentId);
    }

    // Dispatch to random doubles generator if needed
    if ($tournament['type'] === 'random_doubles') {
        return generateRandomDoublesBracket($db, $tournamentId, $numRounds);
    }

    // Dispatch to king court generator if needed
    if ($tournament['type'] === 'king_court') {
        return generateKingCourtBracket($db, $tournamentId, $numRounds);
    }

    // 2. Fetch players (for elimination formats)

    $stmt = $db->prepare("
        SELECT p.*, tp.seed 
        FROM players p
        JOIN tournament_players tp ON p.id = tp.player_id
        WHERE tp.tournament_id = ?
        ORDER BY tp.seed ASC
    ");
    $stmt->execute([$tournamentId]);
    $players = $stmt->fetchAll();
    $numPlayers = count($players);
    if ($numPlayers < 2) return false;
    
    // Determine bracket size P (next power of 2)
    $P = 2;
    while ($P < $numPlayers) {
        $P *= 2;
    }
    
    // Generate random sequential order (1, 2, 3, 4, ...) instead of standard seeding
    // Since players are already shuffled when seeds are assigned, this creates fully random matchups
    $seeding = range(1, $P);
    
    // Map seed -> player_id
    $seededPlayers = [];
    foreach ($players as $p) {
        $seededPlayers[$p['seed']] = $p['id'];
    }
    
    // Delete any existing matches to prevent duplicates
    $stmt = $db->prepare("DELETE FROM matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    $K = log($P, 2); // Number of rounds in WB
    
    if ($tournament['type'] === 'single_elimination') {
        // --- Single Elimination Generation ---
        $insertedMatches = []; // map of [round][match_number] => id
        
        for ($r = $K; $r >= 1; $r--) {
            $numMatches = $P / pow(2, $r);
            for ($m = 1; $m <= $numMatches; $m++) {
                $nextMatchId = null;
                $nextMatchSlot = null;
                
                if ($r < $K) {
                    $nextMatchNum = ceil($m / 2);
                    $nextMatchId = $insertedMatches[$r + 1][$nextMatchNum];
                    $nextMatchSlot = ($m % 2 != 0) ? 1 : 2;
                }
                
                $stmtIns = $db->prepare("
                    INSERT INTO matches (tournament_id, bracket_type, round, match_number, next_match_id, next_match_slot, status)
                    VALUES (?, 'winner', ?, ?, ?, ?, 'pending')
                ");
                $stmtIns->execute([$tournamentId, $r, $m, $nextMatchId, $nextMatchSlot]);
                $insertedMatches[$r][$m] = $db->lastInsertId();
            }
        }
        
        // Populate Round 1 matches
        for ($m = 1; $m <= ($P / 2); $m++) {
            $seed1 = $seeding[2 * $m - 2];
            $seed2 = $seeding[2 * $m - 1];
            
            $p1 = isset($seededPlayers[$seed1]) ? $seededPlayers[$seed1] : null;
            $p2 = isset($seededPlayers[$seed2]) ? $seededPlayers[$seed2] : null;
            
            $matchId = $insertedMatches[1][$m];
            $stmtUpd = $db->prepare("UPDATE matches SET player1_id = ?, player2_id = ? WHERE id = ?");
            $stmtUpd->execute([$p1, $p2, $matchId]);
        }
        
    } else {
        // --- Double Elimination Generation ---
        // 1. Create Grand Final Reset (GF-2)
        $stmtIns = $db->prepare("
            INSERT INTO matches (tournament_id, bracket_type, round, match_number, status)
            VALUES (?, 'grand_final_reset', 1, 1, 'pending')
        ");
        $stmtIns->execute([$tournamentId]);
        $gf2_id = $db->lastInsertId();
        
        // 2. Create Grand Final (GF-1)
        $stmtIns = $db->prepare("
            INSERT INTO matches (tournament_id, bracket_type, round, match_number, next_match_id, next_match_slot, status)
            VALUES (?, 'grand_final', 1, 1, ?, 1, 'pending')
        ");
        $stmtIns->execute([$tournamentId, $gf2_id]);
        $gf1_id = $db->lastInsertId();
        
        $insertedWB = [];
        $insertedLB = [];
        
        for ($r = $K; $r >= 1; $r--) {
            if ($r == $K) {
                // Insert LB Finals (Round 2K - 2, 1 match)
                $stmtIns = $db->prepare("
                    INSERT INTO matches (tournament_id, bracket_type, round, match_number, next_match_id, next_match_slot, status)
                    VALUES (?, 'loser', ?, 1, ?, 2, 'pending')
                ");
                $stmtIns->execute([$tournamentId, 2 * $K - 2, $gf1_id]);
                $insertedLB[2 * $K - 2][1] = $db->lastInsertId();
                
                // Insert WB Finals (Round K, 1 match)
                $stmtIns = $db->prepare("
                    INSERT INTO matches (tournament_id, bracket_type, round, match_number, next_match_id, next_match_slot, loser_match_id, loser_match_slot, status)
                    VALUES (?, 'winner', ?, 1, ?, 1, ?, 2, 'pending')
                ");
                $stmtIns->execute([$tournamentId, $K, $gf1_id, $insertedLB[2 * $K - 2][1]]);
                $insertedWB[$K][1] = $db->lastInsertId();
            } else {
                // Insert LB Round 2r - 1 (odd round)
                $numMatchesLB_odd = $P / pow(2, $r + 1);
                for ($m = 1; $m <= $numMatchesLB_odd; $m++) {
                    $nextMatchId = $insertedLB[2 * $r][$m];
                    $stmtIns = $db->prepare("
                        INSERT INTO matches (tournament_id, bracket_type, round, match_number, next_match_id, next_match_slot, status)
                        VALUES (?, 'loser', ?, ?, ?, 1, 'pending')
                    ");
                    $stmtIns->execute([$tournamentId, 2 * $r - 1, $m, $nextMatchId]);
                    $insertedLB[2 * $r - 1][$m] = $db->lastInsertId();
                }
                
                // Insert LB Round 2r - 2 (even round, if r >= 2)
                if ($r >= 2) {
                    $numMatchesLB_even = $P / pow(2, $r);
                    for ($m = 1; $m <= $numMatchesLB_even; $m++) {
                        $nextMatchId = $insertedLB[2 * $r - 1][ceil($m / 2)];
                        $nextMatchSlot = ($m % 2 != 0) ? 1 : 2;
                        
                        $stmtIns = $db->prepare("
                            INSERT INTO matches (tournament_id, bracket_type, round, match_number, next_match_id, next_match_slot, status)
                            VALUES (?, 'loser', ?, ?, ?, ?, 'pending')
                        ");
                        $stmtIns->execute([$tournamentId, 2 * $r - 2, $m, $nextMatchId, $nextMatchSlot]);
                        $insertedLB[2 * $r - 2][$m] = $db->lastInsertId();
                    }
                }
                
                // Insert WB Round r
                $numMatchesWB = $P / pow(2, $r);
                for ($m = 1; $m <= $numMatchesWB; $m++) {
                    $nextMatchId = $insertedWB[$r + 1][ceil($m / 2)];
                    $nextMatchSlot = ($m % 2 != 0) ? 1 : 2;
                    
                    if ($r == 1) {
                        $loserMatchId = $insertedLB[1][ceil($m / 2)];
                        $loserMatchSlot = ($m % 2 != 0) ? 1 : 2;
                    } else {
                        $M = $numMatchesWB; // matches in this round
                        $loserMatchId = $insertedLB[2 * $r - 2][$M - $m + 1];
                        $loserMatchSlot = 2;
                    }
                    
                    $stmtIns = $db->prepare("
                        INSERT INTO matches (tournament_id, bracket_type, round, match_number, next_match_id, next_match_slot, loser_match_id, loser_match_slot, status)
                        VALUES (?, 'winner', ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmtIns->execute([$tournamentId, $r, $m, $nextMatchId, $nextMatchSlot, $loserMatchId, $loserMatchSlot]);
                    $insertedWB[$r][$m] = $db->lastInsertId();
                }
            }
        }
        
        // Populate WB Round 1 matches
        for ($m = 1; $m <= ($P / 2); $m++) {
            $seed1 = $seeding[2 * $m - 2];
            $seed2 = $seeding[2 * $m - 1];
            
            $p1 = isset($seededPlayers[$seed1]) ? $seededPlayers[$seed1] : null;
            $p2 = isset($seededPlayers[$seed2]) ? $seededPlayers[$seed2] : null;
            
            $matchId = $insertedWB[1][$m];
            $stmtUpd = $db->prepare("UPDATE matches SET player1_id = ?, player2_id = ? WHERE id = ?");
            $stmtUpd->execute([$p1, $p2, $matchId]);
        }
    }
    
    // Update tournament status to active
    $stmtUpd = $db->prepare("UPDATE tournaments SET status = 'active' WHERE id = ?");
    $stmtUpd->execute([$tournamentId]);
    
    // Resolve byes and set readiness
    resolveMatches($db, $tournamentId);
    return true;
}

/**
 * Recursively resolves byes and sets matches to 'ready' when both players are set.
 * @param PDO $db
 * @param int $tournamentId
 */
function resolveMatches($db, $tournamentId) {
    $changed = true;
    while ($changed) {
        $changed = false;
        
        // Fetch all matches in tournament that are not completed
        $stmt = $db->prepare("SELECT * FROM matches WHERE tournament_id = ? AND status != 'completed'");
        $stmt->execute([$tournamentId]);
        $matches = $stmt->fetchAll();
        
        foreach ($matches as $match) {
            $matchId = $match['id'];
            
            // Skip GF-2 (Reset match) until GF-1 completes and activates it
            if ($match['bracket_type'] === 'grand_final_reset' && $match['status'] !== 'ready') {
                continue;
            }
            
            // Check slot 1 resolution
            $slot1_resolved = false;
            $slot1_player = $match['player1_id'];
            if ($slot1_player !== null) {
                $slot1_resolved = true;
            } else {
                // Find if there is a source match feeding slot 1
                $stmtSrc = $db->prepare("
                    SELECT * FROM matches 
                    WHERE (next_match_id = ? AND next_match_slot = 1)
                       OR (loser_match_id = ? AND loser_match_slot = 1)
                ");
                $stmtSrc->execute([$matchId, $matchId]);
                $src = $stmtSrc->fetch();
                if (!$src) {
                    // Seed slot, no source match, so it's a BYE
                    $slot1_resolved = true;
                } else if ($src['status'] === 'completed') {
                    $slot1_resolved = true;
                }
            }
            
            // Check slot 2 resolution
            $slot2_resolved = false;
            $slot2_player = $match['player2_id'];
            if ($slot2_player !== null) {
                $slot2_resolved = true;
            } else {
                // Find if there is a source match feeding slot 2
                $stmtSrc = $db->prepare("
                    SELECT * FROM matches 
                    WHERE (next_match_id = ? AND next_match_slot = 2)
                       OR (loser_match_id = ? AND loser_match_slot = 2)
                ");
                $stmtSrc->execute([$matchId, $matchId]);
                $src = $stmtSrc->fetch();
                if (!$src) {
                    // Seed slot, no source match, so it's a BYE
                    $slot2_resolved = true;
                } else if ($src['status'] === 'completed') {
                    $slot2_resolved = true;
                }
            }
            
            if ($slot1_resolved && $slot2_resolved) {
                if ($slot1_player !== null && $slot2_player !== null) {
                    // Both players present. Make 'ready' if currently pending
                    if ($match['status'] === 'pending') {
                        $stmtUpd = $db->prepare("UPDATE matches SET status = 'ready' WHERE id = ?");
                        $stmtUpd->execute([$matchId]);
                        $changed = true;
                    }
                } else if ($slot1_player !== null && $slot2_player === null) {
                    // Player 1 wins by bye!
                    $stmtUpd = $db->prepare("UPDATE matches SET status = 'completed', winner_id = ?, loser_id = NULL WHERE id = ?");
                    $stmtUpd->execute([$slot1_player, $matchId]);
                    
                    // Advance player 1
                    if ($match['next_match_id'] !== null) {
                        $slotCol = ($match['next_match_slot'] == 1) ? 'player1_id' : 'player2_id';
                        $stmtAdv = $db->prepare("UPDATE matches SET $slotCol = ? WHERE id = ?");
                        $stmtAdv->execute([$slot1_player, $match['next_match_id']]);
                    }
                    $changed = true;
                } else if ($slot1_player === null && $slot2_player !== null) {
                    // Player 2 wins by bye!
                    $stmtUpd = $db->prepare("UPDATE matches SET status = 'completed', winner_id = ?, loser_id = NULL WHERE id = ?");
                    $stmtUpd->execute([$slot2_player, $matchId]);
                    
                    // Advance player 2
                    if ($match['next_match_id'] !== null) {
                        $slotCol = ($match['next_match_slot'] == 1) ? 'player1_id' : 'player2_id';
                        $stmtAdv = $db->prepare("UPDATE matches SET $slotCol = ? WHERE id = ?");
                        $stmtAdv->execute([$slot2_player, $match['next_match_id']]);
                    }
                    $changed = true;
                } else {
                    // Both are BYEs (double bye)
                    $stmtUpd = $db->prepare("UPDATE matches SET status = 'completed', winner_id = NULL, loser_id = NULL WHERE id = ?");
                    $stmtUpd->execute([$matchId]);
                    
                    // Advance NULL
                    if ($match['next_match_id'] !== null) {
                        $slotCol = ($match['next_match_slot'] == 1) ? 'player1_id' : 'player2_id';
                        $stmtAdv = $db->prepare("UPDATE matches SET $slotCol = NULL WHERE id = ?");
                        $stmtAdv->execute([$match['next_match_id']]);
                    }
                    $changed = true;
                }
            }
        }
    }
}

/**
 * Validates if a set score is completed according to Badminton rules.
 * @param int $p1
 * @param int $p2
 * @return bool
 */
function isValidCompletedSetScore($p1, $p2) {
    if ($p1 < 0 || $p2 < 0 || $p1 > 40 || $p2 > 40) return false;
    
    // Standard win (30 points, opponent <= 28)
    if ($p1 == 30 && $p2 <= 28) return true;
    if ($p2 == 30 && $p1 <= 28) return true;
    
    // Win by 2 (between 31 and 40 points)
    if ($p1 >= 31 && $p1 <= 40 && ($p1 - $p2) == 2) return true;
    if ($p2 >= 31 && $p2 <= 40 && ($p2 - $p1) == 2) return true;
    
    // Cap win (40-39)
    if ($p1 == 40 && $p2 == 39) return true;
    if ($p2 == 40 && $p1 == 39) return true;
    
    return false;
}

/**
 * Validates if a set score is currently valid in-progress.
 * @param int $p1
 * @param int $p2
 * @return bool
 */
function isValidInProgressSetScore($p1, $p2) {
    if ($p1 < 0 || $p2 < 0 || $p1 >= 40 || $p2 >= 40) return false;
    
    // If a player reached 30 or more, the difference must be at most 1 (otherwise it is completed)
    if ($p1 >= 30 && ($p1 - $p2) > 1) return false;
    if ($p2 >= 30 && ($p2 - $p1) > 1) return false;
    
    // Cannot be already completed
    if (isValidCompletedSetScore($p1, $p2)) return false;
    
    return true;
}

/**
 * Updates a match's live scores, validates them, and advances players if the match is completed.
 * @param PDO $db
 * @param int $matchId
 * @param array $setScores Array of sets, e.g. [1 => ['p1' => 21, 'p2' => 10], 2 => ['p1' => 20, 'p2' => 22], ...]
 * @return array ['success' => bool, 'message' => string]
 */
function updateMatchScore($db, $matchId, $setScores) {
    // 1. Fetch match and tournament details
    $stmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) {
        return ['success' => false, 'message' => 'Match not found.'];
    }
    
    if ($match['status'] === 'completed') {
        return ['success' => false, 'message' => 'Match is already completed.'];
    }
    
    $p1_id = $match['player1_id'];
    $p2_id = $match['player2_id'];
    
    if ($p1_id === null || $p2_id === null) {
        return ['success' => false, 'message' => 'Cannot score a match that does not have both players.'];
    }
    
    // 2. Validate set scores sequentially
    $setsWon1 = 0;
    $setsWon2 = 0;
    $completedSetsCount = 0;
    
    $cleanScores = [];
    
    for ($s = 1; $s <= 1; $s++) {  // Single set mode
        $p1_score = isset($setScores[$s]['p1']) && $setScores[$s]['p1'] !== '' ? intval($setScores[$s]['p1']) : null;
        $p2_score = isset($setScores[$s]['p2']) && $setScores[$s]['p2'] !== '' ? intval($setScores[$s]['p2']) : null;
        
        // If this set is completely empty
        if ($p1_score === null && $p2_score === null) {
            // Check if we already have a match winner
            if ($setsWon1 == 1 || $setsWon2 == 1) {
                // That's valid (Set 3 is not needed)
                break;
            }
            // Set is not yet played, which is fine, but we cannot have subsequent sets scored
            break;
        }
        
        // If only one score is filled
        if ($p1_score === null || $p2_score === null) {
            return ['success' => false, 'message' => "Set $s score must have values for both players."];
        }
        
        // Validate set scores
        if (isValidCompletedSetScore($p1_score, $p2_score)) {
            $completedSetsCount++;
            if ($p1_score > $p2_score) {
                $setsWon1++;
            } else {
                $setsWon2++;
            }
            $cleanScores[$s] = ['p1' => $p1_score, 'p2' => $p2_score, 'completed' => true];
            
            // Check if we reached a match winner (1 set wins)
            if ($setsWon1 == 1 || $setsWon2 == 1) {
                // Match is completed! Stop validating further sets
                break;
            }
        } else if (isValidInProgressSetScore($p1_score, $p2_score)) {
            // Set is in-progress. This must be the current active set, and subsequent sets must be empty
            $cleanScores[$s] = ['p1' => $p1_score, 'p2' => $p2_score, 'completed' => false];
            
            // Verify no further sets are provided
            for ($nextSet = $s + 1; $nextSet <= 3; $nextSet++) {
                if (isset($setScores[$nextSet]['p1']) && $setScores[$nextSet]['p1'] !== '' ||
                    isset($setScores[$nextSet]['p2']) && $setScores[$nextSet]['p2'] !== '') {
                    return ['success' => false, 'message' => "Cannot score Set $nextSet while Set $s is in progress."];
                }
            }
            break; // Stop validating as we hit an in-progress set
        } else {
            return ['success' => false, 'message' => "Invalid score format for Set $s under Badminton rules."];
        }
    }
    
    // Check if the input is valid based on set continuity
    // E.g. cannot score Set 2 if Set 1 is not completed
    if (isset($cleanScores[2]) && !isset($cleanScores[1]['completed'])) {
        return ['success' => false, 'message' => 'Set 1 must be completed before Set 2 can be scored.'];
    }
    if (isset($cleanScores[3]) && !isset($cleanScores[2]['completed'])) {
        return ['success' => false, 'message' => 'Set 2 must be completed before Set 3 can be scored.'];
    }
    
    // 3. Save scores to the database
    // Start Transaction for atomic updates
    $db->beginTransaction();
    try {
        // Delete old set scores for this match
        $stmtDel = $db->prepare("DELETE FROM match_sets WHERE match_id = ?");
        $stmtDel->execute([$matchId]);
        
        // Insert new scores
        $stmtIns = $db->prepare("
            INSERT INTO match_sets (match_id, set_number, player1_score, player2_score)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($cleanScores as $sNumber => $scores) {
            $stmtIns->execute([$matchId, $sNumber, $scores['p1'], $scores['p2']]);
        }
        
        $matchCompleted = ($setsWon1 == 1 || $setsWon2 == 1);
        
        if ($matchCompleted) {
            $winner_id = ($setsWon1 == 1) ? $p1_id : $p2_id;
            $loser_id = ($setsWon1 == 1) ? $p2_id : $p1_id;

            if ($match['player1b_id'] !== null || $match['player2b_id'] !== null) {
                // Doubles match
                $winner_b_id = ($setsWon1 == 1) ? $match['player1b_id'] : $match['player2b_id'];
                $loser_b_id = ($setsWon1 == 1) ? $match['player2b_id'] : $match['player1b_id'];

                $stmtUpd = $db->prepare("
                    UPDATE matches 
                    SET status = 'completed', winner_id = ?, winner_b_id = ?, loser_id = ?, loser_b_id = ?
                    WHERE id = ?
                ");
                $stmtUpd->execute([$winner_id, $winner_b_id, $loser_id, $loser_b_id, $matchId]);
            } else {
                // Singles match
                $stmtUpd = $db->prepare("
                    UPDATE matches 
                    SET status = 'completed', winner_id = ?, loser_id = ? 
                    WHERE id = ?
                ");
                $stmtUpd->execute([$winner_id, $loser_id, $matchId]);
            }

            // Handle Advancement
            if ($match['bracket_type'] === 'grand_final') {
                // GF-1 completed.
                // In Double Elimination, GF Player 1 is WB Winner, Player 2 is LB Winner.
                // If Player 1 wins: Tournament is over!
                if ($winner_id == $p1_id) {
                    // WB Winner won, tournament ends.
                    $stmtTourn = $db->prepare("UPDATE tournaments SET status = 'completed' WHERE id = ?");
                    $stmtTourn->execute([$match['tournament_id']]);
                    
                    // Mark GF-2 (Reset) as completed with no winner (or same winner to show final state)
                    if ($match['next_match_id'] !== null) {
                        $stmtGF2 = $db->prepare("UPDATE matches SET status = 'completed', winner_id = ? WHERE id = ?");
                        $stmtGF2->execute([$winner_id, $match['next_match_id']]);
                    }
                } else {
                    // LB Winner won GF-1, trigger bracket reset!
                    // Activate GF-2 (Reset match)
                    if ($match['next_match_id'] !== null) {
                        $stmtGF2 = $db->prepare("
                            UPDATE matches 
                            SET player1_id = ?, player2_id = ?, status = 'ready' 
                            WHERE id = ?
                        ");
                        $stmtGF2->execute([$p1_id, $p2_id, $match['next_match_id']]);
                    }
                }
            } else if ($match['bracket_type'] === 'grand_final_reset') {
                // GF-2 completed. Tournament ends.
                $stmtTourn = $db->prepare("UPDATE tournaments SET status = 'completed' WHERE id = ?");
                $stmtTourn->execute([$match['tournament_id']]);
            } else {
                // Standard Match Advancement
                // Advance winner
                if ($match['next_match_id'] !== null) {
                    $slotCol = ($match['next_match_slot'] == 1) ? 'player1_id' : 'player2_id';
                    $stmtAdvWinner = $db->prepare("UPDATE matches SET $slotCol = ? WHERE id = ?");
                    $stmtAdvWinner->execute([$winner_id, $match['next_match_id']]);
                }
                
                // Advance loser (for double elimination)
                if ($match['loser_match_id'] !== null) {
                    $slotCol = ($match['loser_match_slot'] == 1) ? 'player1_id' : 'player2_id';
                    $stmtAdvLoser = $db->prepare("UPDATE matches SET $slotCol = ? WHERE id = ?");
                    $stmtAdvLoser->execute([$loser_id, $match['loser_match_id']]);
                }
            }
        } else {
            // Match is still live
            $stmtUpd = $db->prepare("UPDATE matches SET status = 'live' WHERE id = ?");
            $stmtUpd->execute([$matchId]);
        }
        
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
    
    // Resolve any further ready matches/byes after advancement
    resolveMatches($db, $match['tournament_id']);
    
    // Check if we need to generate the next round for King Court
    $stmtTourn = $db->prepare("SELECT type, num_rounds FROM tournaments WHERE id = ?");
    $stmtTourn->execute([$match['tournament_id']]);
    $tDetails = $stmtTourn->fetch();
    if ($tDetails && $tDetails['type'] === 'king_court') {
        // Find current round
        $stmtMax = $db->prepare("SELECT MAX(round) FROM matches WHERE tournament_id = ?");
        $stmtMax->execute([$match['tournament_id']]);
        $currRound = $stmtMax->fetchColumn() ?: 1;

        // Check if all matches in the current round are completed
        $stmtInc = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round = ? AND status != 'completed'");
        $stmtInc->execute([$match['tournament_id'], $currRound]);
        $incRoundCount = $stmtInc->fetchColumn();

        if ($incRoundCount == 0 && $currRound < $tDetails['num_rounds']) {
            generateKingCourtNextRound($db, $match['tournament_id']);
        }
    }
    
    // Check if the tournament is completely finished (e.g. all matches completed)
    checkTournamentCompletion($db, $match['tournament_id']);
    
    return ['success' => true, 'message' => $matchCompleted ? 'Match completed and bracket updated!' : 'Scores updated successfully!'];
}

/**
 * Checks if all matches in a tournament are completed and marks the tournament as completed.
 * @param PDO $db
 * @param int $tournamentId
 */
function checkTournamentCompletion($db, $tournamentId) {
    $stmt = $db->prepare("SELECT type, num_rounds FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch();
    if (!$tournament) return;

    if ($tournament['type'] === 'king_court') {
        $stmtMax = $db->prepare("SELECT MAX(round) FROM matches WHERE tournament_id = ?");
        $stmtMax->execute([$tournamentId]);
        $maxRound = $stmtMax->fetchColumn();

        if ($maxRound < $tournament['num_rounds']) {
            return; // More rounds to generate
        }
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND status != 'completed'");
    $stmt->execute([$tournamentId]);
    $incompleteCount = $stmt->fetchColumn();
    
    if ($incompleteCount == 0) {
        $stmtUpd = $db->prepare("UPDATE tournaments SET status = 'completed' WHERE id = ?");
        $stmtUpd->execute([$tournamentId]);
    }
}

/**
 * Generates Round 1 for a King Court Doubles tournament.
 * @param PDO $db
 * @param int $tournamentId
 * @param int $numRounds
 * @return bool
 */
function generateKingCourtBracket($db, $tournamentId, $numRounds = 5) {
    // 1. Fetch all tournament players
    $stmt = $db->prepare("
        SELECT player_id
        FROM tournament_players
        WHERE tournament_id = ?
        ORDER BY seed ASC
    ");
    $stmt->execute([$tournamentId]);
    $playerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $n = count($playerIds);
    if ($n < 4) return false;

    // 2. Delete any existing matches to prevent duplicates
    $stmt = $db->prepare("DELETE FROM matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);

    // 3. Shuffle players for Round 1
    shuffle($playerIds);

    // 4. Pair in groups of 4
    $numMatches = floor($n / 4);
    $selectedPlayers = array_slice($playerIds, 0, $numMatches * 4);

    $db->beginTransaction();
    try {
        $stmtIns = $db->prepare("
            INSERT INTO matches (tournament_id, bracket_type, round, match_number, player1_id, player1b_id, player2_id, player2b_id, status)
            VALUES (?, 'winner', 1, ?, ?, ?, ?, ?, 'ready')
        ");

        for ($m = 0; $m < $numMatches; $m++) {
            $four = array_slice($selectedPlayers, $m * 4, 4);
            // In Round 1, pair P1+P2 vs P3+P4
            $stmtIns->execute([
                $tournamentId,
                $m + 1,
                $four[0],
                $four[1],
                $four[2],
                $four[3]
            ]);
        }

        // Activate tournament
        $stmtUpd = $db->prepare("UPDATE tournaments SET status = 'active' WHERE id = ?");
        $stmtUpd->execute([$tournamentId]);

        $db->commit();
        return true;
    } catch (\Exception $e) {
        $db->rollBack();
        return false;
    }
}

/**
 * Generates the next round for a King Court Doubles tournament.
 * Group players by play count & standings, rotate partners, and insert new matches.
 * @param PDO $db
 * @param int $tournamentId
 * @return bool
 */
function generateKingCourtNextRound($db, $tournamentId) {
    // 1. Fetch tournament details
    $stmt = $db->prepare("SELECT type, num_rounds FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch();
    if (!$tournament || $tournament['type'] !== 'king_court') return false;

    // 2. Determine next round number
    $stmtMax = $db->prepare("SELECT MAX(round) FROM matches WHERE tournament_id = ?");
    $stmtMax->execute([$tournamentId]);
    $currentRound = intval($stmtMax->fetchColumn() ?: 0);
    $nextRound = $currentRound + 1;

    if ($nextRound > intval($tournament['num_rounds'])) {
        return false; // Tournament is completed
    }

    // 3. Fetch all player IDs and standings (win/loss count)
    $standings = getRandomDoublesStandings($db, $tournamentId);
    $playerIds = array_column($standings, 'id');
    $n = count($playerIds);
    if ($n < 4) return false;

    $numMatches = floor($n / 4);

    // 4. Group players by play count to prioritize those who sat out
    $byPlayed = [];
    foreach ($standings as $p) {
        $byPlayed[$p['played']][] = $p;
    }
    ksort($byPlayed);

    $selectedPlayers = [];
    $targetCount = $numMatches * 4;
    foreach ($byPlayed as $playedCount => $group) {
        // Shuffle within the group to randomize sit-outs fairly
        shuffle($group);
        foreach ($group as $p) {
            if (count($selectedPlayers) < $targetCount) {
                $selectedPlayers[] = $p;
            }
        }
    }

    // Sort the selected players by their overall standings (highest wins first)
    $selectedIds = array_column($selectedPlayers, 'id');
    $sortedSelected = [];
    foreach ($standings as $p) {
        if (in_array($p['id'], $selectedIds)) {
            $sortedSelected[] = $p['id'];
        }
    }

    // 5. Build partner and opponent history matrices
    $partnerHistory = [];
    $opponentHistory = [];
    foreach ($playerIds as $p1) {
        foreach ($playerIds as $p2) {
            $partnerHistory[$p1][$p2] = 0;
            $opponentHistory[$p1][$p2] = 0;
        }
    }

    $stmtMatches = $db->prepare("
        SELECT player1_id, player1b_id, player2_id, player2b_id
        FROM matches
        WHERE tournament_id = ?
    ");
    $stmtMatches->execute([$tournamentId]);
    foreach ($stmtMatches->fetchAll() as $m) {
        $p1a = $m['player1_id'];
        $p1b = $m['player1b_id'];
        $p2a = $m['player2_id'];
        $p2b = $m['player2b_id'];

        if ($p1a && $p1b) {
            $partnerHistory[$p1a][$p1b]++;
            $partnerHistory[$p1b][$p1a]++;
        }
        if ($p2a && $p2b) {
            $partnerHistory[$p2a][$p2b]++;
            $partnerHistory[$p2b][$p2a]++;
        }
        if ($p1a && $p2a) {
            $opponentHistory[$p1a][$p2a]++; $opponentHistory[$p2a][$p1a]++;
        }
        if ($p1a && $p2b) {
            $opponentHistory[$p1a][$p2b]++; $opponentHistory[$p2b][$p1a]++;
        }
        if ($p1b && $p2a) {
            $opponentHistory[$p1b][$p2a]++; $opponentHistory[$p2a][$p1b]++;
        }
        if ($p1b && $p2b) {
            $opponentHistory[$p1b][$p2b]++; $opponentHistory[$p2b][$p1b]++;
        }
    }

    $db->beginTransaction();
    try {
        $stmtIns = $db->prepare("
            INSERT INTO matches (tournament_id, bracket_type, round, match_number, player1_id, player1b_id, player2_id, player2b_id, status)
            VALUES (?, 'winner', ?, ?, ?, ?, ?, ?, 'ready')
        ");

        for ($c = 0; $c < $numMatches; $c++) {
            $four = array_slice($sortedSelected, $c * 4, 4);

            // Three configurations to split 4 players into 2 teams
            $configs = [
                [['p1' => $four[0], 'p2' => $four[1]], ['p1' => $four[2], 'p2' => $four[3]]], // P1+P2 vs P3+P4
                [['p1' => $four[0], 'p2' => $four[3]], ['p1' => $four[1], 'p2' => $four[2]]], // P1+P4 vs P2+P3
                [['p1' => $four[0], 'p2' => $four[2]], ['p1' => $four[1], 'p2' => $four[3]]]  // P1+P3 vs P2+P4
            ];

            $bestConfigIdx = 0;
            $minPenalty = INF;

            foreach ($configs as $idx => $conf) {
                $t1_p1 = $conf[0]['p1'];
                $t1_p2 = $conf[0]['p2'];
                $t2_p1 = $conf[1]['p1'];
                $t2_p2 = $conf[1]['p2'];

                // Partnership repetition penalty (weighted high to force partner rotation)
                $p_penalty = $partnerHistory[$t1_p1][$t1_p2] + $partnerHistory[$t2_p1][$t2_p2];
                // Opponent repetition penalty (weighted low)
                $o_penalty = $opponentHistory[$t1_p1][$t2_p1] + $opponentHistory[$t1_p1][$t2_p2] +
                             $opponentHistory[$t1_p2][$t2_p1] + $opponentHistory[$t1_p2][$t2_p2];

                $penalty = $p_penalty * 10 + $o_penalty;
                if ($penalty < $minPenalty) {
                    $minPenalty = $penalty;
                    $bestConfigIdx = $idx;
                }
            }

            $bestConf = $configs[$bestConfigIdx];

            // In subsequent rounds, dynamically label bracket_type:
            // Since we can only store winner/loser/etc., we keep 'winner' for DB ENUM compatibility.
            // Court labels (Winner Court / Loser Court) will be determined in rendering.
            $stmtIns->execute([
                $tournamentId,
                $nextRound,
                $c + 1,
                $bestConf[0]['p1'],
                $bestConf[0]['p2'],
                $bestConf[1]['p1'],
                $bestConf[1]['p2']
            ]);
        }

        $db->commit();
        return true;
    } catch (\Exception $e) {
        $db->rollBack();
        return false;
    }
}
