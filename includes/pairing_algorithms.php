<?php
declare(strict_types=1);
/**
 * Pairing Algorithm Plugin System
 * Different algorithms for pairing participants in exchange cycles
 */

interface PairingAlgorithm {
    /**
     * Pair participants for a cycle
     * @param PDO $db Database connection
     * @param int $cycleId Cycle ID
     * @return bool Success status
     */
    public function pair($db, $cycleId);
}

/**
 * Country Priority Algorithm - Prioritizes pairing within same country
 */
class CountryPriorityAlgorithm implements PairingAlgorithm {
    public function pair($db, $cycleId) {
        // Get confirmed participants
        $stmt = $db->prepare("
            SELECT cp.user_id, u.country 
            FROM cycle_participations cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.cycle_id = ? AND cp.participation_confirmed = 1 AND cp.wants_to_participate = 1
        ");
        $stmt->execute([$cycleId]);
        $participants = $stmt->fetchAll();
        
        if (count($participants) < 2) {
            return false;
        }
        
        // Group by country
        $byCountry = [];
        foreach ($participants as $p) {
            $byCountry[$p['country']][] = $p['user_id'];
        }
        
        // Sort countries by participant count (descending)
        uasort($byCountry, function($a, $b) {
            return count($b) - count($a);
        });
        
        // Create ordered list prioritizing same-country pairs
        $ordered = [];
        $remaining = [];
        
        foreach ($byCountry as $country => $userIds) {
            if (count($userIds) >= 2) {
                // Pair within country
                for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                    $ordered[] = $userIds[$i];
                    $ordered[] = $userIds[$i + 1];
                }
                if (count($userIds) % 2 == 1) {
                    // Odd number, save last one for cross-country pairing
                    $remaining[] = end($userIds);
                }
            } else {
                $remaining[] = $userIds[0];
            }
        }
        
        // Add remaining participants for cross-country pairing
        $ordered = array_merge($ordered, $remaining);
        
        // Perform the pairing
        for ($i = 0; $i < count($ordered) - 1; $i += 2) {
            $user1 = $ordered[$i];
            $user2 = $ordered[$i + 1];
            
            $stmt = $db->prepare("UPDATE cycle_participations SET paired_with_id = ? WHERE cycle_id = ? AND user_id = ?");
            $stmt->execute([$user2, $cycleId, $user1]);
            $stmt->execute([$user1, $cycleId, $user2]);
        }
        
        // Mark cycle as paired
        $stmt = $db->prepare("UPDATE cycles SET pairing_done = 1 WHERE id = ?");
        $stmt->execute([$cycleId]);
        
        // Check for unpaired participant (odd count)
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND paired_with_id IS NULL AND participation_confirmed = 1 AND wants_to_participate = 1");
        $checkStmt->execute([$cycleId]);
        $unpaired = (int)$checkStmt->fetchColumn();
        if ($unpaired > 0) {
            error_log("[" . (new ReflectionClass($this))->getShortName() . "] {$unpaired} participant(s) left unpaired in cycle {$cycleId} (odd count).");
        }
        
        return true;
    }
}

/**
 * Random Algorithm - Pairs participants randomly
 */
class RandomAlgorithm implements PairingAlgorithm {
    public function pair($db, $cycleId) {
        // Get confirmed participants
        $stmt = $db->prepare("
            SELECT cp.user_id 
            FROM cycle_participations cp
            WHERE cp.cycle_id = ? AND cp.participation_confirmed = 1 AND cp.wants_to_participate = 1
        ");
        $stmt->execute([$cycleId]);
        $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($participants) < 2) {
            return false;
        }
        
        // Shuffle participants randomly
        shuffle($participants);
        
        // Perform the pairing
        for ($i = 0; $i < count($participants) - 1; $i += 2) {
            $user1 = $participants[$i];
            $user2 = $participants[$i + 1];
            
            $stmt = $db->prepare("UPDATE cycle_participations SET paired_with_id = ? WHERE cycle_id = ? AND user_id = ?");
            $stmt->execute([$user2, $cycleId, $user1]);
            $stmt->execute([$user1, $cycleId, $user2]);
        }
        
        // Mark cycle as paired
        $stmt = $db->prepare("UPDATE cycles SET pairing_done = 1 WHERE id = ?");
        $stmt->execute([$cycleId]);
        
        // Check for unpaired participant (odd count)
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND paired_with_id IS NULL AND participation_confirmed = 1 AND wants_to_participate = 1");
        $checkStmt->execute([$cycleId]);
        $unpaired = (int)$checkStmt->fetchColumn();
        if ($unpaired > 0) {
            error_log("[" . (new ReflectionClass($this))->getShortName() . "] {$unpaired} participant(s) left unpaired in cycle {$cycleId} (odd count).");
        }
        
        return true;
    }
}

