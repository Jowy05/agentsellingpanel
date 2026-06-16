<?php
// public/api/metering.php — Recalcular consumo de minutos desde el CDR del PBX
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/pbx.php';

// Solo POST: este endpoint modifica datos (recalcula consumo y marca avisos)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'Método no permitido'], 405);
}

// Cualquier usuario autenticado (con 2FA completo) puede recalcular
$me = require_auth();

$in       = body_json();
// Validación de entrada: client_id opcional, debe ser entero positivo si viene
$clientId = 0;
if (isset($in['client_id']) && $in['client_id'] !== '' && $in['client_id'] !== null) {
    if (!is_numeric($in['client_id']) || (int)$in['client_id'] <= 0) {
        json_out(['error' => 'client_id no válido'], 400);
    }
    $clientId = (int)$in['client_id'];
}

$config  = cfg();
$dateFmt = (string)($config['app']['cdr_date_fmt'] ?? 'M-d-Y');
$webhook = trim((string)($config['n8n']['webhook'] ?? ''));

// Periodo actual y rango de fechas (primer día del mes -> hoy)
$periodo = date('Y-m');
$inicio  = date($dateFmt, strtotime('first day of this month'));
$fin     = date($dateFmt);

// Selección de clientes con DDI (uno concreto o todos) — siempre preparado
if ($clientId > 0) {
    $stmt = db()->prepare(
        "SELECT id, nombre, correo, ddi, minutos_contratados
           FROM clientes
          WHERE id = ? AND ddi IS NOT NULL AND ddi <> ''"
    );
    $stmt->execute([$clientId]);
} else {
    $stmt = db()->prepare(
        "SELECT id, nombre, correo, ddi, minutos_contratados
           FROM clientes
          WHERE ddi IS NOT NULL AND ddi <> ''
          ORDER BY nombre ASC"
    );
    $stmt->execute();
}
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$resumen = [];
$errores = [];

foreach ($clientes as $c) {
    $cid         = (int)$c['id'];
    $nombre      = (string)$c['nombre'];
    $correo      = $c['correo'] !== null ? (string)$c['correo'] : null;
    $ddi         = (string)$c['ddi'];
    $contratados = (int)$c['minutos_contratados'];

    // Consultar minutos consumidos en el CDR para el DDI y rango
    $r = pbx_cdr_minutos($ddi, $inicio, $fin);
    if (empty($r['ok'])) {
        // Acumula error y continúa con el resto (sin exponer detalles del PBX)
        $errores[] = ['cliente' => $nombre, 'error' => 'No se pudo consultar el CDR'];
        continue;
    }

    $minutos = (int)($r['minutos'] ?? 0);

    // UPSERT del consumo del periodo (clave única cliente_id+periodo)
    $up = db()->prepare(
        "INSERT INTO consumo (cliente_id, periodo, minutos_usados, actualizado)
              VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
              minutos_usados = VALUES(minutos_usados),
              actualizado    = NOW()"
    );
    $up->execute([$cid, $periodo, $minutos]);

    // Leer estado actual de avisos del consumo recién guardado
    $sel = db()->prepare(
        "SELECT aviso_75, aviso_100
           FROM consumo
          WHERE cliente_id = ? AND periodo = ?"
    );
    $sel->execute([$cid, $periodo]);
    $row = $sel->fetch(PDO::FETCH_ASSOC) ?: ['aviso_75' => 0, 'aviso_100' => 0];

    // Porcentaje de uso respecto a lo contratado (0 si no hay contratados)
    $pct = $contratados > 0 ? (int)floor(($minutos / $contratados) * 100) : 0;

    $avisosLanzados = [];

    // Umbral 75% — marcar y (si hay webhook) disparar n8n una sola vez
    if ($pct >= 75 && (int)$row['aviso_75'] === 0) {
        $mark = db()->prepare(
            "UPDATE consumo SET aviso_75 = 1 WHERE cliente_id = ? AND periodo = ?"
        );
        $mark->execute([$cid, $periodo]);
        if ($webhook !== '') {
            notificar_n8n($webhook, $nombre, $correo, $pct, 75);
        }
        $avisosLanzados[] = 75;
    }

    // Umbral 100% — marcar y (si hay webhook) disparar n8n una sola vez
    if ($pct >= 100 && (int)$row['aviso_100'] === 0) {
        $mark = db()->prepare(
            "UPDATE consumo SET aviso_100 = 1 WHERE cliente_id = ? AND periodo = ?"
        );
        $mark->execute([$cid, $periodo]);
        if ($webhook !== '') {
            notificar_n8n($webhook, $nombre, $correo, $pct, 100);
        }
        $avisosLanzados[] = 100;
    }

    $resumen[] = [
        'nombre'  => $nombre,
        'minutos' => $minutos,
        'pct'     => $pct,
        'avisos'  => $avisosLanzados,
    ];
}

// Auditoría de la operación sensible (recálculo masivo + posibles avisos)
$alcance = $clientId > 0 ? "cliente #$clientId" : 'todos los clientes';
audit(
    (int)$me['id'],
    'metering_recalcular',
    "Recalculo de consumo ($periodo) para $alcance: " . count($resumen) . " ok, " . count($errores) . " errores"
);

json_out([
    'ok'       => true,
    'periodo'  => $periodo,
    'rango'    => ['inicio' => $inicio, 'fin' => $fin],
    'clientes' => $resumen,
    'errores'  => $errores,
]);

/**
 * Dispara el webhook de n8n con el aviso de consumo.
 * No expone secretos ni rompe el flujo si falla la llamada.
 */
function notificar_n8n(string $webhook, string $cliente, ?string $correo, int $pct, int $nivel): void
{
    $payload = json_encode([
        'cliente' => $cliente,
        'correo'  => $correo,
        'pct'     => $pct,
        'nivel'   => $nivel,
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return; // No enviamos payload malformado
    }

    $ch = curl_init($webhook);
    if ($ch === false) {
        return;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    // Disparo "best effort": ignoramos la respuesta; el aviso ya quedó marcado
    curl_exec($ch);
    curl_close($ch);
}
