<?php
/**
 * noc-treemap.php -- live estate-wide treemap for a NOC display.
 *
 * Renders every site as a packed block, each closet as a sub-block, and each
 * host as a tile colored by its worst active problem. Uplink sparklines come
 * from a PINNED config file that this page only ever reads.
 *
 * WEB
 *   Drop next to the Zabbix frontend or behind its own nginx location.
 *   Point the TV at it. Data is fetched per request, cached briefly.
 *
 * CLI
 *   php noc-treemap.php --selftest         check config, API, cache, uplinks
 *   php noc-treemap.php --seed             detect uplinks, write the pin file
 *   php noc-treemap.php --seed --force     re-detect everything, overwrite
 *   php noc-treemap.php --seed --site NHS  re-detect one site only
 *   php noc-treemap.php --list             show what is currently pinned
 *
 * Seeding is deliberately a separate command: the web process needs no write
 * access anywhere, and nothing ever silently changes a graph you chose.
 */

declare(strict_types=1);

const CONF_CANDIDATES = [
    '/etc/zabbix/noc-treemap.conf.php',
    __DIR__ . '/noc-treemap.conf.php',
];

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
function load_config(): array
{
    foreach (CONF_CANDIDATES as $path) {
        if (is_readable($path)) {
            $cfg = require $path;
            if (!is_array($cfg)) {
                throw new RuntimeException("Config at $path did not return an array");
            }
            $cfg['_path'] = $path;
            return $cfg;
        }
    }
    throw new RuntimeException('No config found. Looked in: ' . implode(', ', CONF_CANDIDATES));
}

function cfg(array $c, string $key, $default = null)
{
    return array_key_exists($key, $c) ? $c[$key] : $default;
}

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------
function cache_path(array $cfg, string $key): ?string
{
    $dir = cfg($cfg, 'cache_dir');
    if (!$dir || (int)cfg($cfg, 'cache_ttl', 0) <= 0) {
        return null;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return is_dir($dir) && is_writable($dir) ? rtrim($dir, '/') . '/' . $key . '.json' : null;
}

function zbx_api(array $cfg, string $method, $params = [], bool $auth = true, bool $cacheable = true)
{
    $body = [
        'jsonrpc' => '2.0',
        'method'  => $method,
        'params'  => (is_array($params) && count($params) === 0) ? new stdClass() : $params,
        'id'      => 1,
    ];
    $payload = json_encode($body);

    $key = 'q' . sha1($method . '|' . $payload);
    $cache = $cacheable ? cache_path($cfg, $key) : null;
    $ttl = (int)cfg($cfg, 'cache_ttl', 0);
    if ($cache && is_file($cache) && (time() - filemtime($cache)) < $ttl) {
        $hit = json_decode((string)file_get_contents($cache), true);
        if ($hit !== null) {
            return $hit;
        }
    }

    $headers = ['Content-Type: application/json-rpc'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . cfg($cfg, 'api_token', '');
    }

    $ch = curl_init(cfg($cfg, 'api_url'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    $verify = (bool)cfg($cfg, 'verify_tls', true);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)cfg($cfg, 'api_timeout', 25),
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ]);
    if (cfg($cfg, 'ca_bundle')) {
        curl_setopt($ch, CURLOPT_CAINFO, cfg($cfg, 'ca_bundle'));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Cannot reach the Zabbix API: $err");
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400) {
        throw new RuntimeException("Zabbix API returned HTTP $status for $method");
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Unparseable API response for $method");
    }
    if (isset($decoded['error'])) {
        $e = $decoded['error'];
        throw new RuntimeException(sprintf('%s: %s %s', $method,
            $e['message'] ?? '?', $e['data'] ?? ''));
    }
    $result = $decoded['result'] ?? null;

    if ($cache) {
        // Write via a temp file and rename. rename() only needs write
        // permission on the DIRECTORY, so an entry left behind by a root CLI
        // run can still be refreshed by the php-fpm user (and vice versa).
        // It is also atomic, so a concurrent reader never sees a partial file.
        $tmp = $cache . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($result), LOCK_EX) !== false) {
            @chmod($tmp, 0640);
            if (!@rename($tmp, $cache)) {
                @unlink($tmp);
            }
        }
    }
    return $result;
}

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------
/**
 * Display-name overrides. Anything absent falls through to the real name, so
 * a labels file only needs the entries you actually want to rename.
 * Closets may be keyed by full group name ("NHS/MDF") or bare sub ("MDF");
 * hosts by visible name or by hostid.
 */
function load_labels(array $cfg): array
{
    $labels = ['sites' => [], 'closets' => [], 'hosts' => []];
    foreach ((array)cfg($cfg, 'labels', []) as $section => $map) {
        if (isset($labels[$section]) && is_array($map)) {
            $labels[$section] = $map;
        }
    }
    $path = cfg($cfg, 'labels_file');
    if ($path && is_readable($path)) {
        $data = json_decode((string)file_get_contents($path), true);
        if (is_array($data)) {
            foreach (['sites', 'closets', 'hosts'] as $section) {
                if (!empty($data[$section]) && is_array($data[$section])) {
                    $labels[$section] = array_merge($labels[$section], $data[$section]);
                }
            }
        }
    }
    return $labels;
}


function host_groups_of(array $host): array
{
    foreach (['hostgroups', 'hostGroups', 'groups'] as $key) {
        if (!empty($host[$key])) {
            return $host[$key];
        }
    }
    return [];
}

function strip_label(string $name, array $cfg): string
{
    foreach ((array)cfg($cfg, 'strip', []) as $rx) {
        $name = (string)preg_replace($rx, '', $name);
    }
    $name = trim($name);
    return $name === '' ? '?' : $name;
}