/**
 * Sequential Algorithm - Pairs participants in order of registration
 */
class SequentialAlgorithm implements PairingAlgorithm {
    public function pair($db, $cycleId) {
        // Get confirmed participants in order of registration
        $stmt = $db->prepare("
            SELECT cp.user_id 
            FROM cycle_participations cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.cycle_id = ? AND cp.participation_confirmed = 1 AND cp.wants_to_participate = 1
            ORDER BY u.created_at ASC
        ");
        $stmt->execute([$cycleId]);
        $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($participants) < 2) {
            return false;
        }
        
        // Perform the pairing in sequential order
        for ($i = 0; $i < count($participants) - 1; $i += 2) {
            $user1 = $participants[$i];
            $user2 = $participants[$i + 1];
            
            $stmt = $db->prepare("UPDATE cycle_participations SET paired_with_id = ? WHERE cycle_id = ? AND user_id = ?");
            $stmt->execute([$user2, $cycleId, $user1]);
            $stmt->execute([$user1, $cycleId, $user2]);
        }
        
        // Mark cycle as paired
        $stmt = $db->prepare("UPDATE cycles SET pairing_done = 1 WHERE id = ?");
        $stmt->execute([$cycleId]);
        
        // Check for unpaired participant (odd count)
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND paired_with_id IS NULL AND participation_confirmed = 1 AND wants_to_participate = 1");
        $checkStmt->execute([$cycleId]);
        $unpaired = (int)$checkStmt->fetchColumn();
        if ($unpaired > 0) {
            error_log("[" . (new ReflectionClass($this))->getShortName() . "] {$unpaired} participant(s) left unpaired in cycle {$cycleId} (odd count).");
        }
        
        return true;
    }
}

/**
 * Zine Type Algorithm - Prioritizes pairing participants with similar zine types
 */
class ZineTypeAlgorithm implements PairingAlgorithm {
    public function pair($db, $cycleId) {
        // Get confirmed participants with their zine info
        $stmt = $db->prepare("
            SELECT cp.user_id, u.country, z.format as zine_format
            FROM cycle_participations cp
            JOIN users u ON cp.user_id = u.id
            LEFT JOIN zines z ON cp.user_id = z.user_id
            WHERE cp.cycle_id = ? AND cp.participation_confirmed = 1 AND cp.wants_to_participate = 1
        ");
        $stmt->execute([$cycleId]);
        $participants = $stmt->fetchAll();
        
        if (count($participants) < 2) {
            return false;
        }
        
        // Group by zine format
        $byFormat = [];
        foreach ($participants as $p) {
            $format = $p['zine_format'] ?: 'other';
            $byFormat[$format][] = $p['user_id'];
        }
        
        // Sort formats by participant count (descending) to prioritize larger groups
        uasort($byFormat, function($a, $b) {
            return count($b) - count($a);
        });
        
        // Create ordered list prioritizing same-format pairs
        $ordered = [];
        $remaining = [];
        
        foreach ($byFormat as $format => $userIds) {
            if (count($userIds) >= 2) {
                // Pair within same format
                for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                    $ordered[] = $userIds[$i];
                    $ordered[] = $userIds[$i + 1];
                }
                if (count($userIds) % 2 == 1) {
                    // Odd number, save last one for cross-format pairing
                    $remaining[] = end($userIds);
                }
            } else {
                $remaining[] = $userIds[0];
            }
        }
        
        // Add remaining participants for cross-format pairing
        $ordered = array_merge($ordered, $remaining);
        
        // Perform the pairing
        for ($i = 0; $i < count($ordered) - 1; $i += 2) {
            $user1 = $ordered[$i];
            $user2 = $ordered[$i + 1];
            
            $stmt = $db->prepare("UPDATE cycle_participations SET paired_with_id = ? WHERE cycle_id = ? AND user_id = ?");
            $stmt->execute([$user2, $cycleId, $user1]);
            $stmt->execute([$user1, $cycleId, $user2]);
        }
        
        // Mark cycle as paired
        $stmt = $db->prepare("UPDATE cycles SET pairing_done = 1 WHERE id = ?");
        $stmt->execute([$cycleId]);
        
        // Check for unpaired participant (odd count)
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND paired_with_id IS NULL AND participation_confirmed = 1 AND wants_to_participate = 1");
        $checkStmt->execute([$cycleId]);
        $unpaired = (int)$checkStmt->fetchColumn();
        if ($unpaired > 0) {
            error_log("[" . (new ReflectionClass($this))->getShortName() . "] {$unpaired} participant(s) left unpaired in cycle {$cycleId} (odd count).");
        }
        
        return true;
    }
}

