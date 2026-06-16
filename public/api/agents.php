<?php
// public/api/agents.php — Agentes de un cliente: leer de la centralita, alta manual, CRUD.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/pbx.php';

set_exception_handler(function (Throwable $e): void {
    error_log('agents.php: ' . $e->getMessage());
    json_out(['error' => 'Error interno del servidor.'], 500);
});
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Método no permitido'], 405);

$auth   = require_auth(true);
$in     = body_json();
$action = (string)($in['action'] ?? '');

function cliente_de($id) {
    $st = db()->prepare('SELECT id, nombre, tenant, desvio_100 FROM clientes WHERE id = ? LIMIT 1');
    $st->execute([(int)$id]);
    return $st->fetch();
}

switch ($action) {

    // Leer los agentes del tenant del cliente desde la centralita (DIDs con destino UUID).
    case 'read': {
        $cli = cliente_de($in['client_id'] ?? 0);
        if (!$cli) json_out(['error' => 'Cliente no encontrado.'], 404);
        $code = trim((string)$cli['tenant']);
        if ($code === '') json_out(['error' => 'El cliente no tiene tenant configurado.'], 422);

        $server = pbx_tenant_server($code);
        if ($server === null) json_out(['error' => 'No se encontró el tenant "' . $code . '" en la centralita.'], 404);

        $r = pbx_list_agents($server);
        if (empty($r['ok'])) json_out(['error' => 'No se pudieron leer los agentes de la centralita.'], 502);

        // Marca cuáles ya están dados de alta en este cliente (por DDI).
        $ya = db()->prepare('SELECT ddi FROM agentes WHERE cliente_id = ?');
        $ya->execute([(int)$cli['id']]);
        $existentes = array_column($ya->fetchAll(), 'ddi');
        foreach ($r['agentes'] as &$a) { $a['ya_dado_alta'] = in_array($a['ddi'], $existentes, true); }

        audit($auth['id'], 'agentes_leer', 'Cliente #' . $cli['id'] . ' tenant ' . $code . ' server ' . $server . ': ' . count($r['agentes']) . ' agentes');
        json_out(['server' => $server, 'agentes' => $r['agentes']]);
        break;
    }

    // Importar (guardar) agentes detectados. Ignora los que ya existan por DDI.
    case 'import': {
        $cli = cliente_de($in['client_id'] ?? 0);
        if (!$cli) json_out(['error' => 'Cliente no encontrado.'], 404);
        $lista = is_array($in['agentes'] ?? null) ? $in['agentes'] : [];
        $ins = db()->prepare('INSERT IGNORE INTO agentes (cliente_id, uuid, nombre, ddi, ivr_corte, creado, actualizado)
                              VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        $n = 0;
        foreach ($lista as $a) {
            $ddi = trim((string)($a['ddi'] ?? '')); if ($ddi === '') continue;
            $ins->execute([(int)$cli['id'], (string)($a['uuid'] ?? '') ?: null, trim((string)($a['nombre'] ?? 'Agente')), $ddi, isset($a['ivr_corte']) ? trim((string)$a['ivr_corte']) : null]);
            $n += $ins->rowCount();
        }
        audit($auth['id'], 'agentes_importar', 'Cliente #' . $cli['id'] . ': ' . $n . ' nuevos');
        json_out(['ok' => true, 'importados' => $n]);
        break;
    }

    // Alta manual de un agente.
    case 'create': {
        $cli = cliente_de($in['client_id'] ?? 0);
        if (!$cli) json_out(['error' => 'Cliente no encontrado.'], 404);
        $nombre = trim((string)($in['nombre'] ?? ''));
        $ddi    = trim((string)($in['ddi'] ?? ''));
        if ($nombre === '' || $ddi === '') json_out(['error' => 'Nombre y DDI son obligatorios.'], 422);
        try {
            $st = db()->prepare('INSERT INTO agentes (cliente_id, nombre, dial_number, ddi, ivr_corte, creado, actualizado)
                                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
            $st->execute([(int)$cli['id'], $nombre, trim((string)($in['dial_number'] ?? '')) ?: null, $ddi, trim((string)($in['ivr_corte'] ?? '')) ?: null]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') json_out(['error' => 'Ya existe un agente con ese DDI en este cliente.'], 409);
            throw $e;
        }
        audit($auth['id'], 'agente_crear', 'Cliente #' . $cli['id'] . ' DDI ' . $ddi);
        json_out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
        break;
    }

    // Listar agentes de un cliente.
    case 'list': {
        $st = db()->prepare('SELECT id, uuid, nombre, dial_number, ddi, ivr_corte, estado_desvio FROM agentes WHERE cliente_id = ? ORDER BY nombre');
        $st->execute([(int)($in['client_id'] ?? 0)]);
        json_out(['agentes' => $st->fetchAll()]);
        break;
    }

    // Editar un agente (nombre, dial_number, ddi, ivr_corte).
    case 'update': {
        $id = (int)($in['id'] ?? 0);
        $chk = db()->prepare('SELECT id FROM agentes WHERE id = ?'); $chk->execute([$id]);
        if ($chk->fetchColumn() === false) json_out(['error' => 'Agente no encontrado.'], 404);
        $campos = []; $vals = [];
        foreach (['nombre', 'dial_number', 'ddi', 'ivr_corte'] as $c) {
            if (array_key_exists($c, $in)) { $campos[] = "$c = ?"; $v = trim((string)$in[$c]); $vals[] = ($v === '' && $c !== 'nombre' && $c !== 'ddi') ? null : $v; }
        }
        if (!$campos) json_out(['error' => 'Nada que actualizar.'], 422);
        $campos[] = 'actualizado = NOW()'; $vals[] = $id;
        db()->prepare('UPDATE agentes SET ' . implode(', ', $campos) . ' WHERE id = ?')->execute($vals);
        audit($auth['id'], 'agente_actualizar', 'Agente #' . $id);
        json_out(['ok' => true]);
        break;
    }

    case 'delete': {
        $id = (int)($in['id'] ?? 0);
        db()->prepare('DELETE FROM agentes WHERE id = ?')->execute([$id]);
        audit($auth['id'], 'agente_borrar', 'Agente #' . $id);
        json_out(['ok' => true]);
        break;
    }

    default:
        json_out(['error' => 'Acción no válida.'], 400);
}
