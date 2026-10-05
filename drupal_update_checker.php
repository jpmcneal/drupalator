<?php

/**
 * Drupal Update Checker - Queries Drupal.org directly
 * Avoids deprecated pm:updatestatus by checking updates manually
 */

class DrupalUpdateChecker {
    // Cache configuration
    private string $cacheDir;
    private const CACHE_TTL = 86400; // 24 hours in seconds

    private $sites = [];
    private $drupalOrgCache = [];

    public function __construct(bool $loadDefaultSites = false, ?string $cacheDir = null) {
        $this->cacheDir = $cacheDir ?? '/tmp/drupal_update_cache';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        if ($loadDefaultSites) {
            throw new RuntimeException("Default sites removed — pass configuration via Drupalator\\Config or setSingleSite()");
        } else {
            $this->sites = [];
        }
    }

    /**
     * Configure a single site for checking
     */
    public function setSingleSite($key, $config) {
        // Clear all sites first, then set just this one
        $this->sites = [];
        $this->drupalOrgCache = []; // Clear cache too
        $this->sites[$key] = $config;
    }

    /**
     * Generate cache filename for a project
     */
    private function getCacheFilePath($projectName) {
        // Sanitize project name for filesystem
        $safeName = preg_replace('/[^a-z0-9_-]/i', '_', $projectName);
        return $this->cacheDir . '/' . $safeName . '_current.cache';
    }

    /**
     * Check if cache file is valid (not expired)
     * Cache expires after 24 hours OR on Wednesday midnight UTC (Drupal security release day)
     */
    private function isCacheValid($cacheFilePath) {
        if (!file_exists($cacheFilePath)) {
            return false;
        }

        $cacheTime = filemtime($cacheFilePath);
        $currentTime = time();

        // Check standard 24-hour TTL
        if (($currentTime - $cacheTime) > self::CACHE_TTL) {
            return false;
        }

        // Check Wednesday midnight UTC purge
        // Drupal.org releases security updates on Wednesdays
        $currentDate = new DateTime('now', new DateTimeZone('UTC'));
        $cacheDate = new DateTime('@' . $cacheTime);
        $cacheDate->setTimezone(new DateTimeZone('UTC'));

        // If today is Wednesday
        if ($currentDate->format('N') == 3) { // 3 = Wednesday (ISO-8601)
            // Get midnight UTC today
            $midnightToday = clone $currentDate;
            $midnightToday->setTime(0, 0, 0);

            // If cache was created before midnight UTC today, it's invalid
            if ($cacheDate < $midnightToday) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read XML from cache file
     */
    private function readCache($cacheFilePath) {
        if (!$this->isCacheValid($cacheFilePath)) {
            return null;
        }

        $contents = @file_get_contents($cacheFilePath);
        if ($contents === false) {
            return null;
        }

        // Parse XML and return
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents);
        libxml_clear_errors();

        return $xml !== false ? $xml : null;
    }

    /**
     * Write XML to cache file
     */
    private function writeCache($cacheFilePath, $xmlString) {
        // Validate it's actually XML before caching
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_clear_errors();

        if ($xml === false) {
            return false; // Don't cache invalid XML
        }

        // Write to cache
        return @file_put_contents($cacheFilePath, $xmlString) !== false;
    }

    /**
     * Load submodule mappings from cache file and static definitions
     */
    private function loadSubmoduleMappings() {
        static $submoduleMappings = null;

        if ($submoduleMappings !== null) {
            return $submoduleMappings;
        }

        // Start with known static mappings
        $submoduleMappings = [
            // Purge project submodules
            'purge_ui' => 'purge',
            'purge_processor_cron' => 'purge',
            'purge_processor_lateruntime' => 'purge',
            'purge_queuer_coretags' => 'purge',
            'purge_drush' => 'purge',

            // Webform project submodules
            'webform_ui' => 'webform',
            'webform_node' => 'webform',
            'webform_access' => 'webform',
            'webform_templates' => 'webform',
            'webform_example_composite' => 'webform',
            'webform_example_custom_form' => 'webform',

            // Field Group submodules
            'field_group_table' => 'field_group',

            // Rules submodules
            'rules_admin' => 'rules',

            // Commerce submodules (if applicable)
            'commerce_price' => 'commerce',
            'commerce_store' => 'commerce',
            'commerce_product' => 'commerce',
            'commerce_cart' => 'commerce',
            'commerce_checkout' => 'commerce',
            'commerce_order' => 'commerce',
            'commerce_payment' => 'commerce',
        ];

        // Load discovered mappings from cache file
        $cacheFile = $this->cacheDir . '/submodule_mappings.json';
        if (file_exists($cacheFile)) {
            $discovered = @json_decode(file_get_contents($cacheFile), true);
            if (is_array($discovered)) {
                $submoduleMappings = array_merge($submoduleMappings, $discovered);
            }
        }

        return $submoduleMappings;
    }

    /**
     * Save a newly discovered submodule mapping to cache
     */
    private function saveSubmoduleMapping($submodule, $parentProject) {
        $cacheFile = $this->cacheDir . '/submodule_mappings.json';

        // Load existing discovered mappings
        $discovered = [];
        if (file_exists($cacheFile)) {
            $discovered = @json_decode(file_get_contents($cacheFile), true) ?: [];
        }

        // Add new mapping
        $discovered[$submodule] = $parentProject;

        // Save back to cache
        @file_put_contents($cacheFile, json_encode($discovered, JSON_PRETTY_PRINT));

        echo "Discovered: {$submodule} is a submodule of {$parentProject} (cached for future runs)\n";
    }

    /**
     * Map submodules to their parent projects
     * Returns the parent project name, or the original name if not a submodule
     */
    private function getParentProject($moduleName) {
        $mappings = $this->loadSubmoduleMappings();
        return $mappings[$moduleName] ?? $moduleName;
    }

    /**
     * Check if API response is a "project not found" error
     * Returns parent project name to try, or null if not a submodule error
     */
    private function parseSubmoduleError($xmlResponse) {
        if (!$xmlResponse) {
            return false;
        }

        // Parse the XML response to check for submodule error
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlResponse);
        libxml_clear_errors();

        if ($xml && $xml->getName() === 'error') {
            $errorMessage = (string)$xml;
            // Look for the specific "No release history was found" error
            if (strpos($errorMessage, 'No release history was found for the requested project') !== false) {
                return true; // This is a submodule error
            }
        }

        return false;
    }

