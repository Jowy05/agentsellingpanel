<?php
// Inicializa la BD SQLite LOCAL desde schema.sqlite.sql (borra y recrea).
$dbPath = 'C:/Users/Lucia/Documents/Panel-Minutos-App/.localtools/panel.sqlite';
$schema = 'C:/Users/Lucia/Documents/Panel-Minutos-App/public/api/schema.sqlite.sql';

if (file_exists($dbPath)) { unlink($dbPath); }
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents($schema);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
  if ($stmt !== '' && stripos($stmt, 'PRAGMA') !== 0) { $pdo->exec($stmt); }
}
$pdo->exec('PRAGMA foreign_keys=ON');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
echo "BD local creada en $dbPath\n";
echo "Tablas: " . implode(', ', $tables) . "\n";
