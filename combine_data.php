<?php

require_once __DIR__ . '/lib/Config.php';

$options = parseArgs($argv);
$config  = Drupalator\Config::load();

$customerKeys = $options['customers']
    ? array_map('trim', explode(',', $options['customers']))
    : array_keys($config->customers());

$files = [];
foreach ($customerKeys as $key) {
    try {
        $dir = $config->customerOutputDir($key);
    } catch (Throwable $e) {
        fwrite(STDERR, "[combine] unknown customer '{$key}' — skipping\n");
        continue;
    }

    if ($options['date']) {
        $path = "{$dir}/{$key}-security-report-{$options['date']}.jsonc";
        if (!file_exists($path)) {
            fwrite(STDERR, "[combine] no report found for {$key} on {$options['date']}\n");
            continue;
        }
        $files[] = $path;
    } else {
        $matches = glob("{$dir}/{$key}-security-report-*.jsonc") ?: [];
        if (empty($matches)) {
            fwrite(STDERR, "[combine] no report found for {$key}\n");
            continue;
        }
        // Newest by mtime
        usort($matches, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $files[] = $matches[0];
    }
}

$allData = [];
foreach ($files as $file) {
    $data = parseJsonc($file);
    if ($data === null) continue;
    $allData[] = $data;
}

$json = json_encode($allData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($options['output']) {
    file_put_contents($options['output'], $json);
    fwrite(STDERR, "[combine] wrote " . count($allData) . " customer(s) to {$options['output']}\n");
} else {
    header('Content-Type: application/json');
    echo $json;
}

function parseArgs(array $argv): array {
    $out = ['date' => null, 'customers' => null, 'output' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--date='))       $out['date']      = substr($arg, 7);
        elseif (str_starts_with($arg, '--customers=')) $out['customers'] = substr($arg, 12);
        elseif (str_starts_with($arg, '--output='))    $out['output']    = substr($arg, 9);
    }
    return $out;
}

function parseJsonc(string $file): ?array {
    $content = file_get_contents($file);
    if ($content === false) return null;

    // Preserve existing JSONC cleanup logic verbatim
    $content = str_replace("\xEF\xBB\xBF", '', $content);
    $content = preg_replace('~/\*.*?\*/~s', '', $content);
    $content = preg_replace('~(?<!:)//.*$~m', '', $content);
    $content = preg_replace('~,\s*([\]}])~m', '$1', $content);
    $content = str_replace("\xc2\xa0", ' ', $content);
    $content = preg_replace('~[\x00-\x1F\x7F]~u', '', $content);

    $data = json_decode(trim($content), true);
    if ($data === null) {
        fwrite(STDERR, "[combine] JSON error in {$file}: " . json_last_error_msg() . "\n");
        return null;
    }
    return $data;
}
