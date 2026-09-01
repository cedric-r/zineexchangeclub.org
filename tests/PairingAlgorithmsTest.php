<?php
/**
 * Tests for includes/pairing_algorithms.php
 *
 * Covers: PairingAlgorithmFactory, all 7 pairing algorithms,
 *         GeographicProximityAlgorithm private methods (via reflection),
 *         and pairParticipants().
 */

require_once __DIR__ . '/bootstrap.php';
// config.php required for constants (PAIRING_ALGORITHM, SITE_URL, etc.)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/pairing_algorithms.php';

$db = getDB();
createTestSchema($db);

// ── Helper: add participation records ───────────────────────────────

function addParticipation(PDO $db, int $cycleId, int $userId, bool $confirmed = true): void {
    $stmt = $db->prepare("
        INSERT INTO cycle_participations (cycle_id, user_id, participation_confirmed, wants_to_participate)
        VALUES (?, ?, ?, 1)
    ");
    $stmt->execute([$cycleId, $userId, $confirmed ? 1 : 0]);
}

/**
 * Assert that a set of pairings forms valid two-way bonds.
 * $participants is the set of user_ids we expect to be paired.
 */
function assertPairingsValid(string $label, PDO $db, int $cycleId, array $participants): void {
    $stmt = $db->prepare("
        SELECT user_id, partner_id FROM cycle_pairings
        WHERE cycle_id = ?
        ORDER BY user_id
    ");
    $stmt->execute([$cycleId]);
    $pairs = $stmt->fetchAll();

    $allOk = true;

    // Every paired user should have their partner also pointing back
    $pairMap = [];
    foreach ($pairs as $p) {
        $pairMap[$p['user_id']] = (int)$p['partner_id'];
    }

    foreach ($pairMap as $uid => $partnerId) {
        if (!isset($pairMap[$partnerId]) || $pairMap[$partnerId] !== $uid) {
            $allOk = false;
        }
    }

    assert_true("{$label}: all pairings are two-way symmetric", $allOk);

    // No participant paired with themselves
    foreach ($pairMap as $uid => $partnerId) {
        if ($uid === $partnerId) {
            assert_true("{$label}: no self-pairing", false);
            return;
        }
    }
    assert_true("{$label}: no participant paired with themselves", true);

    // No pairing record for unpaired (odd-count leftovers) — cycle_pairings only has paired users
    $unpaired = count($participants) - count($pairMap);
    assert_true("{$label}: unpaired count matches odd remainder", ($unpaired === 0) || ($unpaired === 1));

    // Cycle is marked as done
    $stmt = $db->prepare("SELECT pairing_done FROM cycles WHERE id = ?");
    $stmt->execute([$cycleId]);
    $cycle = $stmt->fetch();
    assert_true("{$label}: cycle marked as paired", (int)$cycle['pairing_done'] === 1);
}

// ════════════════════════════════════════════════════════════════════
//  PairingAlgorithmFactory
// ════════════════════════════════════════════════════════════════════

assert_true('factory returns available algorithms as array',
    is_array(PairingAlgorithmFactory::getAvailableAlgorithms()));

$available = PairingAlgorithmFactory::getAvailableAlgorithms();
assert_true('factory lists country_priority', in_array('country_priority', $available));
assert_true('factory lists random', in_array('random', $available));
assert_true('factory lists sequential', in_array('sequential', $available));
assert_true('factory lists zine_type', in_array('zine_type', $available));
assert_true('factory lists country_zine_type', in_array('country_zine_type', $available));
assert_true('factory lists geographic_proximity', in_array('geographic_proximity', $available));
assert_true('factory lists continent_novelty', in_array('continent_novelty', $available));

assert_true('factory returns CountryPriorityAlgorithm instance',
    PairingAlgorithmFactory::getAlgorithm('country_priority') instanceof CountryPriorityAlgorithm);
assert_true('factory returns RandomAlgorithm instance',
    PairingAlgorithmFactory::getAlgorithm('random') instanceof RandomAlgorithm);
assert_true('factory returns SequentialAlgorithm instance',
    PairingAlgorithmFactory::getAlgorithm('sequential') instanceof SequentialAlgorithm);
assert_true('factory returns GeographicProximityAlgorithm instance',
    PairingAlgorithmFactory::getAlgorithm('geographic_proximity') instanceof GeographicProximityAlgorithm);
assert_true('factory returns ContinentNoveltyAlgorithm instance',
    PairingAlgorithmFactory::getAlgorithm('continent_novelty') instanceof ContinentNoveltyAlgorithm);

assert_throws('factory throws for unknown algorithm',
    fn() => PairingAlgorithmFactory::getAlgorithm('nonexistent_algo'),
    InvalidArgumentException::class);

// ════════════════════════════════════════════════════════════════════
//  Algorithm: insufficient participants (< 2) → return false
// ════════════════════════════════════════════════════════════════════

$algoNames = ['country_priority', 'random', 'sequential', 'zine_type', 'country_zine_type', 'geographic_proximity', 'continent_novelty'];

foreach ($algoNames as $name) {
    // Fresh cycle with 0 participants
    $cycleId = insertTestCycle($db);
    $algo = PairingAlgorithmFactory::getAlgorithm($name);
    $result = $algo->pair($db, $cycleId);
    assert_true("{$name}: returns false for 0 participants", $result === false);

    // Fresh cycle with 1 participant
    $cycleId2 = insertTestCycle($db);
    $userId = insertTestUser($db, ['email' => "single-{$name}@pair-test.local", 'country' => 'US']);
    addParticipation($db, $cycleId2, $userId);
    $result = $algo->pair($db, $cycleId2);
    assert_true("{$name}: returns false for 1 participant", $result === false);

    // Not-confirmed participants should be ignored
    $cycleId3 = insertTestCycle($db);
    $u1 = insertTestUser($db, ['email' => "unconfirmed-{$name}-1@pair-test.local"]);
    $u2 = insertTestUser($db, ['email' => "unconfirmed-{$name}-2@pair-test.local"]);
    addParticipation($db, $cycleId3, $u1, false);
    addParticipation($db, $cycleId3, $u2, false);
    $result = $algo->pair($db, $cycleId3);
    assert_true("{$name}: returns false when no confirmed participants", $result === false);
}

// ════════════════════════════════════════════════════════════════════
//  CountryPriorityAlgorithm — pairs same-country when possible
// ════════════════════════════════════════════════════════════════════

$cycleId = insertTestCycle($db);
$us1 = insertTestUser($db, ['email' => 'cp-us1@pair-test.local', 'country' => 'US']);
$us2 = insertTestUser($db, ['email' => 'cp-us2@pair-test.local', 'country' => 'US']);
$uk1 = insertTestUser($db, ['email' => 'cp-uk1@pair-test.local', 'country' => 'UK']);
$uk2 = insertTestUser($db, ['email' => 'cp-uk2@pair-test.local', 'country' => 'UK']);
foreach ([$us1, $us2, $uk1, $uk2] as $uid) {
    addParticipation($db, $cycleId, $uid);
}

$algo = new CountryPriorityAlgorithm();
$result = $algo->pair($db, $cycleId);
assert_true('CountryPriority pairs 4 participants', $result === true);
assertPairingsValid('CountryPriority 4 same-country', $db, $cycleId, [$us1, $us2, $uk1, $uk2]);

// Verify same-country pairing: US paired with US, UK paired with UK
$stmt = $db->prepare("
    SELECT cp.user_id, cp.partner_id, u1.country AS c1, u2.country AS c2\n\t    FROM cycle_pairings cp\n\t    JOIN users u1 ON cp.user_id = u1.id\n\t    JOIN users u2 ON cp.partner_id = u2.id\n\t    WHERE cp.cycle_id = ?
");
$stmt->execute([$cycleId]);
$pairs = $stmt->fetchAll();
foreach ($pairs as $p) {
    assert_true("CountryPriority pair {$p['user_id']}-{$p['paired_with_id']}: same country",
        $p['c1'] === $p['c2']);
}

// ── Odd participant count (5 users) ────────────────────────────────
$cycleId5 = insertTestCycle($db);
$u = [];
foreach (['US', 'US', 'US', 'UK', 'UK'] as $i => $country) {
    $u[] = insertTestUser($db, ['email' => "cp-odd-{$i}@pair-test.local", 'country' => $country]);
}
foreach ($u as $uid) {
    addParticipation($db, $cycleId5, $uid);
}
$algo5 = new CountryPriorityAlgorithm();
$result5 = $algo5->pair($db, $cycleId5);
assert_true('CountryPriority pairs 5 participants', $result5 === true);
// Should pair 2 within US and 2 within UK, leaving 1 unpaired
assertPairingsValid('CountryPriority 5 (odd)', $db, $cycleId5, $u);

// ════════════════════════════════════════════════════════════════════
//  RandomAlgorithm — basic pairing (shuffle makes exact pairs unpredictable)
// ════════════════════════════════════════════════════════════════════

$cycleId = insertTestCycle($db);
$raUsers = [];
foreach (range(1, 6) as $i) {
    $raUsers[] = insertTestUser($db, ['email' => "ra-{$i}@pair-test.local", 'country' => 'US']);
}
foreach ($raUsers as $uid) {
    addParticipation($db, $cycleId, $uid);
}

$algo = new RandomAlgorithm();
$result = $algo->pair($db, $cycleId);
assert_true('RandomAlgorithm pairs 6 participants', $result === true);
assertPairingsValid('RandomAlgorithm 6', $db, $cycleId, $raUsers);

// ════════════════════════════════════════════════════════════════════
//  SequentialAlgorithm — pairs in order of registration
// ════════════════════════════════════════════════════════════════════

$cycleId = insertTestCycle($db);
$seqUsers = [];
foreach (['First', 'Second', 'Third', 'Fourth'] as $i => $name) {
    $id = insertTestUser($db, [
        'name'       => $name,
        'email'      => "seq-{$i}@pair-test.local",
    ]);
    // Set created_at sequentially for ordering tests
    $ts = date('Y-m-d H:i:s', strtotime("2024-01-0" . ($i + 1) . " 10:00:00"));
    $db->prepare("UPDATE users SET created_at = ? WHERE id = ?")->execute([$ts, $id]);
    $seqUsers[] = $id;
    addParticipation($db, $cycleId, $id);
}

$algo = new SequentialAlgorithm();
$result = $algo->pair($db, $cycleId);
assert_true('SequentialAlgorithm pairs 4 participants', $result === true);
assertPairingsValid('SequentialAlgorithm 4', $db, $cycleId, $seqUsers);

// Verify strict sequential order: [0,1] and [2,3]
$stmt = $db->prepare("SELECT user_id, partner_id FROM cycle_pairings WHERE cycle_id = ? ORDER BY user_id");
$stmt->execute([$cycleId]);
$pairs = $stmt->fetchAll();
assert_equal('Sequential: first user paired with second', $seqUsers[1], (int)$pairs[0]['partner_id']);
assert_equal('Sequential: second user paired with first', $seqUsers[0], (int)$pairs[1]['partner_id']);
if (isset($pairs[2])) {
    assert_equal('Sequential: third user paired with fourth', $seqUsers[3], (int)$pairs[2]['partner_id']);
}

// ════════════════════════════════════════════════════════════════════
//  ZineTypeAlgorithm — pairs same-format when possible
// ════════════════════════════════════════════════════════════════════

$cycleId = insertTestCycle($db);
$ztUsers = [];
foreach ([
    ['email' => 'zt-digital1@pair-test.local', 'format' => 'digital'],
    ['email' => 'zt-digital2@pair-test.local', 'format' => 'digital'],
    ['email' => 'zt-physical1@pair-test.local', 'format' => 'physical'],
    ['email' => 'zt-physical2@pair-test.local', 'format' => 'physical'],
] as $spec) {
    $id = insertTestUser($db, $spec);
    $ztUsers[] = $id;
    // Also create zine records
    $db->prepare("INSERT INTO zines (user_id, format) VALUES (?, ?)")->execute([$id, $spec['format']]);
    addParticipation($db, $cycleId, $id);
}

$algo = new ZineTypeAlgorithm();
$result = $algo->pair($db, $cycleId);
assert_true('ZineTypeAlgorithm pairs 4 participants', $result === true);
assertPairingsValid('ZineTypeAlgorithm 4', $db, $cycleId, $ztUsers);

// Check same-format pairing
$stmt = $db->prepare("
    SELECT cp1.user_id, cp1.paired_with_id, COALESCE(z1.format, 'other') AS f1, COALESCE(z2.format, 'other') AS f2
    FROM cycle_participations cp1
    JOIN cycle_participations cp2 ON cp1.paired_with_id = cp2.user_id AND cp2.cycle_id = cp1.cycle_id
    LEFT JOIN zines z1 ON cp1.user_id = z1.user_id
    LEFT JOIN zines z2 ON cp2.user_id = z2.user_id
    WHERE cp1.cycle_id = ? AND cp1.paired_with_id IS NOT NULL
");
$stmt->execute([$cycleId]);
$ztPairs = $stmt->fetchAll();
foreach ($ztPairs as $p) {
    assert_true("ZineType pair {$p['user_id']}-{$p['paired_with_id']}: same format", $p['f1'] === $p['f2']);
}

// ── User with no zine record → format defaults to 'other' ──────────
$cycleId2 = insertTestCycle($db);
$zNoZine1 = insertTestUser($db, ['email' => 'zt-nozine1@pair-test.local']);
$zNoZine2 = insertTestUser($db, ['email' => 'zt-nozine2@pair-test.local']);
// No zine record for either user
addParticipation($db, $cycleId2, $zNoZine1);
addParticipation($db, $cycleId2, $zNoZine2);

$algo = new ZineTypeAlgorithm();
$result = $algo->pair($db, $cycleId2);
assert_true('ZineTypeAlgorithm pairs users with no zine record', $result === true);

// ════════════════════════════════════════════════════════════════════
//  CountryZineTypeAlgorithm — pairs same-country + same-format
// ════════════════════════════════════════════════════════════════════

$cycleId = insertTestCycle($db);
$czUsers = [];
foreach ([
    ['email' => 'cz-us-dig1@pair-test.local', 'country' => 'US', 'format' => 'digital'],
    ['email' => 'cz-us-dig2@pair-test.local', 'country' => 'US', 'format' => 'digital'],
    ['email' => 'cz-uk-phy1@pair-test.local', 'country' => 'UK', 'format' => 'physical'],
    ['email' => 'cz-uk-phy2@pair-test.local', 'country' => 'UK', 'format' => 'physical'],
] as $spec) {
    $id = insertTestUser($db, $spec);
    $czUsers[] = $id;
    $db->prepare("INSERT INTO zines (user_id, format) VALUES (?, ?)")->execute([$id, $spec['format']]);
    addParticipation($db, $cycleId, $id);
}

$algo = new CountryZineTypeAlgorithm();
$result = $algo->pair($db, $cycleId);
assert_true('CountryZineTypeAlgorithm pairs 4 participants', $result === true);
assertPairingsValid('CountryZineTypeAlgorithm 4', $db, $cycleId, $czUsers);

// ════════════════════════════════════════════════════════════════════
//  GeographicProximityAlgorithm — pairScore / getRegion via reflection
// ════════════════════════════════════════════════════════════════════

$refGetRegion = new ReflectionMethod(GeographicProximityAlgorithm::class, 'getRegion');
$refGetRegion->setAccessible(true);
$refPairScore = new ReflectionMethod(GeographicProximityAlgorithm::class, 'pairScore');
$refPairScore->setAccessible(true);

$algo = new GeographicProximityAlgorithm();

// getRegion
assert_equal('getRegion returns "europe" for UK', 'europe', $refGetRegion->invoke($algo, 'UK'));
assert_equal('getRegion returns "europe" for united kingdom', 'europe', $refGetRegion->invoke($algo, 'united kingdom'));
assert_equal('getRegion returns "north_america" for USA', 'north_america', $refGetRegion->invoke($algo, 'USA'));
assert_equal('getRegion returns "north_america" for canada', 'north_america', $refGetRegion->invoke($algo, 'canada'));
assert_equal('getRegion returns "asia" for japan', 'asia', $refGetRegion->invoke($algo, 'japan'));
assert_equal('getRegion returns "oceania" for australia', 'oceania', $refGetRegion->invoke($algo, 'australia'));
assert_equal('getRegion returns "south_america" for brazil', 'south_america', $refGetRegion->invoke($algo, 'brazil'));
assert_equal('getRegion returns "africa" for kenya', 'africa', $refGetRegion->invoke($algo, 'kenya'));
assert_equal('getRegion returns null for unknown country', null, $refGetRegion->invoke($algo, 'atlantis'));
assert_equal('getRegion handles whitespace', 'europe', $refGetRegion->invoke($algo, '  france  '));
assert_equal('getRegion handles mixed case', 'asia', $refGetRegion->invoke($algo, 'JaPaN'));

// pairScore
assert_equal('pairScore returns 3 for same country', 3, $refPairScore->invoke($algo, 'US', 'US'));
assert_equal('pairScore returns 2 for same region', 2, $refPairScore->invoke($algo, 'France', 'Germany'));
assert_equal('pairScore returns 2 for same region (varied case)', 2, $refPairScore->invoke($algo, 'france', 'GERMANY'));
assert_equal('pairScore returns 0 for different regions', 0, $refPairScore->invoke($algo, 'US', 'Japan'));
assert_equal('pairScore returns 0 when one country unknown', 0, $refPairScore->invoke($algo, 'US', 'Atlantis'));
assert_equal('pairScore returns 0 when both unknown', 0, $refPairScore->invoke($algo, 'Atlantis', 'El Dorado'));

// ── GeographicProximityAlgorithm.pair() integration ────────────────
$cycleId = insertTestCycle($db);
$gpUsers = [];
foreach ([
    ['email' => 'gp-us1@pair-test.local', 'country' => 'US'],
    ['email' => 'gp-us2@pair-test.local', 'country' => 'US'],
    ['email' => 'gp-fr@pair-test.local',  'country' => 'France'],
    ['email' => 'gp-de@pair-test.local',  'country' => 'Germany'],
] as $spec) {
    $id = insertTestUser($db, $spec);
    $gpUsers[] = $id;
    addParticipation($db, $cycleId, $id);
}

$algo = new GeographicProximityAlgorithm();
$result = $algo->pair($db, $cycleId);
assert_true('GeographicProximityAlgorithm pairs 4 participants', $result === true);
assertPairingsValid('GeographicProximityAlgorithm 4', $db, $cycleId, $gpUsers);

// ── All cross-region → falls back to RandomAlgorithm ───────────────
$cycleId = insertTestCycle($db);
$gpFallbackUsers = [];
$gpFallbacks = [
    ['email' => 'gp-fallback1@pair-test.local', 'country' => 'Japan'],
    ['email' => 'gp-fallback2@pair-test.local', 'country' => 'Brazil'],
    ['email' => 'gp-fallback3@pair-test.local', 'country' => 'Australia'],
    ['email' => 'gp-fallback4@pair-test.local', 'country' => 'South Africa'],
];
foreach ($gpFallbacks as $spec) {
    $id = insertTestUser($db, $spec);
    $gpFallbackUsers[] = $id;
    addParticipation($db, $cycleId, $id);
}

$algo = new GeographicProximityAlgorithm();
$result = $algo->pair($db, $cycleId);
assert_true('GeographicProximityAlgorithm falls back for cross-region participants', $result === true);
assertPairingsValid('GeographicProximityAlgorithm cross-region fallback', $db, $cycleId, $gpFallbackUsers);

// ════════════════════════════════════════════════════════════════════
//  ContinentNoveltyAlgorithm — continent level + avoids repeat pairings
// ════════════════════════════════════════════════════════════════════

// Helper: get the set of unordered pairs for a cycle as "a-b" keys.
$getPairSet = function (PDO $db, int $cycleId): array {
    $stmt = $db->prepare("SELECT user_id, partner_id FROM cycle_pairings WHERE cycle_id = ?");
    $stmt->execute([$cycleId]);
    $set = [];
    foreach ($stmt->fetchAll() as $row) {
        $a = (int)$row['user_id']; $b = (int)$row['partner_id'];
        if ($a > $b) { $tmp = $a; $a = $b; $b = $tmp; }
        $set["$a-$b"] = true;
    }
    return $set;
};

// ── Basic: prefers same continent when all participants are novel ──
$cycleId = insertTestCycle($db);
$cnUsers = [];
foreach (['France', 'Germany', 'Spain', 'Italy'] as $i => $country) {
    $id = insertTestUser($db, ['email' => "cn-{$i}@pair-test.local", 'country' => $country]);
    $cnUsers[] = $id;
    addParticipation($db, $cycleId, $id);
}

$algo = new ContinentNoveltyAlgorithm();
$result = $algo->pair($db, $cycleId);
assert_true('ContinentNoveltyAlgorithm pairs 4 participants', $result === true);
assertPairingsValid('ContinentNoveltyAlgorithm 4', $db, $cycleId, $cnUsers);

// Same-continent preference: each pair should be within the same continent.
$stmt = $db->prepare("
    SELECT cp1.user_id, cp1.partner_id, u1.country AS c1, u2.country AS c2
    FROM cycle_pairings cp1
    JOIN users u1 ON cp1.user_id = u1.id
    JOIN users u2 ON cp1.partner_id = u2.id
    WHERE cp1.cycle_id = ?
");
$stmt->execute([$cycleId]);
foreach ($stmt->fetchAll() as $p) {
    $r1 = GeographicProximityAlgorithm::getRegion($p['c1']);
    $r2 = GeographicProximityAlgorithm::getRegion($p['c2']);
    assert_true("ContinentNovelty pair {$p['user_id']}-{$p['partner_id']}: same continent", $r1 !== null && $r1 === $r2);
}

// ── Avoids repeat pairings across cycles ──────────────────────────
// Run a first cycle with 4 same-continent users, then a second cycle
// with the same users. The second cycle must NOT reproduce any of the
// first cycle's pairs.
$cycleA = insertTestCycle($db);
$repeatUsers = [];
foreach (['France', 'Germany', 'Spain', 'Italy'] as $i => $country) {
    $id = insertTestUser($db, ['email' => "cn-rep-{$i}@pair-test.local", 'country' => $country]);
    $repeatUsers[] = $id;
    addParticipation($db, $cycleA, $id);
}
$algo = new ContinentNoveltyAlgorithm();
assert_true('ContinentNovelty cycle A pairs', $algo->pair($db, $cycleA) === true);
$setA = $getPairSet($db, $cycleA);

$cycleB = insertTestCycle($db);
foreach ($repeatUsers as $uid) {
    addParticipation($db, $cycleB, $uid);
}
$algo = new ContinentNoveltyAlgorithm();
assert_true('ContinentNovelty cycle B pairs', $algo->pair($db, $cycleB) === true);
$setB = $getPairSet($db, $cycleB);
$overlap = array_intersect_key($setA, $setB);
assert_true('ContinentNovelty avoids repeat pairings across cycles', count($overlap) === 0);

// ── Repeat allowed only when unavoidable (just 2 participants) ────
$cycleOnly = insertTestCycle($db);
$only1 = insertTestUser($db, ['email' => 'cn-only1@pair-test.local', 'country' => 'France']);
$only2 = insertTestUser($db, ['email' => 'cn-only2@pair-test.local', 'country' => 'Germany']);
addParticipation($db, $cycleOnly, $only1);
addParticipation($db, $cycleOnly, $only2);
$algo = new ContinentNoveltyAlgorithm();
assert_true('ContinentNovelty pairs 2 participants (first time)', $algo->pair($db, $cycleOnly) === true);

$cycleOnly2 = insertTestCycle($db);
addParticipation($db, $cycleOnly2, $only1);
addParticipation($db, $cycleOnly2, $only2);
$algo = new ContinentNoveltyAlgorithm();
assert_true('ContinentNovelty re-pairs 2 participants when no alternative', $algo->pair($db, $cycleOnly2) === true);

// ── All cross-continent, never paired → still pairs (novelty 2 each) ──
$cycleCross = insertTestCycle($db);
$crossUsers = [];
foreach (['Japan', 'Brazil', 'Australia', 'Kenya'] as $i => $country) {
    $id = insertTestUser($db, ['email' => "cn-cross-{$i}@pair-test.local", 'country' => $country]);
    $crossUsers[] = $id;
    addParticipation($db, $cycleCross, $id);
}
$algo = new ContinentNoveltyAlgorithm();
assert_true('ContinentNovelty pairs across continents when never paired', $algo->pair($db, $cycleCross) === true);
assertPairingsValid('ContinentNovelty cross-continent', $db, $cycleCross, $crossUsers);

// ════════════════════════════════════════════════════════════════════
//  pairParticipants() — wraps algorithm + email sending
// ════════════════════════════════════════════════════════════════════

// pairParticipants() calls sendEmail() which tries SMTP — this will fail
// in CLI. Wrap in try/catch or suppress. The function itself catches
// exceptions internally and returns false on error.
// Since SMTP isn't available, the error_log will be called but the email
// sending will fail. However, the pairing DB update should succeed first.
// Let's test with a config that won't try SMTP — we override sendEmail
// after including the file that defines it... but pairParticipants is in
// pairing_algorithms.php not email.php, so sendEmail is not yet defined.

// The simplest approach: define a stub sendEmail before calling pairParticipants.
require_once __DIR__ . '/../includes/email.php';

// We can't easily stub sendEmail after it's defined, but we can wrap the
// test to verify the function handles failure gracefully.

$cycleId = insertTestCycle($db);
$pp1 = insertTestUser($db, ['email' => 'pp1@pair-test.local', 'country' => 'US', 'name' => 'PP One']);
$pp2 = insertTestUser($db, ['email' => 'pp2@pair-test.local', 'country' => 'US', 'name' => 'PP Two']);
addParticipation($db, $cycleId, $pp1);
addParticipation($db, $cycleId, $pp2);

// Temporarily override error_log to suppress SMTP errors
set_error_handler(function () { return true; }, E_WARNING);
$result = pairParticipants($cycleId, $db);
restore_error_handler();

// The pairParticipants function tries to send emails which will fail (no SMTP),
// but the DB pairing should have succeeded. The function returns the
// algorithm's result (true) even if email sending fails, because the email
// sending is inside an unconditional block after the algorithm succeeds.
// Actually, looking at the code more carefully:
//
//     if ($result) {
//         // ... send emails ...
//         sendEmail(...) // this will fail
//     }
//
// The email sending will fail and pairParticipants will log an error.
// But it doesn't return false — it returns $result.
assert_true('pairParticipants returns algorithm result despite email failure', $result === true);

// Verify pairing was done in DB despite failed emails
assertPairingsValid('pairParticipants DB pairing succeeded', $db, $cycleId, [$pp1, $pp2]);
