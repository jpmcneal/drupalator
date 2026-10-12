<?php

/**
 * Multi-Site Security Report Generator
 * Generate security reports for multiple sites per customer
 */

require_once __DIR__ . '/drupal_update_checker.php';
require_once __DIR__ . '/kev_catalog.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/icons.php';

$config = Drupalator\Config::load();
$workingDir = $config->workingDir();
chdir($workingDir);

$customerType = $argv[1] ?? null;

if (!$customerType) {
    echo "Usage: php generate_multi_site_report.php <customer_type>\n";
    echo "Available types: all, " . implode(', ', array_keys($config->customers())) . "\n";
    exit(1);
}

$customerConfigs = [];
foreach ($config->customers() as $key => $data) {
    $customerConfigs[$key] = [
        'name'       => $data['name'],
        'output_dir' => $config->customerOutputDir($key),
        'sites'      => $data['sites'],
    ];
}

if ($customerType !== 'all' && !isset($customerConfigs[$customerType])) {
    echo "❌ Unknown customer type: {$customerType}\n";
    echo "Available types: all, " . implode(', ', array_keys($customerConfigs)) . "\n";
    exit(1);
}

$currentDate = date("Y-m-d");

// 'all' runs every customer; otherwise just the one requested
$customersToProcess = ($customerType === 'all')
    ? $customerConfigs
    : [$customerType => $customerConfigs[$customerType]];

// Accumulate per-customer CJSON data so we can write a combined file at the end
$allCustomerCjsonData = [];

foreach ($customersToProcess as $currentType => $currentConfig) {
    // Create output directory
    if (!is_dir($currentConfig['output_dir'])) {
        mkdir($currentConfig['output_dir'], 0777, true);
    }

    $outputFile = "{$currentConfig['output_dir']}/{$currentType}-security-report-{$currentDate}.html";

    echo "🔒 Multi-Site Security Report Generator\n";
    echo str_repeat("=", 50) . "\n";
    echo "Customer: {$currentConfig['name']}\n";
    echo "Sites to check: " . count($currentConfig['sites']) . "\n";
    echo "Output: {$outputFile}\n\n";

    // Check each site
    $allResults = [];
    $totalSecurityUpdates = 0;
    $totalRegularUpdates = 0;

    foreach ($currentConfig['sites'] as $siteKey => $siteConfig) {
        echo "🔍 Checking {$siteConfig['name']}...\n";

        try {
            $updateChecker = new DrupalUpdateChecker(false, $config->cacheDir());
            $updateChecker->setSingleSite($siteKey, $siteConfig);

            $results = $updateChecker->checkAllSites();
            $allResults = array_merge($allResults, $results);

            if (isset($results[$siteKey])) {
                $siteData = $results[$siteKey];
                if (!isset($siteData['error'])) {
                    $secCount = count($siteData['security_updates'] ?? []);
                    $regCount = count($siteData['available_updates'] ?? []);

                    $totalSecurityUpdates += $secCount;
                    $totalRegularUpdates  += $regCount;

                    if ($secCount > 0) {
                        echo "🚨 {$secCount} security update(s) found\n";
                    } else {
                        echo "✅ No security updates needed\n";
                    }
                }
            }

        } catch (Exception $e) {
            echo "❌ Error checking {$siteConfig['name']}: " . $e->getMessage() . "\n";
            $allResults[$siteKey] = ['error' => $e->getMessage()];
        }

        echo "\n";
    }

    // Build KEV exposure map for this customer's sites
    echo "🔥 Checking CISA KEV catalog...\n";
    $kevCatalog = new KevCatalog($config->cacheDir());
    $siteKev = [];
    foreach ($allResults as $siteKey => $siteData) {
        if (!isset($siteData['error']) && !empty($siteData['core_version'])) {
            $siteKev[$siteKey] = $kevCatalog->getEntriesAffectingVersion($siteData['core_version']);
        } else {
            $siteKev[$siteKey] = [];
        }
    }
    $kevMeta = $kevCatalog->getMetadata();

    // Generate HTML report
    echo "📊 Generating combined HTML report...\n";
    $effectiveTheme = $config->effectiveTheme($currentType);
    generateMultiSiteReport($allResults, $outputFile, $currentConfig, $siteKev, $effectiveTheme);

    // Generate per-customer CJSON; returns the data array for the combined report
    $jsonOutputFile = "{$currentConfig['output_dir']}/{$currentType}-security-report-{$currentDate}.jsonc";
    $cjsonData = generateCjsonReport($allResults, $jsonOutputFile, $currentConfig, $currentType, $siteKev, $kevMeta);
    $allCustomerCjsonData[$currentType] = $cjsonData;
    echo "📋 Dashboard CJSON generated: {$jsonOutputFile}\n";

    // Per-customer summary
    echo "✅ Multi-site security report generated: {$outputFile}\n\n";
    echo "📊 SUMMARY FOR {$currentConfig['name']}:\n";
    echo "Sites checked: " . count($currentConfig['sites']) . "\n";
    echo "Total security updates: {$totalSecurityUpdates}\n";
    echo "Total regular updates: {$totalRegularUpdates}\n";

    $kevExposureCount = count(array_filter($siteKev, fn($entries) => !empty($entries)));
    if ($kevExposureCount > 0) {
        echo "\n🔥 KEV exposure: {$kevExposureCount} site(s) running core versions with actively-exploited CVEs\n";
    }

    if ($totalSecurityUpdates > 0) {
        echo "\n🚨 IMMEDIATE ATTENTION REQUIRED: {$totalSecurityUpdates} security updates across all sites!\n";
    }

    echo "\n";

    // Draft email for this customer
    $emailScript = __DIR__ . '/bin/draft-email.php';
    echo "📧 Drafting email for {$currentConfig['name']}...\n";
    passthru(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($emailScript) . ' ' . escapeshellarg($currentType));
    echo "\n";
}