    /**
     * Attempt to detect parent project using naming patterns
     * Fallback for modules not in static mapping
     */
    private function detectParentProject($moduleName) {
        // Pattern 1: {parent}_{suffix} format (most common)
        if (strpos($moduleName, '_') !== false) {
            $parts = explode('_', $moduleName);
            if (count($parts) >= 2) {
                // Try progressively shorter prefixes (longest first)
                for ($i = count($parts) - 1; $i > 0; $i--) {
                    $candidate = implode('_', array_slice($parts, 0, $i));
                    if (strlen($candidate) >= 3) { // Minimum reasonable project name length
                        return $candidate;
                    }
                }
            }
        }

        // Pattern 2: No underscore - likely standalone
        return $moduleName;
    }

    /**
     * Check all sites for updates
     */
    public function checkAllSites() {
        // First, check if Drush is available
        try {
            $this->checkDrushAvailability();
        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
            exit(1);
        }

        $allResults = [];

        foreach ($this->sites as $siteKey => $config) {
            echo "Checking site: {$config['name']} ({$config['url']})\n";
            echo str_repeat("-", 50) . "\n";

            try {
                $updates = $this->checkSiteUpdates($siteKey, $config);
                $allResults[$siteKey] = $updates;
                $this->displayResults($updates);

            } catch (Exception $e) {
                echo "❌ Error checking {$config['name']}: " . $e->getMessage() . "\n";
                $allResults[$siteKey] = ['error' => $e->getMessage()];
            }

            echo "\n" . str_repeat("=", 60) . "\n\n";
        }

        return $allResults;
    }

    /**
     * Check if Drush is available and executable by testing against the first configured site.
     *
     * @throws Exception if drush is not found or fails.
     */
    private function checkDrushAvailability() {
        echo "Checking Drush availability...\n";

        // If no sites are configured, there's nothing to check.
        if (empty($this->sites)) {
            echo "No sites configured, skipping Drush check.\n";
            return true;
        }

        // Use the first configured site to test Drush invocation
        $testSite = reset($this->sites);
        $command = $this->buildDrushCommand($testSite, "status --fields=drush-version --format=json");

        try {
            // Note: We use executeDrushCommand which redirects stderr to stdout
            $output = $this->executeDrushCommand($command);

            // Strip any preamble (SSH banners, tool output) that might not be JSON
            $jsonStart = strpos($output, '{');
            if ($jsonStart !== false) {
                $output = substr($output, $jsonStart);
            }

            $json = json_decode($output, true);

            if (isset($json['drush-version']) && !empty($json['drush-version'])) {
                echo "✅ Drush is available and responding (version {$json['drush-version']})\n\n";
                return true;
            }

            // Handle cases where drush returns an error in JSON or other non-JSON output
            $errorMessage = 'Drush is not available or returned an unexpected response.';
            if (is_array($json) && !empty($json['error'])) {
                $errorMessage = "Drush error: " . $json['error'];
            } elseif (!empty($output)) {
                $errorMessage .= " Raw output: " . substr(trim($output), 0, 250);
            }
            throw new Exception($errorMessage);

        } catch (Exception $e) {
            // Rethrow with more context for the user
            $prefix = trim((string) getenv('DRUSH_PREFIX'));
            $finalMessage = "Drush availability check failed. Make sure 'drush' is in your system's PATH";
            if ($prefix) {
                $finalMessage .= " and accessible via the prefix '{$prefix}'";
            }
            $finalMessage .= ".\n   The test command was: '{$command}'";
            $finalMessage .= "\n   Details: " . $e->getMessage();
            throw new Exception($finalMessage);
        }
    }

