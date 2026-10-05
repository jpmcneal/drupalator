<?php

namespace Drupalator\EmailBackend;

use Drupalator\EmailBackend;
use RuntimeException;

require_once __DIR__ . '/MimeBuilder.php';

/**
 * Gmail draft backend via the Google Workspace CLI (`gws`).
 *
 * Builds an RFC2822 multipart/mixed message, base64-encodes it, and
 * hands it to `gws gmail users drafts create` as a draft (not a send).
 *
 * Requires the `gws` binary on PATH. When absent, throws with a
 * hint to switch backends via DRUPALATOR_EMAIL_BACKEND=stdout-eml.
 */
class Gws implements EmailBackend
{
    use MimeBuilder;

    public function __construct()
    {
    }

    public function send(string $subject, string $bodyPlain, string $htmlAttachmentPath, string $htmlAttachmentName): string
    {
        // Guard: gws must exist on PATH.
        $whichRc = 0;
        $whichOut = [];
        exec('which gws 2>/dev/null', $whichOut, $whichRc);
        if ($whichRc !== 0 || empty(trim(implode('', $whichOut)))) {
            throw new RuntimeException(
                "gws backend requires the `gws` binary on PATH.\n"
                . 'Install Google Workspace CLI or set DRUPALATOR_EMAIL_BACKEND=stdout-eml '
                . 'to write .eml files locally instead.'
            );
        }

        $mime = $this->buildMimeMessage(
            $subject,
            $bodyPlain,
            $htmlAttachmentPath,
            $htmlAttachmentName
        );

        // Gmail draft API expects the full RFC2822 message base64-encoded.
        $raw = base64_encode($mime);

        $tmpFile = tempnam(sys_get_temp_dir(), 'drupalator_draft_');
        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temp file for gws payload');
        }

        $payload = json_encode(
            ['message' => ['raw' => $raw]],
            JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            @unlink($tmpFile);
            throw new RuntimeException('Failed to encode gws payload as JSON');
        }
        if (file_put_contents($tmpFile, $payload) === false) {
            @unlink($tmpFile);
            throw new RuntimeException("Failed to write gws payload to {$tmpFile}");
        }

        try {
            $cmd = sprintf(
                'gws gmail users drafts create --params %s --json %s',
                escapeshellarg('{"userId": "me"}'),
                escapeshellarg((string) file_get_contents($tmpFile))
            );

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                throw new RuntimeException('Failed to spawn gws process');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $rc = proc_close($proc);

            if ($rc !== 0) {
                throw new RuntimeException(
                    "gws exited {$rc}: " . trim((string) $stderr)
                );
            }

            return trim((string) $stdout);
        } finally {
            @unlink($tmpFile);
        }
    }
}