// When running all customers, write a single combined CJSON for the dashboard
if ($customerType === 'all') {
    $reportsDir = $config->reportsDir();
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0777, true);
    }
    $combinedOutputFile = "{$reportsDir}/all-security-report-{$currentDate}.jsonc";
    generateCombinedCjsonReport($allCustomerCjsonData, $combinedOutputFile, $customerConfigs);
    echo "🗂️  Combined dashboard CJSON generated: {$combinedOutputFile}\n";
}

/**
 * Generate multi-site HTML report
 */
function generateMultiSiteReport($allResults, $outputFile, $customerConfig, $siteKev = [], string $theme = 'default') {
    $html = getMultiSiteHtmlTemplate($theme);

    // Add customer header
    $html .= "<div class='customer-header'>";
    $html .= "<div class='header-emblem'>" . getIcon('lock', 24, false) . "</div>";
    $html .= "<div class='header-details'>";
    $html .= "<h1>Security Report: {$customerConfig['name']}</h1>";
    $html .= "<div class='report-meta'>";
    $html .= "<p><strong>Generated:</strong> " . date('Y-m-d') . "</p>";
    $html .= "<p><strong>Sites:</strong> " . count($allResults) . "</p>";
    $html .= "</div>";
    $html .= "</div>";
    $html .= "</div>";

    // Summary cards for all sites
    $sitesWithCoreSecurity = 0;
    $sitesWithCoreEol = 0;
    $sitesWithContribSecurity = 0;
    $sitesWithUnsupported = 0;
    $totalRegular = 0;
    $totalSites = 0;
    $coreVersions = [];

    foreach ($allResults as $siteKey => $siteData) {
        if (!isset($siteData['error'])) {
            $totalSites++;
            $secCount = count($siteData['security_updates'] ?? []);
            $regCount = count($siteData['available_updates'] ?? []);
            $unsupportedCount = count($siteData['unsupported_versions'] ?? []);
            $totalRegular += $regCount;

            // Core security status
            $coreUpdate = $siteData['core_update'] ?? [];
            if (!empty($coreUpdate['has_security_update'])) {
                $sitesWithCoreSecurity++;
            }
            if (!empty($coreUpdate['is_eol'])) {
                $sitesWithCoreEol++;
            }

            // Contrib security status
            if ($secCount > 0) {
                $sitesWithContribSecurity++;
            }
            if ($unsupportedCount > 0) {
                $sitesWithUnsupported++;
            }

            // Track Drupal Core versions (major.minor format) and count sites per version
            if (!empty($siteData['core_version'])) {
                $coreVersion = $siteData['core_version'];
                // Extract major.minor (e.g., "10.4.1" -> "10.4", "11.1.0" -> "11.1")
                $parts = explode('.', $coreVersion);
                if (count($parts) >= 2) {
                    $majorMinor = $parts[0] . '.' . $parts[1] . '.x';
                    if (!isset($coreVersions[$majorMinor])) {
                        $coreVersions[$majorMinor] = 0;
                    }
                    $coreVersions[$majorMinor]++;
                }
            }
        }
    }

    $coreVersionCount = count($coreVersions);

    // Build Core version breakdown text
    $coreVersionBreakdown = '';
    if (!empty($coreVersions)) {
        // Sort versions in descending order
        krsort($coreVersions);
        $breakdownLines = [];
        foreach ($coreVersions as $version => $count) {
            $breakdownLines[] = "• {$version}: {$count}";
        }
        $coreVersionBreakdown = implode('<br>', $breakdownLines);
    }

    $html .= "<div class='summary-cards'>";

    // Sites Checked - always show
    $html .= "<div class='summary-card info'>";
    $html .= "<div class='card-number'>{$totalSites}</div>";
    $html .= "<div class='card-label'>Sites Checked</div>";
    $html .= "</div>";

    // Drupal Core card
    $coreDetails = [];
    if ($sitesWithCoreSecurity > 0) {
        $coreDetails[] = "{$sitesWithCoreSecurity} " . ($sitesWithCoreSecurity === 1 ? 'site' : 'sites') . " with security updates";
    }
    if ($sitesWithCoreEol > 0) {
        $coreDetails[] = "{$sitesWithCoreEol} " . ($sitesWithCoreEol === 1 ? 'site' : 'sites') . " on unsupported core";
    }
    if ($coreVersionBreakdown) {
        $coreDetails[] = $coreVersionBreakdown;
    }

    $coreCardClass = $sitesWithCoreSecurity > 0 ? 'danger' : ($sitesWithCoreEol > 0 ? 'warning' : 'success');
    $coreCardNumber = $sitesWithCoreSecurity + $sitesWithCoreEol;
    $html .= "<div class='summary-card {$coreCardClass}'>";
    $html .= "<div class='card-number'>{$coreCardNumber}</div>";
    $html .= "<div class='card-label'>Drupal Core Issues</div>";
    if (!empty($coreDetails)) {
        $html .= "<div class='card-details'>" . implode('<br>', $coreDetails) . "</div>";
    }
    $html .= "</div>";

    // Contrib Modules card
    $contribDetails = [];
    if ($sitesWithContribSecurity > 0) {
        $contribDetails[] = "{$sitesWithContribSecurity} " . ($sitesWithContribSecurity === 1 ? 'site' : 'sites') . " with security updates";
    }
    if ($sitesWithUnsupported > 0) {
        $contribDetails[] = "{$sitesWithUnsupported} " . ($sitesWithUnsupported === 1 ? 'site' : 'sites') . " with unsupported modules";
    }

    $contribCardClass = $sitesWithContribSecurity > 0 ? 'danger' : ($sitesWithUnsupported > 0 ? 'warning' : 'success');
    $contribCardNumber = $sitesWithContribSecurity + $sitesWithUnsupported;
    $html .= "<div class='summary-card {$contribCardClass}'>";
    $html .= "<div class='card-number'>{$contribCardNumber}</div>";
    $html .= "<div class='card-label'>Contrib Module Issues</div>";
    if (!empty($contribDetails)) {
        $html .= "<div class='card-details'>" . implode('<br>', $contribDetails) . "</div>";
    }
    $html .= "</div>";

    // Core Versions - always show
    $html .= "<div class='summary-card drupal'>";
    $html .= "<div class='card-number'>{$coreVersionCount}</div>";
    $html .= "<div class='card-label'>Core Versions</div>";
    if ($coreVersionBreakdown) {
        $html .= "<div class='card-details'>{$coreVersionBreakdown}</div>";
    }
    $html .= "</div>";

    // KEV card - only show when there are exposures
    $kevExposureCount = count(array_filter($siteKev, fn($entries) => !empty($entries)));
    if ($kevExposureCount > 0) {
        $html .= "<div class='summary-card kev'>";
        $html .= "<div class='card-number'>{$kevExposureCount}</div>";
        $html .= "<div class='card-label'>KEV Exposed Sites</div>";
        $html .= "<div class='card-details'>U.S. gov't list of vulnerabilities<br>confirmed exploited by attackers now</div>";
        $html .= "</div>";
    }

    $html .= "</div>";

    // Individual site reports
    foreach ($allResults as $siteKey => $siteData) {
        if (isset($siteData['error'])) {
            $html .= "<div class='site-section error'>";
            $html .= "<h2>" . getIcon('circle-x') . "{$siteKey} - Error</h2>";
            $html .= "<div class='alert alert-danger'>{$siteData['error']}</div>";
            $html .= "</div>";
            continue;
        }

        $securityCount = count($siteData['security_updates'] ?? []);
        $updateCount = count($siteData['available_updates'] ?? []);
        $unsupportedCount = count($siteData['unsupported_versions'] ?? []);
        $upToDateCount = count($siteData['up_to_date'] ?? []);

        $html .= "<div class='site-section'>";
        $html .= "<h2>" . getIcon('globe') . "{$siteData['site_name']}</h2>";
        $html .= "<p class='site-url'><a href='{$siteData['site_url']}' target='_blank'>{$siteData['site_url']}</a></p>";

        // KEV panel — shown above core alert so it's the first signal seen
        $kevEntries = $siteKev[$siteKey] ?? [];
        if (!empty($kevEntries)) {
            $coreVer = htmlspecialchars($siteData['core_version'] ?? 'unknown');
            $html .= "<div class='alert-kev'>";
            $html .= "<strong>" . getIcon('flame') . "Actively Exploited (CISA KEV) — affects your core {$coreVer}</strong>";
            $html .= "<br><small class='kev-explainer'>CISA KEV is the U.S. government's list of vulnerabilities confirmed being actively exploited by attackers right now — these are the highest-priority patches.</small>";
            foreach ($kevEntries as $kev) {
                $cveId   = htmlspecialchars($kev['cveID'] ?? '');
                $name    = htmlspecialchars($kev['vulnerabilityName'] ?? '');
                $added   = htmlspecialchars($kev['dateAdded'] ?? '');
                $desc    = htmlspecialchars($kev['shortDescription'] ?? '');
                $isRansomware = ($kev['knownRansomwareCampaignUse'] ?? '') === 'Known';
                $nvdUrl  = "https://nvd.nist.gov/vuln/detail/{$cveId}";
                $html .= "<div class='kev-entry'>";
                $html .= "<strong><a href='{$nvdUrl}' target='_blank'>{$cveId}</a></strong>: {$name}";
                if ($isRansomware) {
                    $html .= "<span class='badge-ransomware'>Ransomware</span>";
                }
                $html .= "<br><small>Added to KEV: {$added}</small>";
                if ($desc) {
                    $html .= "<br><small>{$desc}</small>";
                }
                $html .= "</div>";
            }
            $html .= "</div>";
        }

        // Core status
        if (isset($siteData['core_update'])) {
            $coreHasSecurity = $siteData['core_update']['has_security_update'] ?? false;
            $coreIsEol = $siteData['core_update']['is_eol'] ?? false;
            $supportedBranches = $siteData['core_update']['supported_branches'] ?? [];

            if ($coreHasSecurity) {
                $html .= "<div class='alert alert-danger'>";
                $html .= "<strong>" . getIcon('siren') . "Drupal Core Security Update Required!</strong><br>";
                $html .= "Core: {$siteData['core_version']} → {$siteData['core_update']['latest_security_version']}";
                $html .= "</div>";
            } elseif ($coreIsEol) {
                // Build supported branches list (remove trailing dots and format nicely)
                $branchList = '';
                if (!empty($supportedBranches)) {
                    $cleanBranches = array_map(function($branch) {
                        return rtrim($branch, '.');
                    }, $supportedBranches);
                    // Join with commas and "or" before last item
                    if (count($cleanBranches) > 1) {
                        $lastBranch = array_pop($cleanBranches);
                        $branchList = implode(', ', $cleanBranches) . ', or ' . $lastBranch;
                    } else {
                        $branchList = $cleanBranches[0] ?? '';
                    }
                }

                $html .= "<div class='core-status unsupported'>";
                $html .= "Core: {$siteData['core_version']} " . getIcon('triangle-alert') . "(Unsupported";
                if ($branchList) {
                    $html .= " - Upgrade to {$branchList}";
                }
                $html .= ")";
                $html .= "</div>";
            } else {
                $html .= "<div class='core-status'>Core: {$siteData['core_version']} " . getIcon('circle-check-big') . "</div>";
            }
        }

        // PHP version
        if (!empty($siteData['php_version'])) {
            $html .= "<div class='core-status'>PHP: {$siteData['php_version']}</div>";
        }

        // Unsupported versions
        if ($unsupportedCount > 0) {
            $html .= "<div class='alert alert-warning'>";
            $html .= "<strong>" . getIcon('triangle-alert') . "{$unsupportedCount} Unsupported Module Version(s)</strong>";
            $html .= "<ul>";
            foreach ($siteData['unsupported_versions'] as $module) {
                $html .= "<li><strong>{$module['title']}</strong>: {$module['version']} ";
                $html .= "(no longer supported for Drupal {$siteData['core_version']})";
                if (strpos($module['supported_version'], 'No ') !== 0) {
                    $html .= " → <span class='badge badge-warning'>{$module['supported_version']}</span>";
                } else {
                    $html .= " <em>({$module['supported_version']})</em>";
                }
                $html .= "</li>";
            }
            $html .= "</ul>";
            $html .= "</div>";
        }

        // Security updates
        if ($securityCount > 0) {
            $html .= "<div class='alert alert-danger'>";
            $html .= "<strong>" . getIcon('siren') . "{$securityCount} Security Update(s) Required</strong>";
            $html .= "<ul>";
            foreach ($siteData['security_updates'] as $module) {
                $html .= "<li><strong>{$module['title']}</strong>: {$module['version']} → ";
                $html .= "<span class='badge badge-danger'>" .
                         ($module['latest_security_version'] ?: $module['latest_version']) . "</span></li>";
            }
            $html .= "</ul>";
            $html .= "</div>";
        }

        // Regular updates summary
        if ($updateCount > 0) {
            $html .= "<details class='updates-summary'>";
            $html .= "<summary>" . getIcon('package') . "{$updateCount} Regular Updates Available</summary>";
            $html .= "<ul>";
            foreach ($siteData['available_updates'] as $module) {
                $html .= "<li>{$module['title']}: {$module['version']} → ";
                $html .= "<span class='badge badge-info'>{$module['latest_version']}</span></li>";
            }
            $html .= "</ul>";
            $html .= "</details>";
        }

        if ($securityCount === 0 && $updateCount === 0 && $unsupportedCount === 0) {
            $html .= "<div class='alert alert-success'>" . getIcon('circle-check-big') . "All modules up to date and compatible</div>";
        }

        $kevCount = count($siteKev[$siteKey] ?? []);
        $kevNote = $kevCount > 0
            ? getIcon('flame', 14) . "{$kevCount} CISA KEV match(es)"
            : getIcon('circle-check-big', 14) . "CISA KEV checked: no known active exploits for this core version";
        $html .= "<p class='site-footer'>Modules: {$siteData['total_modules']} | Checked: {$siteData['check_timestamp']} | {$kevNote}</p>";
        $html .= "</div>";
    }

    $html .= "</body></html>";

    file_put_contents($outputFile, $html);
}