function discover_sites(array $cfg, array $labels = []): array
{
    $pattern = cfg($cfg, 'group_pattern');
    $exclude = cfg($cfg, 'exclude_pattern');
    $aliases = (array)cfg($cfg, 'aliases', []);
    $only    = (array)cfg($cfg, 'only', []);

    $groups = zbx_api($cfg, 'hostgroup.get',
        ['output' => ['groupid', 'name'], 'selectHosts' => 'count']);

    $closets = [];
    $index   = [];
    foreach ((array)$groups as $g) {
        if ((int)($g['hosts'] ?? 0) === 0) {
            continue;
        }
        if ($exclude && preg_match($exclude, $g['name'])) {
            continue;
        }
        if (!preg_match($pattern, $g['name'], $m)) {
            continue;
        }
        $site = trim($m['site'] ?? $g['name']);
        $key  = strtolower($site);
        if (isset($aliases[$key])) {
            $site = $aliases[$key];
        }
        if ($only && !in_array($site, $only, true)) {
            continue;
        }
        $sub = trim($m['sub'] ?? '');
        if ($sub === '') {
            $sub = $site;
        }
        if (!isset($closets[$site])) {
            $closets[$site] = [];
        }
        $closetLabel = $labels['closets'][$g['name']]
                    ?? $labels['closets'][$sub]
                    ?? $sub;
        $closets[$site][] = ['groupid' => $g['groupid'], 'name' => $g['name'],
                             'sub' => $sub, 'display' => $closetLabel, 'hosts' => []];
        $index[$g['groupid']] = [$site, count($closets[$site]) - 1];
    }
    if (!$index) {
        return [];
    }

    $hosts = zbx_api($cfg, 'host.get', [
        'output'           => ['hostid', 'name', 'maintenance_status'],
        'groupids'         => array_keys($index),
        'monitored_hosts'  => true,
        'selectHostGroups' => ['groupid'],
    ]);

    $triggers = zbx_api($cfg, 'trigger.get', [
        'output'            => ['triggerid', 'priority', 'description'],
        'selectHosts'       => ['hostid'],
        'filter'            => ['value' => 1],
        'monitored'         => true,
        'only_true'         => true,
        'skipDependent'     => true,
        'expandDescription' => true,
    ]);
    $worst = [];
    $why   = [];
    foreach ((array)$triggers as $t) {
        $sev = (int)$t['priority'];
        foreach ((array)($t['hosts'] ?? []) as $h) {
            $id = $h['hostid'];
            if (!isset($worst[$id]) || $sev > $worst[$id]) {
                $worst[$id] = $sev;
                $why[$id]   = $t['description'] ?? '';
            }
        }
    }

    $placed = 0;
    foreach ((array)$hosts as $h) {
        $entry = [
            'hostid'      => $h['hostid'],
            'full'        => $h['name'],
            'name'        => $labels['hosts'][$h['name']]
                          ?? $labels['hosts'][(string)$h['hostid']]
                          ?? strip_label($h['name'], $cfg),
            'severity'    => $worst[$h['hostid']] ?? -1,
            'problem'     => $why[$h['hostid']] ?? '',
            'maintenance' => ($h['maintenance_status'] ?? '0') === '1',
        ];
        foreach (host_groups_of($h) as $g) {
            if (isset($index[$g['groupid']])) {
                [$site, $slot] = $index[$g['groupid']];
                $closets[$site][$slot]['hosts'][] = $entry;
                $placed++;
            }
        }
    }
    if ($hosts && $placed === 0) {
        throw new RuntimeException('Fetched hosts but could not map any to a group; '
            . 'host.get returned keys: ' . implode(', ', array_keys((array)$hosts[0])));
    }

    $sites = [];
    foreach ($closets as $site => $list) {
        $list = array_values(array_filter($list, fn($c) => count($c['hosts']) > 0));
        if (!$list) {
            continue;
        }
        usort($list, function ($a, $b) {
            $d = count($b['hosts']) <=> count($a['hosts']);
            return $d !== 0 ? $d : strcasecmp($a['sub'], $b['sub']);
        });
        $total = 0;
        foreach ($list as $c) {
            $total += count($c['hosts']);
        }
        if ($total >= (int)cfg($cfg, 'min_hosts', 1)) {
            $sites[$site] = [
                'closets' => $list,
                'count'   => $total,
                'display' => $labels['sites'][$site] ?? $site,
                'graphs'  => [],
            ];
        }
    }
    return $sites;
}

// ---------------------------------------------------------------------------
// Squarified treemap
// ---------------------------------------------------------------------------
function layout_row(array $sizes, float $x, float $y, float $dx, float $dy): array
{
    $covered = array_sum($sizes);
    $w = $dy > 0 ? $covered / $dy : 0.0;
    $out = [];
    $cy = $y;
    foreach ($sizes as $s) {
        $h = $w > 0 ? $s / $w : 0.0;
        $out[] = [$x, $cy, $w, $h];
        $cy += $h;
    }
    return $out;
}

function layout_col(array $sizes, float $x, float $y, float $dx, float $dy): array
{
    $covered = array_sum($sizes);
    $h = $dx > 0 ? $covered / $dx : 0.0;
    $out = [];
    $cx = $x;
    foreach ($sizes as $s) {
        $w = $h > 0 ? $s / $h : 0.0;
        $out[] = [$cx, $y, $w, $h];
        $cx += $w;
    }
    return $out;
}

function layout_any(array $sizes, float $x, float $y, float $dx, float $dy): array
{
    return $dx >= $dy ? layout_row($sizes, $x, $y, $dx, $dy)
                      : layout_col($sizes, $x, $y, $dx, $dy);
}

function leftover_any(array $sizes, float $x, float $y, float $dx, float $dy): array
{
    $covered = array_sum($sizes);
    if ($dx >= $dy) {
        $w = $dy > 0 ? $covered / $dy : 0.0;
        return [$x + $w, $y, $dx - $w, $dy];
    }
    $h = $dx > 0 ? $covered / $dx : 0.0;
    return [$x, $y + $h, $dx, $dy - $h];
}

function worst_ratio(array $sizes, float $x, float $y, float $dx, float $dy): float
{
    $worst = 0.0;
    foreach (layout_any($sizes, $x, $y, $dx, $dy) as [$rx, $ry, $w, $h]) {
        if ($w <= 0 || $h <= 0) {
            return INF;
        }
        $worst = max($worst, max($w / $h, $h / $w));
    }
    return $worst > 0 ? $worst : INF;
}

function squarify(array $sizes, float $x, float $y, float $dx, float $dy): array
{
    $sizes = array_values(array_filter($sizes, fn($s) => $s > 0));
    if (!$sizes || $dx <= 0 || $dy <= 0) {
        return [];
    }
    if (count($sizes) === 1) {
        return layout_any($sizes, $x, $y, $dx, $dy);
    }
    $i = 1;
    while ($i < count($sizes)
        && worst_ratio(array_slice($sizes, 0, $i), $x, $y, $dx, $dy)
           >= worst_ratio(array_slice($sizes, 0, $i + 1), $x, $y, $dx, $dy)) {
        $i++;
    }
    $current   = array_slice($sizes, 0, $i);
    $remaining = array_slice($sizes, $i);
    [$lx, $ly, $ldx, $ldy] = leftover_any($current, $x, $y, $dx, $dy);
    return array_merge(layout_any($current, $x, $y, $dx, $dy),
                       squarify($remaining, $lx, $ly, $ldx, $ldy));
}

/** Squarify arbitrary weights into a box, returning rects in the input order. */
function place(array $weights, float $x, float $y, float $w, float $h): array
{
    if (!$weights || $w <= 0 || $h <= 0) {
        return [];
    }
    $order = array_keys($weights);
    usort($order, fn($a, $b) => $weights[$b] <=> $weights[$a]);
    $ordered = array_map(fn($k) => (float)$weights[$k], $order);
    $total = array_sum($ordered) ?: 1.0;
    $scaled = array_map(fn($v) => $v * $w * $h / $total, $ordered);
    $rects = squarify($scaled, $x, $y, $w, $h);

    $out = [];
    foreach ($order as $n => $key) {
        $out[$key] = $rects[$n] ?? [$x, $y, 0.0, 0.0];
    }
    ksort($out);
    return $out;
}