    /**
     * Check updates for a single site
     */
    private function checkSiteUpdates($siteKey, $config) {
        // Get installed modules using Drush
        echo "Getting installed modules...\n";
        $installedModules = $this->getInstalledModules($config);
        echo "Found " . count($installedModules) . " enabled modules\n";

        // Get Drupal core version
        $coreVersion = $this->getDrupalCoreVersion($config);
        echo "Drupal core version: $coreVersion\n";

        // Get PHP version
        $phpVersion = $this->getPhpVersion($config);
        echo "PHP version: $phpVersion\n";

        // Check Drupal core for updates
        echo "Checking Drupal core updates...\n";
        $coreUpdate = $this->checkDrupalCoreUpdate($coreVersion);

        // Check each module for updates
        echo "Checking module updates from Drupal.org...\n";
        $updateResults = $this->checkModuleUpdates($installedModules, $coreVersion);

        return [
            'site_name' => $config['name'],
            'site_url' => $config['url'],
            'core_version' => $coreVersion,
            'php_version' => $phpVersion,
            'core_update' => $coreUpdate,
            'total_modules' => count($installedModules),
            'security_updates' => $updateResults['security'],
            'available_updates' => $updateResults['regular'],
            'up_to_date' => $updateResults['current'],
            'unsupported_versions' => $updateResults['unsupported'],
            'check_timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Get installed modules via drush
     */
    private function getInstalledModules($config) {
        $command = $this->buildDrushCommand($config, "pm:list --type=module --status=enabled --format=json --no-core");

        $output = $this->executeDrushCommand($command);

        // Strip any preamble (SSH banners, tool output) mixed in via stderr redirect
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }

        $modules = json_decode($output, true);

        if (!$modules) {
            throw new Exception("Failed to get module list from Drush. Raw output: " . substr($output, 0, 500));
        }

        // Clean up the module data
        $cleanModules = [];
        foreach ($modules as $name => $info) {
            if (isset($info['version']) && $info['version'] !== 'Unknown') {
                $cleanModules[$name] = [
                    'name' => $name,
                    'version' => $info['version'],
                    'title' => $info['display_name'] ?? $name,
                    'package' => $info['package'] ?? 'Other'
                ];
            }
        }

        return $cleanModules;
    }

    /**
     * Get Drupal core version
     */
    private function getDrupalCoreVersion($config) {
        $command = $this->buildDrushCommand($config, "status --field=drupal-version");

        $output = $this->executeDrushCommand($command);
        return trim($output);
    }

    /**
     * Get PHP version
     */
    private function getPhpVersion($config) {
        $command = $this->buildDrushCommand($config, "status --field=php-version");

        $output = $this->executeDrushCommand($command);
        return trim($output);
    }

    /**
     * Check if Drupal core needs updating
     */
    private function checkDrupalCoreUpdate($currentCoreVersion) {
        // Check file cache first
        $cacheFilePath = $this->getCacheFilePath('drupal');
        $xml = $this->readCache($cacheFilePath);

        if ($xml !== null) {
            // Cache hit - use cached data
            $coreAnalysis = $this->analyzeCoreReleases($xml, $currentCoreVersion);

            echo "Core status: ";
            if ($coreAnalysis['has_security_update']) {
                echo "SECURITY UPDATE AVAILABLE\n";
            } elseif ($coreAnalysis['is_eol']) {
                echo "END-OF-LIFE - UNSUPPORTED VERSION\n";
            } elseif ($coreAnalysis['has_update']) {
                echo "update available\n";
            } else {
                echo "up to date\n";
            }

            return $coreAnalysis;
        }

        // Cache miss - query Drupal.org for core updates
        $url = "https://updates.drupal.org/release-history/drupal/current";

        $result = $this->fetchWithRetry($url);

        if (!$result['success'] || !$result['response']) {
            $errorMsg = isset($result['error']) ? $result['error'] : 'Failed to check core updates';
            return [
                'error' => $errorMsg,
                'has_update' => false,
                'has_security_update' => false
            ];
        }

        $response = $result['response'];

        // Validate and parse XML response
        $validation = $this->validateDrupalOrgResponse($response, 'core');
        if (!$validation['valid']) {
            return [
                'error' => 'Invalid API response: ' . $validation['error'],
                'has_update' => false,
                'has_security_update' => false
            ];
        }

        // Write to cache
        $this->writeCache($cacheFilePath, $response);

        $xml = $validation['xml'];

        // Analyze core releases
        $coreAnalysis = $this->analyzeCoreReleases($xml, $currentCoreVersion);

        echo "Core status: ";
        if ($coreAnalysis['has_security_update']) {
            echo "SECURITY UPDATE AVAILABLE\n";
        } elseif ($coreAnalysis['is_eol']) {
            echo "END-OF-LIFE - UNSUPPORTED VERSION\n";
        } elseif ($coreAnalysis['has_update']) {
            echo "update available\n";
        } else {
            echo "up to date\n";
        }

        return $coreAnalysis;
    }

    /**
     * Analyze Drupal core releases
     */
    private function analyzeCoreReleases($xml, $currentVersion) {
        if (!isset($xml->releases)) {
            return [
                'has_update' => false,
                'has_security_update' => false,
                'latest_version' => $currentVersion,
                'latest_security_version' => null,
                'current_version' => $currentVersion
            ];
        }

        // Check supported branches for Drupal Core
        $supportedBranches = [];
        if (isset($xml->supported_branches)) {
            $branchesString = (string)$xml->supported_branches;
            // Parse comma-separated supported branches (e.g., "10.5.,10.6.,11.2.,11.3.")
            $supportedBranches = array_map('trim', explode(',', $branchesString));
            $supportedBranches = array_filter($supportedBranches); // Remove empty values
        }

        $releases = [];
        $securityReleases = [];
        $stableReleases = [];

        foreach ($xml->releases->release as $release) {
            $version = (string)$release->version;
            $status = (string)$release->status;

            // Skip dev/alpha/beta/rc releases
            if (strpos($version, 'dev') !== false ||
                strpos($version, 'alpha') !== false ||
                strpos($version, 'beta') !== false ||
                strpos($version, 'rc') !== false) {
                continue;
            }

            $releaseData = [
                'version' => $version,
                'status' => $status,
                'date' => (string)$release->date,
                'security' => false
            ];

            // Check if this is a security release
            if (isset($release->terms)) {
                foreach ($release->terms->term as $term) {
                    $termName = (string)$term->name;
                    $termValue = (string)$term->value;

                    if ($termName === 'Release type' && $termValue === 'Security update') {
                        $releaseData['security'] = true;
                        break;
                    }
                }
            }

            $releases[] = $releaseData;

            if ($releaseData['security']) {
                $securityReleases[] = $releaseData;
            } elseif ($status === 'published') {
                $stableReleases[] = $releaseData;
            }
        }

        // Get current major.minor version (e.g., 10.4.9 -> 10.4)
        $currentMajorMinor = $this->getMajorMinorVersion($currentVersion);

        // Determine update status - only compare within same major.minor branch
        $hasSecurityUpdate = false;
        $hasRegularUpdate = false;
        $latestVersion = $currentVersion;
        $latestSecurityVersion = null;

        // Check for security updates (only within same major.minor)
        foreach ($securityReleases as $release) {
            $releaseMajorMinor = $this->getMajorMinorVersion($release['version']);

            // Only compare if same major.minor version
            if ($releaseMajorMinor === $currentMajorMinor &&
                version_compare($release['version'], $currentVersion, '>')) {
                $hasSecurityUpdate = true;
                if (!$latestSecurityVersion ||
                    version_compare($release['version'], $latestSecurityVersion, '>')) {
                    $latestSecurityVersion = $release['version'];
                }
            }
        }

        // Check for regular updates (only within same major.minor)
        foreach ($stableReleases as $release) {
            $releaseMajorMinor = $this->getMajorMinorVersion($release['version']);

            // Only compare if same major.minor version
            if ($releaseMajorMinor === $currentMajorMinor &&
                version_compare($release['version'], $currentVersion, '>')) {
                $hasRegularUpdate = true;
                if (version_compare($release['version'], $latestVersion, '>')) {
                    $latestVersion = $release['version'];
                }
            }
        }

        // Check for End-of-Life (EOL) branches using official supported_branches from Drupal.org
        $isEol = false;
        $recommendedVersion = null;

        // Use the official supported_branches from Drupal.org API (parsed earlier at line 248-254)
        // This contains the authoritative list like "10.5.,10.6.,11.2.,11.3."
        if (!empty($supportedBranches)) {
            $currentMajorMinor = $this->getMajorMinorVersion($currentVersion);

            // Check if current major.minor version matches any officially supported branch
            $currentBranchSupported = false;
            foreach ($supportedBranches as $supportedBranch) {
                // Remove trailing dots (e.g., "10.5." -> "10.5")
                $cleanBranch = rtrim($supportedBranch, '.');

                if ($cleanBranch === $currentMajorMinor) {
                    $currentBranchSupported = true;
                    break;
                }
            }

            // If current branch is not in the official supported list, it's EOL
            if (!$currentBranchSupported) {
                $isEol = true;

                // Find the next highest supported version (closest upgrade path)
                $currentMajor = explode('.', $currentVersion)[0];
                $recommendedVersion = '';

                // Clean and sort supported branches to find the next highest
                $cleanSupportedBranches = [];
                foreach ($supportedBranches as $supportedBranch) {
                    $cleanBranch = rtrim($supportedBranch, '.');
                    $cleanSupportedBranches[] = $cleanBranch;
                }

                // Sort branches in ascending order to find the next highest
                usort($cleanSupportedBranches, function($a, $b) {
                    return version_compare($a, $b); // Ascending order
                });

                // Find the next highest supported branch compared to current version
                $nextHighestBranch = null;
                foreach ($cleanSupportedBranches as $supportedBranch) {
                    if (version_compare($supportedBranch, $currentMajorMinor, '>')) {
                        // Prioritize same major version if available
                        $branchMajor = explode('.', $supportedBranch)[0];
                        if ($branchMajor === $currentMajor) {
                            $nextHighestBranch = $supportedBranch;
                            break; // Use the first (lowest) supported branch in same major version
                        } elseif (!$nextHighestBranch) {
                            // If no same-major version found yet, consider this as fallback
                            $nextHighestBranch = $supportedBranch;
                        }
                    }
                }

                // If we found a next highest branch, get the latest version in that branch
                if ($nextHighestBranch) {
                    foreach ($stableReleases as $release) {
                        $releaseMajorMinor = $this->getMajorMinorVersion($release['version']);
                        if ($releaseMajorMinor === $nextHighestBranch) {
                            if (!$recommendedVersion ||
                                version_compare($release['version'], $recommendedVersion, '>')) {
                                $recommendedVersion = $release['version'];
                            }
                        }
                    }
                }

                // If no next highest found (shouldn't happen), fallback to newest supported branch
                if (!$recommendedVersion && !empty($cleanSupportedBranches)) {
                    // Sort to get the newest supported branch as fallback
                    usort($cleanSupportedBranches, function($a, $b) {
                        return version_compare($b, $a); // Descending order
                    });

                    $newestBranch = $cleanSupportedBranches[0];
                    foreach ($stableReleases as $release) {
                        $releaseMajorMinor = $this->getMajorMinorVersion($release['version']);
                        if ($releaseMajorMinor === $newestBranch) {
                            if (!$recommendedVersion ||
                                version_compare($release['version'], $recommendedVersion, '>')) {
                                $recommendedVersion = $release['version'];
                            }
                        }
                    }
                }
            }
        }

        return [
            'has_update' => $hasRegularUpdate || $hasSecurityUpdate,
            'has_security_update' => $hasSecurityUpdate,
            'latest_version' => $latestVersion,
            'latest_security_version' => $latestSecurityVersion,
            'current_version' => $currentVersion,
            'is_eol' => $isEol,
            'recommended_version' => $recommendedVersion,
            'supported_branches' => $supportedBranches,
            'all_releases' => $releases
        ];
    }

    /**
     * Check updates for all modules by querying Drupal.org
     */
    private function checkModuleUpdates($installedModules, $coreVersion) {
        $results = [
            'security' => [],
            'regular' => [],
            'current' => [],
            'unsupported' => []
        ];

        $coreVersionMajor = $this->getMajorVersion($coreVersion);

        foreach ($installedModules as $name => $info) {
            $updateInfo = $this->checkModuleUpdate($name, $info['version'], $coreVersionMajor, $coreVersion);

            // Display check status with submodule info
            if ($updateInfo && isset($updateInfo['is_submodule']) && $updateInfo['is_submodule']) {
                echo "Checking {$name} (submodule of {$updateInfo['parent_project']})... ";
            } else {
                echo "Checking {$name}... ";
            }

            try {
                if ($updateInfo) {
                    $moduleData = array_merge($info, $updateInfo);

                    if ($updateInfo['is_unsupported']) {
                        $results['unsupported'][] = $moduleData;
                        echo "UNSUPPORTED VERSION\n";
                    } elseif ($updateInfo['has_security_update']) {
                        $results['security'][] = $moduleData;
                        echo "SECURITY UPDATE AVAILABLE\n";
                    } elseif ($updateInfo['has_update']) {
                        $results['regular'][] = $moduleData;
                        echo "update available\n";
                    } else {
                        $results['current'][] = $moduleData;
                        echo "up to date\n";
                    }
                } else {
                    $results['current'][] = $info;
                    echo "no update info\n";
                }

            } catch (Exception $e) {
                echo "error: " . $e->getMessage() . "\n";
                $results['current'][] = $info; // Assume current if we can't check
            }

            // Small delay to be respectful to Drupal.org
            usleep(500000); // 0.5 seconds
        }

        return $results;
    }

    /**
     * Check update for a single module via Drupal.org API
     */
    private function checkModuleUpdate($projectName, $currentVersion, $coreVersionMajor, $coreVersion) {
        // Check in-memory cache first (fastest) - try original name first
        $cacheKey = "{$projectName}_current";
        if (isset($this->drupalOrgCache[$cacheKey])) {
            return $this->analyzeReleases(
                $this->drupalOrgCache[$cacheKey],
                $currentVersion,
                $coreVersion
            );
        }

        // Check file cache second
        $cacheFilePath = $this->getCacheFilePath($projectName);
        $xml = $this->readCache($cacheFilePath);

        if ($xml !== null) {
            // Cache hit - store in memory cache and use
            $this->drupalOrgCache[$cacheKey] = $xml;
            return $this->analyzeReleases($xml, $currentVersion, $coreVersion);
        }

        // Cache miss - query Drupal.org API for original module name first
        $url = "https://updates.drupal.org/release-history/{$projectName}/current";
        $result = $this->fetchWithRetry($url);

        if ($result['success'] && $result['response']) {
            // Check if this is a submodule error
            if ($this->parseSubmoduleError($result['response'])) {
                // This is a submodule - try parent project
                return $this->tryParentProject($projectName, $currentVersion, $coreVersion);
            }

            // Successful response - parse and cache
            $xml = simplexml_load_string($result['response']);
            if ($xml) {
                $this->writeCache($cacheFilePath, $result['response']);
                $this->drupalOrgCache[$cacheKey] = $xml;
                return $this->analyzeReleases($xml, $currentVersion, $coreVersion);
            }
        }

        // Network error or other failure - try parent project as fallback
        return $this->tryParentProject($projectName, $currentVersion, $coreVersion);
    }

    /**
     * Attempt to find updates using parent project
     */
    private function tryParentProject($originalName, $currentVersion, $coreVersion) {
        // Step 1: Try static mapping first
        $parentProject = $this->getParentProject($originalName);
        if ($parentProject === $originalName) {
            // Step 2: No static mapping, try pattern detection
            $parentProject = $this->detectParentProject($originalName);
        }

        // Don't bother if we couldn't guess a different parent
        if ($parentProject === $originalName) {
            return null;
        }

        // Check cache for parent project
        $parentCacheKey = "{$parentProject}_current";
        if (isset($this->drupalOrgCache[$parentCacheKey])) {
            $result = $this->analyzeReleases(
                $this->drupalOrgCache[$parentCacheKey],
                $currentVersion,
                $coreVersion
            );
            return $this->addSubmoduleMetadata($result, $originalName, $parentProject);
        }

        // Check file cache for parent
        $parentCacheFile = $this->getCacheFilePath($parentProject);
        $xml = $this->readCache($parentCacheFile);

        if ($xml !== null) {
            $this->drupalOrgCache[$parentCacheKey] = $xml;
            $result = $this->analyzeReleases($xml, $currentVersion, $coreVersion);
            return $this->addSubmoduleMetadata($result, $originalName, $parentProject);
        }

        // Query parent project API
        $parentUrl = "https://updates.drupal.org/release-history/{$parentProject}/current";
        $parentResult = $this->fetchWithRetry($parentUrl);

        if ($parentResult['success'] && $parentResult['response'] &&
            !$this->parseSubmoduleError($parentResult['response'])) {

            $xml = simplexml_load_string($parentResult['response']);
            if ($xml) {
                // Success! Cache the API response
                $this->writeCache($parentCacheFile, $parentResult['response']);
                $this->drupalOrgCache[$parentCacheKey] = $xml;

                // Cache the discovered submodule mapping for future runs
                $this->saveSubmoduleMapping($originalName, $parentProject);

                $result = $this->analyzeReleases($xml, $currentVersion, $coreVersion);
                return $this->addSubmoduleMetadata($result, $originalName, $parentProject);
            }
        }

        return null; // No parent project found either
    }

    /**
     * Add submodule metadata to analysis result
     */
    private function addSubmoduleMetadata($result, $submoduleName, $parentProject) {
        if ($result) {
            $result['is_submodule'] = true;
            $result['parent_project'] = $parentProject;
            $result['submodule_name'] = $submoduleName;
        }
        return $result;
    }

    /**
     * Analyze releases to determine update status
     */
    private function analyzeReleases($xml, $currentVersion, $coreVersion) {
        if (!isset($xml->releases)) {
            return null;
        }

        // Check supported branches
        $supportedBranches = [];
        if (isset($xml->supported_branches)) {
            $branchesString = (string)$xml->supported_branches;
            // Parse comma-separated supported branches (e.g., "3.0.,4.0.")
            $supportedBranches = array_map('trim', explode(',', $branchesString));
            $supportedBranches = array_filter($supportedBranches); // Remove empty values
        }

        $releases = [];
        $securityReleases = [];
        $stableReleases = [];

        foreach ($xml->releases->release as $release) {
            $version = (string)$release->version;
            $status = (string)$release->status;

            // Skip dev/alpha/beta/rc releases for security analysis
            if (strpos($version, 'dev') !== false ||
                strpos($version, 'alpha') !== false ||
                strpos($version, 'beta') !== false ||
                strpos($version, 'rc') !== false) {
                continue;
            }

            $releaseData = [
                'version' => $version,
                'status' => $status,
                'date' => (string)$release->date,
                'security' => false,
                'core_compatibility' => isset($release->core_compatibility) ? (string)$release->core_compatibility : ''
            ];

            // Check if this is a security release using proper XML structure
            if (isset($release->terms)) {
                foreach ($release->terms->term as $term) {
                    $termName = (string)$term->name;
                    $termValue = (string)$term->value;

                    if ($termName === 'Release type' && $termValue === 'Security update') {
                        $releaseData['security'] = true;
                        break;
                    }
                }
            }

            $releases[] = $releaseData;

            if ($releaseData['security']) {
                $securityReleases[] = $releaseData;
            } elseif ($status === 'published') {
                $stableReleases[] = $releaseData;
            }
        }

        // Sort releases by version (newest first)
        usort($releases, function($a, $b) {
            return version_compare($b['version'], $a['version']);
        });

        // Determine update status
        $hasSecurityUpdate = false;
        $hasRegularUpdate = false;
        $latestVersion = '';
        $latestSecurityVersion = '';

        // Get current major version for compatibility checking
        $currentMajorVersion = $this->getModuleMajorVersion($currentVersion);

        // Check for security updates - only within same major version
        $sameMajorSecurityVersion = '';

        foreach ($securityReleases as $release) {
            if ($this->compareVersions($release['version'], $currentVersion) > 0) {
                $releaseMajorVersion = $this->getModuleMajorVersion($release['version']);

                if ($releaseMajorVersion === $currentMajorVersion) {
                    // Same major version - safe compatibility
                    if (!$sameMajorSecurityVersion ||
                        $this->compareVersions($release['version'], $sameMajorSecurityVersion) > 0) {
                        $sameMajorSecurityVersion = $release['version'];
                    }
                }
                // We skip cross-major security releases as they're not patches for the current branch
            }
        }

        // Only suggest security updates within the same major version
        // Cross-major "security" updates are usually not actual security fixes for the current branch
        if ($sameMajorSecurityVersion) {
            $hasSecurityUpdate = true;
            $latestSecurityVersion = $sameMajorSecurityVersion;
        }
        // Note: We intentionally do NOT suggest cross-major security updates
        // as they represent different software versions, not security patches

        // Check for regular updates - also prioritize same major version
        $sameMajorRegularVersion = '';
        $crossMajorRegularVersion = '';

        foreach ($stableReleases as $release) {
            if ($this->compareVersions($release['version'], $currentVersion) > 0) {
                $releaseMajorVersion = $this->getModuleMajorVersion($release['version']);

                if ($releaseMajorVersion === $currentMajorVersion) {
                    // Same major version - preferred for compatibility
                    if (!$sameMajorRegularVersion ||
                        $this->compareVersions($release['version'], $sameMajorRegularVersion) > 0) {
                        $sameMajorRegularVersion = $release['version'];
                    }
                } else {
                    // Different major version
                    if (!$crossMajorRegularVersion ||
                        $this->compareVersions($release['version'], $crossMajorRegularVersion) > 0) {
                        $crossMajorRegularVersion = $release['version'];
                    }
                }
            }
        }

        // For regular updates, only suggest if no security update available
        if (!$hasSecurityUpdate) {
            if ($sameMajorRegularVersion) {
                $hasRegularUpdate = true;
                $latestVersion = $sameMajorRegularVersion;
            } elseif ($crossMajorRegularVersion) {
                $hasRegularUpdate = true;
                $latestVersion = $crossMajorRegularVersion;
            }
        }

        // Check for unsupported version using actual core compatibility from Drupal.org API
        $isUnsupported = false;
        $supportedVersion = '';

        // Find the current version's compatibility
        $currentVersionCompatibility = '';
        foreach ($releases as $release) {
            if ($release['version'] === $currentVersion) {
                $currentVersionCompatibility = $release['core_compatibility'];
                break;
            }
        }

        // Check if current version is compatible with current Drupal core
        if ($currentVersionCompatibility && !$this->isCompatibleWithCore($currentVersionCompatibility, $coreVersion)) {
            // Look for a compatible version
            foreach ($releases as $release) {
                if ($release['status'] === 'published' &&
                    $release['core_compatibility'] &&
                    $this->isCompatibleWithCore($release['core_compatibility'], $coreVersion)) {
                    $isUnsupported = true;
                    if (!$supportedVersion || $this->compareVersions($release['version'], $supportedVersion) > 0) {
                        $supportedVersion = $release['version'];
                    }
                }
            }

            // If no compatible version found, still mark as unsupported
            if (!$supportedVersion) {
                $isUnsupported = true;
                $supportedVersion = "No compatible version available for Drupal " . explode('.', $coreVersion)[0];
            }
        }

        // Check if current version is in an unsupported branch
        if (!empty($supportedBranches) && !$isUnsupported) {
            $currentMajorVersion = $this->getModuleMajorVersion($currentVersion);

            // Check if current major version matches any supported branch
            $currentBranchSupported = false;
            foreach ($supportedBranches as $supportedBranch) {
                // Remove trailing dots (e.g., "3.0." -> "3.0")
                $cleanBranch = rtrim($supportedBranch, '.');

                // Check if current version starts with supported branch
                if (strpos($currentVersion, $cleanBranch) === 0 || $currentMajorVersion === $cleanBranch) {
                    $currentBranchSupported = true;
                    break;
                }
            }

            if (!$currentBranchSupported) {
                $isUnsupported = true;

                // Find the latest version in the newest supported branch
                $newestSupportedBranch = $supportedBranches[0] ?? null;
                if ($newestSupportedBranch) {
                    $cleanBranch = rtrim($newestSupportedBranch, '.');
                    $recommendedVersion = '';

                    foreach ($stableReleases as $release) {
                        $releaseVersion = $release['version'];
                        if (strpos($releaseVersion, $cleanBranch) === 0) {
                            if (!$recommendedVersion ||
                                $this->compareVersions($releaseVersion, $recommendedVersion) > 0) {
                                $recommendedVersion = $releaseVersion;
                            }
                        }
                    }

                    if ($recommendedVersion) {
                        $supportedVersion = $recommendedVersion;
                    }
                }
            }
        }

        return [
            'has_security_update' => $hasSecurityUpdate,
            'has_update' => $hasRegularUpdate || $hasSecurityUpdate,
            'latest_version' => $latestVersion ?: $currentVersion,
            'latest_security_version' => $latestSecurityVersion,
            'is_unsupported' => $isUnsupported,
            'supported_version' => $supportedVersion,
            'all_releases' => $releases
        ];
    }

    /**
     * Compare Drupal versions (handles 8.x-1.2 format)
     */
    private function compareVersions($version1, $version2) {
        // Normalize Drupal version strings
        $v1 = $this->normalizeVersion($version1);
        $v2 = $this->normalizeVersion($version2);

        return version_compare($v1, $v2);
    }

    /**
     * Normalize Drupal version format for comparison
     */
    private function normalizeVersion($version) {
        // Convert 8.x-1.2 to 1.2 for comparison within same major version
        if (preg_match('/^(\d+)\.x-(.+)$/', $version, $matches)) {
            return $matches[2];
        }

        // For versions like 9.5.2, keep as is
        return $version;
    }

    /**
     * Get major version (e.g., "9.5.2" -> "9.x")
     */
    private function getMajorVersion($version) {
        $parts = explode('.', $version);
        return $parts[0] . '.x';
    }

    /**
     * Get major.minor version for SemVer comparison (e.g., "10.4.9" -> "10.4")
     */
    private function getMajorMinorVersion($version) {
        $parts = explode('.', $version);
        if (count($parts) >= 2) {
            return $parts[0] . '.' . $parts[1];
        }
        return $version; // fallback if format is unexpected
    }

    /**
     * Get module major version for compatibility checking (e.g., "2.0.9" -> "2", "8.x-1.5" -> "1")
     */
    private function getModuleMajorVersion($version) {
        // Handle Drupal 8.x-style versions (e.g., "8.x-1.5" -> "1")
        if (preg_match('/^(\d+)\.x-(\d+)\./', $version, $matches)) {
            return $matches[2];
        }

        // Handle semantic versions (e.g., "2.0.9" -> "2")
        $parts = explode('.', $version);
        return $parts[0];
    }

    /**
     * Check if a core compatibility constraint is satisfied by the current Drupal version
     * Handles constraints like: "^9.3 | ^10", "^10.2 || ^11", "8.x"
     */
    private function isCompatibleWithCore($constraint, $coreVersion) {
        if (!$constraint) {
            return true; // No constraint specified, assume compatible
        }

        // Normalize core version (e.g., "10.3.1" -> "10.3")
        $coreVersionParts = explode('.', $coreVersion);
        $coreMajorMinor = $coreVersionParts[0] . '.' . ($coreVersionParts[1] ?? '0');

        // Handle different constraint formats
        $constraints = preg_split('/\s*(\|\|?|\s+or\s+)\s*/', $constraint, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($constraints as $singleConstraint) {
            $singleConstraint = trim($singleConstraint);

            // Handle "8.x" format
            if (preg_match('/^(\d+)\.x$/', $singleConstraint, $matches)) {
                if ($coreVersionParts[0] === $matches[1]) {
                    return true;
                }
                continue;
            }

            // Handle "^X.Y" format (caret constraint)
            if (preg_match('/^\^(\d+)(?:\.(\d+))?/', $singleConstraint, $matches)) {
                $constraintMajor = (int)$matches[1];
                $constraintMinor = isset($matches[2]) ? (int)$matches[2] : 0;
                $currentMajor = (int)$coreVersionParts[0];
                $currentMinor = (int)($coreVersionParts[1] ?? 0);

                // ^X.Y means >= X.Y.0 and < (X+1).0.0
                if ($currentMajor === $constraintMajor && $currentMinor >= $constraintMinor) {
                    return true;
                }
                continue;
            }

            // Handle ">=X.Y" format (greater than or equal constraint)
            if (preg_match('/^>=(\d+)(?:\.(\d+))?/', $singleConstraint, $matches)) {
                $constraintMajor = (int)$matches[1];
                $constraintMinor = isset($matches[2]) ? (int)$matches[2] : 0;
                $currentMajor = (int)$coreVersionParts[0];
                $currentMinor = (int)($coreVersionParts[1] ?? 0);

                // >=X.Y means current version must be >= X.Y
                if ($currentMajor > $constraintMajor ||
                    ($currentMajor === $constraintMajor && $currentMinor >= $constraintMinor)) {
                    return true;
                }
                continue;
            }

            // Handle exact version matches
            if ($singleConstraint === $coreMajorMinor || $singleConstraint === $coreVersion) {
                return true;
            }
        }

        return false; // No constraint matched
    }

    /**
     * Validate Drupal.org API XML response format
     *
     * @param string $xmlString Raw XML response
     * @param string $type Type of response: 'core' or 'module'
     * @return array ['valid' => bool, 'xml' => SimpleXMLElement|null, 'error' => string|null]
     */
    private function validateDrupalOrgResponse($xmlString, $type = 'module') {
        // Check if response is not empty
        if (empty($xmlString)) {
            return [
                'valid' => false,
                'xml' => null,
                'error' => 'Empty response from Drupal.org API'
            ];
        }

        // Suppress XML parsing warnings and capture them
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();

        // Check if XML parsing failed
        if ($xml === false) {
            $errorMsg = 'Invalid XML format';
            if (!empty($xmlErrors)) {
                $errorDetails = [];
                foreach ($xmlErrors as $error) {
                    $errorDetails[] = trim($error->message);
                }
                $errorMsg .= ': ' . implode('; ', $errorDetails);
            }
            return [
                'valid' => false,
                'xml' => null,
                'error' => $errorMsg
            ];
        }

        // Validate required root element
        if ($xml->getName() !== 'project') {
            return [
                'valid' => false,
                'xml' => null,
                'error' => 'Invalid root element: expected "project", got "' . $xml->getName() . '"'
            ];
        }

        // Validate required fields based on type
        // Note: 'api_version' is only required for modules, not for core
        $requiredFields = ['title', 'short_name'];
        if ($type !== 'core') {
            $requiredFields[] = 'api_version';
        }

        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (!isset($xml->$field)) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            return [
                'valid' => false,
                'xml' => null,
                'error' => 'Missing required fields: ' . implode(', ', $missingFields)
            ];
        }

        // Check if releases section exists (even if empty)
        if (!isset($xml->releases)) {
            return [
                'valid' => false,
                'xml' => null,
                'error' => 'Missing releases section in API response'
            ];
        }

        // Validate supported_branches format if present
        if (isset($xml->supported_branches)) {
            $branches = (string)$xml->supported_branches;
            if (!empty($branches) && !preg_match('/^[\d\.,\s]+$/', $branches)) {
                return [
                    'valid' => false,
                    'xml' => null,
                    'error' => 'Invalid supported_branches format: ' . $branches
                ];
            }
        }

        // All validation passed
        return [
            'valid' => true,
            'xml' => $xml,
            'error' => null
        ];
    }

    /**
     * Execute HTTP request with retry logic and exponential backoff
     *
     * @param string $url The URL to fetch
     * @param int $maxRetries Maximum number of retry attempts (default: 3)
     * @param int $initialDelay Initial delay in milliseconds (default: 500ms)
     * @return array ['success' => bool, 'response' => string|null, 'http_code' => int]
     */
    private function fetchWithRetry($url, $maxRetries = 3, $initialDelay = 500000) {
        $attempt = 0;
        $delay = $initialDelay;

        while ($attempt <= $maxRetries) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Drupal Update Checker 1.0',
                CURLOPT_CONNECTTIMEOUT => 5
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // Success condition
            if ($httpCode === 200 && $response) {
                return [
                    'success' => true,
                    'response' => $response,
                    'http_code' => $httpCode
                ];
            }

            // Determine if we should retry based on error type
            $shouldRetry = false;
            if ($httpCode === 0 || $httpCode >= 500 || $httpCode === 429) {
                // Network error, server error, or rate limit - retry
                $shouldRetry = true;
            } elseif ($httpCode === 404) {
                // Not found - don't retry
                return [
                    'success' => false,
                    'response' => null,
                    'http_code' => $httpCode
                ];
            }

            // If we've exhausted retries, return failure
            if ($attempt >= $maxRetries || !$shouldRetry) {
                return [
                    'success' => false,
                    'response' => $response,
                    'http_code' => $httpCode,
                    'error' => $curlError ?: "HTTP $httpCode"
                ];
            }

            // Wait before retrying (exponential backoff)
            if ($attempt < $maxRetries) {
                usleep($delay);
                $delay *= 2; // Double the delay for next attempt
            }

            $attempt++;
        }

        return [
            'success' => false,
            'response' => null,
            'http_code' => 0,
            'error' => 'Max retries exceeded'
        ];
    }

