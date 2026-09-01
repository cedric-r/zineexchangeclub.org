#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Pairing Simulation — dry-run: no DB writes, no emails.
 *
 * Reads confirmed participants from the database and simulates what
 * each pairing algorithm would produce.
 *
 * Usage:
 *   php scripts/simulate_pairing.php                # all active cycles, configured algorithm
 *   php scripts/simulate_pairing.php --all           # all cycles × all algorithms
 *   php scripts/simulate_pairing.php --cycle=3       # specific cycle ID
 *   php scripts/simulate_pairing.php --algo=random   # specific algorithm only
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/pairing_algorithms.php';

// ── Parse CLI args ─────────────────────────────────────────────────

$options = getopt('', ['all', 'cycle:', 'algo:', 'help']);

if (isset($options['help'])) {
    echo "Pairing Simulation — dry-run: no DB writes, no emails.\n\n";
    echo "Usage:\n";
    echo "  php scripts/simulate_pairing.php                # active cycles, configured algorithm\n";
    echo "  php scripts/simulate_pairing.php --all           # all cycles × all algorithms\n";
    echo "  php scripts/simulate_pairing.php --cycle=3       # specific cycle ID\n";
    echo "  php scripts/simulate_pairing.php --algo=random   # specific algorithm only\n";
    echo "  php scripts/simulate_pairing.php --help          # this message\n\n";
    echo "Requires a config.php with access to the production database.\n";
    echo "The current config.php uses: " . DB_NAME . "\n";
    exit(0);
}

$showAll     = isset($options['all']);
$cycleFilter = isset($options['cycle']) ? (int)$options['cycle'] : null;
$algoFilter  = $options['algo'] ?? null;

// ── Get data (read-only) ───────────────────────────────────────────

$db = getDB();

// Verify we're connected to a real database (not test in-memory)
try {
    $db->query("SELECT COUNT(*) FROM cycles");
} catch (PDOException $e) {
    echo "Error: Cannot access the database. The current config.php is likely the\n";
    echo "test configuration (SQLite in-memory). To run the simulation, you need\n";
    echo "a config.php pointing at your production database.\n\n";
    echo "Copy config.php.sample to config.php and update the credentials:\n";
    echo "  DB_HOST=" . DB_HOST . "\n";
    echo "  DB_NAME=" . DB_NAME . "\n\n";
    echo "Or create a config.local.php and run:\n";
    echo "  php -d auto_prepend_file=config.local.php scripts/simulate_pairing.php\n";
    exit(1);
}

// Build cycle query
if ($cycleFilter) {
    $cycles = $db->prepare("SELECT * FROM cycles WHERE id = ?");
    $cycles->execute([$cycleFilter]);
    $cycles = $cycles->fetchAll();
} else {
    $cycles = $db->query("SELECT * FROM cycles WHERE status = 'active' ORDER BY start_date DESC")->fetchAll();
}

if ($showAll) {
    $cycles = $db->query("SELECT * FROM cycles ORDER BY start_date DESC")->fetchAll();
}

if (empty($cycles)) {
    echo "No cycles found.\n";
    exit(0);
}

// Determine which algorithms to simulate
$algorithms = $algoFilter
    ? (in_array($algoFilter, PairingAlgorithmFactory::getAvailableAlgorithms()) ? [$algoFilter] : [])
    : ($showAll ? PairingAlgorithmFactory::getAvailableAlgorithms() : [PAIRING_ALGORITHM]);

if (empty($algorithms)) {
    echo "Unknown algorithm: {$algoFilter}\n";
    echo "Available: " . implode(', ', PairingAlgorithmFactory::getAvailableAlgorithms()) . "\n";
    exit(1);
}

// Pre-fetch all users (for names)
$users = [];
foreach ($db->query("SELECT id, name, email, country FROM users")->fetchAll() as $u) {
    $users[(int)$u['id']] = $u;
}

$regionMap = (new ReflectionClass(GeographicProximityAlgorithm::class))
    ->getProperty('regionMap')
    ->getValue(new GeographicProximityAlgorithm());

$countryAliases = GeographicProximityAlgorithm::COUNTRY_ALIASES;

// ── Simulation helpers ─────────────────────────────────────────────

function normalizeCountry(string $country, array $aliases): string {
    $lower = strtolower(trim($country));
    return $aliases[$lower] ?? $lower;
}

function getRegion(string $country, array $map, array $aliases): ?string {
    return $map[normalizeCountry($country, $aliases)] ?? null;
}

function pairScore(string $c1, string $c2, array $map, array $aliases): int {
    $n1 = normalizeCountry($c1, $aliases);
    $n2 = normalizeCountry($c2, $aliases);
    if (strcasecmp($n1, $n2) === 0) return 3;
    $r1 = getRegion($n1, $map, $aliases);
    $r2 = getRegion($n2, $map, $aliases);
    if ($r1 !== null && $r2 !== null && $r1 === $r2) return 2;
    return 0;
}

