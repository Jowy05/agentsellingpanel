<?php
// Ayudante LOCAL: imprime el código TOTP actual del usuario id=1 (para smoke tests).
require 'C:/Users/Lucia/Documents/Panel-Minutos-App/public/api/lib/totp.php';
if (isset($argv[1]) && $argv[1] !== '') { echo totp_code($argv[1]); return; }
$pdo = new PDO('sqlite:C:/Users/Lucia/Documents/Panel-Minutos-App/.localtools/panel.sqlite');
$s = (string)$pdo->query("SELECT totp_secret FROM usuarios WHERE id = 1")->fetchColumn();
echo $s === '' ? 'NO_SECRET' : totp_code($s);