// ---------------------------------------------------------------------------
// Uplinks: pinned, read-only from the web page
// ---------------------------------------------------------------------------
function load_uplinks(array $cfg): array
{
    $path = cfg($cfg, 'uplinks_file');
    if (!$path || !is_readable($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }
    unset($data['_note']);
    return $data;
}

/**
 * A site may pin one uplink or several. Accepts all three shapes:
 *   "NHS": {..}                        single
 *   "NHS": [ {..}, {..} ]              list
 *   "NHS": {"MetroE": {..}, "Internet": {..}}   keyed, key becomes the label
 */
function pin_entries($pin): array
{
    if (!is_array($pin)) {
        return [];
    }
    if (isset($pin['items'])) {
        return [$pin];
    }
    $out = [];
    foreach ($pin as $key => $entry) {
        if (is_array($entry) && isset($entry['items'])) {
            if (!isset($entry['label']) && !is_int($key)) {
                $entry['label'] = (string)$key;
            }
            $out[] = $entry;
        }
    }
    return $out;
}


function short_iface(string $name): string
{
    $name = preg_replace('~^Interface\s+~i', '', $name);
    return trim((string)$name);
}


function bucketize(array $points, int $t0, int $window, int $n): array
{
    $sums = array_fill(0, $n, 0.0);
    $hits = array_fill(0, $n, 0);
    foreach ($points as [$clock, $value]) {
        $idx = (int)floor(($clock - $t0) / $window * $n);
        if ($idx >= 0 && $idx < $n) {
            $sums[$idx] += $value;
            $hits[$idx]++;
        }
    }
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $hits[$i] ? $sums[$i] / $hits[$i] : null;
    }
    return $out;
}

/**
 * Carry the last known value forward across short gaps.
 *
 * Without this, "received" and "sent" samples that land in different time
 * buckets never appear together, so a summed total alternates between one
 * direction and the other -- which renders as a sawtooth rather than traffic.
 * Runs longer than $maxRun are left as real gaps so an outage still shows.
 */
function fill_gaps(array $buckets, int $maxRun): array
{
    if ($maxRun <= 0) {
        return $buckets;
    }
    $out = $buckets;
    $last = null;
    $run = 0;
    foreach ($buckets as $i => $v) {
        if ($v !== null) {
            $last = $v;
            $run = 0;
            continue;
        }
        if ($last === null) {
            continue;
        }
        if ($run < $maxRun) {
            $out[$i] = $last;
            $run++;
        } else {
            $last = null;
        }
    }
    return $out;
}


/** Typical seconds between samples, taken from the slowest item. */
function estimate_step(array $raw): int
{
    $slowest = 0;
    foreach ($raw as $points) {
        if (count($points) < 3) {
            continue;
        }
        $deltas = [];
        for ($i = 1; $i < count($points); $i++) {
            $d = $points[$i][0] - $points[$i - 1][0];
            if ($d > 0) {
                $deltas[] = $d;
            }
        }
        if (!$deltas) {
            continue;
        }
        sort($deltas);
        $median = $deltas[intdiv(count($deltas), 2)];
        $slowest = max($slowest, $median);
    }
    return $slowest > 0 ? $slowest : 60;
}


function history_for(array $cfg, array $ids_by_type, int $from): array
{
    $series = [];
    foreach ($ids_by_type as $valueType => $itemids) {
        if (!$itemids) {
            continue;
        }
        $rows = zbx_api($cfg, 'history.get', [
            'output'    => 'extend',
            'history'   => (int)$valueType,
            'itemids'   => array_values($itemids),
            'time_from' => $from,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
            'limit'     => (int)cfg($cfg, 'history_limit', 20000),
        ]);
        foreach ((array)$rows as $r) {
            $series[$r['itemid']][] = [(int)$r['clock'], (float)$r['value']];
        }
    }
    return $series;
}

function attach_graphs(array $cfg, array &$sites, array $uplinks): void
{
    if ((float)cfg($cfg, 'graph_h', 0) <= 0 || !$uplinks) {
        return;
    }
    $window  = (int)cfg($cfg, 'graph_window', 7200);
    $buckets = (int)cfg($cfg, 'graph_buckets', 120);
    $t0      = time() - $window;

    // One resolve pass for every pinned item across all sites.
    $wanted = [];
    foreach ($uplinks as $site => $pin) {
        foreach (pin_entries($pin) as $entry) {
            foreach ((array)($entry['items'] ?? []) as $it) {
                if (!empty($it['itemid'])) {
                    $wanted[$it['itemid']] = true;
                }
            }
        }
    }
    $live = [];
    if ($wanted) {
        $rows = zbx_api($cfg, 'item.get', [
            'output'  => ['itemid', 'name', 'value_type', 'hostid'],
            'itemids' => array_map('strval', array_keys($wanted)),
        ]);
        foreach ((array)$rows as $r) {
            $live[(string)$r['itemid']] = $r;
        }
    }

    foreach ($sites as $label => &$site) {
        $entries = pin_entries($uplinks[$label] ?? null);
        if (!$entries) {
            continue;
        }
        $graphs = [];

        foreach ($entries as $entry) {
            $chosen = [];
            foreach ((array)($entry['items'] ?? []) as $it) {
                $id = isset($it['itemid']) ? (string)$it['itemid'] : null;
                if ($id !== null && isset($live[$id])) {
                    $chosen[] = $live[$id];
                }
            }
            if (!$chosen) {
                continue;
            }

            $byType = [];
            foreach ($chosen as $it) {
                $byType[(int)$it['value_type']][] = $it['itemid'];
            }
            $raw = history_for($cfg, $byType, $t0);
            if (!$raw) {
                continue;
            }

            // Bucket width must be at least the poll interval, or samples
            // from different items fall into separate buckets and never
            // combine. graph_buckets = 0 derives it from the data.
            $n = $buckets;
            $step = estimate_step($raw);
            if ($n <= 0) {
                $n = max(20, min(240, (int)floor($window / max($step, 30))));
            } elseif ($window / $n < $step) {
                $n = max(20, (int)floor($window / $step));
            }

            $series = [];
            foreach ($chosen as $it) {
                if (!empty($raw[$it['itemid']])) {
                    $series[] = [$it['name'],
                                 bucketize($raw[$it['itemid']], $t0, $window, $n)];
                }
            }
            if (!$series) {
                continue;
            }

            $maxRun = (int)cfg($cfg, 'graph_fill_gaps', 3);
            $filled = [];
            foreach ($series as [$name, $b]) {
                $filled[] = [$name, fill_gaps($b, $maxRun)];
            }
            $series = $filled;

            if (cfg($cfg, 'graph_mode', 'total') === 'total') {
                $combined = [];
                for ($i = 0; $i < $n; $i++) {
                    $vals = [];
                    foreach ($series as [$sn, $b]) {
                        if ($b[$i] !== null) {
                            $vals[] = $b[$i];
                        }
                    }
                    // Only total a bucket where every direction is present,
                    // otherwise a half-filled bucket reads as a dropout.
                    $combined[] = count($vals) === count($series) ? array_sum($vals) : null;
                }
                $plotted = [['Traffic Total', fill_gaps($combined, $maxRun)]];
            } else {
                $plotted = $series;
            }

            $all = [];
            foreach ($plotted as [$n, $b]) {
                foreach ($b as $v) {
                    if ($v !== null) {
                        $all[] = $v;
                    }
                }
            }
            if (!$all) {
                continue;
            }
            $div = (float)cfg($cfg, 'graph_divisor', 1e6);
            $last = 0.0;
            foreach ($plotted as [$n, $b]) {
                for ($i = count($b) - 1; $i >= 0; $i--) {
                    if ($b[$i] !== null) {
                        $last = $b[$i];
                        break 2;
                    }
                }
            }
            $scaled = [];
            foreach ($plotted as [$n, $b]) {
                $scaled[] = [$n, array_map(fn($v) => $v === null ? null : $v / $div, $b)];
            }
            $graphs[] = [
                'series' => $scaled,
                'max'    => max($all) / $div,
                'min'    => min($all) / $div,
                'last'   => $last / $div,
                't0'     => $t0,
                't1'     => $t0 + $window,
                'host'   => $entry['host'] ?? '',
                'label'  => $entry['label'] ?? short_iface((string)($entry['interface'] ?? '')),
            ];
        }
        $site['graphs'] = $graphs;
    }
    unset($site);
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------
const SEVERITY = [
    5 => ['Disaster', '#E45959'],
    4 => ['High', '#E97659'],
    3 => ['Average', '#FFA059'],
    2 => ['Warning', '#FFC859'],
    1 => ['Information', '#7499FF'],
    0 => ['Not classified', '#97AAB3'],
];
const OK_COLOR = '#59DB8F';
const MAINT_COLOR = '#B8C4CC';
const NICE_MAX = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 25000, 100000];