function simulateCountryPriority(array $participants, array $aliases): array {
    $byCountry = [];
    foreach ($participants as $p) {
        $country = normalizeCountry($p['country'], $aliases);
        $byCountry[$country][] = $p['user_id'];
    }
    uasort($byCountry, fn($a, $b) => count($b) - count($a));

    $ordered = [];
    $remaining = [];
    foreach ($byCountry as $userIds) {
        if (count($userIds) >= 2) {
            for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                $ordered[] = (int)$userIds[$i];
                $ordered[] = (int)$userIds[$i + 1];
            }
            if (count($userIds) % 2 === 1) $remaining[] = (int)end($userIds);
        } else {
            $remaining[] = (int)$userIds[0];
        }
    }
    $ordered = array_merge($ordered, $remaining);

    $pairs = [];
    for ($i = 0; $i < count($ordered) - 1; $i += 2) {
        $pairs[] = [$ordered[$i], $ordered[$i + 1]];
    }
    return $pairs;
}

function simulateRandom(array $participants): array {
    $ids = array_map(fn($p) => (int)$p['user_id'], $participants);
    shuffle($ids);
    $pairs = [];
    for ($i = 0; $i < count($ids) - 1; $i += 2) {
        $pairs[] = [$ids[$i], $ids[$i + 1]];
    }
    return $pairs;
}

function simulateSequential(array $participants): array {
    $sorted = $participants;
    usort($sorted, fn($a, $b) => ($a['created_at'] ?? '') <=> ($b['created_at'] ?? ''));
    $ids = array_map(fn($p) => (int)$p['user_id'], $sorted);
    $pairs = [];
    for ($i = 0; $i < count($ids) - 1; $i += 2) {
        $pairs[] = [$ids[$i], $ids[$i + 1]];
    }
    return $pairs;
}

function simulateZineType(array $participants): array {
    $byFormat = [];
    foreach ($participants as $p) {
        $format = ($p['zine_format'] ?? '') ?: 'other';
        $byFormat[$format][] = (int)$p['user_id'];
    }
    uasort($byFormat, fn($a, $b) => count($b) - count($a));

    $ordered = [];
    $remaining = [];
    foreach ($byFormat as $userIds) {
        if (count($userIds) >= 2) {
            for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                $ordered[] = $userIds[$i];
                $ordered[] = $userIds[$i + 1];
            }
            if (count($userIds) % 2 === 1) $remaining[] = (int)end($userIds);
        } else {
            $remaining[] = $userIds[0];
        }
    }
    $ordered = array_merge($ordered, $remaining);

    $pairs = [];
    for ($i = 0; $i < count($ordered) - 1; $i += 2) {
        $pairs[] = [$ordered[$i], $ordered[$i + 1]];
    }
    return $pairs;
}

function simulateCountryZineType(array $participants, array $aliases): array {
    $byKey = [];
    foreach ($participants as $p) {
        $country = normalizeCountry($p['country'], $aliases);
        $key = $country . '|' . (($p['zine_format'] ?? '') ?: 'other');
        $byKey[$key][] = (int)$p['user_id'];
    }
    uasort($byKey, fn($a, $b) => count($b) - count($a));

    $ordered = [];
    $remaining = [];
    foreach ($byKey as $userIds) {
        if (count($userIds) >= 2) {
            for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                $ordered[] = $userIds[$i];
                $ordered[] = $userIds[$i + 1];
            }
            if (count($userIds) % 2 === 1) $remaining[] = (int)end($userIds);
        } else {
            $remaining[] = $userIds[0];
        }
    }
    $ordered = array_merge($ordered, $remaining);

    $pairs = [];
    for ($i = 0; $i < count($ordered) - 1; $i += 2) {
        $pairs[] = [$ordered[$i], $ordered[$i + 1]];
    }
    return $pairs;
}

function formatMatch(array $p1, array $p2): bool {
    $f1 = ($p1['zine_format'] ?? '') ?: '';
    $f2 = ($p2['zine_format'] ?? '') ?: '';
    return $f1 !== '' && $f2 !== '' && strcasecmp($f1, $f2) === 0;
}

