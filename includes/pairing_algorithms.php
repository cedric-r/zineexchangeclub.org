<?php
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
        $paired = [];
        $remaining = [];
        
        foreach ($byCountry as $country => $userIds) {
            if (count($userIds) >= 2) {
                // Pair within country
                for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                    $ordered[] = $userIds[$i];
                    $ordered[] = $userIds[$i + 1];
                    $paired[] = $userIds[$i];
                    $paired[] = $userIds[$i + 1];
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
        $paired = [];
        $remaining = [];
        
        foreach ($byFormat as $format => $userIds) {
            if (count($userIds) >= 2) {
                // Pair within same format
                for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                    $ordered[] = $userIds[$i];
                    $ordered[] = $userIds[$i + 1];
                    $paired[] = $userIds[$i];
                    $paired[] = $userIds[$i + 1];
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
        $paired = [];
        $remaining = [];
        
        foreach ($byCountryAndFormat as $groupKey => $userIds) {
            if (count($userIds) >= 2) {
                // Pair within same country and format
                for ($i = 0; $i < count($userIds) - 1; $i += 2) {
                    $ordered[] = $userIds[$i];
                    $ordered[] = $userIds[$i + 1];
                    $paired[] = $userIds[$i];
                    $paired[] = $userIds[$i + 1];
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
        
        return true;
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
    ];
    
    /**
     * Get pairing algorithm instance
     * @param string $algorithmName Algorithm name
     * @return PairingAlgorithm|null
     */
    public static function getAlgorithm($algorithmName) {
        if (!isset(self::$algorithms[$algorithmName])) {
            throw new InvalidArgumentException("Unknown pairing algorithm: {$algorithmName}");
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
        return $algorithm->pair($db, $cycleId);
    } catch (Exception $e) {
        error_log("Pairing failed: " . $e->getMessage());
        return false;
    }
}
?>
