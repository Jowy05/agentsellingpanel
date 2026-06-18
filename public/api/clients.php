<?php
// public/api/clients.php
// CRUD de clientes del "Panel de minutos · Agente IA" de Conexia.
// Requiere sesión completa (uid + rol + twofa_ok) vía require_auth().
require __DIR__ . '/_bootstrap.php';

// Manejador global: ninguna excepción/PDOException debe filtrar SQL, esquema
// ni secretos en la respuesta. Registramos el detalle en el log del servidor
// y devolvemos un error genérico en español.
set_exception_handler(function (Throwable $e): void {
    error_log('clients.php: ' . $e->getMessage());
    json_out(['error' => 'Error interno del servidor.'], 500);
});

// Solo se aceptan peticiones POST (la acción viaja en el cuerpo).
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'Método no permitido'], 405);
}

// Sesión válida con 2FA. Devuelve ['id'=>int, 'rol'=>string].
$auth = require_auth(true);

$in     = body_json();
$action = isset($in['action']) ? (string)$in['action'] : '';

// Separación de privilegios: escribir clientes (alta/edición/baja, incl. minutos y estado de corte)
// es exclusivo de admin. El rol técnico tiene acceso de SOLO LECTURA (list/get).
if (in_array($action, ['create', 'update', 'delete'], true) && ($auth['rol'] ?? '') !== 'admin') {
    json_out(['error' => 'forbidden', 'detalle' => 'Esta acción requiere rol administrador.'], 403);
}

// Longitud máxima del slug en BD (clientes.slug VARCHAR(120)).
const SLUG_MAX = 120;

/**
 * Genera un slug base a partir del nombre (minúsculas, sin acentos, guiones).
 */
function slugify(string $texto): string
{
    $texto = trim($texto);
    // Translitera acentos comunes a ASCII.
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if ($conv !== false) {
            $texto = $conv;
        }
    }
    $texto = strtolower($texto);
    // Sustituye todo lo que no sea a-z 0-9 por guion.
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    $texto = trim((string)$texto, '-');
    if ($texto === '') {
        $texto = 'cliente';
    }
    // Reservamos margen para el sufijo "-NN" sin desbordar la columna.
    if (strlen($texto) > SLUG_MAX - 6) {
        $texto = rtrim(substr($texto, 0, SLUG_MAX - 6), '-');
        if ($texto === '') {
            $texto = 'cliente';
        }
    }
    return $texto;
}

/**
 * Devuelve un slug único en la tabla clientes, añadiendo sufijo -2, -3... si hace falta.
 */
function slug_unico(string $base): string
{
    $slug = $base;
    $i    = 1;
    $stmt = db()->prepare('SELECT 1 FROM clientes WHERE slug = ? LIMIT 1');
    while (true) {
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn() === false) {
            return $slug;
        }
        $i++;
        $sufijo = '-' . $i;
        // Recortar la base si el sufijo hiciese superar el límite de la columna.
        $maxBase = SLUG_MAX - strlen($sufijo);
        $b = strlen($base) > $maxBase ? rtrim(substr($base, 0, $maxBase), '-') : $base;
        if ($b === '') {
            $b = 'cliente';
        }
        $slug = $b . $sufijo;
    }
}

/**
 * Valida y normaliza un entero >= 0. Devuelve null si no es válido.
 */
function int_no_negativo($v): ?int
{
    if (is_int($v)) {
        return $v >= 0 ? $v : null;
    }
    if (is_string($v) && preg_match('/^\d+$/', trim($v))) {
        return (int)trim($v);
    }
    return null;
}

/**
 * Recoge y valida los campos de un cliente para create/update.
 * $obligatorios: si true exige nombre, correo y minutos_contratados (create).
 * Devuelve ['datos'=>[...], 'error'=>string|null].
 */