function simulateGeographicProximity(array $participants, array $map, array $aliases): array {
    $bestPairs = null;
    $bestScore = -1;
    $total = count($participants);
    $maxScore = (int)($total / 2) * 3;
    $maxIterations = max(50, min(500, count($participants) * 10));

    for ($iter = 0; $iter < $maxIterations; $iter++) {
        shuffle($participants);
        $remaining = $participants;
        $pairs = [];
        $score = 0;

        while (count($remaining) >= 2) {
            $p1 = array_shift($remaining);
            $bestIdx = -1;
            $bestMatch = -1;
            $bestMatchP2 = [];

            foreach ($remaining as $idx => $p2) {
                $s = pairScore($p1['country'], $p2['country'], $map, $aliases);
                if ($s > $bestMatch || ($s === $bestMatch && $s > 0 && formatMatch($p1, $p2) && !formatMatch($p1, $bestMatchP2))) {
                    $bestMatch = $s;
                    $bestIdx = $idx;
                    $bestMatchP2 = $p2;
                }
            }

            $p2 = array_splice($remaining, $bestIdx, 1)[0];
            $pairs[] = [(int)$p1['user_id'], (int)$p2['user_id']];
            $score += $bestMatch;
        }

        if ($score > $bestScore) { $bestScore = $score; $bestPairs = $pairs; }
        if ($score === $maxScore) break;
    }

    return $bestPairs ?? [];
}

function simulateContinentNovelty(array $participants, array $map, array $aliases, array $already): array {
    $bestPairs = null;
    $bestScore = -1;
    $total = count($participants);
    $maxScore = (int)($total / 2) * 3;
    $maxIterations = max(50, min(500, count($participants) * 10));

    for ($iter = 0; $iter < $maxIterations; $iter++) {
        shuffle($participants);
        $remaining = $participants;
        $pairs = [];
        $score = 0;

        while (count($remaining) >= 2) {
            $p1 = array_shift($remaining);
            $bestIdx = -1;
            $bestMatch = -1;

            foreach ($remaining as $idx => $p2) {
                $s = pairScoreNovelty(
                    $p1['country'],
                    $p2['country'],
                    haveBeenPairedSim((int)$p1['user_id'], (int)$p2['user_id'], $already),
                    $map,
                    $aliases
                );
                if ($s > $bestMatch) {
                    $bestMatch = $s;
                    $bestIdx = $idx;
                }
            }

            $p2 = array_splice($remaining, $bestIdx, 1)[0];
            $pairs[] = [(int)$p1['user_id'], (int)$p2['user_id']];
            $score += $bestMatch;
        }

        if ($score > $bestScore) { $bestScore = $score; $bestPairs = $pairs; }
        if ($score === $maxScore) break;
    }

    return $bestPairs ?? [];
}

/**
 * Continent Novelty scoring: avoiding repeats is primary, same-continent
 * is a secondary preference. Mirrors ContinentNoveltyAlgorithm::pairScore.
 */
function pairScoreNovelty(string $c1, string $c2, bool $pairedBefore, array $map, array $aliases): int {
    $r1 = getRegion($c1, $map, $aliases);
    $r2 = getRegion($c2, $map, $aliases);
    $sameContinent = $r1 !== null && $r2 !== null && $r1 === $r2;

    if (!$pairedBefore && $sameContinent) return 3;
    if (!$pairedBefore) return 2;
    if ($sameContinent) return 1;
    return 0;
}

function haveBeenPairedSim(int $a, int $b, array $already): bool {
    if ($a > $b) { $tmp = $a; $a = $b; $b = $tmp; }
    return isset($already["$a-$b"]);
}

/**
 * Load the set of unordered "a-b" pairs already paired in any other cycle.
 */
function loadHistoricalPairingsForSim(PDO $db, int $cycleId): array {
    $already = [];
    $stmt = $db->prepare("SELECT user_id, partner_id FROM cycle_pairings WHERE cycle_id != ?");
    $stmt->execute([$cycleId]);
    foreach ($stmt->fetchAll() as $row) {
        $a = (int)$row['user_id'];
        $b = (int)$row['partner_id'];
        if ($a > $b) { $tmp = $a; $a = $b; $b = $tmp; }
        $already["$a-$b"] = true;
    }
    return $already;
}

// ── User name helper ───────────────────────────────────────────────

function userName(int $id, array $users): string {
    $u = $users[$id] ?? null;
    return $u ? $u['name'] . " <{$u['email']}>" : "#{$id}";
}

function userCountry(int $id, array $users): string {
    return $users[$id]['country'] ?? '??';
}

function userFormat(int $id, array $participants): string {
    foreach ($participants as $p) {
        if ((int)$p['user_id'] === $id) return ($p['zine_format'] ?? '') ?: '—';
    }
    return '—';
}

// ── Run simulation ─────────────────────────────────────────────────

