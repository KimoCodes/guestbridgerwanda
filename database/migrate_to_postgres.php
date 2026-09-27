<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$url = null;
$drop = false;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--drop') {
        $drop = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg !== '' && $arg[0] !== '-') {
        $url = $arg;
    }
}

if ($url === null) {
    fwrite(STDERR, "Usage: php database/migrate_to_postgres.php <postgres-url> [--drop] [--dry-run]\n");
    exit(1);
}

$mysqlSocket = getenv('GB_MYSQL_SOCKET') ?: '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
$mysqlDb = getenv('GB_MYSQL_DB') ?: 'guestbridgerwanda';
$mysqlUser = getenv('GB_MYSQL_USER') ?: 'root';
$mysqlPass = getenv('GB_MYSQL_PASS') ?: '';

function qid(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function bid(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function ident(string $base, array &$used): string
{
    $base = preg_replace('/[^a-z0-9_]+/', '_', strtolower($base));
    $base = trim($base, '_');
    if ($base === '') {
        $base = 'obj';
    }
    $name = substr($base, 0, 60);
    $i = 1;
    while (isset($used[$name])) {
        $i++;
        $name = substr($base, 0, 57) . '_' . $i;
    }
    $used[$name] = true;
    return $name;
}

function pgType(array $c): string
{
    $base = strtolower(preg_replace('/\(.*/', '', $c['column_type']));
    $len = $c['character_maximum_length'];
    $p = $c['numeric_precision'];
    $s = $c['numeric_scale'];
    switch ($base) {
        case 'tinyint':
        case 'smallint':
        case 'year':
            return 'smallint';
        case 'mediumint':
        case 'int':
        case 'integer':
            return 'integer';
        case 'bigint':
            return 'bigint';
        case 'decimal':
        case 'numeric':
        case 'dec':
        case 'fixed':
            return 'numeric(' . (int) $p . ',' . (int) $s . ')';
        case 'float':
            return 'real';
        case 'double':
            return 'double precision';
        case 'char':
        case 'varchar':
            return $len !== null ? 'varchar(' . (int) $len . ')' : 'text';
        case 'binary':
        case 'varbinary':
        case 'blob':
        case 'tinyblob':
        case 'mediumblob':
        case 'longblob':
            return 'bytea';
        case 'date':
            return 'date';
        case 'time':
            return 'time';
        case 'datetime':
        case 'timestamp':
            return 'timestamp';
        default:
            return 'text';
    }
}

function pgDefault(array $c): string
{
    $extra = strtolower((string) $c['extra']);
    if (strpos($extra, 'auto_increment') !== false) {
        return '';
    }
    $d = $c['column_default'];
    if ($d === null || $d === 'NULL') {
        return '';
    }
    if (stripos($d, 'current_timestamp') === 0 || stripos($d, 'localtimestamp') === 0) {
        return ' DEFAULT LOCALTIMESTAMP';
    }
    return ' DEFAULT ' . $d;
}

$myDsn = 'mysql:unix_socket=' . $mysqlSocket . ';dbname=' . $mysqlDb . ';charset=utf8mb4';
try {
    $my = new PDO($myDsn, $mysqlUser, $mysqlPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'MySQL connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$p = parse_url($url);
if ($p === false || !isset($p['host'], $p['user'], $p['pass'], $p['path'])) {
    fwrite(STDERR, "Invalid PostgreSQL URL.\n");
    exit(1);
}
parse_str($p['query'] ?? '', $opts);
$dsn = 'pgsql:host=' . $p['host'] . ';port=' . ($p['port'] ?? 5432) . ';dbname=' . ltrim($p['path'], '/');
foreach ($opts as $k => $v) {
    $dsn .= ';' . $k . '=' . $v;
}
try {
    $pg = new PDO($dsn, $p['user'], $p['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 30,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'PostgreSQL connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "MySQL: {$mysqlDb} @ {$mysqlSocket}\n";
echo 'PostgreSQL: ' . $p['host'] . '/' . ltrim($p['path'], '/') . "\n";

$schema = $my->query(
    "SELECT table_name, column_name, ordinal_position, column_default, is_nullable,
            data_type, column_type, character_maximum_length, numeric_precision, numeric_scale, extra
     FROM information_schema.columns
     WHERE table_schema = " . $my->quote($mysqlDb) . "
     ORDER BY table_name, ordinal_position"
)->fetchAll();

$tables = $my->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = " . $my->quote($mysqlDb) . " AND table_type = 'BASE TABLE'
     ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$columns = [];
$identity = [];
$touch = [];
foreach ($schema as $c) {
    $columns[$c['table_name']][] = $c;
    if (strpos(strtolower((string) $c['extra']), 'auto_increment') !== false) {
        $identity[$c['table_name']] = $c['column_name'];
    }
    if (strpos(strtolower((string) $c['extra']), 'on update') !== false) {
        $touch[$c['table_name']][] = $c['column_name'];
    }
}

$pkRows = $my->query(
    "SELECT tc.table_name, kcu.column_name
     FROM information_schema.table_constraints tc
     JOIN information_schema.key_column_usage kcu
       ON kcu.constraint_schema = tc.constraint_schema
      AND kcu.constraint_name = tc.constraint_name
      AND kcu.table_name = tc.table_name
     WHERE tc.table_schema = " . $my->quote($mysqlDb) . " AND tc.constraint_type = 'PRIMARY KEY'
     ORDER BY tc.table_name, kcu.ordinal_position"
)->fetchAll();
$pk = [];
foreach ($pkRows as $r) {
    $pk[$r['table_name']][] = $r['column_name'];
}

$uqRows = $my->query(
    "SELECT tc.table_name, tc.constraint_name, kcu.column_name
     FROM information_schema.table_constraints tc
     JOIN information_schema.key_column_usage kcu
       ON kcu.constraint_schema = tc.constraint_schema
      AND kcu.constraint_name = tc.constraint_name
      AND kcu.table_name = tc.table_name
     WHERE tc.table_schema = " . $my->quote($mysqlDb) . " AND tc.constraint_type = 'UNIQUE'
     ORDER BY tc.table_name, tc.constraint_name, kcu.ordinal_position"
)->fetchAll();
$unique = [];
$uniqueNames = [];
foreach ($uqRows as $r) {
    $unique[$r['table_name']][$r['constraint_name']][] = $r['column_name'];
    $uniqueNames[strtolower($r['table_name'] . '.' . $r['constraint_name'])] = true;
}

$idxRows = $my->query(
    "SELECT table_name, index_name, non_unique, seq_in_index, column_name, index_type
     FROM information_schema.statistics
     WHERE table_schema = " . $my->quote($mysqlDb) . "
     ORDER BY table_name, index_name, seq_in_index"
)->fetchAll();
$indexes = [];
foreach ($idxRows as $r) {
    if ($r['index_name'] === 'PRIMARY' || $r['column_name'] === null) {
        continue;
    }
    $indexes[$r['table_name']][$r['index_name']]['non_unique'] = (int) $r['non_unique'];
    $indexes[$r['table_name']][$r['index_name']]['type'] = $r['index_type'];
    $indexes[$r['table_name']][$r['index_name']]['cols'][] = $r['column_name'];
}

$fkRows = $my->query(
    "SELECT tc.table_name, tc.constraint_name, kcu.column_name, kcu.ordinal_position,
            kcu.referenced_table_name, kcu.referenced_column_name,
            rc.delete_rule, rc.update_rule
     FROM information_schema.table_constraints tc
     JOIN information_schema.key_column_usage kcu
       ON kcu.constraint_schema = tc.constraint_schema
      AND kcu.constraint_name = tc.constraint_name
      AND kcu.table_name = tc.table_name
     JOIN information_schema.referential_constraints rc
       ON rc.constraint_schema = tc.constraint_schema
      AND rc.constraint_name = tc.constraint_name
     WHERE tc.table_schema = " . $my->quote($mysqlDb) . " AND tc.constraint_type = 'FOREIGN KEY'
     ORDER BY tc.table_name, tc.constraint_name, kcu.ordinal_position"
)->fetchAll();
$fks = [];
foreach ($fkRows as $r) {
    $f = &$fks[$r['table_name']][$r['constraint_name']];
    $f['cols'][] = $r['column_name'];
    $f['ref_table'] = $r['referenced_table_name'];
    $f['ref_cols'][] = $r['referenced_column_name'];
    $f['delete_rule'] = $r['delete_rule'];
    $f['update_rule'] = $r['update_rule'];
    unset($f);
}

$tables = array_values(array_map(static function ($t) {
    return is_array($t) ? $t['table_name'] : $t;
}, $tables));
$indexCount = 0;
foreach ($indexes as $t => $set) {
    $indexCount += count($set);
}
echo 'Tables: ' . count($tables)
    . ' | columns: ' . count($schema)
    . ' | PKs: ' . count($pk)
    . ' | unique: ' . count($uqRows)
    . ' | indexes: ' . $indexCount
    . ' | FKs: ' . count($fkRows)
    . ' | identity: ' . count($identity)
    . ' | touch triggers: ' . count($touch) . "\n";

$existing = $pg->query(
    "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
)->fetchAll(PDO::FETCH_COLUMN);
if ($existing && !$drop) {
    fwrite(STDERR, 'PostgreSQL already has ' . count($existing) . " tables. Re-run with --drop to replace them.\n");
    exit(1);
}

$ddl = [];
$used = [];

foreach ($tables as $t) {
    $lines = [];
    foreach ($columns[$t] as $c) {
        $line = '  ' . qid($c['column_name']) . ' ' . pgType($c);
        if ($c['is_nullable'] === 'NO') {
            $line .= ' NOT NULL';
        }
        $line .= pgDefault($c);
        if (isset($identity[$t]) && $identity[$t] === $c['column_name']) {
            $line .= ' GENERATED BY DEFAULT AS IDENTITY';
        }
        $lines[] = $line;
    }
    $ddl[] = 'CREATE TABLE ' . qid($t) . " (\n" . implode(",\n", $lines) . "\n);";
    if (isset($pk[$t])) {
        $ddl[] = 'ALTER TABLE ' . qid($t) . ' ADD CONSTRAINT ' . qid($t . '_pkey')
            . ' PRIMARY KEY (' . implode(', ', array_map('qid', $pk[$t])) . ');';
    }
    foreach ($unique[$t] ?? [] as $cname => $cols) {
        $sig = 'sig_' . strtolower($t . '|' . implode(',', $cols) . '|0');
        if (isset($used[$sig])) {
            continue;
        }
        $used[$sig] = true;
        $ddl[] = 'ALTER TABLE ' . qid($t) . ' ADD CONSTRAINT ' . qid(ident('uq_' . $t . '_' . $cname, $used))
            . ' UNIQUE (' . implode(', ', array_map('qid', $cols)) . ');';
    }
    foreach ($indexes[$t] ?? [] as $iname => $idx) {
        $sig = strtolower($t . '|' . implode(',', $idx['cols']) . '|' . $idx['non_unique']);
        if (isset($used['sig_' . $sig])) {
            continue;
        }
        $used['sig_' . $sig] = true;
        if (isset($uniqueNames[strtolower($t . '.' . $iname)])) {
            continue;
        }
        $ddl[] = 'CREATE ' . ($idx['non_unique'] ? '' : 'UNIQUE ') . 'INDEX '
            . qid(ident('ix_' . $t . '_' . $iname, $used)) . ' ON ' . qid($t)
            . ' (' . implode(', ', array_map('qid', $idx['cols'])) . ');';
    }
}
foreach ($fks as $t => $set) {
    foreach ($set as $cname => $f) {
        $ddl[] = 'ALTER TABLE ' . qid($t) . ' ADD CONSTRAINT ' . qid(ident('fk_' . $t . '_' . $cname, $used))
            . ' FOREIGN KEY (' . implode(', ', array_map('qid', $f['cols'])) . ')'
            . ' REFERENCES ' . qid($f['ref_table']) . ' (' . implode(', ', array_map('qid', $f['ref_cols'])) . ')'
            . ' ON DELETE ' . strtoupper($f['delete_rule'])
            . ' ON UPDATE ' . strtoupper($f['update_rule'])
            . ' DEFERRABLE;';
    }
}
foreach ($touch as $t => $cols) {
    foreach ($cols as $col) {
        $fn = ident('gb_touch_' . $t . '_' . $col, $used);
        $ddl[] = 'CREATE OR REPLACE FUNCTION ' . qid($fn) . '() RETURNS trigger LANGUAGE plpgsql AS '
            . "\$\$ BEGIN IF NEW." . qid($col) . ' IS NOT DISTINCT FROM OLD.' . qid($col)
            . ' THEN NEW.' . qid($col) . ' := LOCALTIMESTAMP; END IF; RETURN NEW; END; $$;';
        $ddl[] = 'CREATE TRIGGER ' . qid(ident('trg_touch_' . $t . '_' . $col, $used))
            . ' BEFORE UPDATE ON ' . qid($t) . ' FOR EACH ROW EXECUTE FUNCTION ' . qid($fn) . '();';
    }
}

echo 'DDL statements: ' . count($ddl) . "\n";

if ($dryRun) {
    echo implode("\n", $ddl) . "\n";
    echo "Dry run: no changes written.\n";
    exit(0);
}

$pg->beginTransaction();
try {
    if ($existing) {
        $pg->exec('DROP TABLE IF EXISTS ' . implode(', ', array_map('qid', $existing)) . ' CASCADE');
    }
    foreach ($ddl as $statement) {
        $pg->exec($statement);
    }
    $pg->exec('SET CONSTRAINTS ALL DEFERRED');

    $copied = 0;
    foreach ($tables as $t) {
        $cols = array_map(static function ($c) {
            return $c['column_name'];
        }, $columns[$t]);
        $select = 'SELECT ' . implode(', ', array_map('bid', $cols)) . ' FROM ' . bid($t);
        $insert = 'INSERT INTO ' . qid($t) . ' (' . implode(', ', array_map('qid', $cols)) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $stmt = $pg->prepare($insert);
        $rows = 0;
        foreach ($my->query($select) as $row) {
            foreach ($cols as $i => $col) {
                $stmt->bindValue($i + 1, $row[$col], $row[$col] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
            $stmt->execute();
            $rows++;
        }
        $copied += $rows;
        printf("  %-34s %d\n", $t, $rows);
    }

    foreach ($identity as $t => $col) {
        $info = $my->query('SELECT COUNT(*) AS c, MAX(' . bid($col) . ') AS m FROM ' . bid($t))->fetch();
        $count = (int) $info['c'];
        $max = $info['m'] === null ? 0 : (int) $info['m'];
        $seq = $pg->quote($t) . ', ' . $pg->quote($col);
        $pg->exec('SELECT setval(pg_get_serial_sequence(' . $seq . '), ' . max($max, 1) . ', ' . ($count > 0 ? 'true' : 'false') . ')');
    }

    $pg->commit();
} catch (Throwable $e) {
    $pg->rollBack();
    fwrite(STDERR, 'Migration failed, rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Copied rows: {$copied}\n";

$failed = 0;
foreach ($tables as $t) {
    $myCount = (int) $my->query('SELECT COUNT(*) FROM ' . bid($t))->fetchColumn();
    $pgCount = (int) $pg->query('SELECT COUNT(*) FROM ' . qid($t))->fetchColumn();
    if ($myCount !== $pgCount) {
        echo "MISMATCH {$t}: mysql={$myCount} pg={$pgCount}\n";
        $failed++;
    }
}
echo $failed === 0 ? "Row counts verified on all " . count($tables) . " tables.\n" : "{$failed} tables mismatched.\n";
exit($failed === 0 ? 0 : 1);
