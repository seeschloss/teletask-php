<?php

declare(strict_types=1);

namespace Teletask;

/**
 * Small HTTP client used for the TELETASK configuration files.
 *
 * It uses the curl extension when available and falls back to PHP streams.
 * No third-party packages are required.
 */
final class Http
{
    private static bool $verifyTls = true;

    public static function setVerifyTls(bool $verify): void
    {
        self::$verifyTls = $verify;
    }

    /**
     * Resolve a user supplied host/URL into an HTTP origin.
     *
     * Accepted forms:
     *   192.168.0.82
     *   192.168.0.82:55959
     *   http://192.168.0.82:55959
     *   https://example:55959/
     *
     * @return array{scheme:string,host:string,port:int,base_url:string}
     */
    public static function resolveBase(string $input, int $defaultPort = 55959): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new TeletaskException('No host or URL was supplied');
        }

        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $input)) {
            $input = 'http://' . $input;
        }

        $parts = parse_url($input);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new TeletaskException('Could not parse host/URL: ' . $input);
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new TeletaskException('Only http:// and https:// URLs are supported, got: ' . $scheme);
        }

        $host = $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : $defaultPort;
        $hostPart = str_contains($host, ':') && $host[0] !== '[' ? '[' . $host . ']' : $host;

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'base_url' => $scheme . '://' . $hostPart . ':' . $port,
        ];
    }

    public static function get(string $url, float $timeout = 10.0, array $headers = []): HttpResponse
    {
        if (function_exists('curl_init')) {
            return self::curlGet($url, $timeout, $headers);
        }

        return self::streamGet($url, $timeout, $headers);
    }

    /**
     * Build a URL path, percent-encoding each segment but preserving '/'.
     */
    public static function encodePath(string $path): string
    {
        $segments = explode('/', $path);
        $segments = array_map(static fn (string $s): string => rawurlencode($s), $segments);

        return implode('/', $segments);
    }

    /**
     * @param list<string> $headers
     */
    private static function curlGet(string $url, float $timeout, array $headers): HttpResponse
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new TeletaskException('Could not initialise curl');
        }

        $responseHeaders = [];
        $headerLines = array_merge([
            'Accept: */*',
            'User-Agent: TeletaskPHP/1.0',
        ], $headers);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT_MS => (int) max(1, $timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) max(1, $timeout * 1000),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => self::$verifyTls,
            CURLOPT_SSL_VERIFYHOST => self::$verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                    return strlen($line);
                }
                $pos = strpos($trimmed, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($trimmed, 0, $pos)));
                    $value = trim(substr($trimmed, $pos + 1));
                    $responseHeaders[$name] = $value;
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($curl);
        if ($body === false) {
            $error = curl_error($curl);
            $errno = curl_errno($curl);
            curl_close($curl);
            throw new TeletaskException('HTTP request failed: ' . $error, [
                'url' => $url,
                'curl_errno' => $errno,
            ]);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) (curl_getinfo($curl, CURLINFO_EFFECTIVE_URL) ?: $url);
        curl_close($curl);

        return new HttpResponse($status, (string) $body, $effectiveUrl, $responseHeaders);
    }

    /**
     * @param list<string> $headers
     */
    private static function streamGet(string $url, float $timeout, array $headers): HttpResponse
    {
        $headerLines = array_merge([
            'Accept: */*',
            'User-Agent: TeletaskPHP/1.0',
        ], $headers);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines) . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => self::$verifyTls,
                'verify_peer_name' => self::$verifyTls,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();
            throw new TeletaskException('HTTP request failed: ' . ($error['message'] ?? 'unknown error'), [
                'url' => $url,
            ]);
        }

        $responseHeaders = [];
        $status = 0;
        $rawHeaders = $http_response_header ?? [];
        foreach ($rawHeaders as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
                $status = (int) $m[1];
                $responseHeaders = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return new HttpResponse($status, $body, $url, $responseHeaders);
    }
}
