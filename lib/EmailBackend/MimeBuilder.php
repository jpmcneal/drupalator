<?php

namespace Drupalator\EmailBackend;

use RuntimeException;

/**
 * Shared MIME assembly for email backends.
 *
 * Produces a full RFC2822 multipart/mixed message with a text/plain
 * body part and a single base64-encoded HTML attachment. Used by
 * Gws, Sendmail, Msmtp, and StdoutEml.
 */
trait MimeBuilder
{
    /**
     * Build a complete RFC2822 message: headers + multipart body.
     *
     * The returned string is CRLF-normalised and safe to base64 for
     * the Gmail API, pipe to a `sendmail -t` compatible binary, or
     * write directly to a .eml file (a .eml IS a full RFC2822 message).
     *
     * @param string      $subject
     * @param string      $bodyPlain
     * @param string      $htmlAttachmentPath  Absolute path; must be readable.
     * @param string      $htmlAttachmentName  Filename shown in the MIME part.
     * @param string|null $to                  Optional To: header.
     * @param string|null $from                Optional From: header.
     *
     * @throws RuntimeException when the attachment file cannot be read.
     */
    protected function buildMimeMessage(
        string $subject,
        string $bodyPlain,
        string $htmlAttachmentPath,
        string $htmlAttachmentName,
        ?string $to = null,
        ?string $from = null
    ): string {
        if (!is_file($htmlAttachmentPath) || !is_readable($htmlAttachmentPath)) {
            throw new RuntimeException(
                "Cannot read HTML attachment: {$htmlAttachmentPath}"
            );
        }
        $htmlContent = file_get_contents($htmlAttachmentPath);
        if ($htmlContent === false) {
            throw new RuntimeException(
                "Failed to read HTML attachment: {$htmlAttachmentPath}"
            );
        }
        $htmlB64 = chunk_split(base64_encode($htmlContent), 76, "\r\n");

        // Boundary: 16 hex chars of randomness, no time collisions.
        $boundary = '__boundary_' . bin2hex(random_bytes(8)) . '__';

        $headers = [];
        if ($to !== null && $to !== '') {
            $headers[] = 'To: ' . $to;
        }
        if ($from !== null && $from !== '') {
            $headers[] = 'From: ' . $from;
        }
        $headers[] = 'Subject: ' . $subject;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $lines = [];
        foreach ($headers as $h) {
            $lines[] = $h;
        }
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/plain; charset=utf-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        $lines[] = $bodyPlain;
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/html; name="' . $htmlAttachmentName . '"';
        $lines[] = 'Content-Disposition: attachment; filename="' . $htmlAttachmentName . '"';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = rtrim($htmlB64, "\r\n");
        $lines[] = '--' . $boundary . '--';
        $lines[] = '';

        return implode("\r\n", $lines);
    }
}
