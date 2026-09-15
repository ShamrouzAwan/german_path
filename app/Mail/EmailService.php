<?php

declare(strict_types=1);

namespace GermanPath\Mail;

use GermanPath\Config\Config;
use GermanPath\Support\Logger;

final class EmailService
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function sendVerification(string $email, string $token): void
    {
        $url = $this->url('/verify-email?token=' . rawurlencode($token));
        $this->send(
            $email,
            'Verify your GermanPath email',
            "Welcome to GermanPath.\n\nVerify your email address here:\n{$url}\n\nThis link expires soon."
        );
    }

    public function sendPasswordReset(string $email, string $token): void
    {
        $url = $this->url('/reset-password?token=' . rawurlencode($token));
        $this->send(
            $email,
            'Reset your GermanPath password',
            "Reset your GermanPath password here:\n{$url}\n\nIf you did not request this, you can ignore this email."
        );
    }

    public function sendEmailChange(string $email, string $token): void
    {
        $url = $this->url('/verify-email?token=' . rawurlencode($token));
        $this->send(
            $email,
            'Confirm your GermanPath email change',
            "Confirm your new GermanPath email address here:\n{$url}"
        );
    }

    private function send(string $to, string $subject, string $body): void
    {
        $driver = (string) $this->config->get('mail_driver', 'log');
        if ($driver === 'php') {
            $headers = 'From: ' . $this->config->get('mail_from', 'no-reply@germanpath.site');
            @mail($to, $subject, $body, $headers);
        }

        // The default log driver is safe for development and shared-hosting setup.
        // Never log the body: it contains a one-time token URL.
        $this->logger->info('Email prepared', [
            'driver' => $driver,
            'template_subject' => $subject,
            'recipient_domain' => substr(strrchr($to, '@') ?: '', 1),
        ]);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config->get('site_url', ''), '/') . $path;
    }
}
