<?php
// Seed LOCAL para pruebas: admin SIN 2FA (lo enrola el usuario en el 1er login) + clientes demo.
$dbPath = 'C:/Users/Lucia/Documents/Panel-Minutos-App/.localtools/panel.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->prepare("INSERT INTO usuarios (email,nombre,pass_hash,rol,totp_enabled,estado,intentos,creado)
               VALUES (?,?,?,?,0,'activo',0,CURRENT_TIMESTAMP)")
    ->execute(['admin@conexiatec.com', 'Admin Conexia', password_hash('Conexia2026!', PASSWORD_DEFAULT), 'admin']);

// [slug, nombre, correo, sector, plan, minutos_contratados, alta, tenant, ddi, desvio_100, minutos_usados]
$cl = [
  ['sonrisa','Clínica Dental Sonrisa','gerencia@clinicasonrisa.es','Salud','Plan Recepción 500',500,'Mar 2025','18','930905101','185',320],
  ['aurora','Seguros Aurora','soporte@segurosaurora.es','Seguros','Plan Empresa 1200',1200,'Dic 2024','18','930905102','186',1100],
];
$ins = $pdo->prepare("INSERT INTO clientes (slug,nombre,correo,sector,plan,minutos_contratados,alta,tenant,ddi,desvio_100,estado_desvio,creado,actualizado)
                      VALUES (?,?,?,?,?,?,?,?,?,?, 'normal', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$cons = $pdo->prepare("INSERT INTO consumo (cliente_id,periodo,minutos_usados,actualizado) VALUES (?,?,?,CURRENT_TIMESTAMP)");
$periodo = date('Y-m');
foreach ($cl as $c) {
  $ins->execute([$c[0],$c[1],$c[2],$c[3],$c[4],$c[5],$c[6],$c[7],$c[8],$c[9]]);
  $cons->execute([$pdo->lastInsertId(), $periodo, $c[10]]);
}
echo "Seed OK: admin@conexiatec.com / Conexia2026!  + " . count($cl) . " clientes demo (periodo $periodo)\n";
