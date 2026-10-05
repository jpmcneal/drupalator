<?php

/**
 * CISA KEV Catalog — fetch, cache, and filter by Drupal Core version.
 * v1 scope: Drupal Core entries only, version-filtered via NVD CPE ranges.
 */
class KevCatalog {
    private const KEV_FEED      = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';
    private const NVD_CVE_API   = 'https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=';
    private const KEV_CACHE     = 'cisa_kev.json';
    private const CPE_CACHE_DIR = 'nvd_cpe';
    private const KEV_CACHE_TTL = 86400;   // 24h
    private const CPE_CACHE_TTL = 604800;  // 7 days
    private const NVD_THROTTLE  = 6;       // seconds between NVD requests on cache miss

    private string $cacheDir;
    private ?array $catalog = null;

    public function __construct(?string $cacheDir = null) {
        $this->cacheDir = $cacheDir ?? '/tmp/drupal_update_cache';
        $cpeDir = $this->cacheDir . '/' . self::CPE_CACHE_DIR;
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        if (!is_dir($cpeDir)) {
            mkdir($cpeDir, 0755, true);
        }
    }

    /**
     * Return full KEV catalog array (cached 24h, Wednesday-purged).
     */
    public function fetch(): array {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $cachePath = $this->cacheDir . '/' . self::KEV_CACHE;

        if ($this->isKevCacheValid($cachePath)) {
            $raw = file_get_contents($cachePath);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->catalog = $decoded;
                return $this->catalog;
            }
        }

