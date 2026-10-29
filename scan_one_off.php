<?php

/**
 * One-off single-site security scan.
 *
 * Bypasses config/sites.json entirely — site identity (url, drush alias)
 * is passed directly via CLI flags. Reuses DrupalUpdateChecker and
 * KevCatalog for the scan/KEV lookup itself, but renders its own minimal
 * HTML + JSONC report rather than reusing generate_multi_site_report.php's
 * generators: that file's top-level code unconditionally calls
 * Drupalator\Config::load() (which requires sites.json to exist) and does
 * argv[1]-based customer-key lookup with an early exit(1) when the key is
 * missing/unknown — so require_once'ing it here would immediately exit
 * before this script's own logic ever ran. Extracting just
 * generateMultiSiteReport()/generateCjsonReport() (and the helper chain
 * they call — buildCjsonData, serializeToCjson, getIcon, theme CSS
 * loading, etc.) was judged not worth the duplication/fragility for a
 * one-off tool, so this script writes a plain, self-contained report
 * instead.
 *
 * Usage: php scan_one_off.php --url=<url> --alias=<drush_alias> [--name=<name>] [--uri=<uri>] [--output-dir=<path>]
 */

function printOneOffUsage() {
    fwrite(STDERR, "Usage: php scan_one_off.php --url=<url> --alias=<drush_alias> [--name=<name>] [--uri=<uri>] [--output-dir=<path>]\n");
}

$url = null;
$alias = null;
$name = null;
$uri = null;
$outputDirOpt = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    } elseif (str_starts_with($arg, '--alias=')) {
        $alias = substr($arg, 8);
    } elseif (str_starts_with($arg, '--name=')) {
        $name = substr($arg, 7);
    } elseif (str_starts_with($arg, '--uri=')) {
        $uri = substr($arg, 6);
    } elseif (str_starts_with($arg, '--output-dir=')) {
        $outputDirOpt = substr($arg, 13);
    }
}

if (empty($url) || empty($alias)) {
    printOneOffUsage();
    exit(1);
}

if (empty($name)) {
    $host = parse_url($url, PHP_URL_HOST);
    $name = !empty($host) ? $host : $url;
}

require_once __DIR__ . '/lib/Config.php';
$config = Drupalator\Config::loadEnvOnly();
chdir($config->workingDir());

require_once __DIR__ . '/drupal_update_checker.php';
require_once __DIR__ . '/kev_catalog.php';

$siteConfig = [
    'name' => $name,
    'url' => $url,
    'drush_alias' => $alias,
];
if (!empty($uri)) {
    $siteConfig['uri'] = $uri;
}

echo "🔍 One-off scan: {$name} ({$url})\n";

$checker = new DrupalUpdateChecker(false, $config->cacheDir());
$checker->setSingleSite('oneoff', $siteConfig);
$results = $checker->checkAllSites();

if (isset($results['oneoff']['error'])) {
    echo "❌ Error checking {$name}: {$results['oneoff']['error']}\n";
    exit(1);
}

$siteData = $results['oneoff'];

$siteKev = ['oneoff' => []];
$kevCatalog = new KevCatalog($config->cacheDir());
if (!empty($siteData['core_version'])) {
    $siteKev['oneoff'] = $kevCatalog->getEntriesAffectingVersion($siteData['core_version']);
}
$kevMeta = $kevCatalog->getMetadata();

$customerConfig = ['name' => $name];
$theme = $config->theme();

$outputDir = $outputDirOpt ?: ($config->reportsDir() . '/oneoff');
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$slug = preg_replace('/[^a-z0-9_-]+/i', '_', $name);
$slug = strtolower(trim((string) $slug, '_'));
if ($slug === '') {
    $slug = 'site';
}

$date = date('Y-m-d');
$htmlFile = "{$outputDir}/oneoff-{$slug}-{$date}.html";
$jsonFile = "{$outputDir}/oneoff-{$slug}-{$date}.jsonc";

generateOneOffHtmlReport($siteData, $htmlFile, $customerConfig, $siteKev['oneoff'], $theme);
generateOneOffJsonReport($results, $jsonFile, $customerConfig, $siteKev, $kevMeta);

$securityCount = count($siteData['security_updates'] ?? []);
$regularCount = count($siteData['available_updates'] ?? []);
$kevCount = count($siteKev['oneoff']);

echo "\n📊 SUMMARY FOR {$name}:\n";
echo "Total security updates: {$securityCount}\n";
echo "Total regular updates: {$regularCount}\n";

if ($kevCount > 0) {
    echo "\n🔥 KEV exposure: actively-exploited CVE(s) affecting core {$siteData['core_version']}\n";
}

if ($securityCount > 0) {
    echo "\n🚨 IMMEDIATE ATTENTION REQUIRED: {$securityCount} security update(s)!\n";
} else {
    echo "\n✅ No security updates needed\n";
}

echo "\n🔍 HTML report: {$htmlFile}\n";
echo "🔍 JSON report: {$jsonFile}\n";

exit(0);

/**
 * Minimal self-contained HTML report for a one-off single-site scan.
 * (Fallback path — see file header for why generateMultiSiteReport()
 * from generate_multi_site_report.php is not reused here.)
 */
