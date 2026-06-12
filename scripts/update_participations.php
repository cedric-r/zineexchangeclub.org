#!/usr/bin/env php
<?php
/**
 * CLI script to set wants_to_participate=1 for all users in all existing cycles
 * Run this script from the command line: php scripts/update_participations.php
 */

// Include the config file to get database connection
require_once __DIR__ . '/../config.php';

echo "Starting participation update script...\n";

try {
    $db = getDB();
    
    // Get all users
    $stmt = $db->prepare("SELECT id FROM users");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // Get all active cycles
    $stmt = $db->prepare("SELECT id, name FROM cycles WHERE status = 'active'");
    $stmt->execute();
    $cycles = $stmt->fetchAll();
    
    $totalUpdates = 0;
    
    echo "Found " . count($users) . " users\n";
    echo "Found " . count($cycles) . " active cycles\n";
    echo "\n";
    
    foreach ($cycles as $cycle) {
        echo "Processing cycle: " . $cycle['name'] . " (ID: " . $cycle['id'] . ")\n";
        
        foreach ($users as $user) {
            // Insert or update participation record
            $stmt = $db->prepare("
                INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate) 
                VALUES (?, ?, 1) 
                ON DUPLICATE KEY UPDATE wants_to_participate = 1
            ");
            $stmt->execute([$cycle['id'], $user['id']]);
            $totalUpdates++;
        }
    }
    
    echo "\nUpdate completed successfully!\n";
    echo "Total participation records updated: " . $totalUpdates . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Script finished.\n";
?>