/**
 * Country + Zine Type Algorithm - Prioritizes pairing within same country AND similar zine types
 */
class CountryZineTypeAlgorithm implements PairingAlgorithm {
    public function pair($db, $cycleId) {
        // Get confirmed participants with their zine info
        $stmt = $db->prepare("
            SELECT cp.user_id, u.country, z.format as zine_format
            FROM cycle_participations cp
            JOIN users u ON cp.user_id = u.id
            LEFT JOIN zines z ON cp.user_id = z.user_id
            WHERE cp.cycle_id = ? AND cp.participation_confirmed = 1 AND cp.wants_to_participate = 1
        ");
        $stmt->execute([$cycleId]);
        $participants = $stmt->fetchAll();
        
        if (count($participants) < 2) {
            return false;
        }
        
        // Group by country AND zine format (highest priority)
        $byCountryAndFormat = [];
        foreach ($participants as $p) {
            $country = $p['country'];
            $format = $p['zine_format'] ?: 'other';
            $key = $country . '|' . $format;
            $byCountryAndFormat[$key][] = $p['user_id'];
        }
        
        // Sort groups by participant count (descending)
        uasort($byCountryAndFormat, function($a, $b) {
            return count($b) - count($a);
        });
        
        // Create ordered list prioritizing same country + same format pairs
        $ordered = [];
        $remaining = [];
        
        foreach ($byCountryAndFormat as $groupKey => $userIds) {
            if (count($userIds) >= 2) {
                // Pair within same country and format
                for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                    $ordered[] = $userIds[$i];
                    $ordered[] = $userIds[$i + 1];
                }
                if (count($userIds) % 2 == 1) {
                    // Odd number, save last one for lower priority pairing
                    $remaining[] = end($userIds);
                }
            } else {
                $remaining[] = $userIds[0];
            }
        }
        
        // Add remaining participants for fallback pairing
        $ordered = array_merge($ordered, $remaining);
        
        // Perform the pairing
        for ($i = 0; $i < count($ordered) - 1; $i += 2) {
            $user1 = $ordered[$i];
            $user2 = $ordered[$i + 1];
            
            $stmt = $db->prepare("UPDATE cycle_participations SET paired_with_id = ? WHERE cycle_id = ? AND user_id = ?");
            $stmt->execute([$user2, $cycleId, $user1]);
            $stmt->execute([$user1, $cycleId, $user2]);
        }
        
        // Mark cycle as paired
        $stmt = $db->prepare("UPDATE cycles SET pairing_done = 1 WHERE id = ?");
        $stmt->execute([$cycleId]);
        
        // Check for unpaired participant (odd count)
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND paired_with_id IS NULL AND participation_confirmed = 1 AND wants_to_participate = 1");
        $checkStmt->execute([$cycleId]);
        $unpaired = (int)$checkStmt->fetchColumn();
        if ($unpaired > 0) {
            error_log("[" . (new ReflectionClass($this))->getShortName() . "] {$unpaired} participant(s) left unpaired in cycle {$cycleId} (odd count).");
        }
        
        return true;
    }
}

/**
 * Geographic Proximity Algorithm
 * Tries hard to pair people within the same country or close geographically.
 * Runs up to 50 iterations with shuffling to find a satisfying solution,
 * then falls back to random when no geographic pairings are possible.
 */