/**
 * HTML template for multi-site reports
 */
function getMultiSiteHtmlTemplate(string $theme = 'default') {
    $css = getThemeCss($theme);

    return <<<EOL
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Site Security Report</title>
    <style>
{$css}
    </style>
</head>
<body>
EOL;
}

/**
 * Build the CSS to embed in the single <style> block: base.css always,
 * followed by the named theme's override CSS (if not 'default'), each
 * preceded by an HTML-comment marker. Reads directly from themes/ at the
 * repo root — no @import, no <link>, no external fetches, so the report
 * stays a single self-contained HTML file.
 */
function getThemeCss(string $theme = 'default'): string {
    $themesDir = __DIR__ . '/themes';

    $baseFile = $themesDir . '/base.css';
    $baseCss = @file_get_contents($baseFile);
    if ($baseCss === false) {
        throw new RuntimeException("Failed to read base theme CSS at: {$baseFile}");
    }

    $css = "/* theme: default */\n" . $baseCss;

    if ($theme !== 'default') {
        $themeFile = $themesDir . '/' . $theme . '.css';
        $themeCss = @file_get_contents($themeFile);
        if ($themeCss === false) {
            throw new RuntimeException("Failed to read theme CSS at: {$themeFile}");
        }
        $css .= "\n/* theme: {$theme} */\n" . $themeCss;
    }

    return $css;
}

