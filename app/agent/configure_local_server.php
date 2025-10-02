<?php
require_once '/app/vendor/autoload.php';

use WpOps\Dashboard\Database\Connection;

try {
    $pdo = Connection::getInstance();
    
    // Check if localhost server already exists
    $stmt = $pdo->prepare("SELECT id FROM servers WHERE hostname IN ('localhost', '127.0.0.1', 'host.docker.internal')");
    $stmt->execute();
    $existingServer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingServer) {
        echo "Servidor local ya existe con ID: " . $existingServer['id'] . "\n";
        
        // Update the existing server
        $stmt = $pdo->prepare("
            UPDATE servers 
            SET name = ?, scan_paths = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([
            'Servidor Local (Docker)',
            '[\"test_sites/*/public_html\"]',
            $existingServer['id']
        ]);
        
        echo "Servidor local actualizado exitosamente.\n";
    } else {
        // Insert new localhost server
        $stmt = $pdo->prepare("
            INSERT INTO servers (name, hostname, ip_address, scan_paths, exclude_paths, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            'Servidor Local (Docker)',
            'host.docker.internal',
            '127.0.0.1',
            '[\"test_sites/*/public_html\"]',
            '[]'
        ]);
        
        echo "Servidor local creado exitosamente con ID: " . $pdo->lastInsertId() . "\n";
    }
    
    // Show all servers
    echo "\n=== Servidores configurados ===\n";
    $stmt = $pdo->query("SELECT id, name, hostname, scan_paths FROM servers ORDER BY id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['id']}, Nombre: {$row['name']}, Host: {$row['hostname']}, Rutas: {$row['scan_paths']}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}