function recoger_campos(array $in, bool $obligatorios): array
{
    $datos = [];

    // Campos de texto admitidos (lista blanca: evita asignación masiva de id, slug, creado...).
    $textos = [
        'nombre', 'correo', 'sector', 'plan', 'alta', 'tenant',
        'ddi', 'desvio_100',
    ];
    foreach ($textos as $c) {
        if (array_key_exists($c, $in)) {
            // Rechazar tipos no escalares (arrays/objetos) que romperían (string).
            if ($in[$c] !== null && !is_scalar($in[$c])) {
                return ['datos' => [], 'error' => "El campo '$c' no es válido."];
            }
            $datos[$c] = $in[$c] === null ? null : trim((string)$in[$c]);
        }
    }

    // estado_desvio: solo 'normal' o 'cortado'.
    if (array_key_exists('estado_desvio', $in)) {
        $ed = is_scalar($in['estado_desvio']) ? (string)$in['estado_desvio'] : '';
        if ($ed !== 'normal' && $ed !== 'cortado') {
            return ['datos' => [], 'error' => "El campo 'estado_desvio' debe ser 'normal' o 'cortado'."];
        }
        $datos['estado_desvio'] = $ed;
    }

    // minutos_contratados: entero >= 0.
    if (array_key_exists('minutos_contratados', $in)) {
        $m = int_no_negativo($in['minutos_contratados']);
        if ($m === null) {
            return ['datos' => [], 'error' => "El campo 'minutos_contratados' debe ser un entero mayor o igual que 0."];
        }
        $datos['minutos_contratados'] = $m;
    }

    if ($obligatorios) {
        if (!isset($datos['nombre']) || $datos['nombre'] === '') {
            return ['datos' => [], 'error' => "El campo 'nombre' es obligatorio."];
        }
        if (!isset($datos['correo']) || $datos['correo'] === '') {
            return ['datos' => [], 'error' => "El campo 'correo' es obligatorio."];
        }
        if (!filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
            return ['datos' => [], 'error' => 'El correo no tiene un formato válido.'];
        }
        if (!isset($datos['minutos_contratados'])) {
            return ['datos' => [], 'error' => "El campo 'minutos_contratados' es obligatorio."];
        }
    } else {
        // En update, si viene correo y no es vacío, validar formato.
        if (isset($datos['correo']) && $datos['correo'] !== '' && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
            return ['datos' => [], 'error' => 'El correo no tiene un formato válido.'];
        }
    }

    return ['datos' => $datos, 'error' => null];
}

/**
 * Calcula porcentaje y estado de consumo a partir de minutos.
 */
function calc_consumo(int $contratados, int $usados): array
{
    $porcentaje = $contratados > 0 ? (int)round($usados / $contratados * 100) : 0;
    if ($porcentaje >= 100) {
        $estado = 'cortado';
    } elseif ($porcentaje >= 75) {
        $estado = 'aviso';
    } else {
        $estado = 'ok';
    }
    return ['porcentaje' => $porcentaje, 'estado' => $estado];
}

