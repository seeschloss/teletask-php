<?php

declare(strict_types=1);

namespace Teletask;

final class HttpResponse
{
    public int $status;

    public string $body;

    public string $url;

    /** @var array<string, string> */
    public array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, string $body, string $url, array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        $this->url = $url;
        $this->headers = $headers;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
