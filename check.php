<?php
declare(strict_types=1);

/**
 * Runs on GitHub Actions every 5 minutes, outside our server:
 *   php check.php <data dir>
 * probes the site, the cloud and the mail ports, keeps the 90-day history (same format as tools/monitor.php),
 * copies the notices published by orneos.eu (/status/feed.json, the last copy is kept while it is unreachable),
 * and opens an issue assigned to the owner when a service stays down (closed when it is back).
 */
require __DIR__ . '/lib.php';

const USER_AGENT = 'orneos-status (+https://status.orneos.eu)';
const FAILS_BEFORE_ISSUE = 2;          // two runs in a row, about 10 minutes
const MONITOR_STALE_AFTER = 20 * 60;   // tools/monitor.php runs every 5 minutes on the server

$dir = rtrim($argv[1] ?? 'data', '/');
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$now = time();

/** @return array{0: int, 1: string, 2: int, 3: string} status, body, milliseconds, error */
function http_get(string $url, int $timeout = 15): array
{
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
        'user_agent' => USER_AGENT, 'header' => "Cache-Control: no-cache\r\n"]]);
    $t = microtime(true);
    $body = @file_get_contents($url, false, $ctx);
    $ms = (int) round((microtime(true) - $t) * 1000);
    $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $code = 0;
    foreach ($headers as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return [$code, $body === false ? '' : $body, $ms, $body === false ? 'unreachable' : ''];
}

/** @return array{ok: bool, detail: string} */
function probe(string $name): array
{
    switch ($name) {
        case 'site':
            [$code, $body, $ms, $err] = http_get(SITE . '/fr/');
            return ['ok' => $code === 200 && str_contains($body, 'Orneos') && $ms <= 10000,
                'detail' => $err !== '' ? $err : "HTTP $code ($ms ms)"];
        case 'cloud':
            [$code, $body, $ms, $err] = http_get('https://app.orneos.eu/status.php');
            $s = json_decode($body, true);
            $ok = $code === 200 && is_array($s) && ($s['installed'] ?? false) === true && ($s['maintenance'] ?? true) === false
                && empty($s['needsDbUpgrade']) && $ms <= 10000;
            $detail = $err !== '' ? $err : "HTTP $code ($ms ms)";
            if (is_array($s) && !empty($s['maintenance'])) {
                $detail .= ', maintenance mode';
            }
            return ['ok' => $ok, 'detail' => $detail];
        case 'mail_smtps':
        case 'mail_imaps':
            $port = $name === 'mail_smtps' ? 465 : 993;
            $fp = @stream_socket_client("tcp://mail.orneos.eu:$port", $errno, $errstr, 10);
            if ($fp === false) {
                return ['ok' => false, 'detail' => "port $port closed"];
            }
            fclose($fp);
            return ['ok' => true, 'detail' => "port $port open"];
    }
    return ['ok' => false, 'detail' => 'unknown check'];
}

// ─── Probes: a failure is tried again 20 s later, so a network hiccup of GitHub's side is not an outage ───
$checks = [];
foreach (array_merge(...array_values(SERVICES)) as $name) {
    $checks[$name] = probe($name);
}
if (array_filter($checks, static fn (array $c): bool => !$c['ok'])) {
    sleep(20);
    foreach ($checks as $name => $c) {
        if (!$c['ok']) {
            $checks[$name] = probe($name);
        }
    }
}
write_json("$dir/status.json", ['checked_at' => gmdate('c', $now), 'checks' => $checks]);

// ─── Daily availability per service: [checks, failures, events], as tools/monitor.php wrote it ───
$history = read_json("$dir/history.json");
$day = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d');
foreach (SERVICES as $service => $names) {
    $failed = array_values(array_filter($names, static fn (string $n): bool => empty($checks[$n]['ok'])));
    $up = $failed === [];
    $cell = $history[$day][$service] ?? [0, 0];
    $events = isset($cell[2]) && is_array($cell[2]) ? $cell[2] : [];
    if ($up) {
        // Close today's open incidents, and one that started before midnight.
        foreach ($events as &$event) {
            $event['resolved_at'] ??= gmdate('c', $now);
        }
        unset($event);
        foreach (array_reverse(array_keys($history)) as $eventDay) {
            if ($eventDay === $day || empty($history[$eventDay][$service][2])) {
                continue;
            }
            foreach ($history[$eventDay][$service][2] as &$event) {
                $event['resolved_at'] ??= gmdate('c', $now);
            }
            unset($event);
            break;
        }
    }
    foreach ($failed as $name) {
        $at = gmdate('c', $now);
        $kind = $name === 'cloud' && str_contains($checks[$name]['detail'], 'maintenance mode') ? 'maintenance' : 'incident';
        $joined = false;
        // One record per continuous incident; GitHub may start a run a few minutes late, hence 20 minutes.
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (($events[$i]['check'] ?? '') !== $name) {
                continue;
            }
            $lastAt = strtotime((string) ($events[$i]['last_at'] ?? $events[$i]['at'] ?? ''));
            if (empty($events[$i]['resolved_at']) && $lastAt !== false && $now - $lastAt <= 20 * 60
                && ($events[$i]['kind'] ?? 'incident') === $kind) {
                $events[$i]['last_at'] = $at;
                $events[$i]['samples'] = (int) ($events[$i]['samples'] ?? 1) + 1;
                $joined = true;
            } elseif (empty($events[$i]['resolved_at'])) {
                $events[$i]['resolved_at'] = $at;
            }
            break;
        }
        if (!$joined) {
            $events[] = ['at' => $at, 'last_at' => $at, 'check' => $name, 'kind' => $kind, 'samples' => 1];
        }
    }
    $history[$day][$service] = [$cell[0] + 1, $cell[1] + ($up ? 0 : 1), array_slice($events, -200)];
}
ksort($history);
write_json("$dir/history.json", array_slice($history, -90, null, true));

