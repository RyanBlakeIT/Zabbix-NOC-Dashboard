<?php
/**
 * Configuration for noc-treemap.php
 *
 * Keep this file OUT of the web root, or at least chmod 640 and owned by the
 * php-fpm user -- it holds the API token. Suggested location:
 *     /etc/zabbix/noc-treemap.conf.php
 *
 * The token only ever reads. Create it against a read-only Zabbix role.
 */

return [
    // --- connection --------------------------------------------------------
    'api_url'        => 'https://zabbix.example.org/api_jsonrpc.php',
    'api_token'      => 'PUT-YOUR-READ-ONLY-TOKEN-HERE',
    'api_timeout'    => 25,
    'verify_tls'     => true,
    'ca_bundle'      => null,      // path to an internal CA PEM, or null

    // --- what counts as a site --------------------------------------------
    // Named captures: 'site' and (optionally) 'sub' for the closet.
    'group_pattern'   => '~^(?P<site>[^/]+?)(?:/(?P<sub>.+))?$~',
    'exclude_pattern' => '~^(Zabbix servers|Templates.*|Discovered hosts|Applications'
                       . '|Databases|Hypervisors|Virtual machines|Linux servers'
                       . '|Windows servers)$~',
    // Fold inconsistent labels together: lowercase key => canonical name.
    'aliases'         => ['nhs' => 'North High'],
    'only'            => [],       // limit to these site labels, or [] for all
    'min_hosts'       => 1,

    // --- uplink graphs -----------------------------------------------------
    // Pinned per site. Seed it once with:  php noc-treemap.php --seed
    // After that the page only READS this file; your edits are never
    // overwritten unless you pass --force.
    'uplinks_file'    => '/etc/zabbix/noc-uplinks.json',
    // Used only by --seed, to decide what to look at:
    'seed_closet'     => '~MDF~i',                  // which closet holds the uplink
    'seed_item'       => 'Interface *: Bits *',     // item name pattern, * allowed
    // A port whose description names it wins over a merely busy one. If your
    // switch interfaces carry descriptions like "...(MetroE)" or "...(WAN)",
    // they will be preferred. Set to null to rank purely by traffic.
    'seed_prefer'     => '~(MetroE|WAN|Uplink|Core|Fiber|ISP)~i',
    'seed_rank_window'=> 900,                       // seconds of history used to rank
    'seed_max_items'  => 2,                         // items kept per site (in + out)
    'seed_scan_limit' => 400,

    'graph_window'    => 7200,     // seconds shown in the sparkline
    'graph_buckets'   => 120,
    'graph_mode'      => 'total',  // 'total' sums the items, 'both' draws each
    'graph_divisor'   => 1e6,      // bits/s -> Mbit/s
    'graph_units'     => 'Mbit/s',
    'graph_ceiling'   => 0,        // 0 = auto-round per site; or fix e.g. 1000
    'graph_colors'    => ['#7EB6FF', '#FFC859', '#59DB8F', '#E97659'],
    'graph_h'         => 6.5,      // band height, % of page (0 disables)
    'graph_max_share' => 0.34,
    'graph_hide_below'=> 8.0,
    'history_limit'   => 20000,

    // --- caching -----------------------------------------------------------
    // Every page load hits the API, so a short cache keeps a wall of screens
    // from hammering the server. Directory must be writable by php-fpm.
    'cache_dir'       => '/var/cache/zabbix-noc',
    'cache_ttl'       => 25,       // seconds; 0 disables

    // --- presentation ------------------------------------------------------
    'title'           => 'Network Status',
    'refresh'         => 60,       // browser meta-refresh, seconds; 0 disables
    'exponent'        => 0.85,     // site area weighting (1.0 = strict host count)
    'summary_h'       => 4.2,
    'show_summary'    => true,
    'site_header'     => 2.4,
    'closet_header'   => 1.6,
    'gap'             => 0.25,
    'pad'             => 0.18,
    'hide_label_below'=> 1.5,
    'background'      => '#141b21',
    'foreground'      => '#e6edf3',
    'maintenance_severity' => false,

    // label fitting
    'font_uniform'    => 'closet', // 'closet' or 'tile'
    'font_percentile' => 0.40,
    'min_font'        => 0.34,     // vw
    'max_font'        => 1.10,     // vw
    'fit_width'       => 1920,     // reference screen used for sizing math
    'fit_height'      => 1080,
    'char_ratio'      => 0.58,
    'line_ratio'      => 1.12,
    'fill_ratio'      => 0.74,
    'text_pad'        => 2.0,

    // --- display names -----------------------------------------------------
    // Optional. Anything not listed keeps its real name, so you only add the
    // entries you want renamed. Generate a starter file with:
    //     php noc-treemap.php --labels-template > /etc/zabbix/noc-labels.json
    // Closets key on the full group name ("NHS/MDF") or the bare closet
    // ("MDF", which then applies at every site). Hosts key on the visible
    // name or the hostid. Hover text always shows the real name.
    'labels_file'     => '/etc/zabbix/noc-labels.json',
    // Small overrides can live here instead of in a file:
    'labels'          => [
        // 'sites'   => ['NHS' => 'North High School'],
        // 'closets' => ['MDF' => 'Main Closet'],
        // 'hosts'   => ['NHS_MDF_2 10.0.0.1' => 'North Core 2'],
    ],

    // strip regexes applied to host labels, e.g. '~\s+\d+(\.\d+){3}$~' to drop
    // a trailing IP address from the visible name
    'strip'           => [],
];
