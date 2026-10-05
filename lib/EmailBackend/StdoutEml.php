<?php

namespace Drupalator\EmailBackend;

use Drupalator\EmailBackend;
use RuntimeException;

require_once __DIR__ . '/MimeBuilder.php';

/**
 * Local-file backend: writes a full RFC2822 message as a .eml file.
 *
 * The output directory is passed to the constructor (typically the
 * customer's reports/emails dir). No To/From is required — a .eml on
 * disk is a review artifact, not a delivered message.
 *
 * The filename is derived from the subject, sanitized to
 * [a-z0-9_-]+, truncated to 80 chars, with a YmdHis timestamp suffix.
 */
class StdoutEml implements EmailBackend
{
    use MimeBuilder;

    private string $outputDir;

    public function __construct(string $outputDir)
    {
        $this->outputDir = rtrim($outputDir, '/');
    }

    public function send(string $subject, string $bodyPlain, string $htmlAttachmentPath, string $htmlAttachmentName): string
    {
        if (!is_dir($this->outputDir)) {
            if (!@mkdir($this->outputDir, 0755, true) && !is_dir($this->outputDir)) {
                throw new RuntimeException(
                    "Failed to create output dir: {$this->outputDir}"
                );
            }
        }

        $mime = $this->buildMimeMessage(
            $subject,
            $bodyPlain,
            $htmlAttachmentPath,
            $htmlAttachmentName
        );

        $slug = preg_replace('/[^a-z0-9_-]+/i', '_', $subject);
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'email';
        }
        if (strlen($slug) > 80) {
            $slug = substr($slug, 0, 80);
        }
        $filename = $slug . '-' . date('YmdHis') . '.eml';
        $fullPath = $this->outputDir . '/' . $filename;

        if (file_put_contents($fullPath, $mime) === false) {
            throw new RuntimeException(
                "Failed to write .eml to: {$fullPath}"
            );
        }

        return "Wrote EML to {$fullPath}";
    }
}