// ─── Notices from orneos.eu (kept as they were while the server is unreachable) ───
[$code, $body] = http_get(SITE . '/status/feed.json', 10);
$feed = json_decode($body, true);
$feedOk = $code === 200 && is_array($feed) && isset($feed['notices']) && is_array($feed['notices']);
if ($feedOk) {
    write_json("$dir/feed.json", $feed + ['fetched_at' => gmdate('c', $now)]);
} else {
    $feed = read_json("$dir/feed.json");
}

// ─── Alerts: an issue assigned to the owner (GitHub notifies the phone), closed with the duration ───
function github(string $method, string $path, array $data = []): ?array
{
    $token = (string) getenv('GITHUB_TOKEN');
    $repo = (string) getenv('GITHUB_REPOSITORY');
    if ($token === '' || $repo === '') {
        return null;
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 20, 'user_agent' => USER_AGENT,
        'header' => "Authorization: Bearer $token\r\nAccept: application/vnd.github+json\r\nContent-Type: application/json\r\n",
        'content' => $data === [] ? '' : json_encode($data, JSON_UNESCAPED_UNICODE)]]);
    $res = @file_get_contents("https://api.github.com/repos/$repo$path", false, $ctx);
    return $res === false ? null : json_decode($res, true);
}

$names = ['site' => 'Site et connexion', 'cloud' => 'Cloud', 'mail' => 'Messagerie', 'monitor' => 'Supervision du serveur'];
$failing = [];
foreach (SERVICES as $service => $probes) {
    $bad = array_filter($probes, static fn (string $n): bool => empty($checks[$n]['ok']));
    // A cloud in maintenance mode is announced on the page, not an outage to be woken up for.
    $maintenance = $service === 'cloud' && $bad !== [] && str_contains($checks['cloud']['detail'], 'maintenance mode');
    if ($bad !== [] && !$maintenance) {
        $failing[$service] = implode(', ', array_map(static fn (string $n): string => "$n : " . $checks[$n]['detail'], array_keys($bad)));
    }
}
// The server answers but tools/monitor.php no longer runs (cron stopped, disk full…): only this page can tell.
$monitorAt = strtotime((string) ($feed['monitor_checked_at'] ?? ''));
if (!isset($failing['site']) && ($monitorAt === false || $now - $monitorAt > MONITOR_STALE_AFTER)) {
    $failing['monitor'] = $feedOk
        ? 'tools/monitor.php n\'a pas tourné depuis ' . ($monitorAt === false ? 'longtemps' : gmdate('Y-m-d H:i', $monitorAt) . ' UTC')
        : 'orneos.eu/status/feed.json ne répond pas';
}

$alerts = read_json("$dir/alerts.json");
$owner = (string) getenv('GITHUB_REPOSITORY_OWNER');
foreach (array_keys($names) as $key) {
    $a = $alerts[$key] ?? ['fails' => 0];
    if (isset($failing[$key])) {
        $a['fails'] = (int) $a['fails'] + 1;
        $a['since'] ??= gmdate('c', $now);
        if ($a['fails'] >= FAILS_BEFORE_ISSUE && empty($a['issue'])) {
            $issue = github('POST', '/issues', [
                'title'     => 'Panne : ' . $names[$key],
                'body'      => "Détecté depuis l'extérieur (GitHub) le " . gmdate('d/m/Y à H:i', strtotime($a['since'])) . " UTC.\n\n"
                    . "`{$failing[$key]}`\n\nPage publique : " . STATUS_URL . "\n\nCette issue se ferme seule au retour du service.",
                'assignees' => $owner !== '' ? [$owner] : [],
            ]);
            $a['issue'] = $issue['number'] ?? null;
        }
    } elseif ((int) $a['fails'] > 0) {
        if (!empty($a['issue'])) {
            $minutes = max(1, (int) round(($now - (int) strtotime($a['since'])) / 60));
            github('POST', '/issues/' . $a['issue'] . '/comments', ['body' => "Rétabli le " . gmdate('d/m/Y à H:i', $now) . " UTC, après environ $minutes min."]);
            github('PATCH', '/issues/' . $a['issue'], ['state' => 'closed']);
        }
        $a = ['fails' => 0];
    }
    $alerts[$key] = $a;
}
write_json("$dir/alerts.json", $alerts);

foreach ($checks as $name => $c) {
    printf("%-11s %-4s %s\n", $name, $c['ok'] ? 'ok' : 'FAIL', $c['detail']);
}
echo 'feed: ', $feedOk ? 'ok' : 'unreachable (last copy kept)', "\n";
echo 'alerts: ', $failing === [] ? 'none' : implode(', ', array_keys($failing)), "\n";