/**
 * Generate CJSON dashboard export file
 */
function generateCjsonReport($allResults, $outputFile, $customerConfig, $customerType, $siteKev = [], $kevMeta = []) {
    $data = buildCjsonData($allResults, $customerConfig, $customerType, $siteKev, $kevMeta);
    $cjson = serializeToCjson($data, $customerConfig['name']);
    file_put_contents($outputFile, $cjson);
    return $data;
}

/**
 * Assemble clean PHP array for CJSON export.
 * Strips all_releases and supported_branches; adds meta, summary, and full module inventory.
 */
function buildCjsonData($allResults, $customerConfig, $customerType, $siteKev = [], $kevMeta = []) {
    $reportDate = date('Y-m-d');
    $generatedAt = date('Y-m-d\TH:i:sP');

    // Build summary aggregates
    $totalSites = 0;
    $sitesWithSecurityUpdates = 0;
    $sitesClean = 0;
    $totalSecurityModuleUpdates = 0;
    $totalRegularUpdates = 0;
    $totalUnsupportedModules = 0;
    $coreVersions = [];

    foreach ($allResults as $siteKey => $siteData) {
        if (isset($siteData['error'])) {
            continue;
        }
        $totalSites++;

        $secCount    = count($siteData['security_updates'] ?? []);
        $regCount    = count($siteData['available_updates'] ?? []);
        $unsupCount  = count($siteData['unsupported_versions'] ?? []);

        $totalSecurityModuleUpdates += $secCount;
        $totalRegularUpdates        += $regCount;
        $totalUnsupportedModules    += $unsupCount;

        if ($secCount > 0) {
            $sitesWithSecurityUpdates++;
        } else {
            $sitesClean++;
        }

        // Aggregate core versions (major.minor.x)
        if (!empty($siteData['core_version'])) {
            $parts = explode('.', $siteData['core_version']);
            if (count($parts) >= 2) {
                $majorMinor = $parts[0] . '.' . $parts[1] . '.x';
                $coreVersions[$majorMinor] = ($coreVersions[$majorMinor] ?? 0) + 1;
            }
        }
    }

    krsort($coreVersions);

    // Build per-site data
    $sites = [];
    foreach ($allResults as $siteKey => $siteData) {
        if (isset($siteData['error'])) {
            $sites[$siteKey] = [
                'name'            => $siteKey,
                'error'           => $siteData['error'],
                'check_timestamp' => date('Y-m-d\TH:i:sP'),
            ];
            continue;
        }

        $coreUpdate = $siteData['core_update'] ?? [];

        // Determine core_status
        if (!empty($coreUpdate['has_security_update'])) {
            $coreStatus = 'security_update';
        } elseif (!empty($coreUpdate['is_eol'])) {
            $coreStatus = 'eol';
        } elseif (!empty($coreUpdate['has_update'])) {
            $coreStatus = 'update_available';
        } else {
            $coreStatus = 'up_to_date';
        }

        $securityUpdates = array_map(function($m) {
            return [
                'name'                    => $m['name'],
                'title'                   => $m['title'],
                'version'                 => $m['version'],
                'latest_security_version' => $m['latest_security_version'] ?? null,
                'latest_version'          => $m['latest_version'] ?? null,
            ];
        }, $siteData['security_updates'] ?? []);

        $unsupportedModules = array_map(function($m) {
            return [
                'name'              => $m['name'],
                'title'             => $m['title'],
                'version'           => $m['version'],
                'supported_version' => $m['supported_version'] ?? null,
            ];
        }, $siteData['unsupported_versions'] ?? []);

        $availableUpdates = array_map(function($m) {
            return [
                'name'           => $m['name'],
                'title'          => $m['title'],
                'version'        => $m['version'],
                'latest_version' => $m['latest_version'] ?? null,
            ];
        }, $siteData['available_updates'] ?? []);

        $allModules = buildAllModulesList($siteData);

        $rawKev = $siteKev[$siteKey] ?? [];
        $kevExploited = array_map(function($entry) {
            return [
                'cveID'                     => $entry['cveID'] ?? null,
                'vulnerabilityName'         => $entry['vulnerabilityName'] ?? null,
                'dateAdded'                 => $entry['dateAdded'] ?? null,
                'dueDate'                   => $entry['dueDate'] ?? null,
                'knownRansomwareCampaignUse'=> $entry['knownRansomwareCampaignUse'] ?? null,
                'shortDescription'          => $entry['shortDescription'] ?? null,
                'source_nvd_url'            => 'https://nvd.nist.gov/vuln/detail/' . ($entry['cveID'] ?? ''),
            ];
        }, $rawKev);

        $sites[$siteKey] = [
            'name' => $siteData['site_name'],
            'url'  => $siteData['site_url'],
            'environment' => [
                'core_version'                => $siteData['core_version'] ?? null,
                'php_version'                 => $siteData['php_version'] ?? null,
                'core_status'                 => $coreStatus,
                'core_has_security_update'    => (bool)($coreUpdate['has_security_update'] ?? false),
                'core_is_eol'                 => (bool)($coreUpdate['is_eol'] ?? false),
                'core_latest_security_version'=> $coreUpdate['latest_security_version'] ?? null,
                'core_recommended_version'    => $coreUpdate['recommended_version'] ?? null,
            ],
            'counts' => [
                'total_modules'    => (int)($siteData['total_modules'] ?? 0),
                'security_updates' => count($securityUpdates),
                'regular_updates'  => count($availableUpdates),
                'unsupported'      => count($unsupportedModules),
                'up_to_date'       => count($siteData['up_to_date'] ?? []),
                'kev_exploited'    => count($kevExploited),
            ],
            'security_updates'   => $securityUpdates,
            'unsupported_modules'=> $unsupportedModules,
            'available_updates'  => $availableUpdates,
            'kev_exploited'      => $kevExploited,
            'modules'            => $allModules,
            'check_timestamp'    => $siteData['check_timestamp'] ?? $generatedAt,
        ];
    }

    $totalKevExposures = count(array_filter($siteKev, fn($entries) => !empty($entries)));

    return [
        'meta' => [
            'customer'            => $customerConfig['name'],
            'customer_key'        => $customerType,
            'report_date'         => $reportDate,
            'generated_at'        => $generatedAt,
            'kev_catalog_version' => $kevMeta['catalogVersion'] ?? null,
            'kev_catalog_date'    => $kevMeta['dateReleased'] ?? null,
        ],
        'summary' => [
            'total_sites'                   => $totalSites,
            'sites_with_security_updates'   => $sitesWithSecurityUpdates,
            'sites_clean'                   => $sitesClean,
            'total_security_module_updates' => $totalSecurityModuleUpdates,
            'total_regular_updates'         => $totalRegularUpdates,
            'total_unsupported_modules'     => $totalUnsupportedModules,
            'core_versions'                 => $coreVersions,
            'total_kev_exposures'           => $totalKevExposures,
        ],
        'sites' => $sites,
    ];
}