class GeographicProximityAlgorithm implements PairingAlgorithm {
    private static array $regionMap = [
        // Europe
        'united kingdom' => 'europe', 'uk' => 'europe', 'great britain' => 'europe', 'england' => 'europe',
        'france' => 'europe', 'germany' => 'europe', 'italy' => 'europe', 'spain' => 'europe',
        'portugal' => 'europe', 'netherlands' => 'europe', 'belgium' => 'europe', 'switzerland' => 'europe',
        'austria' => 'europe', 'sweden' => 'europe', 'norway' => 'europe', 'denmark' => 'europe',
        'finland' => 'europe', 'ireland' => 'europe', 'poland' => 'europe', 'czech republic' => 'europe',
        'czechia' => 'europe', 'hungary' => 'europe', 'greece' => 'europe', 'romania' => 'europe',
        'bulgaria' => 'europe', 'croatia' => 'europe', 'slovakia' => 'europe', 'slovenia' => 'europe',
        'lithuania' => 'europe', 'latvia' => 'europe', 'estonia' => 'europe', 'luxembourg' => 'europe',
        'malta' => 'europe', 'cyprus' => 'europe', 'iceland' => 'europe', 'turkey' => 'europe',
        'ukraine' => 'europe', 'russia' => 'europe', 'serbia' => 'europe', 'bosnia' => 'europe',
        'albania' => 'europe', 'moldova' => 'europe', 'macedonia' => 'europe', 'belarus' => 'europe',

        // North America
        'united states' => 'north_america', 'usa' => 'north_america', 'us' => 'north_america',
        'canada' => 'north_america', 'mexico' => 'north_america',

        // South America
        'brazil' => 'south_america', 'argentina' => 'south_america', 'chile' => 'south_america',
        'colombia' => 'south_america', 'peru' => 'south_america', 'ecuador' => 'south_america',
        'venezuela' => 'south_america', 'uruguay' => 'south_america', 'paraguay' => 'south_america',
        'bolivia' => 'south_america',

        // Asia
        'japan' => 'asia', 'china' => 'asia', 'south korea' => 'asia', 'korea' => 'asia',
        'india' => 'asia', 'thailand' => 'asia', 'vietnam' => 'asia', 'philippines' => 'asia',
        'malaysia' => 'asia', 'indonesia' => 'asia', 'singapore' => 'asia', 'taiwan' => 'asia',
        'hong kong' => 'asia', 'pakistan' => 'asia', 'bangladesh' => 'asia', 'nepal' => 'asia',
        'sri lanka' => 'asia', 'israel' => 'asia', 'united arab emirates' => 'asia', 'uae' => 'asia',
        'saudi arabia' => 'asia',

        // Africa
        'south africa' => 'africa', 'nigeria' => 'africa', 'kenya' => 'africa', 'egypt' => 'africa',
        'ghana' => 'africa', 'morocco' => 'africa', 'tunisia' => 'africa', 'algeria' => 'africa',
        'senegal' => 'africa', 'uganda' => 'africa', 'ethiopia' => 'africa', 'tanzania' => 'africa',

        // Oceania
        'australia' => 'oceania', 'new zealand' => 'oceania',
    ];

    public function pair($db, $cycleId): bool {
        $stmt = $db->prepare("
            SELECT cp.user_id, u.country
            FROM cycle_participations cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.cycle_id = ? AND cp.participation_confirmed = 1 AND cp.wants_to_participate = 1
        ");
        $stmt->execute([$cycleId]);
        $participants = $stmt->fetchAll();

        if (count($participants) < 2) {
            return false;
        }

        $bestPairs = null;
        $bestScore = -1;
        $totalParticipants = count($participants);
        $maxPossibleScore = (int)($totalParticipants / 2) * 3;

        for ($iteration = 0; $iteration < 50; $iteration++) {
            shuffle($participants);

            $remaining = $participants;
            $pairs = [];
            $score = 0;

            while (count($remaining) >= 2) {
                $p1 = array_shift($remaining);

                $bestMatchIdx = -1;
                $bestMatchScore = -1;

                foreach ($remaining as $idx => $p2) {
                    $s = $this->pairScore($p1['country'], $p2['country']);
                    if ($s > $bestMatchScore) {
                        $bestMatchScore = $s;
                        $bestMatchIdx = $idx;
                    }
                }

                $p2 = array_splice($remaining, $bestMatchIdx, 1)[0];
                $pairs[] = [$p1['user_id'], $p2['user_id']];
                $score += $bestMatchScore;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPairs = $pairs;
            }

            // All pairs are same-country — no need to keep iterating
            if ($score === $maxPossibleScore) {
                break;
            }
        }

        // Fallback to random when no geographic proximity was achievable
        if ($bestScore <= 0) {
            $randomAlgo = new RandomAlgorithm();
            // Check for unpaired participant (odd count) before fallback
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND paired_with_id IS NULL AND participation_confirmed = 1 AND wants_to_participate = 1");
            $checkStmt->execute([$cycleId]);
            $unpaired = (int)$checkStmt->fetchColumn();
            if ($unpaired > 0) {
                error_log("[" . (new ReflectionClass($this))->getShortName() . "] {$unpaired} participant(s) left unpaired in cycle {$cycleId} (odd count).");
            }
            return $randomAlgo->pair($db, $cycleId);
        }

        // Save the best pairing found
        foreach ($bestPairs as $pair) {
            [$user1, $user2] = $pair;
            $stmt = $db->prepare("UPDATE cycle_participations SET paired_with_id = ? WHERE cycle_id = ? AND user_id = ?");
            $stmt->execute([$user2, $cycleId, $user1]);
            $stmt->execute([$user1, $cycleId, $user2]);
        }

        $stmt = $db->prepare("UPDATE cycles SET pairing_done = 1 WHERE id = ?");
        $stmt->execute([$cycleId]);
        
        // Check for unpaired participant (odd count)
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND paired_with_id IS NULL AND participation_confirmed = 1 AND wants_to_participate = 1");
        $checkStmt->execute([$cycleId]);
        $unpaired = (int)$checkStmt->fetchColumn();
        if ($unpaired > 0) {
            error_log("[" . (new ReflectionClass($this))->getShortName() . "] {$unpaired} participant(s) left unpaired in cycle {$cycleId} (odd count).");
        }
        
        return true;
    }

    private function pairScore(string $country1, string $country2): int {
        if (strcasecmp($country1, $country2) === 0) {
            return 3;
        }

        $region1 = $this->getRegion($country1);
        $region2 = $this->getRegion($country2);

        if ($region1 !== null && $region2 !== null && $region1 === $region2) {
            return 2;
        }

        return 0;
    }

    private function getRegion(string $country): ?string {
        return self::$regionMap[strtolower(trim($country))] ?? null;
    }
}

/**
 * Pairing Algorithm Factory
 */
class PairingAlgorithmFactory {
    private static $algorithms = [
        'country_priority' => CountryPriorityAlgorithm::class,
        'random' => RandomAlgorithm::class,
        'sequential' => SequentialAlgorithm::class,
        'zine_type' => ZineTypeAlgorithm::class,
        'country_zine_type' => CountryZineTypeAlgorithm::class,
        'geographic_proximity' => GeographicProximityAlgorithm::class,
    ];
    
