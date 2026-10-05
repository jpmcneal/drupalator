<?php

namespace Drupalator;

use RuntimeException;

/**
 * Environment + sites.json configuration loader for the
 * drupalator tool.
 *
 * Usage:
 *   require_once __DIR__ . '/lib/Config.php';
 *   $config = \Drupalator\Config::load();
 *   $config->workingDir();
 *
 * No autoloader is used — callers `require_once` this file directly.
 */
class Config
{
    private const VALID_EMAIL_BACKENDS = ['gws', 'sendmail', 'msmtp', 'stdout-eml'];

    private string $repoRoot;
    private array $customers;
    private string $sitesFile;

    private function __construct(string $repoRoot, string $sitesFile, array $customers)
    {
        $this->repoRoot  = $repoRoot;
        $this->sitesFile = $sitesFile;
        $this->customers = $customers;
    }

    /**
     * Load configuration.
     *
     * Reads $repoRoot/config/.env if present, then loads and validates the
     * sites.json file referenced by DRUPALATOR_SITES_FILE.
     *
     * @throws RuntimeException on missing/malformed sites.json or invalid backend.
     */
    public static function load(?string $repoRoot = null): self
    {
        $repoRoot = $repoRoot ?? dirname(__DIR__);
        $repoRoot = rtrim($repoRoot, '/');

        self::loadDotEnv($repoRoot . '/config/.env');

        // Resolve sites file
        $sitesFileEnv = getenv('DRUPALATOR_SITES_FILE');
        if ($sitesFileEnv === false || $sitesFileEnv === '') {
            $sitesFileEnv = 'config/sites.json';
        }
        $sitesFile = self::resolvePath($sitesFileEnv, $repoRoot);

        if (!is_file($sitesFile)) {
            throw new RuntimeException(
                "sites.json not found at: {$sitesFile}\n"
                . "Set DRUPALATOR_SITES_FILE in config/.env or create config/sites.json (see config/sites.example.json)."
            );
        }

        $raw = file_get_contents($sitesFile);
        if ($raw === false) {
            throw new RuntimeException("Failed to read sites.json at: {$sitesFile}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                "sites.json is not valid JSON at: {$sitesFile}\n"
                . 'json_decode error: ' . json_last_error_msg()
            );
        }

        $customers = self::validateSchema($decoded, $sitesFile);

        // Validate email backend eagerly so misconfig fails fast at load time.
        $backend = getenv('DRUPALATOR_EMAIL_BACKEND');
        if ($backend !== false && $backend !== '' && !in_array($backend, self::VALID_EMAIL_BACKENDS, true)) {
            throw new RuntimeException(
                "Invalid DRUPALATOR_EMAIL_BACKEND: '{$backend}'. "
                . 'Must be one of: ' . implode(', ', self::VALID_EMAIL_BACKENDS)
            );
        }

        return new self($repoRoot, $sitesFile, $customers);
    }

    public function workingDir(): string
    {
        $val = getenv('DRUPALATOR_WORKING_DIR');
        if ($val === false || $val === '') {
            throw new RuntimeException(
                'DRUPALATOR_WORKING_DIR is required but not set. '
                . 'Set it in config/.env or export it in your shell.'
            );
        }
        return $this->expandPath($val);
    }

    public function reportsDir(): string
    {
        $val = getenv('DRUPALATOR_REPORTS_DIR');
        if ($val !== false && $val !== '') {
            return $this->expandPath($val);
        }
        return $this->workingDir() . '/reports';
    }

    public function cacheDir(): string
    {
        $val = getenv('DRUPALATOR_CACHE_DIR');
        if ($val === false || $val === '') {
            return '/tmp/drupal_update_cache';
        }
        return $this->expandPath($val);
    }

    public function emailBackend(): string
    {
        $val = getenv('DRUPALATOR_EMAIL_BACKEND');
        if ($val === false || $val === '') {
            return 'stdout-eml';
        }
        if (!in_array($val, self::VALID_EMAIL_BACKENDS, true)) {
            throw new RuntimeException(
                "Invalid DRUPALATOR_EMAIL_BACKEND: '{$val}'. "
                . 'Must be one of: ' . implode(', ', self::VALID_EMAIL_BACKENDS)
            );
        }
        return $val;
    }