function nice_ceiling(float $v): float
{
    $steps = NICE_MAX;
    foreach ($steps as $step) {
        if ($v <= $step) {
            return (float)$step;
        }
    }
    return (float)end($steps);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wrappable(string $label): string
{
    return str_replace(['_', '-', '.'], ['_<wbr>', '-<wbr>', '.<wbr>'], e($label));
}

function fmt_rate(float $v): string
{
    if ($v >= 100) {
        return (string)round($v);
    }
    return $v >= 10 ? number_format($v, 1) : number_format($v, 2);
}

function font_for(float $w, float $h, array $cfg, float $scale = 1.0): float
{
    $size = min($w * 0.115, $h * 0.30) * $scale;
    return max((float)cfg($cfg, 'min_font', 0.34),
           min((float)cfg($cfg, 'max_font', 1.10), $size));
}

/** Font size (vw) and line count so a label fills its tile. */
function fit_text(string $label, float $w, float $h, array $cfg): array
{
    $fitW = (float)cfg($cfg, 'fit_width', 1920);
    $fitH = (float)cfg($cfg, 'fit_height', 1080);
    $charR = (float)cfg($cfg, 'char_ratio', 0.58);
    $lineR = (float)cfg($cfg, 'line_ratio', 1.12);
    $pad = (float)cfg($cfg, 'text_pad', 2.0);

    $availW = max(1.0, $w / 100 * $fitW - 2 * $pad);
    $availH = max(1.0, $h / 100 * $fitH - 2 * $pad);

    $tokens = preg_split('~[_\-./:]~', $label) ?: [];
    $longest = 1;
    foreach ($tokens as $t) {
        $longest = max($longest, strlen($t));
    }
    $chars = max(strlen($label), 1);

    $byWidth = $availW / ($longest * $charR);
    $byArea  = sqrt($availW * $availH * (float)cfg($cfg, 'fill_ratio', 0.74)
                    / ($chars * $charR * $lineR));

    $maxPx = (float)cfg($cfg, 'max_font', 1.10) / 100 * $fitW;
    $minPx = (float)cfg($cfg, 'min_font', 0.34) / 100 * $fitW;
    $px = max($minPx, min($byWidth, $byArea, $maxPx));

    for ($i = 0; $i < 48; $i++) {
        $lines = max(1, (int)floor($availH / ($px * $lineR)));
        $perLine = max(1, (int)floor($availW / ($px * $charR)));
        if ($perLine * $lines >= $chars || $px <= $minPx * 1.001) {
            break;
        }
        $px = max($minPx, $px * 0.94);
    }
    $lines = max(1, (int)floor($availH / ($px * $lineR)));
    return [$px / $fitW * 100, $lines];
}

function tile_color(array $host, array $cfg): string
{
    if ($host['maintenance'] && !cfg($cfg, 'maintenance_severity', false)) {
        return MAINT_COLOR;
    }
    return $host['severity'] < 0 ? OK_COLOR : SEVERITY[$host['severity']][1];
}

/**
 * Draw the traffic graph. Two styles:
 *   'chart' - axes, gridlines, time labels, annotated min/max (needs height)
 *   'spark' - bare area+line, for bands too short to letter
 * Falls back to 'spark' automatically when the box is too small.
 *
 * The viewBox is sized to the box's REAL pixel dimensions so that
 * preserveAspectRatio="none" maps 1:1 and text is not stretched.
 */
function graph_svg(array $graph, array $cfg, float $wPct, float $hPct): array
{
    $W = max(60.0, $wPct / 100 * (float)cfg($cfg, 'fit_width', 1920));
    $H = max(20.0, $hPct / 100 * (float)cfg($cfg, 'fit_height', 1080));

    $ceiling = (float)cfg($cfg, 'graph_ceiling', 0);
    if ($ceiling <= 0) {
        $ceiling = nice_ceiling((float)$graph['max']);
    }

    $axes = cfg($cfg, 'graph_style', 'chart') === 'chart'
         && $H >= (float)cfg($cfg, 'graph_axes_min_h', 46)
         && $W >= 180;

    $fs = max(7.0, min(12.0, $H * 0.145));
    $ml = $axes ? $fs * 3.2 : 0.0;   // room for "1,000"
    $mb = $axes ? $fs * 1.9 : 0.0;   // room for "12:05 PM"
    $mt = $axes ? $fs * 1.1 : 0.0;
    $mr = $axes ? $fs * 0.8 : 0.0;
    $px = $ml;
    $py = $mt;
    $pw = max(10.0, $W - $ml - $mr);
    $ph = max(8.0, $H - $mt - $mb);

    $svg = sprintf('<svg class="spark" viewBox="0 0 %.1f %.1f" preserveAspectRatio="none">',
                   $W, $H);

    $yOf = fn(float $v) => $py + $ph - min($v / $ceiling, 1.0) * $ph;

    if ($axes) {
        $steps = $ph > 78 ? 4 : 2;
        for ($i = 0; $i <= $steps; $i++) {
            $value = $ceiling * $i / $steps;
            $y = $yOf($value);
            $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" class="gridline"/>',
                            $px, $y, $px + $pw, $y);
            $svg .= sprintf('<text x="%.1f" y="%.1f" class="axis" text-anchor="end" '
                          . 'font-size="%.1f">%s</text>',
                            $px - $fs * 0.4, $y + $fs * 0.35, $fs,
                            number_format($value, 0));
        }
        $t0 = (int)($graph['t0'] ?? 0);
        $t1 = (int)($graph['t1'] ?? 0);
        if ($t1 > $t0) {
            $marks = $pw > 460 ? 5 : ($pw > 260 ? 4 : 3);
            for ($i = 0; $i < $marks; $i++) {
                $frac = $i / ($marks - 1);
                $x = $px + $frac * $pw;
                $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" '
                              . 'class="gridline vert"/>', $x, $py, $x, $py + $ph);
                $anchor = $i === 0 ? 'start' : ($i === $marks - 1 ? 'end' : 'middle');
                $svg .= sprintf('<text x="%.1f" y="%.1f" class="axis" text-anchor="%s" '
                              . 'font-size="%.1f">%s</text>',
                                $x, $py + $ph + $fs * 1.45, $anchor, $fs,
                                date('g:i A', (int)round($t0 + $frac * ($t1 - $t0))));
            }
        }
        $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" class="axisline"/>',
                        $px, $py, $px, $py + $ph);
        $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" class="axisline"/>',
                        $px, $py + $ph, $px + $pw, $py + $ph);
    }

    $colors = (array)cfg($cfg, 'graph_colors', ['#7EB6FF']);
    $primary = null;
    foreach ($graph['series'] as $idx => [$name, $buckets]) {
        $n = count($buckets);
        $pts = [];
        foreach ($buckets as $i => $value) {
            if ($value === null) {
                continue;
            }
            $x = $px + ($i / max($n - 1, 1)) * $pw;
            $pts[] = [$x, $yOf($value), $value];
        }
        if (count($pts) < 2) {
            continue;
        }
        if ($primary === null) {
            $primary = $pts;
        }
        $color = $colors[$idx % count($colors)];
        $line = '';
        foreach ($pts as $p) {
            $line .= sprintf('%.1f,%.1f ', $p[0], $p[1]);
        }
        $line = trim($line);
        $svg .= sprintf('<polygon points="%.1f,%.1f %s %.1f,%.1f" fill="%s" opacity=".28"/>',
                        $pts[0][0], $py + $ph, $line,
                        $pts[count($pts) - 1][0], $py + $ph, $color);
        $svg .= sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="%.1f" '
                      . 'stroke-linejoin="round"/>', $line, $color, $axes ? 1.6 : 2.2);
    }

    // Annotate the high and low points of the first series, PRTG-style.
    if ($axes && $primary) {
        $hi = $lo = $primary[0];
        foreach ($primary as $p) {
            if ($p[2] > $hi[2]) {
                $hi = $p;
            }
            if ($p[2] < $lo[2]) {
                $lo = $p;
            }
        }
        foreach ([['Max', $hi, -1], ['Min', $lo, 1]] as [$tag, $p, $dir]) {
            $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" class="marker"/>',
                            $p[0], $p[1], max(2.0, $fs * 0.26));
            $ty = $p[1] + $dir * $fs * 1.25;
            $ty = max($py + $fs, min($py + $ph - $fs * 0.2, $ty));
            $anchor = 'middle';
            $tx = $p[0];
            if ($tx < $px + $pw * 0.16) {
                $anchor = 'start';
                $tx = $px + 2;
            } elseif ($tx > $px + $pw * 0.84) {
                $anchor = 'end';
                $tx = $px + $pw - 2;
            }
            $svg .= sprintf('<text x="%.1f" y="%.1f" class="mark" text-anchor="%s" '
                          . 'font-size="%.1f">%s: %s %s</text>',
                            $tx, $ty, $anchor, $fs, $tag, fmt_rate($p[2]),
                            e((string)cfg($cfg, 'graph_units', '')));
        }
    }

    return [$svg . '</svg>', $ceiling, $axes];
}