/**
 * Merge the four module buckets into a flat array with status field.
 */
function buildAllModulesList($siteData) {
    $modules = [];

    $buckets = [
        'security_update' => $siteData['security_updates']    ?? [],
        'update_available'=> $siteData['available_updates']   ?? [],
        'unsupported'     => $siteData['unsupported_versions'] ?? [],
        'current'         => $siteData['up_to_date']          ?? [],
    ];

    foreach ($buckets as $status => $items) {
        foreach ($items as $module) {
            $name = $module['name'] ?? '';
            if ($name === '') {
                continue;
            }
            // Later buckets should not overwrite higher-priority ones
            if (!isset($modules[$name])) {
                $modules[$name] = [
                    'name'    => $name,
                    'version' => $module['version'] ?? null,
                    'status'  => $status,
                ];
            }
        }
    }

    // Return as a numerically-indexed array sorted by name
    usort($modules, fn($a, $b) => strcmp($a['name'], $b['name']));
    return array_values($modules);
}

/**
 * Convert a PHP array to CJSON (JSON with comments).
 * Uses pretty-printed JSON and injects // comment lines before top-level and site-level keys.
 */
function serializeToCjson($data, $customerName) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Inject comments before top-level section keys
    $topLevelComments = [
        '"meta"'    => '// Customer identification and report metadata',
        '"summary"' => '// Aggregate counts across all sites for dashboard summary cards',
        '"sites"'   => '// Per-site detailed results',
    ];

    foreach ($topLevelComments as $key => $comment) {
        // Match the key at the start of a line (with leading spaces)
        $json = preg_replace(
            '/^(\s+)(' . preg_quote($key, '/') . '\s*:)/m',
            "$1{$comment}\n$1$2",
            $json
        );
    }

    // Inject per-site sub-section comments inside each site block
    $siteSubComments = [
        '"name"'              => '// Site identification',
        '"environment"'       => '// Drupal core and PHP environment',
        '"counts"'            => '// Quick-reference counts for dashboard widgets',
        '"security_updates"'  => '// Modules needing immediate security patches',
        '"unsupported_modules"'=> '// Modules on unsupported versions for this Drupal core',
        '"available_updates"' => '// Non-critical updates available',
        '"kev_exploited"'     => '// CISA KEV entries actively exploiting this core version',
        '"modules"'           => '// Complete inventory of all installed contrib modules (status: security_update | update_available | unsupported | current)',
        '"check_timestamp"'   => '// ISO-8601 timestamp of when this site was scanned',
    ];

    foreach ($siteSubComments as $key => $comment) {
        $json = preg_replace(
            '/^(\s{8})(' . preg_quote($key, '/') . '\s*:)/m',
            "$1{$comment}\n$1$2",
            $json
        );
    }

    // Build file-level header
    $reportDate  = date('Y-m-d');
    $generatedAt = date('Y-m-d H:i:s T');
    $header = <<<HEADER