switch ($action) {

    // ---------------------------------------------------------------
    // LIST: todos los clientes + consumo del periodo actual.
    // ---------------------------------------------------------------
    case 'list': {
        $periodo = date('Y-m');
        $sql = 'SELECT c.id, c.slug, c.nombre, c.correo, c.sector, c.plan,
                       c.minutos_contratados, c.alta, c.tenant, c.ddi,
                       c.desvio_100, c.did_dest_backup,
                       c.estado_desvio, c.creado, c.actualizado,
                       COALESCE((SELECT SUM(co.minutos_usados) FROM agentes a
                                 JOIN consumo co ON co.agente_id = a.id AND co.periodo = ?
                                 WHERE a.cliente_id = c.id), 0) AS minutos_usados,
                       (SELECT COUNT(*) FROM agentes a2 WHERE a2.cliente_id = c.id) AS num_agentes
                FROM clientes c
                ORDER BY c.nombre ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute([$periodo]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agentes cortados (desviados) agrupados por cliente, para la campana de avisos.
        // Persisten hasta que se marca la reactivación (divert.php action=reactivado).
        $cutMap = [];
        foreach (db()->query("SELECT cliente_id, id, nombre, ddi FROM agentes WHERE estado_desvio = 'cortado' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC) as $ca) {
            $cutMap[(int)$ca['cliente_id']][] = ['id' => (int)$ca['id'], 'nombre' => $ca['nombre'], 'ddi' => $ca['ddi']];
        }

        $clientes = [];
        foreach ($filas as $f) {
            $contratados = (int)$f['minutos_contratados'];
            $usados      = (int)$f['minutos_usados'];
            $calc        = calc_consumo($contratados, $usados);

            $f['minutos_contratados'] = $contratados;
            $f['minutos_usados']      = $usados;
            $f['porcentaje']          = $calc['porcentaje'];
            $f['estado']              = $calc['estado'];
            $f['periodo']             = $periodo;
            $f['agentes_cortados']    = $cutMap[(int)$f['id']] ?? [];
            $clientes[] = $f;
        }

        json_out(['clientes' => $clientes, 'periodo' => $periodo], 200);
        break;
    }

    // ---------------------------------------------------------------
    // GET: un cliente por id (con consumo del periodo actual).
    // ---------------------------------------------------------------
    case 'get': {
        $id = isset($in['id']) ? int_no_negativo($in['id']) : null;
        if ($id === null || $id === 0) {
            json_out(['error' => 'Identificador de cliente no válido.'], 422);
        }

        $periodo = date('Y-m');
        $sql = 'SELECT c.id, c.slug, c.nombre, c.correo, c.sector, c.plan,
                       c.minutos_contratados, c.alta, c.tenant, c.ddi,
                       c.desvio_100, c.did_dest_backup,
                       c.estado_desvio, c.creado, c.actualizado,
                       COALESCE((SELECT SUM(co.minutos_usados) FROM agentes a
                                 JOIN consumo co ON co.agente_id = a.id AND co.periodo = ?
                                 WHERE a.cliente_id = c.id), 0) AS minutos_usados,
                       (SELECT COUNT(*) FROM agentes a2 WHERE a2.cliente_id = c.id) AS num_agentes
                FROM clientes c
                WHERE c.id = ?
                LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute([$periodo, $id]);
        $cli = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cli) {
            json_out(['error' => 'Cliente no encontrado.'], 404);
        }

        $contratados = (int)$cli['minutos_contratados'];
        $usados      = (int)$cli['minutos_usados'];
        $calc        = calc_consumo($contratados, $usados);

        $cli['minutos_contratados'] = $contratados;
        $cli['minutos_usados']      = $usados;
        $cli['porcentaje']          = $calc['porcentaje'];
        $cli['estado']              = $calc['estado'];
        $cli['periodo']             = $periodo;

        json_out(['cliente' => $cli], 200);
        break;
    }

    // ---------------------------------------------------------------
    // CREATE: nuevo cliente. Slug autogenerado y único desde el nombre.
    // ---------------------------------------------------------------
    case 'create': {
        $r = recoger_campos($in, true);
        if ($r['error'] !== null) {
            json_out(['error' => $r['error']], 422);
        }
        $datos = $r['datos'];

        // Slug único a partir del nombre.
        $slug = slug_unico(slugify($datos['nombre']));

        // Valor por defecto de estado_desvio si no llega.
        $estadoDesvio = $datos['estado_desvio'] ?? 'normal';

        $sql = 'INSERT INTO clientes
                   (slug, nombre, correo, sector, plan, minutos_contratados,
                    alta, tenant, ddi, desvio_100,
                    estado_desvio, creado, actualizado)
                VALUES
                   (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute([
                $slug,
                $datos['nombre'],
                $datos['correo'],
                $datos['sector']   ?? null,
                $datos['plan']     ?? null,
                $datos['minutos_contratados'],
                $datos['alta']     ?? null,
                $datos['tenant']   ?? null,
                $datos['ddi']      ?? null,
                $datos['desvio_100'] ?? null,
                $estadoDesvio,
            ]);
        } catch (PDOException $e) {
            // Colisión de slug por carrera u otra violación de integridad.
            // No exponemos el mensaje SQL.
            if ($e->getCode() === '23000') {
                json_out(['error' => 'No se pudo crear el cliente: conflicto de datos. Inténtalo de nuevo.'], 409);
            }
            throw $e; // lo recoge el manejador global (500 genérico)
        }

        $id = (int)db()->lastInsertId();

        audit($auth['id'], 'cliente_crear', "Alta cliente #$id ($slug)");

        json_out(['ok' => true, 'id' => $id, 'slug' => $slug], 201);
        break;
    }

    // ---------------------------------------------------------------
    // UPDATE: modifica campos del cliente indicado por id.
    // ---------------------------------------------------------------
    case 'update': {
        $id = isset($in['id']) ? int_no_negativo($in['id']) : null;
        if ($id === null || $id === 0) {
            json_out(['error' => 'Identificador de cliente no válido.'], 422);
        }

        // Comprueba que existe.
        $chk = db()->prepare('SELECT slug FROM clientes WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        $slugActual = $chk->fetchColumn();
        if ($slugActual === false) {
            json_out(['error' => 'Cliente no encontrado.'], 404);
        }

        $r = recoger_campos($in, false);
        if ($r['error'] !== null) {
            json_out(['error' => $r['error']], 422);
        }
        $datos = $r['datos'];

        if (empty($datos)) {
            json_out(['error' => 'No se han indicado campos para actualizar.'], 422);
        }

        // SET dinámico: las claves provienen de la lista blanca de recoger_campos
        // (nunca de la entrada del usuario), por lo que no hay inyección por nombre de columna.
        $sets   = [];
        $params = [];
        foreach ($datos as $campo => $valor) {
            $sets[]   = "$campo = ?";
            $params[] = $valor;
        }
        $sets[]   = 'actualizado = NOW()';
        $params[] = $id;

        $sql  = 'UPDATE clientes SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        $campos = implode(', ', array_keys($datos));
        audit($auth['id'], 'cliente_actualizar', "Edición cliente #$id; campos: $campos");

        json_out(['ok' => true, 'id' => $id], 200);
        break;
    }

    // ---------------------------------------------------------------
    // DELETE: elimina el cliente indicado por id.
    // ---------------------------------------------------------------
    case 'delete': {
        $id = isset($in['id']) ? int_no_negativo($in['id']) : null;
        if ($id === null || $id === 0) {
            json_out(['error' => 'Identificador de cliente no válido.'], 422);
        }

        $chk = db()->prepare('SELECT slug FROM clientes WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        $slug = $chk->fetchColumn();
        if ($slug === false) {
            json_out(['error' => 'Cliente no encontrado.'], 404);
        }

        $stmt = db()->prepare('DELETE FROM clientes WHERE id = ?');
        $stmt->execute([$id]);

        audit($auth['id'], 'cliente_eliminar', "Baja cliente #$id ($slug)");

        json_out(['ok' => true, 'id' => $id], 200);
        break;
    }

    default:
        json_out(['error' => 'Acción no reconocida.'], 400);
}