    /**
     * Build complete drush command with optional URI flag.
     *
     * Respects two env vars from config/.env:
     *   DRUSH_PREFIX  — prepended before the drush invocation (e.g. "ddev", "lando")
     *   DRUSH_SYNTAX  — "default" (drush @alias cmd) or "alternate" (@alias drush cmd)
     */
    private function buildDrushCommand($config, $drushCommand) {
        $prefix = trim((string) getenv('DRUSH_PREFIX'));
        $syntax = trim((string) getenv('DRUSH_SYNTAX')) ?: 'default';
        $drushTarget = $this->getDrushTarget($config);

        // --root= targets always use default drush syntax regardless of DRUSH_SYNTAX
        if (str_starts_with($drushTarget, '--root=')) {
            $parts = array_filter([$prefix, 'drush', $drushTarget, $drushCommand]);
        } elseif ($syntax === 'alternate') {
            // alternate: [prefix] @alias drush cmd  (e.g. lando @mysite.prod drush status)
            $parts = array_filter([$prefix, $drushTarget, 'drush', $drushCommand]);
        } else {
            // default: [prefix] drush @alias cmd  (e.g. drush @mysite.dev status)
            $parts = array_filter([$prefix, 'drush', $drushTarget, $drushCommand]);
        }

        $command = implode(' ', $parts);

        if (!empty($config['uri'])) {
            $command .= " --uri={$config['uri']}";
        }

        return $command;
    }