foreach ($cycles as $cycle) {
    $cycleId = (int)$cycle['id'];
    echo str_repeat('═', 72) . "\n";
    echo "  Cycle: {$cycle['name']}  (ID: {$cycleId})  Status: {$cycle['status']}\n";
    echo str_repeat('═', 72) . "\n";

    // Get participants (reads user_id, name, email, country, created_at, zine_format)
    $stmt = $db->prepare("
        SELECT cp.user_id, u.name, u.email, u.country, u.created_at, z.format as zine_format
        FROM cycle_participations cp
        JOIN users u ON cp.user_id = u.id
        LEFT JOIN zines z ON cp.user_id = z.user_id
        WHERE cp.cycle_id = ?
          AND cp.participation_confirmed = 1
          AND cp.wants_to_participate = 1
        ORDER BY u.name ASC
    ");
    $stmt->execute([$cycleId]);
    $participants = $stmt->fetchAll();

    // Convert IDs to ints
    foreach ($participants as &$p) { $p['user_id'] = (int)$p['user_id']; }
    unset($p);

    if (count($participants) < 2) {
        echo "  Participants: " . count($participants) . " (need at least 2)\n\n";
        continue;
    }

    // Show participant list
    $regStatus  = $cycle['registration_open'] ? 'Registration Open' : 'Registration Closed';
    $pairStatus = $cycle['pairing_done'] ? 'Already paired' : 'Not yet paired';
    echo "\n  Participants ({$regStatus}, {$pairStatus}):\n";
    echo str_repeat('─', 72) . "\n";
    printf("  %-4s %-24s %-16s %-16s %s\n", 'ID', 'Name', 'Country', 'Zine Format', 'Reg. Order');
    echo str_repeat('─', 72) . "\n";
    foreach ($participants as $i => $p) {
        printf("  %-4d %-24.24s %-16s %-16s #%d\n",
            $p['user_id'], $p['name'], $p['country'],
            $p['zine_format'] ?: '—', $i + 1);
    }

    // Historical pairings (needed by continent_novelty) — read-only
    $already = loadHistoricalPairingsForSim($db, $cycleId);

    // Run each algorithm
    foreach ($algorithms as $algoName) {
        echo "\n  ┌─ Algorithm: {$algoName}\n";
        echo "  │\n";

        $simStart = microtime(true);

        $pairs = match($algoName) {
            'country_priority'      => simulateCountryPriority($participants, $countryAliases),
            'random'                => simulateRandom($participants),
            'sequential'            => simulateSequential($participants),
            'zine_type'             => simulateZineType($participants),
            'country_zine_type'     => simulateCountryZineType($participants, $countryAliases),
            'geographic_proximity'  => simulateGeographicProximity($participants, $regionMap, $countryAliases),
            'continent_novelty'     => simulateContinentNovelty($participants, $regionMap, $countryAliases, $already),
            default                 => [],
        };

        $elapsed = (microtime(true) - $simStart) * 1000;
        $pairedCount = count($pairs) * 2;
        $unpaired = count($participants) - $pairedCount;

        echo "  │   Pairs: {$pairedCount}/" . count($participants) . " participants\n";
        if ($unpaired > 0) {
            echo "  │   ⚠  {$unpaired} participant(s) left unpaired (odd count)\n";
        }
        echo "  │   Time: " . number_format($elapsed, 2) . " ms\n";
        echo "  │\n";

        foreach ($pairs as $idx => [$id1, $id2]) {
            $n1 = userName($id1, $users);
            $n2 = userName($id2, $users);
            $c1 = userCountry($id1, $users);
            $c2 = userCountry($id2, $users);
            $f1 = userFormat($id1, $participants);
            $f2 = userFormat($id2, $participants);

            // Pair quality indicators
            $nc1 = normalizeCountry($c1, $countryAliases);
            $nc2 = normalizeCountry($c2, $countryAliases);
            $sameCountry = strcasecmp($nc1, $nc2) === 0 ? '✓ same country' : '';
            $sameFormat  = ($f1 !== '—' && $f2 !== '—' && $f1 === $f2) ? '✓ same format' : '';
            $sameRegion  = '';
            if (!$sameCountry) {
                $r1 = getRegion($nc1, $regionMap, $countryAliases);
                $r2 = getRegion($nc2, $regionMap, $countryAliases);
                if ($r1 !== null && $r2 !== null && $r1 === $r2) $sameRegion = "✓ same region ({$r1})";
            }

            $quality = implode(', ', array_filter([$sameCountry, $sameRegion, $sameFormat]));
            $quality = $quality ? " [{$quality}]" : ' [cross-region]';

            printf("  │   %2d. %-24s ↔  %-24s%s\n", $idx + 1, $n1, $n2, $quality);
        }

        echo "  └──\n";
    }

    echo "\n";
}

echo str_repeat('═', 72) . "\n";
echo "  Simulation complete — no data was modified, no emails sent.\n";
echo str_repeat('═', 72) . "\n";