        $raw = $this->httpGet(self::KEV_FEED);
        if ($raw === false) {
            fwrite(STDERR, "[KEV] Failed to fetch CISA KEV catalog — reports will have no KEV data\n");
            $this->catalog = [];
            return $this->catalog;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['vulnerabilities'])) {
            fwrite(STDERR, "[KEV] Unexpected KEV catalog format — reports will have no KEV data\n");
            $this->catalog = [];
            return $this->catalog;
        }

        file_put_contents($cachePath, $raw);
        $this->catalog = $decoded;
        return $this->catalog;
    }

    /**
     * Return catalog metadata: catalogVersion, dateReleased, count.
     */
    public function getMetadata(): array {
        $catalog = $this->fetch();
        return [
            'catalogVersion' => $catalog['catalogVersion'] ?? null,
            'dateReleased'   => $catalog['dateReleased'] ?? null,
            'count'          => count($catalog['vulnerabilities'] ?? []),
        ];
    }

    /**
     * Return KEV entries where vendorProject ~ /drupal/i AND product ~ /drupal core/i.
     */
    public function getDrupalCoreEntries(): array {
        $catalog = $this->fetch();
        $entries = [];
        foreach ($catalog['vulnerabilities'] ?? [] as $entry) {
            $vendor  = strtolower($entry['vendorProject'] ?? '');
            $product = strtolower($entry['product'] ?? '');
            if (str_contains($vendor, 'drupal') && str_contains($product, 'core')) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Return Drupal Core KEV entries whose NVD CPE ranges include $coreVersion.
     */
    public function getEntriesAffectingVersion(string $coreVersion): array {
        if (empty($coreVersion)) {
            return [];
        }

        $drupalEntries = $this->getDrupalCoreEntries();
        if (empty($drupalEntries)) {
            return [];
        }

        $matching = [];
        $lastRequest = 0;

        foreach ($drupalEntries as $entry) {
            $cveId = $entry['cveID'] ?? '';
            if (empty($cveId)) {
                continue;
            }

            $ranges = $this->fetchCpeRanges($cveId, $lastRequest);
            if (empty($ranges)) {
                // No range data — skip conservatively (don't assume affected)
                continue;
            }

            foreach ($ranges as $range) {
                if ($this->versionInRange($coreVersion, $range)) {
                    $matching[] = $entry;
                    break;
                }
            }
        }

        return $matching;
    }

    /**
     * Fetch NVD CPE version ranges for a CVE ID (cached 7 days).
     * Updates $lastRequest timestamp to enforce throttle.
     */
    private function fetchCpeRanges(string $cveId, int &$lastRequest): array {
        $safe      = preg_replace('/[^A-Z0-9\-]/i', '_', $cveId);
        $cachePath = $this->cacheDir . '/' . self::CPE_CACHE_DIR . '/' . $safe . '.json';

        if (file_exists($cachePath) && (time() - filemtime($cachePath)) < self::CPE_CACHE_TTL) {
            $cached = json_decode(file_get_contents($cachePath), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Throttle: sleep if last request was too recent
        $elapsed = time() - $lastRequest;
        if ($lastRequest > 0 && $elapsed < self::NVD_THROTTLE) {
            sleep(self::NVD_THROTTLE - $elapsed);
        }

        $url = self::NVD_CVE_API . urlencode($cveId);
        $raw = $this->httpGet($url);
        $lastRequest = time();

        if ($raw === false) {
            fwrite(STDERR, "[KEV] NVD fetch failed for {$cveId}\n");
            return [];
        }

        $decoded = json_decode($raw, true);
        $ranges  = $this->parseCpeRanges($decoded);

        file_put_contents($cachePath, json_encode($ranges));
        return $ranges;
    }

    /**
     * Parse NVD API v2 response into flat array of version range objects.
     * Each range: { versionStartIncluding, versionStartExcluding, versionEndIncluding, versionEndExcluding }
     */
    private function parseCpeRanges(?array $decoded): array {
        if (!is_array($decoded)) {
            return [];
        }

        $ranges = [];
        $vulns  = $decoded['vulnerabilities'] ?? [];

        foreach ($vulns as $vuln) {
            $configurations = $vuln['cve']['configurations'] ?? [];
            foreach ($configurations as $config) {
                foreach ($config['nodes'] ?? [] as $node) {
                    foreach ($node['cpeMatch'] ?? [] as $match) {
                        // Only Drupal core CPEs
                        $cpe = $match['criteria'] ?? '';
                        if (!str_contains(strtolower($cpe), 'drupal:drupal')) {
                            continue;
                        }
                        if (!($match['vulnerable'] ?? false)) {
                            continue;
                        }

                        $ranges[] = [
                            'versionStartIncluding' => $match['versionStartIncluding'] ?? null,
                            'versionStartExcluding' => $match['versionStartExcluding'] ?? null,
                            'versionEndIncluding'   => $match['versionEndIncluding']   ?? null,
                            'versionEndExcluding'   => $match['versionEndExcluding']   ?? null,
                        ];
                    }
                }
            }
        }

        return $ranges;
    }

    /**
     * Return true if $version falls within the given CPE range.
     */
    private function versionInRange(string $version, array $range): bool {
        $startInc = $range['versionStartIncluding'] ?? null;
        $startExc = $range['versionStartExcluding'] ?? null;
        $endInc   = $range['versionEndIncluding']   ?? null;
        $endExc   = $range['versionEndExcluding']   ?? null;

        if ($startInc !== null && version_compare($version, $startInc, '<')) {
            return false;
        }
        if ($startExc !== null && version_compare($version, $startExc, '<=')) {
            return false;
        }
        if ($endInc !== null && version_compare($version, $endInc, '>')) {
            return false;
        }
        if ($endExc !== null && version_compare($version, $endExc, '>=')) {
            return false;
        }

        return true;
    }

    /**
     * Check KEV cache validity: 24h TTL + Wednesday-UTC purge.
     */
    private function isKevCacheValid(string $cachePath): bool {
        if (!file_exists($cachePath)) {
            return false;
        }

        $cacheTime   = filemtime($cachePath);
        $currentTime = time();

        if (($currentTime - $cacheTime) > self::KEV_CACHE_TTL) {
            return false;
        }

        $now       = new DateTime('now', new DateTimeZone('UTC'));
        $cacheDate = new DateTime('@' . $cacheTime);
        $cacheDate->setTimezone(new DateTimeZone('UTC'));

        if ($now->format('N') == 3) {
            $midnight = clone $now;
            $midnight->setTime(0, 0, 0);
            if ($cacheDate < $midnight) {
                return false;
            }
        }

        return true;
    }

    /**
     * HTTP GET with User-Agent (cisa.gov returns 403 without it).
     */
    private function httpGet(string $url): string|false {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: Mozilla/5.0 (compatible; DrupalSecurityScanner/1.0)\r\n",
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        return $result;
    }
}