// Security Report: {$customerName}
// Generated: {$generatedAt}
// Report Date: {$reportDate}
// Tool: Drupal Multi-Site Security Scanner
// Format: JSONC (JSON with comments) — strip // lines before parsing as JSON
//
// Validation: node -e "const s=require('fs').readFileSync(process.argv[1],'utf8').replace(/\\/\\/[^\\n]*/g,''); JSON.parse(s); console.log('valid')" <file.jsonc>

HEADER;

    return $header . $json . "\n";
}

/**
 * Write the combined CJSON file that merges all customer reports into one.
 */
function generateCombinedCjsonReport($allCustomerCjsonData, $outputFile, $customerConfigs) {
    $data  = buildCombinedCjsonData($allCustomerCjsonData, $customerConfigs);
    $cjson = serializeCombinedToCjson($data, $customerConfigs);
    file_put_contents($outputFile, $cjson);
}

/**
 * Assemble the combined PHP array: cross-customer meta, aggregated summary, per-customer data.
 */
function buildCombinedCjsonData($allCustomerCjsonData, $customerConfigs) {
    $reportDate  = date('Y-m-d');
    $generatedAt = date('Y-m-d\TH:i:sP');

    // Aggregate summary across all customers
    $totalCustomers             = count($allCustomerCjsonData);
    $totalSites                 = 0;
    $sitesWithSecurityUpdates   = 0;
    $sitesClean                 = 0;
    $totalSecurityModuleUpdates = 0;
    $totalRegularUpdates        = 0;
    $totalUnsupportedModules    = 0;
    $totalKevExposures          = 0;
    $coreVersions               = [];
    $kevCatalogVersion          = null;

    foreach ($allCustomerCjsonData as $type => $customerData) {
        $s = $customerData['summary'] ?? [];
        $totalSites                 += $s['total_sites']                   ?? 0;
        $sitesWithSecurityUpdates   += $s['sites_with_security_updates']   ?? 0;
        $sitesClean                 += $s['sites_clean']                   ?? 0;
        $totalSecurityModuleUpdates += $s['total_security_module_updates'] ?? 0;
        $totalRegularUpdates        += $s['total_regular_updates']         ?? 0;
        $totalUnsupportedModules    += $s['total_unsupported_modules']     ?? 0;
        $totalKevExposures          += $s['total_kev_exposures']           ?? 0;

        foreach ($s['core_versions'] ?? [] as $version => $count) {
            $coreVersions[$version] = ($coreVersions[$version] ?? 0) + $count;
        }

        // Use first non-null kev catalog version found
        if ($kevCatalogVersion === null && !empty($customerData['meta']['kev_catalog_version'])) {
            $kevCatalogVersion = $customerData['meta']['kev_catalog_version'];
        }
    }

    krsort($coreVersions);

    return [
        'meta' => [
            'report_type'         => 'combined',
            'customers'           => array_keys($allCustomerCjsonData),
            'report_date'         => $reportDate,
            'generated_at'        => $generatedAt,
            'kev_catalog_version' => $kevCatalogVersion,
        ],
        'summary' => [
            'total_customers'               => $totalCustomers,
            'total_sites'                   => $totalSites,
            'sites_with_security_updates'   => $sitesWithSecurityUpdates,
            'sites_clean'                   => $sitesClean,
            'total_security_module_updates' => $totalSecurityModuleUpdates,
            'total_regular_updates'         => $totalRegularUpdates,
            'total_unsupported_modules'     => $totalUnsupportedModules,
            'total_kev_exposures'           => $totalKevExposures,
            'core_versions'                 => $coreVersions,
        ],
        'customers' => $allCustomerCjsonData,
    ];
}

