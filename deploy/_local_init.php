<?php
// Inicializa la BD SQLite LOCAL desde schema.sqlite.sql (borra y recrea).
$dbPath = 'C:/Users/Lucia/Documents/Panel-Minutos-App/.localtools/panel.sqlite';
$schema = 'C:/Users/Lucia/Documents/Panel-Minutos-App/public/api/schema.sqlite.sql';

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Borra y recrea (el unlink falla en Windows si el server tiene el fichero abierto).
foreach (['auditoria','consumo','agentes','codigos_recuperacion','clientes','usuarios'] as $t) {
  $pdo->exec("DROP TABLE IF EXISTS $t");
}
$pdo->exec(file_get_contents($schema));   // SQLite ejecuta múltiples sentencias y comentarios
$pdo->exec('PRAGMA foreign_keys=ON');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
echo "BD local creada en $dbPath\n";
echo "Tablas: " . implode(', ', $tables) . "\n";
