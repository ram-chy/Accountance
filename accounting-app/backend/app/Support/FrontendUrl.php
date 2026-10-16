<?php

namespace App\Support;

use App\Exceptions\FrontendUrlNotConfiguredException;

/**
 * Builds absolute URLs that point at the public frontend (SPA).
 *
 * The origin is read from config('frontend.url') and is never derived from the
 * inbound request, so a spoofed Host or X-Forwarded-Host header cannot change
 * where an emailed link points. The origin is validated to be an absolute
 * http(s) URL before it is used; an unusable value raises a loud
 * FrontendUrlNotConfiguredException instead of producing a broken link.
 */
class FrontendUrl
{
    /**
     * Build an absolute frontend URL for a path and optional query string.
     *
     * @param  array<string, string>  $query
     *
     * @throws FrontendUrlNotConfiguredException
     */
    public static function to(string $path, array $query = []): string
    {
        $url = rtrim(self::origin(), '/').'/'.ltrim($path, '/');

        if ($query !== []) {
            // RFC 3986 encoding keeps reserved characters such as '+' in an
            // email address intact (as %2B) rather than turning them into a
            // space under the default form encoding.
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * The validated frontend origin, or a loud failure when it is unusable.
     *
     * @throws FrontendUrlNotConfiguredException
     */
    public static function origin(): string
    {
        $configured = config('frontend.url');
        $origin = is_string($configured) ? trim($configured) : '';

        if (! self::isValidOrigin($origin)) {
            throw new FrontendUrlNotConfiguredException(
                'FRONTEND_URL is not configured or is not an absolute http(s) URL.',
            );
        }

        return $origin;
    }

    /**
     * Assert that a usable frontend origin is configured.
     *
     * @throws FrontendUrlNotConfiguredException
     */
    public static function assertConfigured(): void
    {
        self::origin();
    }

    /**
     * Whether the value is an absolute http(s) URL with a host.
     */
    private static function isValidOrigin(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}