    public function emailSignature(): string
    {
        $val = getenv('DRUPALATOR_EMAIL_SIGNATURE');
        if ($val === false || $val === '') {
            return 'Security Team';
        }
        return $val;
    }

    public function customers(): array
    {
        return $this->customers;
    }

    public function customer(string $key): array
    {
        if (!isset($this->customers[$key])) {
            $valid = implode(', ', array_keys($this->customers));
            throw new RuntimeException(
                "Unknown customer key: '{$key}'. Valid keys: {$valid}"
            );
        }
        return $this->customers[$key];
    }

    public function customerOutputDir(string $key): string
    {
        // Validate key exists so callers get a clear error, not a stray path.
        $this->customer($key);
        return $this->reportsDir() . '/' . $key;
    }

    /**
     * Parse a .env file into env vars. Existing env vars win.
     * Missing file is a silent no-op.
     */
    private static function loadDotEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $eq = strpos($trimmed, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eq));
            $val = trim(substr($trimmed, $eq + 1));
            // Strip surrounding single or double quotes
            if (strlen($val) >= 2 && (
                ($val[0] === "'" && $val[-1] === "'") ||
                ($val[0] === '"' && $val[-1] === '"')
            )) {
                $val = substr($val, 1, -1);
            }
            if ($key === '') {
                continue;
            }
            // Env vars win over .env file — don't overwrite.
            $existing = getenv($key);
            if ($existing !== false && $existing !== '') {
                continue;
            }
            putenv($key . '=' . $val);
        }
    }

    /**
     * Validate the sites.json schema. Returns the customers array.
     * Unknown top-level keys are ignored silently — we only validate
     * the customers subtree so the format can grow later.
     */
    private static function validateSchema(array $data, string $sitesFile): array
    {
        if (!isset($data['customers']) || !is_array($data['customers'])) {
            throw new RuntimeException(
                "sites.json is missing required top-level 'customers' object (at {$sitesFile})"
            );
        }
        $customers = $data['customers'];
        foreach ($customers as $custKey => $cust) {
            $path = "customers.{$custKey}";
            if (!is_array($cust)) {
                throw new RuntimeException("sites.json: {$path} must be an object");
            }
            if (!isset($cust['name']) || !is_string($cust['name'])) {
                throw new RuntimeException("sites.json: {$path}.name must be a string");
            }
            if (!isset($cust['sites']) || !is_array($cust['sites'])) {
                throw new RuntimeException("sites.json: {$path}.sites must be an object");
            }
            foreach ($cust['sites'] as $siteKey => $site) {
                $spath = "{$path}.sites.{$siteKey}";
                if (!is_array($site)) {
                    throw new RuntimeException("sites.json: {$spath} must be an object");
                }
                foreach (['name', 'url', 'drush_alias'] as $req) {
                    if (!isset($site[$req]) || !is_string($site[$req])) {
                        throw new RuntimeException("sites.json: {$spath}.{$req} must be a string");
                    }
                }
                // 'uri' optional; if present, must be string.
                if (isset($site['uri']) && !is_string($site['uri'])) {
                    throw new RuntimeException("sites.json: {$spath}.uri must be a string when present");
                }
            }
        }
        return $customers;
    }

    /**
     * Resolve a path: absolute stays absolute, otherwise join with repo root.
     * Also expands leading ~.
     */
    private static function resolvePath(string $path, string $repoRoot): string
    {
        $expanded = self::staticExpandPath($path);
        if (str_starts_with($expanded, '/')) {
            return $expanded;
        }
        return $repoRoot . '/' . $expanded;
    }

    private function expandPath(string $path): string
    {
        return self::staticExpandPath($path);
    }

    /**
     * Expand a leading ~ to the user's HOME directory. Absolute paths are
     * left alone. Directories are NOT created here — that's the caller's job.
     */
    private static function staticExpandPath(string $path): string
    {
        if ($path === '' || $path[0] !== '~') {
            return $path;
        }
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (!is_string($home) || $home === '') {
            return $path;
        }
        if ($path === '~') {
            return $home;
        }
        if (str_starts_with($path, '~/')) {
            return $home . substr($path, 1);
        }
        return $path;
    }
}
