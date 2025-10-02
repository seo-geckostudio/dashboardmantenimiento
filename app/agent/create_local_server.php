<?php
require_once '/app/vendor/autoload.php';

use WpOps\Dashboard\Database\Connection;

try {
    $db = new Connection();
    $pdo = $db->getPdo();
    
    // Check if local server already exists
    $stmt = $pdo->prepare("SELECT id FROM servers WHERE hostname = 'localhost'");
    $stmt->execute();
    $existingServer = $stmt->fetch();
    
    if ($existingServer) {
        echo "✅ Servidor local ya existe con ID: " . $existingServer['id'] . "\n";
        
        // Update the scan paths to ensure they're correct
        $stmt = $pdo->prepare("UPDATE servers SET scan_paths = ? WHERE id = ?");
        $stmt->execute(['["/test_sites/*/public_html"]', $existingServer['id']]);
        echo "✅ Rutas de escaneo actualizadas\n";
        
    } else {
        // Create local server entry
        $stmt = $pdo->prepare("
            INSERT INTO servers (name, hostname, scan_paths, ssh_user, ssh_port, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            'Servidor Local (Docker)',
            'localhost',
            '["/test_sites/*/public_html"]',
            'root',
            22,
            1
        ]);
        
        if ($result) {
            $serverId = $pdo->lastInsertId();
            echo "✅ Servidor local creado con ID: $serverId\n";
        } else {
            echo "❌ Error al crear el servidor local\n";
        }
    }
    
    // Show current servers
    echo "\n📋 Servidores configurados:\n";
    $stmt = $pdo->query("SELECT id, name, hostname, scan_paths FROM servers WHERE is_active = 1");
    while ($server = $stmt->fetch()) {
        echo "ID: {$server['id']} - {$server['name']} ({$server['hostname']})\n";
        echo "   Rutas: {$server['scan_paths']}\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}