    /**
     * Get drush target (alias or --root= path)
     */
    private function getDrushTarget($config) {
        if (!empty($config['drush_alias'])) {
            return $config['drush_alias'];
        } elseif (!empty($config['path'])) {
            return "--root={$config['path']}";
        } else {
            throw new Exception("No drush alias or path configured");
        }
    }

    /**
     * Execute drush command
     */
    private function executeDrushCommand($command) {
        $output = shell_exec($command . ' 2>&1');

        if ($output === null) {
            throw new Exception("Failed to execute: $command");
        }

        return trim($output);
    }

    /**
     * Display results
     */
    private function displayResults($results) {
        echo "📊 RESULTS SUMMARY:\n";
        echo "Site: {$results['site_name']}\n";
        echo "Core: {$results['core_version']}\n";
        echo "Total modules: {$results['total_modules']}\n";
        echo "Security updates: " . count($results['security_updates']) . "\n";
        echo "Regular updates: " . count($results['available_updates']) . "\n";
        echo "Unsupported versions: " . count($results['unsupported_versions'] ?? []) . "\n";
        echo "Up to date: " . count($results['up_to_date']) . "\n\n";

        if (!empty($results['unsupported_versions'])) {
            echo "⚠️  UNSUPPORTED MODULE VERSIONS:\n";
            foreach ($results['unsupported_versions'] as $module) {
                // Display module name without submodule details for end users
                echo "  🔴 {$module['title']} ({$module['name']})\n";
                echo "      Current: {$module['version']} (no longer supported for Drupal {$results['core_version']})\n";
                if (strpos($module['supported_version'], 'No ') !== 0) {
                    echo "      Upgrade to: {$module['supported_version']}\n";
                } else {
                    echo "      Status: {$module['supported_version']}\n";
                }
            }
            echo "\n";
        }

        if (!empty($results['security_updates'])) {
            echo "🚨 SECURITY UPDATES NEEDED:\n";
            foreach ($results['security_updates'] as $module) {
                // Display module name without submodule details for end users
                echo "  ⚠️  {$module['title']} ({$module['name']})\n";
                echo "      Current: {$module['version']} → Latest: {$module['latest_version']}\n";
                if ($module['latest_security_version']) {
                    echo "      Security: {$module['latest_security_version']}\n";
                }
            }
            echo "\n";
        }

        if (!empty($results['available_updates'])) {
            echo "📦 REGULAR UPDATES AVAILABLE:\n";
            foreach ($results['available_updates'] as $module) {
                // Display module name without submodule details for end users
                echo "  📈 {$module['title']} ({$module['name']})\n";
                echo "      Current: {$module['version']} → Latest: {$module['latest_version']}\n";
            }
            echo "\n";
        }
    }

