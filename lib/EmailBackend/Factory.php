<?php

namespace Drupalator\EmailBackend;

use Drupalator\Config;
use Drupalator\EmailBackend;
use RuntimeException;

require_once __DIR__ . '/MimeBuilder.php';
require_once __DIR__ . '/Gws.php';
require_once __DIR__ . '/Sendmail.php';
require_once __DIR__ . '/Msmtp.php';
require_once __DIR__ . '/StdoutEml.php';

/**
 * Constructs the right EmailBackend based on \Drupalator\Config.
 *
 * Config::load() already validates DRUPALATOR_EMAIL_BACKEND against a
 * known-good list, so the default: branch here is defense-in-depth.
 */
class Factory
{
    public static function create(Config $config): EmailBackend
    {
        $backend = $config->emailBackend();

        switch ($backend) {
            case 'gws':
                return new Gws();

            case 'sendmail':
                $bin = getenv('DRUPALATOR_SENDMAIL_BIN');
                return new Sendmail(($bin === false || $bin === '') ? null : $bin);

            case 'msmtp':
                $bin = getenv('DRUPALATOR_MSMTP_BIN');
                $acct = getenv('DRUPALATOR_MSMTP_ACCOUNT');
                return new Msmtp(
                    ($bin === false || $bin === '') ? null : $bin,
                    ($acct === false || $acct === '') ? null : $acct
                );

            case 'stdout-eml':
                return new StdoutEml($config->reportsDir() . '/emails');

            default:
                throw new RuntimeException(
                    "Unsupported email backend: '{$backend}'"
                );
        }
    }
}