    /**
     * Get pairing algorithm instance
     * @param string $algorithmName Algorithm name
     * @return PairingAlgorithm|null
     */
    public static function getAlgorithm($algorithmName) {
        if (!isset(self::$algorithms[$algorithmName])) {
            throw new \InvalidArgumentException("Unknown pairing algorithm: {$algorithmName}");
        }
        
        $className = self::$algorithms[$algorithmName];
        return new $className();
    }
    
    /**
     * Get available algorithms
     * @return array
     */
    public static function getAvailableAlgorithms() {
        return array_keys(self::$algorithms);
    }
}

/**
 * Main pairing function that uses the configured algorithm
 */
function pairParticipants($cycleId, $db) {
    try {
        $algorithm = PairingAlgorithmFactory::getAlgorithm(PAIRING_ALGORITHM);
        $result = $algorithm->pair($db, $cycleId);

        if ($result) {
            // Send pairing notification emails to each paired user
            $stmt = $db->prepare("
                SELECT cp.user_id, u.name, u.email,
                       p.name as partner_name, p.email as partner_email, p.postal_address as partner_address, p.country as partner_country
                FROM cycle_participations cp
                JOIN users u ON cp.user_id = u.id
                JOIN users p ON cp.paired_with_id = p.id
                WHERE cp.cycle_id = ? AND cp.paired_with_id IS NOT NULL
            ");
            $stmt->execute([$cycleId]);
            $pairedUsers = $stmt->fetchAll();

            foreach ($pairedUsers as $user) {
                $token = bin2hex(random_bytes(16));
                $tokenExpires = date('Y-m-d H:i:s', strtotime('+14 days'));
                $partnerInfo = "Email: " . $user['partner_email'] . "\n" . $user['partner_address'];
                $emailBody = getPairingEmail($user['name'], $user['partner_name'], $partnerInfo, $user['partner_country'], $token);
                sendEmail($user['email'], 'You\'ve Been Paired!', $emailBody);
                logEmail($user['user_id'], $cycleId, 'pairing_notification');

                // Store token for confirmation
                $stmt = $db->prepare("UPDATE cycle_participations SET confirmation_token = ?, confirmation_token_expires = ? WHERE cycle_id = ? AND user_id = ?");
                $stmt->execute([$token, $tokenExpires, $cycleId, $user['user_id']]);
            }
            
            // Check for unpaired participant (odd count)
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND paired_with_id IS NULL AND participation_confirmed = 1 AND wants_to_participate = 1");
            $checkStmt->execute([$cycleId]);
            $unpaired = (int)$checkStmt->fetchColumn();
            if ($unpaired > 0) {
                error_log("[pairParticipants] {$unpaired} participant(s) left unpaired in cycle {$cycleId} (odd count).");
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log("Pairing failed: " . $e->getMessage());
        return false;
    }
}
?>
