<?php

namespace Drupalator\EmailBackend;

use Drupalator\EmailBackend;
use RuntimeException;

require_once __DIR__ . '/MimeBuilder.php';

/**
 * SMTP backend using the msmtp client.
 *
 * Builds an RFC2822 multipart/mixed message with To/From headers and
 * pipes it to `msmtp -t` (msmtp reads To from headers). An optional
 * account can be selected via `-a <account>`.
 *
 * To: is required — read from the DRUPALATOR_EMAIL_TO env var. From: is
 * optional (DRUPALATOR_EMAIL_FROM).
 */
class Msmtp implements EmailBackend
{
    use MimeBuilder;

    private string $binPath;
    private ?string $account;

    public function __construct(?string $binPath = null, ?string $account = null)
    {
        $this->binPath = $binPath !== null && $binPath !== ''
            ? $binPath
            : 'msmtp';
        $this->account = ($account !== null && $account !== '') ? $account : null;
    }

    public function send(string $subject, string $bodyPlain, string $htmlAttachmentPath, string $htmlAttachmentName): string
    {
        $to = getenv('DRUPALATOR_EMAIL_TO');
        if ($to === false || $to === '') {
            throw new RuntimeException(
                'Msmtp backend requires DRUPALATOR_EMAIL_TO env var'
            );
        }
        $fromRaw = getenv('DRUPALATOR_EMAIL_FROM');
        $from = ($fromRaw === false || $fromRaw === '') ? null : $fromRaw;

        $mime = $this->buildMimeMessage(
            $subject,
            $bodyPlain,
            $htmlAttachmentPath,
            $htmlAttachmentName,
            $to,
            $from
        );

        $cmd = escapeshellcmd($this->binPath);
        if ($this->account !== null) {
            $cmd .= ' -a ' . escapeshellarg($this->account);
        }
        $cmd .= ' -t';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException("Failed to spawn msmtp: {$this->binPath}");
        }
        fwrite($pipes[0], $mime);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        if ($rc !== 0) {
            throw new RuntimeException(
                "msmtp exited {$rc}: " . trim((string) $stderr)
            );
        }

        unset($stdout);

        $suffix = $this->account !== null ? " ({$this->account})" : '';
        return 'Sent via msmtp' . $suffix;
    }
}
