<?php
declare(strict_types=1);
// Capa de compatibilidad SQL para ejecutar EN LOCAL el mismo código (escrito para
// MySQL) sobre SQLite. SOLO se carga cuando cfg()['db']['driver'] === 'sqlite'.
// En producción (MySQL) este fichero NUNCA se usa, así que los endpoints no cambian.

function sqlite_sql(string $sql): string {
  // DATE_ADD(NOW(), INTERVAL n MINUTE)  ->  datetime('now','+n minutes')
  $sql = preg_replace_callback(
    '/DATE_ADD\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+MINUTE\s*\)/i',
    static fn($m) => "datetime('now','+{$m[1]} minutes')",
    $sql
  );
  // NOW()  ->  CURRENT_TIMESTAMP
  $sql = preg_replace('/\bNOW\(\)/i', 'CURRENT_TIMESTAMP', $sql);
  // UPSERT MySQL -> SQLite (único upsert: tabla consumo, clave única cliente_id+periodo)
  if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
    $sql = preg_replace('/\bVALUES\(\s*(\w+)\s*\)/i', 'excluded.$1', $sql);
    $sql = preg_replace('/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT(cliente_id, periodo) DO UPDATE SET', $sql);
  }
  return $sql;
}

class SqliteCompatPDO extends PDO {
  public function prepare(string $query, array $options = []): PDOStatement|false {
    return parent::prepare(sqlite_sql($query), $options);
  }
  public function exec(string $statement): int|false {
    return parent::exec(sqlite_sql($statement));
  }
  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
    return parent::query(sqlite_sql($query), $fetchMode, ...$fetchModeArgs);
  }
}
