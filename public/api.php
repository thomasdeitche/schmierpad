<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$config = require __DIR__ . '/../config.php';

const MAX_TITLE    = 500;
const MAX_TASKS    = 5000;
const MAX_BODY     = 2097152;
const PRIO_MIN     = 1;
const PRIO_MAX     = 8;
const PRIO_DEFAULT = 6;

function fail(string $code, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['error' => $code]);
    exit;
}

function ok(array $data = []): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array {
    $fh = fopen('php://input', 'rb');
    $raw = $fh ? stream_get_contents($fh, MAX_BODY + 1) : false;
    if ($fh) fclose($fh);
    if ($raw === false || strlen($raw) > MAX_BODY) fail('too_large', 413);
    $data = json_decode($raw === '' ? '[]' : $raw, true);
    return is_array($data) ? $data : [];
}

// Client-IP. Hinter dem Reverse-Proxy (Loopback) zaehlt der LETZTE X-Forwarded-For-
// Eintrag — den haengt der Proxy selbst an, davor stehende Werte koennte der Client faelschen.
function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '127.0.0.1' || $ip === '::1') {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')),
            'strlen'
        ));
        if ($parts) $ip = end($parts);
    }
    if (stripos($ip, '::ffff:') === 0) $ip = substr($ip, 7);
    return $ip;
}

function ipv4InCidr(string $ip, string $cidr): bool {
    [$net, $bits] = explode('/', $cidr) + [1 => '32'];
    $ipL = ip2long($ip);
    $netL = ip2long($net);
    if ($ipL === false || $netL === false) return false;
    $mask = ((int)$bits === 0) ? 0 : (-1 << (32 - (int)$bits));
    return ($ipL & $mask) === ($netL & $mask);
}

function lookupMac(string $ip): ?string {
    $neigh = (string)shell_exec('ip neigh show 2>/dev/null');
    foreach (explode("\n", $neigh) as $line) {
        $cols = preg_split('~\s+~', trim($line));
        if (($cols[0] ?? '') !== $ip) continue;
        if (preg_match('~lladdr\s+([0-9a-f]{2}(?::[0-9a-f]{2}){5})~i', $line, $m)) {
            return strtolower($m[1]);
        }
    }
    return null;
}

// MAC des aufrufenden Arbeitsplatzes aus der ARP-Tabelle der QNAP (nur im selben Subnetz
// moeglich — ueber einen Router waere es die Router-MAC und wuerde Listen vermischen).
function resolveMac(array $config): string {
    $ip = clientIp();
    if (!filter_var($ip, FILTER_VALIDATE_IP)) fail('no_mac', 409);
    $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    if ($isV4 && !empty($config['allowed_subnet']) && !ipv4InCidr($ip, $config['allowed_subnet'])) {
        fail('off_subnet', 409);
    }
    $mac = lookupMac($ip);
    if ($mac === null) {
        // ARP-Eintrag anstossen (unprivilegierter Connect-Versuch), dann nochmal lesen
        $target = $isV4 ? "tcp://$ip:9" : "tcp://[$ip]:9";
        $s = @stream_socket_client($target, $errno, $errstr, 0.3, STREAM_CLIENT_ASYNC_CONNECT);
        if (is_resource($s)) fclose($s);
        usleep(250000);
        $mac = lookupMac($ip);
    }
    if ($mac === null) fail('no_mac', 409);
    // Gateways (FritzBox, QNAP "server" mit VPN) sind keine Arbeitsplaetze: Wuerde VPN-Verkehr dort
    // umgesetzt (NAT), sahen alle Home-Office-Nutzer dieselbe MAC und teilten sich eine Liste.
    if (in_array($mac, array_map('strtolower', $config['blocked_macs'] ?? []), true)) fail('off_subnet', 409);
    return $mac;
}

function cleanTitle($v): string {
    $t = trim((string)$v);
    if ($t === '') fail('empty_title');
    if (mb_strlen($t) > MAX_TITLE) fail('title_too_long');
    return $t;
}

function cleanPrio($v): int {
    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) fail('bad_priority');
    $p = (int)$v;
    if ($p < PRIO_MIN || $p > PRIO_MAX) fail('bad_priority');
    return $p;
}