function generateOneOffHtmlReport($siteData, $outputFile, $customerConfig, $kevEntries, $theme) {
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    $html .= '<title>One-Off Security Report: ' . htmlspecialchars($customerConfig['name']) . '</title>';
    $html .= '<style>body{font-family:sans-serif;margin:2rem;}table{border-collapse:collapse;width:100%;margin:1rem 0;}';
    $html .= 'th,td{border:1px solid #ccc;padding:6px 10px;text-align:left;}th{background:#f0f0f0;}';
    $html .= '.alert{padding:10px;margin:10px 0;border-radius:4px;}.alert-danger{background:#fdecea;color:#611a15;}';
    $html .= '.alert-success{background:#e8f5e9;color:#1b5e20;}.alert-warning{background:#fff8e1;color:#7a5c00;}</style>';
    $html .= '</head><body>';
    $html .= '<h1>Security Report: ' . htmlspecialchars($customerConfig['name']) . '</h1>';
    $html .= '<p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '<p><strong>URL:</strong> <a href="' . htmlspecialchars($siteData['site_url']) . '">' . htmlspecialchars($siteData['site_url']) . '</a></p>';
    $html .= '<p><strong>Drupal core:</strong> ' . htmlspecialchars($siteData['core_version'] ?? 'unknown') . '</p>';
    $html .= '<p><strong>PHP version:</strong> ' . htmlspecialchars($siteData['php_version'] ?? 'unknown') . '</p>';

    if (!empty($kevEntries)) {
        $html .= '<div class="alert alert-danger"><strong>🔥 Actively Exploited (CISA KEV)</strong><ul>';
        foreach ($kevEntries as $kev) {
            $html .= '<li>' . htmlspecialchars($kev['cveID'] ?? '') . ': ' . htmlspecialchars($kev['vulnerabilityName'] ?? '') . '</li>';
        }
        $html .= '</ul></div>';
    }

    $securityUpdates = $siteData['security_updates'] ?? [];
    if (!empty($securityUpdates)) {
        $html .= '<div class="alert alert-danger"><strong>🚨 ' . count($securityUpdates) . ' Security Update(s) Required</strong></div>';
        $html .= '<table><tr><th>Module</th><th>Current</th><th>Security Update</th></tr>';
        foreach ($securityUpdates as $module) {
            $html .= '<tr><td>' . htmlspecialchars($module['title'] ?? $module['name']) . '</td><td>' . htmlspecialchars($module['version']) . '</td><td>' . htmlspecialchars($module['latest_security_version'] ?: $module['latest_version']) . '</td></tr>';
        }
        $html .= '</table>';
    } else {
        $html .= '<div class="alert alert-success">✅ No security updates needed</div>';
    }

    $availableUpdates = $siteData['available_updates'] ?? [];
    if (!empty($availableUpdates)) {
        $html .= '<h2>📦 Regular Updates Available (' . count($availableUpdates) . ')</h2>';
        $html .= '<table><tr><th>Module</th><th>Current</th><th>Latest</th></tr>';
        foreach ($availableUpdates as $module) {
            $html .= '<tr><td>' . htmlspecialchars($module['title'] ?? $module['name']) . '</td><td>' . htmlspecialchars($module['version']) . '</td><td>' . htmlspecialchars($module['latest_version']) . '</td></tr>';
        }
        $html .= '</table>';
    }

    $unsupported = $siteData['unsupported_versions'] ?? [];
    if (!empty($unsupported)) {
        $html .= '<div class="alert alert-warning"><strong>⚠️ ' . count($unsupported) . ' Unsupported Module Version(s)</strong></div>';
        $html .= '<table><tr><th>Module</th><th>Current</th><th>Status</th></tr>';
        foreach ($unsupported as $module) {
            $html .= '<tr><td>' . htmlspecialchars($module['title'] ?? $module['name']) . '</td><td>' . htmlspecialchars($module['version']) . '</td><td>' . htmlspecialchars($module['supported_version'] ?? '') . '</td></tr>';
        }
        $html .= '</table>';
    }

    $html .= '<p><small>Checked: ' . htmlspecialchars($siteData['check_timestamp'] ?? '') . ' | Total modules: ' . (int)($siteData['total_modules'] ?? 0) . '</small></p>';
    $html .= '</body></html>';

    file_put_contents($outputFile, $html);
}

/**
 * Minimal JSON(C) report for a one-off single-site scan.
 */
function generateOneOffJsonReport($results, $outputFile, $customerConfig, $siteKev, $kevMeta) {
    $data = [
        'meta' => [
            'customer' => $customerConfig['name'],
            'report_type' => 'oneoff',
            'generated_at' => date('Y-m-d\TH:i:sP'),
            'kev_catalog_version' => $kevMeta['catalogVersion'] ?? null,
            'kev_catalog_date' => $kevMeta['dateReleased'] ?? null,
        ],
        'results' => $results,
        'kev' => $siteKev,
    ];
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $header = "// One-off security report: {$customerConfig['name']}\n// Format: JSONC — strip // lines before parsing as JSON\n\n";
    file_put_contents($outputFile, $header . $json . "\n");
}
