<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

// Uptime: /proc/uptime reflects the host's uptime (accessible from Docker on Linux)
$raw  = @file_get_contents('/proc/uptime');
$secs = $raw ? (int) floatval(explode(' ', trim($raw))[0]) : 0;
$d    = floor($secs / 86400);
$h    = floor(($secs % 86400) / 3600);
$m    = floor(($secs % 3600) / 60);
$uptime_str = $d . 'd ' . str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);

// Memory: /proc/meminfo also accessible from inside the container
$meminfo   = @file_get_contents('/proc/meminfo');
$mem_total = 0;
$mem_avail = 0;
if ($meminfo) {
    preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $t);
    preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $a);
    $mem_total = isset($t[1]) ? (int) round($t[1] / 1024) : 0;
    $mem_avail = isset($a[1]) ? (int) round($a[1] / 1024) : 0;
}
$mem_used = $mem_total - $mem_avail;

// Load average (1min, 5min, 15min)
$load = sys_getloadavg();

// CPU Temperature — requires /sys/class/thermal mounted in docker-compose.yml:
//   - /sys/class/thermal:/sys/class/thermal:ro
// Returns null until volume is mounted and container is recreated.
$temp_raw = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
$temp_c   = $temp_raw !== false ? round(intval(trim($temp_raw)) / 1000, 1) : null;

echo json_encode([
    'uptime'     => $uptime_str,
    'uptime_s'   => $secs,
    'mem_used'   => $mem_used,
    'mem_total'  => $mem_total,
    'load1'      => round($load[0], 2),
    'load5'      => round($load[1], 2),
    'load15'     => round($load[2], 2),
    'temp_c'     => $temp_c,
]);
