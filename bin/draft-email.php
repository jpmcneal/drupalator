#!/usr/bin/env php
<?php

/**
 * Draft (or send, per backend) a weekly security-report email for a customer.
 *
 * Usage:
 *   bin/draft-email.php <customer_key>
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/EmailBackend.php';
require_once __DIR__ . '/../lib/EmailBackend/Factory.php';

use Drupalator\Config;
use Drupalator\EmailBackend\Factory;

try {
    $config = Config::load();
} catch (Throwable $e) {
    fwrite(STDERR, "[email] config error: " . $e->getMessage() . "\n");
    exit(1);
}

$customerKey = $argv[1] ?? '';
$available   = array_keys($config->customers());

if ($customerKey === '') {
    fwrite(STDERR, "Usage: " . basename(__FILE__) . " <customer_key>\n");
    fwrite(STDERR, "Available: " . implode(', ', $available) . "\n");
    exit(1);
}

try {
    $config->customer($customerKey);
} catch (Throwable $e) {
    fwrite(STDERR, "[email] " . $e->getMessage() . "\n");
    exit(1);
}

$dir = $config->customerOutputDir($customerKey);

$jsoncFile = newestMatch("{$dir}/{$customerKey}-security-report-*.jsonc");
$htmlFile  = newestMatch("{$dir}/{$customerKey}-security-report-*.html");

if ($jsoncFile === null || $htmlFile === null) {
    fwrite(STDERR, "[email] no security report found in {$dir} for '{$customerKey}'.\n");
    fwrite(STDERR, "[email] run `drupalator generate {$customerKey}` first.\n");
    exit(1);
}

$data = parseJsonc($jsoncFile);
if ($data === null) {
    fwrite(STDERR, "[email] failed to parse {$jsoncFile}\n");
    exit(1);
}

$customerName     = (string) ($data['meta']['customer'] ?? $customerKey);
$reportDate       = (string) ($data['meta']['report_date'] ?? date('Y-m-d'));
$totalSites       = (int) ($data['summary']['total_sites'] ?? 0);
$totalSecUpdates  = (int) ($data['summary']['total_security_module_updates'] ?? 0);
$totalRegular     = (int) ($data['summary']['total_regular_updates'] ?? 0);
$totalUnsupported = (int) ($data['summary']['total_unsupported_modules'] ?? 0);

$coreVersions = $data['summary']['core_versions'] ?? [];
$coreParts    = [];
if (is_array($coreVersions)) {
    foreach ($coreVersions as $ver => $count) {
        $n = (int) $count;
        $label = $n === 1 ? 'site' : 'sites';
        $coreParts[] = "{$ver} ({$n} {$label})";
    }
}
$coreVersionsStr = implode(', ', $coreParts);

$sitesCoreSec = 0;
$sites = $data['sites'] ?? [];
if (is_array($sites)) {
    foreach ($sites as $site) {
        if (is_array($site) && ($site['environment']['core_has_security_update'] ?? false) === true) {
            $sitesCoreSec++;
        }
    }
}

$signature = $config->emailSignature();
$body = <<<BODY
{$customerName} Team,

Here is this week's security report. The full report is attached but here is a summary:

- Total sites reviewed: {$totalSites}
- Sites needing Drupal Core security update: {$sitesCoreSec}
- Module security updates needed: {$totalSecUpdates}
- Unsupported modules: {$totalUnsupported}
- Regular updates available: {$totalRegular}
- Core versions in use: {$coreVersionsStr}

Please let me know if you have any questions.

Thanks,
{$signature}
BODY;

$subject = "Security Report Summary - {$customerName} ({$reportDate})";

try {
    $backend = Factory::create($config);
} catch (Throwable $e) {
    fwrite(STDERR, "[email] backend init failed: " . $e->getMessage() . "\n");
    exit(2);
}

echo "--- Email Preview ---\n";
echo "Backend: " . get_class($backend) . "\n";
echo "Subject: {$subject}\n";
echo "\n";
echo $body . "\n";
echo "--- End Preview ---\n";

try {
    $status = $backend->send($subject, $body, $htmlFile, basename($htmlFile));
    echo $status . "\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, "[email] failed: " . $e->getMessage() . "\n");
    exit(2);
}

exit(0);

// ---------- helpers ----------

function newestMatch(string $pattern): ?string
{
    $matches = glob($pattern) ?: [];
    if (empty($matches)) {
        return null;
    }
    usort($matches, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $matches[0];
}

function parseJsonc(string $file): ?array
{
    $content = @file_get_contents($file);
    if ($content === false) {
        return null;
    }
    // Same cleanup as combine_data.php
    $content = str_replace("\xEF\xBB\xBF", '', $content);
    $content = preg_replace('~/\*.*?\*/~s', '', $content);
    $content = preg_replace('~(?<!:)//.*$~m', '', $content);
    $content = preg_replace('~,\s*([\]}])~m', '$1', $content);
    $content = str_replace("\xc2\xa0", ' ', $content);
    $content = preg_replace('~[\x00-\x1F\x7F]~u', '', $content);

    $data = json_decode(trim((string) $content), true);
    if (!is_array($data)) {
        fwrite(STDERR, "[email] JSON error in {$file}: " . json_last_error_msg() . "\n");
        return null;
    }
    return $data;
}
