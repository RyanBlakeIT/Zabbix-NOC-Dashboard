<?php
/**
 * make-demo.php -- render a demo page with no Zabbix instance.
 *
 * Builds a synthetic estate and hands it to the real render() from
 * noc-treemap.php, so the output is exactly what a live install produces --
 * same layout maths, same colors, same font fitting. Useful for previewing
 * config changes and for generating the screenshot in the README.
 *
 *   php tools/make-demo.php                  # writes docs/demo.html
 *   php tools/make-demo.php /tmp/preview.html
 *
 * To preview your own settings, point it at a real config:
 *
 *   NOC_DEMO_CONFIG=/etc/zabbix/noc-treemap.conf.php php tools/make-demo.php
 *
 * The API token in that config is never read -- no network calls are made.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$out  = $argv[1] ?? $root . '/docs/demo.html';
$conf = getenv('NOC_DEMO_CONFIG') ?: $root . '/noc-treemap.conf.example.php';

// --- load noc-treemap.php as a library, minus its entry-point block ---------
$srcPath = $root . '/noc-treemap.php';
$src = @file_get_contents($srcPath);
if ($src === false) {
    fwrite(STDERR, "Cannot read $srcPath\n");
    exit(1);
}
$marker = "if (PHP_SAPI === 'cli') {";
$cut = strpos($src, $marker);
if ($cut === false) {
    fwrite(STDERR, "Could not find the entry-point block in noc-treemap.php.\n"
        . "This script slices the file just above \"$marker\"; if that line has\n"
        . "moved or changed, update \$marker here to match.\n");
    exit(1);
}
$lib = tempnam(sys_get_temp_dir(), 'nocdemo') ?: exit(1);
register_shutdown_function(fn() => @unlink($lib));
file_put_contents($lib, substr($src, 0, $cut));
require $lib;

$cfg = require $conf;
if (!is_array($cfg)) {
    fwrite(STDERR, "Config at $conf did not return an array\n");
    exit(1);
}

mt_srand(20260811);   // fixed seed: the same input always yields the same page

// --- synthetic estate ------------------------------------------------------
// [site key, display name, closet => host count]
$plan = [
    ['NHS', 'North High School',    ['MDF' => 26, 'IDF1' => 18, 'IDF2' => 16, 'IDF3' => 12]],
    ['SHS', 'South High School',    ['MDF' => 24, 'IDF1' => 17, 'IDF2' => 14]],
    ['EMS', 'East Middle School',   ['MDF' => 19, 'IDF1' => 13, 'IDF2' => 10]],
    ['WMS', 'West Middle School',   ['MDF' => 17, 'IDF1' => 12]],
    ['RES', 'Riverside Elementary', ['MDF' => 14, 'IDF1' => 9]],
    ['OES', 'Oakdale Elementary',   ['MDF' => 13, 'IDF1' => 8]],
    ['PES', 'Pinehurst Elementary', ['MDF' => 12, 'IDF1' => 7]],
    ['MES', 'Meadow Elementary',    ['MDF' => 11, 'IDF1' => 6]],
    ['CES', 'Cedar Elementary',     ['MDF' => 10, 'IDF1' => 6]],
    ['ADM', 'Administration',       ['MDF' => 12, 'DC' => 9]],
    ['BUS', 'Transportation',       ['MDF' => 7]],
    ['WHS', 'Warehouse',            ['MDF' => 5]],
];

// Deliberate problems, keyed "SITE/CLOSET/INDEX", so every severity appears.
$problems = [
    'NHS/IDF2/3' => [5, 'Unavailable by ICMP ping'],
    'NHS/IDF1/2' => [3, 'High CPU utilisation (>90% for 5m)'],
    'SHS/MDF/5'  => [4, 'Port ethernet1/1/2: link down'],
    'SHS/IDF2/1' => [2, 'Interface utilisation above 85%'],
    'EMS/MDF/2'  => [2, 'Free disk space is less than 10%'],
    'WMS/IDF1/4' => [1, 'Configuration changed'],
    'RES/MDF/6'  => [3, 'PoE budget above threshold'],
    'OES/IDF1/1' => [4, 'Unavailable by ICMP ping'],
    'ADM/DC/2'   => [2, 'Battery runtime below 15 minutes'],
    'PES/MDF/3'  => [0, 'Unknown device on uplink'],
];
$maint = ['CES/MDF/1', 'CES/MDF/4', 'BUS/MDF/2', 'MES/IDF1/2'];

$roles  = ['SW', 'AP', 'UPS', 'PRN', 'CAM', 'SRV'];
$hostid = 10500;
$sites  = [];

foreach ($plan as [$code, $display, $closets]) {
    $list = [];
    foreach ($closets as $sub => $n) {
        $hosts = [];
        for ($i = 1; $i <= $n; $i++) {
            $key  = "$code/$sub/$i";
            $role = ($sub === 'MDF' && $i <= 2) ? 'SW' : $roles[mt_rand(0, count($roles) - 1)];
            $name = sprintf('%s-%s%02d', $code, $role, $i);
            [$sev, $why] = $problems[$key] ?? [-1, ''];
            $hosts[] = [
                'hostid'      => (string)$hostid++,
                'full'        => $name . '.example.org',
                'name'        => $name,
                'severity'    => $sev,
                'problem'     => $why,
                'maintenance' => in_array($key, $maint, true),
            ];
        }
        $list[] = ['sub' => $sub, 'display' => $sub, 'hosts' => $hosts];
    }
    usort($list, fn($a, $b) => count($b['hosts']) <=> count($a['hosts']));

    // A plausible busy-hour uplink curve, in whatever graph_units implies.
    $buckets = max(2, (int)($cfg['graph_buckets'] ?? 120));
    $peak    = 140 + mt_rand(0, 420);
    $floor   = $peak * (0.22 + mt_rand(0, 18) / 100);
    $phase   = mt_rand(0, 100) / 100 * M_PI;      // sites don't all peak together
    $series  = [];
    $prev    = $floor;
    for ($i = 0; $i < $buckets; $i++) {
        $wave   = ($peak - $floor) * (0.5 + 0.5 * sin($i / $buckets * M_PI * 1.3 + $phase));
        $target = $floor + $wave + mt_rand(-6, 6) / 100 * $peak;
        if (mt_rand(0, 44) === 0) {               // occasional burst
            $target *= 1.18 + mt_rand(0, 22) / 100;
        }
        $prev = max($floor * 0.55, $prev * 0.6 + $target * 0.4);
        $series[] = round($prev, 2);
    }

    $window = (int)($cfg['graph_window'] ?? 7200);
    $t0     = time() - $window;

    $sites[$code] = [
        'closets' => $list,
        'count'   => array_sum(array_map(fn($c) => count($c['hosts']), $list)),
        'display' => $display,
        'graphs'  => [[
            'series' => [['Traffic Total', $series]],
            'max'    => max($series),
            'min'    => min($series),
            'last'   => end($series),
            't0'     => $t0,          // chart mode labels the time axis from these
            't1'     => $t0 + $window,
            'host'   => "$code-SW01",
            'label'  => 'MetroE',
        ]],
    ];
}

$html = render($sites, $cfg, 'https://zabbix.example.org/');

$dir = dirname($out);
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}
if (@file_put_contents($out, $html) === false) {
    fwrite(STDERR, "Cannot write $out\n");
    exit(1);
}

$hosts = array_sum(array_column($sites, 'count'));
printf("Wrote %s -- %d sites, %d hosts, %s\n",
    $out, count($sites), $hosts,
    number_format(strlen($html) / 1024, 1) . ' KiB');