/**
 * Serialize the combined data structure to CJSON with annotating comments.
 */
function serializeCombinedToCjson($data, $customerConfigs) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Top-level section comments (4-space indent)
    $topLevelComments = [
        '"meta"'      => '// Report type, customer list, and timestamps',
        '"summary"'   => '// Aggregate counts across all customers and sites for dashboard summary cards',
        '"customers"' => '// Per-customer data — each entry has the same structure as a single-customer report',
    ];

    foreach ($topLevelComments as $key => $comment) {
        $json = preg_replace(
            '/^(\s{4})(' . preg_quote($key, '/') . '\s*:)/m',
            "$1{$comment}\n$1$2",
            $json
        );
    }

    // Per-customer key comments (8-space indent) — inject customer name + site count
    foreach ($data['customers'] as $type => $customerData) {
        $customerName = $customerData['meta']['customer'] ?? $type;
        $siteCount    = $customerData['summary']['total_sites'] ?? 0;
        $secCount     = $customerData['summary']['sites_with_security_updates'] ?? 0;
        $secNote      = $secCount > 0 ? ", {$secCount} with security updates" : ", all clean";
        $comment      = "// {$customerName} — {$siteCount} site(s){$secNote}";

        $json = preg_replace(
            '/^(\s{8})(' . preg_quote("\"{$type}\"", '/') . '\s*:)/m',
            "$1{$comment}\n$1$2",
            $json
        );
    }

    // File-level header
    $reportDate  = date('Y-m-d');
    $generatedAt = date('Y-m-d H:i:s T');
    $customerList = implode(', ', array_keys($data['customers']));
    $header = <<<HEADER
// Combined Security Report — All Customers
// Customers: {$customerList}
// Generated: {$generatedAt}
// Report Date: {$reportDate}
// Tool: Drupal Multi-Site Security Scanner
// Format: JSONC (JSON with comments) — strip // lines before parsing as JSON
//
// Validation: node -e "const s=require('fs').readFileSync(process.argv[1],'utf8').replace(/\\/\\/[^\\n]*/g,''); JSON.parse(s); console.log('valid')" <file.jsonc>

HEADER;

    return $header . $json . "\n";
}