function taskOut(array $r): array {
    return [
        'id'         => (int)$r['id'],
        'title'      => $r['title'],
        'priority'   => (int)$r['priority'],
        'done'       => (int)$r['done'] === 1,
        'created_ts' => (int)$r['created_ts'],
        'done_ts'    => $r['done_ts'] === null ? null : (int)$r['done_ts'],
    ];
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$readActions = ['list', 'export'];

if (!in_array($action, $readActions, true)) {
    // Schreibende Aktionen: nur POST + eigener Header (erzwingt CORS-Preflight -> kein
    // Cross-Site-Schreiben aus fremden Webseiten, da die Identitaet nicht per Cookie laeuft).
    if ($method !== 'POST') fail('method_not_allowed', 405);
    if (($_SERVER['HTTP_X_SCHMIERPAD'] ?? '') !== '1') fail('forbidden', 403);
}

try {
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fail('db_connection_failed', 500);
}

$mac = resolveMac($config);

try {
    switch ($action) {
        case 'list': {
            $st = $pdo->prepare('SELECT id,title,priority,done,created_ts,done_ts FROM tasks WHERE owner_mac = ? ORDER BY id');
            $st->execute([$mac]);
            ok(['mac' => $mac, 'now' => time(), 'tasks' => array_map('taskOut', $st->fetchAll())]);
        }

        case 'export': {
            $st = $pdo->prepare('SELECT id,title,priority,done,created_ts,done_ts FROM tasks WHERE owner_mac = ? ORDER BY id');
            $st->execute([$mac]);
            $tasks = array_map(function ($r) {
                $t = taskOut($r);
                unset($t['id']);
                return $t;
            }, $st->fetchAll());
            echo json_encode([
                'app'         => 'SchmierPAD',
                'format'      => 1,
                'exported_ts' => time(),
                'tasks'       => $tasks,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        case 'add': {
            $in = input();
            $title = cleanTitle($in['title'] ?? '');
            $prio = cleanPrio($in['priority'] ?? PRIO_DEFAULT);
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE owner_mac = ?');
            $cnt->execute([$mac]);
            if ((int)$cnt->fetchColumn() >= MAX_TASKS) fail('too_many_tasks', 409);
            $now = time();
            $pdo->prepare('INSERT INTO tasks (owner_mac,title,priority,done,created_ts) VALUES (?,?,?,0,?)')
                ->execute([$mac, $title, $prio, $now]);
            $id = (int)$pdo->lastInsertId();
            ok(['task' => ['id' => $id, 'title' => $title, 'priority' => $prio, 'done' => false, 'created_ts' => $now, 'done_ts' => null]]);
        }

        case 'update': {
            $in = input();
            $id = (int)($in['id'] ?? 0);
            $st = $pdo->prepare('SELECT id,title,priority,done,created_ts,done_ts FROM tasks WHERE id = ? AND owner_mac = ?');
            $st->execute([$id, $mac]);
            $row = $st->fetch();
            if (!$row) fail('not_found', 404);

            $title = array_key_exists('title', $in) ? cleanTitle($in['title']) : $row['title'];
            $prio = array_key_exists('priority', $in) ? cleanPrio($in['priority']) : (int)$row['priority'];
            $done = array_key_exists('done', $in) ? (bool)$in['done'] : ((int)$row['done'] === 1);
            $wasDone = (int)$row['done'] === 1;
            $doneTs = $row['done_ts'] === null ? null : (int)$row['done_ts'];
            if ($done && !$wasDone) $doneTs = time();
            if (!$done) $doneTs = null;

            $pdo->prepare('UPDATE tasks SET title = ?, priority = ?, done = ?, done_ts = ? WHERE id = ? AND owner_mac = ?')
                ->execute([$title, $prio, $done ? 1 : 0, $doneTs, $id, $mac]);
            ok(['task' => ['id' => $id, 'title' => $title, 'priority' => $prio, 'done' => $done,
                           'created_ts' => (int)$row['created_ts'], 'done_ts' => $doneTs]]);
        }

        case 'delete': {
            $in = input();
            $id = (int)($in['id'] ?? 0);
            // Loeschen nur, wenn die Aufgabe als erledigt markiert ist
            $chk = $pdo->prepare('SELECT done FROM tasks WHERE id = ? AND owner_mac = ?');
            $chk->execute([$id, $mac]);
            $row = $chk->fetch();
            if (!$row) fail('not_found', 404);
            if ((int)$row['done'] !== 1) fail('not_done', 409);
            $pdo->prepare('DELETE FROM tasks WHERE id = ? AND owner_mac = ? AND done = 1')->execute([$id, $mac]);
            ok();
        }

        case 'import': {
            $in = input();
            if (($in['app'] ?? '') !== 'SchmierPAD' || (int)($in['format'] ?? 0) !== 1 || !is_array($in['tasks'] ?? null)) {
                fail('invalid_file');
            }
            $src = $in['tasks'];
            if (count($src) === 0) fail('invalid_file');
            if (count($src) > MAX_TASKS) fail('too_many_tasks', 409);

            $now = time();
            $rows = [];
            foreach ($src as $t) {
                if (!is_array($t)) fail('invalid_file');
                $title = trim((string)($t['title'] ?? ''));
                $prio = $t['priority'] ?? null;
                $created = $t['created_ts'] ?? null;
                $doneTs = $t['done_ts'] ?? null;
                if ($title === '' || mb_strlen($title) > MAX_TITLE) fail('invalid_file');
                if (!is_int($prio) || $prio < PRIO_MIN || $prio > PRIO_MAX) fail('invalid_file');
                if (!is_int($created) || $created < 0 || $created > $now + 86400) fail('invalid_file');
                $done = !empty($t['done']);
                if ($done) {
                    if (!is_int($doneTs) || $doneTs < $created || $doneTs > $now + 86400) $doneTs = $created;
                } else {
                    $doneTs = null;
                }
                $rows[] = [$title, $prio, $done ? 1 : 0, $created, $doneTs];
            }

            // Nur in eine LEERE Liste. Zaehlen + Einfuegen in einer Transaktion (Sperre auf die
            // Zeilen dieses Arbeitsplatzes), damit zwei parallele Importe sich nicht umgehen.
            $pdo->beginTransaction();
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE owner_mac = ? FOR UPDATE');
            $cnt->execute([$mac]);
            if ((int)$cnt->fetchColumn() > 0) {
                $pdo->rollBack();
                fail('list_not_empty', 409);
            }
            $ins = $pdo->prepare('INSERT INTO tasks (owner_mac,title,priority,done,created_ts,done_ts) VALUES (?,?,?,?,?,?)');
            foreach ($rows as $r) {
                $ins->execute([$mac, $r[0], $r[1], $r[2], $r[3], $r[4]]);
            }
            $pdo->commit();
            ok(['imported' => count($rows)]);
        }

        default:
            fail('unknown_action', 404);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail('server_error', 500);
}