    /**
     * Generate HTML for integration
     */
    public function generateHtml($results) {
        $html = '';

        foreach ($results as $siteKey => $siteData) {
            if (isset($siteData['error'])) continue;

            $html .= '<div class="row" id="section-security-updates">';
            $html .= '<div class="col">';
            $html .= "<h2 class='section-title'>Module Security Updates - {$siteData['site_name']}</h2>";

            if (!empty($siteData['security_updates'])) {
                $html .= '<div class="alert alert-danger">';
                $html .= '<strong>' . count($siteData['security_updates']) . ' SECURITY UPDATES REQUIRED</strong>';
                $html .= '<table>';
                $html .= '<tr><th>Module</th><th>Current</th><th>Security Update</th></tr>';

                foreach ($siteData['security_updates'] as $module) {
                    $html .= '<tr class="has-error">';

                    // Display module name without submodule details for end users
                    $html .= '<td><strong>' . htmlspecialchars($module['title']) . '</strong><br>';
                    $html .= '<small>' . htmlspecialchars($module['name']) . '</small></td>';

                    $html .= '<td>' . htmlspecialchars($module['version']) . '</td>';
                    $html .= '<td><span class="badge badge-danger">' .
                             htmlspecialchars($module['latest_security_version'] ?: $module['latest_version']) .
                             '</span></td>';
                    $html .= '</tr>';
                }

                $html .= '</table></div>';
            } else {
                $html .= '<div class="alert alert-success">✅ No security updates needed</div>';
            }

            $html .= '<p><small>Checked: ' . $siteData['check_timestamp'] . '</small></p>';
            $html .= '</div></div>';
        }

        return $html;
    }

