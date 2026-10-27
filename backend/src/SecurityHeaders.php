<?php
declare(strict_types=1);
namespace AccManager;

final class SecurityHeaders
{
    public const HSTS_MAX_AGE = 86400;

    /** The web server owns HTTPS; client-supplied proxy headers are not proof. */
    public static function forRequest(array $server): array
    {
        $headers = [
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
        if (in_array(strtolower((string)($server['HTTPS'] ?? '')), ['on', '1'], true)) {
            $headers['Strict-Transport-Security'] = 'max-age=' . self::HSTS_MAX_AGE;
        }
        return $headers;
    }

    /** Usable before application/configuration bootstrap, including error replies. */
    public static function send(array $server): void
    {
        foreach (self::forRequest($server) as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
