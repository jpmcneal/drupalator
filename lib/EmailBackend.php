<?php

namespace Drupalator;

/**
 * Contract for email delivery backends.
 *
 * Implementations decide whether "delivering" an email means creating a
 * Gmail draft, piping to a local MTA (sendmail/msmtp), or writing a
 * .eml file to disk for later review.
 *
 * Callers construct backends via \Drupalator\EmailBackend\Factory::create().
 *
 * No autoloader is used — callers `require_once` this file and the
 * concrete backend files directly.
 */
interface EmailBackend
{
    /**
     * Deliver an email.
     *
     * @param string $subject             Message subject line.
     * @param string $bodyPlain           Plain-text body.
     * @param string $htmlAttachmentPath  Absolute path to HTML report to attach.
     * @param string $htmlAttachmentName  Filename to use in the MIME part.
     *
     * @return string  Human-readable status message (path written, draft ID, etc).
     *
     * @throws \RuntimeException on unrecoverable failure.
     */
    public function send(string $subject, string $bodyPlain, string $htmlAttachmentPath, string $htmlAttachmentName): string;
}