    /**
     * Export results
     */
    public function exportResults($results, $format = 'json') {
        $filename = $this->cacheDir . '/drupal_updates_' . date('Y-m-d_H-i-s');

        if ($format === 'json') {
            $filename .= '.json';
            file_put_contents($filename, json_encode($results, JSON_PRETTY_PRINT));
        } else {
            $filename .= '.html';
            file_put_contents($filename, $this->generateHtml($results));
        }

        echo "Results exported to: $filename\n";
        return $filename;
    }

    /**
     * Get cache statistics for this run
     */
    public function getCacheStats() {
        return [
            'cache_dir' => $this->cacheDir,
            'cache_ttl_hours' => self::CACHE_TTL / 3600,
            'cached_items' => count(glob($this->cacheDir . '/*.cache')),
            'memory_cache_items' => count($this->drupalOrgCache)
        ];
    }

    /**
     * Clear all cached API responses
     */
    public function clearCache() {
        $files = glob($this->cacheDir . '/*.cache');
        $count = 0;

        foreach ($files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

        echo "Cleared $count cache files from " . $this->cacheDir . "\n";
        return $count;
    }
}

// Standalone execution (only if called directly, not when included)
if (php_sapi_name() === 'cli' && basename($_SERVER['PHP_SELF']) === 'drupal_update_checker.php') {
    echo "Drupal Update Checker (Drupal.org API)\n";
    echo str_repeat("=", 40) . "\n\n";

    require_once __DIR__ . '/lib/Config.php';
    $config = Drupalator\Config::load();

    $customerType = $argv[1] ?? null;
    if (!$customerType) {
        echo "Usage: php drupal_update_checker.php <customer_key>\n";
        echo "Available: " . implode(', ', array_keys($config->customers())) . "\n";
        exit(1);
    }

    $customer = $config->customer($customerType);
    $checker = new DrupalUpdateChecker(false, $config->cacheDir());
    foreach ($customer['sites'] as $siteKey => $siteConfig) {
        $checker->setSingleSite($siteKey, $siteConfig);
        $results = $checker->checkAllSites();
        $checker->exportResults($results, 'json');
        $checker->exportResults($results, 'html');
    }

    echo "\n✅ Complete!\n";
}