function render(array $sites, array $cfg, string $baseUrl): string
{
    $divs = [];
    $labels = array_keys($sites);
    usort($labels, fn($a, $b) => $sites[$b]['count'] <=> $sites[$a]['count']);

    $exp = (float)cfg($cfg, 'exponent', 0.85);
    $weights = [];
    foreach ($labels as $i => $label) {
        $weights[$i] = pow($sites[$label]['count'], $exp);
    }

    $top = cfg($cfg, 'show_summary', true) ? (float)cfg($cfg, 'summary_h', 4.2) : 0.0;
    $siteRects = place($weights, 0.0, $top, 100.0, 100.0 - $top);

    $counts = [];
    foreach ($sites as $site) {
        foreach ($site['closets'] as $closet) {
            foreach ($closet['hosts'] as $h) {
                $key = ($h['maintenance'] && !cfg($cfg, 'maintenance_severity', false))
                     ? 'maint' : (string)$h['severity'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
    }

    $gap = (float)cfg($cfg, 'gap', 0.25);
    $pad = (float)cfg($cfg, 'pad', 0.18);
    $hideBelow = (float)cfg($cfg, 'hide_label_below', 1.5);

    foreach ($labels as $i => $label) {
        [$sx, $sy, $sw, $sh] = $siteRects[$i];
        if ($sw <= 0 || $sh <= 0) {
            continue;
        }
        $site = $sites[$label];
        $divs[] = sprintf('<div class="site" style="left:%.4f%%;top:%.4f%%;width:%.4f%%;'
            . 'height:%.4f%%"></div>', $sx, $sy, $sw, $sh);

        $hdr = min((float)cfg($cfg, 'site_header', 2.4), $sh * 0.22);
        $divs[] = sprintf('<div class="site-hdr" style="left:%.4f%%;top:%.4f%%;width:%.4f%%;'
            . 'height:%.4f%%;font-size:%.3fvw">%s <span class="n">%d</span></div>',
            $sx, $sy, $sw, $hdr, font_for($sw, $hdr, $cfg, 1.9),
            e((string)($site['display'] ?? $label)), $site['count']);

        $gband = 0.0;
        $graphs = $site['graphs'] ?? [];
        $gcount = count($graphs);
        if ($gcount > 0 && (float)cfg($cfg, 'graph_h', 0) > 0) {
            $want = (float)cfg($cfg, 'graph_h') * $gcount;
            $gband = min($want, $sh * (float)cfg($cfg, 'graph_max_share', 0.34));
            $rowH = $gband / $gcount;
            if ($rowH > 0.7 && $sw > (float)cfg($cfg, 'graph_hide_below', 8.0)) {
                $gx = $sx + $gap;
                $gw = max(0.0, $sw - 2 * $gap);
                foreach ($graphs as $gi => $graph) {
                    [$svg, $ceiling, $hasAxes] = graph_svg($graph, $cfg, $gw, $rowH);
                    $gy = $sy + $hdr + $gi * $rowH;
                    $name = (string)($graph['label'] ?? '');
                    $nameChip = $name === '' ? '' : sprintf(
                        '<span class="g-name" style="font-size:%.3fvw">%s</span>',
                        font_for($gw, $rowH, $cfg, 0.52), e($name));
                    // With axes drawn the ceiling and min/max are already on
                    // the chart; only the current value is still worth a chip.
                    $scaleChip = $hasAxes ? '' : sprintf(
                        '<span class="g-scale" style="font-size:%.3fvw">%s</span>',
                        font_for($gw, $rowH, $cfg, 0.52), (string)(int)$ceiling);
                    $statChip = $hasAxes
                        ? sprintf('<span class="g-stat" style="font-size:%.3fvw">'
                                . '&#9679;%s %s</span>',
                                  font_for($gw, $rowH, $cfg, 0.62),
                                  fmt_rate((float)$graph['last']),
                                  e((string)cfg($cfg, 'graph_units', '')))
                        : sprintf('<span class="g-stat" style="font-size:%.3fvw">'
                                . '&#9650;%s &#9660;%s &#9679;%s %s</span>',
                                  font_for($gw, $rowH, $cfg, 0.62),
                                  fmt_rate((float)$graph['max']),
                                  fmt_rate((float)$graph['min']),
                                  fmt_rate((float)$graph['last']),
                                  e((string)cfg($cfg, 'graph_units', '')));
                    $divs[] = sprintf('<div class="graph" style="left:%.4f%%;top:%.4f%%;'
                        . 'width:%.4f%%;height:%.4f%%">%s%s%s%s</div>',
                        $gx, $gy, $gw, $rowH, $svg, $scaleChip, $nameChip, $statChip);
                }
            } else {
                $gband = 0.0;
            }
        }

        $ix = $sx + $gap;
        $iy = $sy + $hdr + $gband;
        $iw = max(0.0, $sw - 2 * $gap);
        $ih = max(0.0, $sh - $hdr - $gband - $gap);

        $closetWeights = [];
        foreach ($site['closets'] as $ci => $c) {
            $closetWeights[$ci] = count($c['hosts']);
        }
        $closetRects = place($closetWeights, $ix, $iy, $iw, $ih);

        foreach ($site['closets'] as $ci => $closet) {
            [$cx, $cy, $cw, $ch] = $closetRects[$ci];
            if ($cw <= 0 || $ch <= 0) {
                continue;
            }
            $divs[] = sprintf('<div class="closet" style="left:%.4f%%;top:%.4f%%;'
                . 'width:%.4f%%;height:%.4f%%"></div>', $cx, $cy, $cw, $ch);

            $chdr = min((float)cfg($cfg, 'closet_header', 1.6), $ch * 0.26);
            if ($cw > $hideBelow * 1.6 && $chdr > 0.6) {
                $divs[] = sprintf('<div class="closet-hdr" style="left:%.4f%%;top:%.4f%%;'
                    . 'width:%.4f%%;height:%.4f%%;font-size:%.3fvw">%s</div>',
                    $cx, $cy, $cw, $chdr, font_for($cw, $chdr, $cfg, 1.35),
                    e((string)($closet['display'] ?? $closet['sub'])));
            } else {
                $chdr = 0.0;
            }

            $hosts = $closet['hosts'];
            usort($hosts, function ($a, $b) {
                $d = $b['severity'] <=> $a['severity'];
                return $d !== 0 ? $d : strcasecmp($a['name'], $b['name']);
            });
            $hx = $cx + $pad;
            $hy = $cy + $chdr;
            $hw = max(0.0, $cw - 2 * $pad);
            $hh = max(0.0, $ch - $chdr - $pad);
            $tileRects = place(array_fill(0, count($hosts), 1), $hx, $hy, $hw, $hh);

            $uniform = null;
            if (cfg($cfg, 'font_uniform', 'closet') === 'closet') {
                $sizes = [];
                foreach ($hosts as $hi => $h) {
                    [$tx, $ty, $tw, $th] = $tileRects[$hi];
                    if ($tw > $hideBelow && $th > $hideBelow * 0.55) {
                        $sizes[] = fit_text($h['name'], $tw, $th, $cfg)[0];
                    }
                }
                if ($sizes) {
                    sort($sizes);
                    $idx = min(count($sizes) - 1,
                               (int)floor(count($sizes) * (float)cfg($cfg, 'font_percentile', 0.40)));
                    $uniform = $sizes[$idx];
                }
            }

            foreach ($hosts as $hi => $host) {
                [$tx, $ty, $tw, $th] = $tileRects[$hi];
                if ($tw <= 0 || $th <= 0) {
                    continue;
                }
                $sevLabel = $host['severity'] < 0 ? 'OK' : SEVERITY[$host['severity']][0];
                $tip = $host['full'] . "\n" . $sevLabel
                     . ($host['problem'] !== '' ? '  --  ' . $host['problem'] : '');
                $text = '';
                if ($tw > $hideBelow && $th > $hideBelow * 0.55) {
                    [$fsize, $lines] = fit_text($host['name'], $tw, $th, $cfg);
                    if ($uniform !== null) {
                        $fsize = $uniform;
                        $availH = $th / 100 * (float)cfg($cfg, 'fit_height', 1080)
                                - 2 * (float)cfg($cfg, 'text_pad', 2.0);
                        $lines = max(1, (int)floor($availH /
                            ($fsize / 100 * (float)cfg($cfg, 'fit_width', 1920)
                             * (float)cfg($cfg, 'line_ratio', 1.12))));
                    }
                    $text = sprintf('<span style="font-size:%.3fvw;-webkit-line-clamp:%d;'
                        . 'line-clamp:%d">%s</span>', $fsize, $lines, $lines,
                        wrappable($host['name']));
                }
                $divs[] = sprintf('<a class="host%s" href="%szabbix.php?action=latest.view'
                    . '&amp;hostids%%5B%%5D=%s" target="_blank" title="%s" '
                    . 'style="left:%.4f%%;top:%.4f%%;width:%.4f%%;height:%.4f%%;background:%s">%s</a>',
                    ($host['maintenance'] && !cfg($cfg, 'maintenance_severity', false)) ? ' maint' : '',
                    $baseUrl, $host['hostid'], e($tip), $tx, $ty, $tw, $th,
                    tile_color($host, $cfg), $text);
            }
        }
    }

    $summary = '';
    if (cfg($cfg, 'show_summary', true)) {
        $chips = [];
        foreach ([5, 4, 3, 2, 1, 0] as $sev) {
            if (!empty($counts[(string)$sev])) {
                $chips[] = sprintf('<span class="chip"><i style="background:%s"></i>%s %d</span>',
                    SEVERITY[$sev][1], SEVERITY[$sev][0], $counts[(string)$sev]);
            }
        }
        if (!empty($counts['maint'])) {
            $chips[] = sprintf('<span class="chip"><i style="background:%s"></i>Maintenance %d</span>',
                MAINT_COLOR, $counts['maint']);
        }
        $chips[] = sprintf('<span class="chip"><i style="background:%s"></i>OK %d</span>',
            OK_COLOR, $counts['-1'] ?? 0);
        $total = array_sum($counts);
        $summary = sprintf('<div class="summary" style="height:%.4f%%">'
            . '<div class="brand">%s</div><div class="chips">%s</div>'
            . '<div class="stamp">%d hosts &middot; %s</div></div>',
            (float)cfg($cfg, 'summary_h', 4.2), e((string)cfg($cfg, 'title', '')),
            implode('', $chips), $total, date('D d M H:i:s'));
    }

    $refresh = (int)cfg($cfg, 'refresh', 0) > 0
        ? sprintf('<meta http-equiv="refresh" content="%d">', (int)cfg($cfg, 'refresh'))
        : '';

    return page_html([
        'title'   => e((string)cfg($cfg, 'title', 'Network Status')),
        'refresh' => $refresh,
        'summary' => $summary,
        'divs'    => implode("\n", $divs),
        'bg'      => (string)cfg($cfg, 'background', '#141b21'),
        'fg'      => (string)cfg($cfg, 'foreground', '#e6edf3'),
    ]);
}

function page_html(array $v): string
{
    $css = <<<'CSS'
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%;width:100%;overflow:hidden;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  #wrap{position:relative;height:100%;width:100%}
  .summary{position:absolute;left:0;top:0;width:100%;display:flex;align-items:center;
    gap:1.4vw;padding:0 .8vw;border-bottom:1px solid #2b3640}
  .brand{font-weight:700;font-size:1.35vw;letter-spacing:.02em;white-space:nowrap}
  .chips{display:flex;gap:.9vw;flex-wrap:nowrap;overflow:hidden;flex:1}
  .chip{display:flex;align-items:center;gap:.32vw;font-size:.82vw;white-space:nowrap;opacity:.92}
  .chip i{width:.62vw;height:.62vw;border-radius:2px;display:inline-block}
  .stamp{font-size:.78vw;opacity:.6;white-space:nowrap}
  .site{position:absolute;background:#1b232b;border:1px solid #33414d;border-radius:3px}
  .site-hdr{position:absolute;display:flex;align-items:center;gap:.4vw;padding:0 .35vw;
    font-weight:700;overflow:hidden;white-space:nowrap}
  .site-hdr .n{opacity:.45;font-weight:500}
  .closet{position:absolute;background:#212b34;border:1px solid #384754;border-radius:2px}
  .closet-hdr{position:absolute;display:flex;align-items:center;padding:0 .3vw;opacity:.72;
    overflow:hidden;white-space:nowrap;font-weight:600}
  .graph{position:absolute;background:#18212a;border:1px solid #33414d;border-radius:2px;
    overflow:hidden}
  .graph .spark{position:absolute;inset:0;width:100%;height:100%;display:block}
  .graph .gridline{stroke:#3a4a57;stroke-width:1;stroke-dasharray:3 4}
  .graph .gridline.vert{stroke:#2f3d49}
  .graph .axisline{stroke:#54687a;stroke-width:1}
  .graph .axis{fill:#8fa3b0;font-family:inherit}
  .graph .mark{fill:#dbe6ee;font-family:inherit;font-weight:600;paint-order:stroke;
    stroke:rgba(20,27,33,.85);stroke-width:2.5}
  .graph .marker{fill:#dbe6ee;stroke:#141b21;stroke-width:1}
  .graph .g-scale{position:absolute;left:.18vw;top:.08vw;opacity:.55;line-height:1;
    background:rgba(20,27,33,.72);padding:.04vw .14vw;border-radius:2px}
  .graph .g-name{position:absolute;left:50%;top:.08vw;transform:translateX(-50%);
    opacity:.75;line-height:1;white-space:nowrap;background:rgba(20,27,33,.72);
    padding:.04vw .18vw;border-radius:2px;font-weight:600}
  .graph .g-stat{position:absolute;right:.18vw;bottom:.08vw;opacity:.95;line-height:1;
    font-variant-numeric:tabular-nums;background:rgba(20,27,33,.72);
    padding:.05vw .18vw;border-radius:2px}
  .host{position:absolute;display:flex;align-items:center;justify-content:center;
    border-radius:1px;overflow:hidden;text-decoration:none;color:#10181f;
    line-height:1;text-align:center;box-shadow:inset 0 0 0 1px rgba(0,0,0,.16)}
  .host span{padding:1px 2px;max-width:100%;font-weight:600;line-height:1.08;
    display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden;
    text-overflow:ellipsis;white-space:normal;overflow-wrap:anywhere;word-break:normal}
  .host.maint{background-image:repeating-linear-gradient(45deg,
    rgba(255,255,255,.28) 0 3px,transparent 3px 7px)}
  .host:hover{outline:2px solid #fff;outline-offset:-2px;z-index:9}
CSS;

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . $v['refresh']
        . '<title>' . $v['title'] . '</title><style>'
        . $css
        . sprintf('html,body{background:%s;color:%s}.summary{background:%s}',
                  $v['bg'], $v['fg'], $v['bg'])
        . '</style></head><body><div id="wrap">'
        . $v['summary'] . "\n" . $v['divs']
        . '</div></body></html>';
}

function error_page(string $message, string $bg = '#141b21'): string
{
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Treemap error</title>'
        . '<style>body{background:' . $bg . ';color:#e6edf3;font-family:monospace;'
        . 'padding:3rem;line-height:1.6}code{color:#FFC859}</style></head><body>'
        . '<h1>Treemap unavailable</h1><p><code>' . e($message) . '</code></p>'
        . '<p>Run <code>php noc-treemap.php --selftest</code> on the server for detail.</p>'
        . '</body></html>';
}

// ---------------------------------------------------------------------------
// CLI: seeding and diagnostics
// ---------------------------------------------------------------------------
function cli_seed(array $cfg, array $argv): int
{
    $force = in_array('--force', $argv, true);
    $onlySite = null;
    $pos = array_search('--site', $argv, true);
    if ($pos !== false && isset($argv[$pos + 1])) {
        $onlySite = $argv[$pos + 1];
    }

    $path = cfg($cfg, 'uplinks_file');
    if (!$path) {
        fwrite(STDERR, "uplinks_file is not configured\n");
        return 2;
    }
    $existing = load_uplinks($cfg);
    $sites = discover_sites($cfg);
    if (!$sites) {
        fwrite(STDERR, "No sites discovered; check group_pattern.\n");
        return 1;
    }

    $closetRx = cfg($cfg, 'seed_closet');
    $pattern  = cfg($cfg, 'seed_item');
    $maxItems = (int)cfg($cfg, 'seed_max_items', 2);
    $rankFrom = time() - (int)cfg($cfg, 'seed_rank_window', 900);

    foreach ($sites as $label => $site) {
        if ($onlySite !== null && $label !== $onlySite) {
            continue;
        }
        if (!$force && isset($existing[$label])) {
            printf("  %-16s kept (already pinned)\n", $label);
            continue;
        }

        $candidates = [];
        foreach ($site['closets'] as $closet) {
            if (!$closetRx || preg_match($closetRx, $closet['sub'])) {
                foreach ($closet['hosts'] as $h) {
                    $candidates[$h['hostid']] = $h;
                }
            }
        }
        if (!$candidates) {
            foreach ($site['closets'] as $closet) {
                foreach ($closet['hosts'] as $h) {
                    $candidates[$h['hostid']] = $h;
                }
            }
        }
        if (!$candidates) {
            printf("  %-16s no candidate hosts\n", $label);
            continue;
        }

        $items = zbx_api($cfg, 'item.get', [
            'output'                => ['itemid', 'name', 'value_type', 'hostid'],
            'hostids'               => array_keys($candidates),
            'monitored'             => true,
            'search'                => ['name' => $pattern],
            'searchWildcardsEnabled'=> true,
        ], true, false);
        if (!$items) {
            printf("  %-16s no items matching %s\n", $label, $pattern);
            continue;
        }
        $scanLimit = (int)cfg($cfg, 'seed_scan_limit', 400);
        if (count($items) > $scanLimit) {
            printf("  %-16s %d items match; scanning first %d\n",
                   $label, count($items), $scanLimit);
            $items = array_slice($items, 0, $scanLimit);
        }

        $byType = [];
        foreach ($items as $it) {
            $byType[(int)$it['value_type']][] = $it['itemid'];
        }
        $sample = history_for($cfg, $byType, $rankFrom);
        $means = [];
        foreach ($sample as $id => $points) {
            $means[$id] = count($points)
                ? array_sum(array_column($points, 1)) / count($points) : 0.0;
        }

        // Rank by INTERFACE, not by item: an uplink is one port's in+out pair,
        // so "1/1/48 sent" and "1/1/46 received" must never be paired together.
        $ifaces = [];
        foreach ($items as $it) {
            if (!preg_match('~^(.*?):\s*Bits\s+(received|sent)\s*$~i', $it['name'], $m2)) {
                continue;
            }
            $key = $it['hostid'] . '|' . $m2[1];
            if (!isset($ifaces[$key])) {
                $ifaces[$key] = ['hostid' => (string)$it['hostid'], 'label' => $m2[1],
                                 'items' => [], 'score' => 0.0];
            }
            $ifaces[$key]['items'][strtolower($m2[2])] = $it;
            $ifaces[$key]['score'] += $means[$it['itemid']] ?? 0.0;
        }
        if (!$ifaces) {
            printf("  %-16s items do not look like 'Interface X: Bits in/out'; "
                 . "set seed_item to match your naming\n", $label);
            continue;
        }

        // A named port (MetroE, WAN, Uplink...) beats a busy one when present.
        $prefer = cfg($cfg, 'seed_prefer');
        if ($prefer) {
            $named = array_filter($ifaces, fn($f) => preg_match($prefer, $f['label']));
            if ($named) {
                $ifaces = $named;
            }
        }
        // Prefer interfaces where we actually have both directions.
        $paired = array_filter($ifaces, fn($f) => count($f['items']) >= 2);
        if ($paired) {
            $ifaces = $paired;
        }

        uasort($ifaces, fn($a, $b) => $b['score'] <=> $a['score']);
        $best = reset($ifaces);
        if (!$best || $best['score'] <= 0) {
            printf("  %-16s no recent traffic to rank by\n", $label);
            continue;
        }
        $bestHost = $best['hostid'];

        $mine = [];
        foreach (['received', 'sent'] as $dir) {
            if (isset($best['items'][$dir])) {
                $mine[] = $best['items'][$dir];
            }
        }
        $mine = array_slice($mine, 0, $maxItems);
        if (!$mine) {
            printf("  %-16s interface chosen but no items resolved -- please report\n", $label);
            continue;
        }

        $existing[$label] = [
            'host'      => $candidates[$bestHost]['full'],
            'hostid'    => (string)$bestHost,
            'interface' => $best['label'],
            'items'     => array_map(
                fn($i) => ['itemid' => (string)$i['itemid'], 'name' => $i['name']], $mine),
        ];
        printf("  %-16s %-28s <- %s  [%s]\n", $label, $candidates[$bestHost]['full'],
               $best['label'], implode(' + ', array_map(
                   fn($i) => preg_replace('~^.*Bits\s+~i', '', $i['name']), $mine)));
    }

    $existing['_note'] = 'Edit freely. noc-treemap.php only reads this file; '
        . 'seeding never overwrites an existing site unless run with --force.';
    $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        fwrite(STDERR, "Could not write $path\n");
        return 1;
    }
    echo "\nWrote $path\n";
    return 0;
}

function cli_list(array $cfg): int
{
    $pins = load_uplinks($cfg);
    if (!$pins) {
        echo "Nothing pinned yet. Run: php " . basename(__FILE__) . " --seed\n";
        return 0;
    }
    foreach ($pins as $site => $pin) {
        $entries = pin_entries($pin);
        if (!$entries) {
            printf("  %-16s (no items pinned)\n", $site);
            continue;
        }
        foreach ($entries as $n => $entry) {
            printf("  %-16s %-28s %s\n", $n === 0 ? $site : '', $entry['host'] ?? '?',
                   $entry['label'] ?? short_iface((string)($entry['interface'] ?? '')));
        }
    }
    return 0;
}

function cli_labels_template(array $cfg): int
{
    $existing = load_labels($cfg);
    $sites = discover_sites($cfg);
    $out = ['sites' => [], 'closets' => [], 'hosts' => []];
    foreach ($sites as $label => $site) {
        $out['sites'][$label] = $existing['sites'][$label] ?? $label;
        foreach ($site['closets'] as $closet) {
            $out['closets'][$closet['name']] = $existing['closets'][$closet['name']]
                ?? $existing['closets'][$closet['sub']] ?? $closet['sub'];
            foreach ($closet['hosts'] as $h) {
                $out['hosts'][$h['full']] = $existing['hosts'][$h['full']] ?? $h['full'];
            }
        }
    }
    ksort($out['sites']);
    ksort($out['closets']);
    ksort($out['hosts']);
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return 0;
}


function cli_selftest(array $cfg): int
{
    $ok = true;
    printf("config           %s\n", $cfg['_path']);

    if (!function_exists('curl_init')) {
        echo "curl             MISSING (install php-curl)\n";
        return 1;
    }
    echo "curl             present\n";

    try {
        $version = zbx_api($cfg, 'apiinfo.version', [], false, false);
        printf("api              OK, Zabbix %s\n", $version);
    } catch (Throwable $e) {
        printf("api              FAILED: %s\n", $e->getMessage());
        return 1;
    }

    try {
        $labels = load_labels($cfg);
        $sites = discover_sites($cfg, $labels);
        $hosts = 0;
        foreach ($sites as $s) {
            $hosts += $s['count'];
        }
        printf("discovery        %d site(s), %d host(s)\n", count($sites), $hosts);
        foreach ($sites as $label => $s) {
            printf("                   %-16s %3d hosts, %d closet(s)\n",
                   $label, $s['count'], count($s['closets']));
        }
        if (!$sites) {
            $ok = false;
        }
    } catch (Throwable $e) {
        printf("discovery        FAILED: %s\n", $e->getMessage());
        return 1;
    }

    $lf = cfg($cfg, 'labels_file');
    $n = count($labels['sites']) + count($labels['closets']) + count($labels['hosts']);
    printf("labels           %s (%d override%s)\n",
           $lf ?: '(none configured)', $n, $n === 1 ? '' : 's');

    $pins = load_uplinks($cfg);
    printf("uplinks          %s (%d pinned)\n", cfg($cfg, 'uplinks_file'), count($pins));
    foreach (array_keys($sites) as $label) {
        if (!isset($pins[$label])) {
            printf("                   %-16s NOT PINNED (no graph)\n", $label);
        }
    }

    $dir = cfg($cfg, 'cache_dir');
    if ((int)cfg($cfg, 'cache_ttl', 0) <= 0) {
        echo "cache            disabled\n";
    } elseif ($dir && is_dir($dir) && is_writable($dir)) {
        printf("cache            OK, %s (ttl %ds)\n", $dir, (int)cfg($cfg, 'cache_ttl'));
    } else {
        printf("cache            NOT WRITABLE: %s -- every page load will hit the API\n", $dir);
        $ok = false;
    }
    return $ok ? 0 : 1;
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    try {
        $cfg = load_config();
        $argv = $_SERVER['argv'] ?? [];
        if (in_array('--seed', $argv, true)) {
            exit(cli_seed($cfg, $argv));
        }
        if (in_array('--list', $argv, true)) {
            exit(cli_list($cfg));
        }
        if (in_array('--labels-template', $argv, true)) {
            exit(cli_labels_template($cfg));
        }
        if (in_array('--selftest', $argv, true)) {
            exit(cli_selftest($cfg));
        }
        fwrite(STDERR, "Usage: php " . basename(__FILE__)
            . " [--selftest | --seed [--force] [--site NAME] | --list"
            . " | --labels-template]\n");
        exit(2);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
try {
    $cfg = load_config();
    $baseUrl = preg_replace('~api_jsonrpc\.php.*$~', '', (string)cfg($cfg, 'api_url'));
    $sites = discover_sites($cfg, load_labels($cfg));
    if (!$sites) {
        echo error_page('No sites matched group_pattern.');
        exit;
    }
    attach_graphs($cfg, $sites, load_uplinks($cfg));
    echo render($sites, $cfg, (string)$baseUrl);
} catch (Throwable $e) {
    http_response_code(500);
    echo error_page($e->getMessage());
}
