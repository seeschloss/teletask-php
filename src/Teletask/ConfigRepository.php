<?php

declare(strict_types=1);

namespace Teletask;

/**
 * Discovers and downloads TELETASK .gui configurations.
 *
 * The central unit serves a small text index at:
 *   /gui_files/iSGUI_V2.txt   (preferred)
 *   /gui_files/iSGUI.txt      (fallback)
 * and the actual configuration at:
 *   /gui_files/<CentralFile>.gui
 *
 * The .gui file is XOR-obfuscated and has a three-byte trailer.
 */
final class ConfigRepository
{
    private string $scheme;
    private string $host;
    private int $httpPort;
    private string $baseUrl;
    private float $timeout;
    private bool $refresh;
    private string $cacheDir;
    private int $cacheTtl;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $input, array $options = [])
    {
        $base = Http::resolveBase($input, (int) ($options['http_port'] ?? 55959));
        $this->scheme = $base['scheme'];
        $this->host = $base['host'];
        $this->httpPort = $base['port'];
        $this->baseUrl = $base['base_url'];
        $this->timeout = (float) ($options['timeout'] ?? 10);
        $this->refresh = (bool) ($options['refresh'] ?? false);
        $this->cacheTtl = (int) ($options['cache_ttl'] ?? 3600);
        $this->cacheDir = (string) ($options['cache_dir'] ?? self::defaultCacheDir());
        $this->ensureCacheDir();
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function httpPort(): int
    {
        return $this->httpPort;
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * @return list<array{line:string,name:string,central_file:string,id:?string,key:?string,source_url:string}>
     */
    public function listConfigs(): array
    {
        $cacheFile = $this->cacheDir !== ''
            ? $this->cacheDir . '/configs_' . md5($this->baseUrl) . '.json'
            : '';

        if (!$this->refresh && $cacheFile !== '' && $this->isFresh($cacheFile)) {
            $cached = $this->readJson($cacheFile);
            if (isset($cached['entries']) && is_array($cached['entries'])) {
                return $cached['entries'];
            }
        }

        $candidates = [
            $this->baseUrl . '/gui_files/iSGUI_V2.txt',
            $this->baseUrl . '/gui_files/iSGUI.txt',
        ];

        $errors = [];
        $body = null;
        $sourceUrl = '';
        foreach ($candidates as $url) {
            try {
                $response = Http::get($url, $this->timeout);
                if ($response->ok()) {
                    $body = $response->body;
                    $sourceUrl = $response->url;
                    break;
                }
                $errors[] = $url . ' -> HTTP ' . $response->status;
            } catch (TeletaskException $e) {
                $errors[] = $url . ' -> ' . $e->getMessage();
            }
        }

        if ($body === null) {
            if ($cacheFile !== '' && is_file($cacheFile)) {
                $cached = $this->readJson($cacheFile);
                if (isset($cached['entries']) && is_array($cached['entries'])) {
                    return $cached['entries'];
                }
            }

            throw new TeletaskException(
                'Could not download the TELETASK configuration index',
                ['base_url' => $this->baseUrl, 'errors' => $errors]
            );
        }

        $entries = $this->parseConfigList($body, $sourceUrl);
        if ($entries === []) {
            throw new TeletaskException(
                'The TELETASK configuration index is empty or malformed',
                ['url' => $sourceUrl, 'body' => substr($body, 0, 500)]
            );
        }

        if ($cacheFile !== '') {
            $this->writeJson($cacheFile, [
                'fetched_at' => time(),
                'source_url' => $sourceUrl,
                'entries' => $entries,
            ]);
        }

        return $entries;
    }

    /**
     * @return list<array{line:string,name:string,central_file:string,id:?string,key:?string,source_url:string}>
     */
    public function parseConfigList(string $body, string $sourceUrl = ''): array
    {
        $lines = preg_split('~\r\n|\r|\n~', $body) ?: [];
        $lines = array_map('trim', $lines);

        $first = null;
        $last = null;
        foreach ($lines as $index => $line) {
            if ($line === 'TT') {
                if ($first === null) {
                    $first = $index;
                }
                $last = $index;
            }
        }

        if ($first !== null && $last !== null && $last > $first) {
            $entries = array_slice($lines, $first + 1, $last - $first - 1);
        } else {
            // Be forgiving with servers that omit the TT markers.
            $entries = $lines;
        }

        $result = [];
        $seen = [];
        foreach ($entries as $line) {
            if ($line === '' || $line === 'TT') {
                continue;
            }

            $parts = explode(';', $line);
            $centralFile = trim($parts[0]);
            if ($centralFile === '' || isset($seen[$centralFile])) {
                continue;
            }
            $seen[$centralFile] = true;

            $result[] = [
                'line' => $line,
                'name' => str_replace('_', ' ', $centralFile),
                'central_file' => $centralFile,
                'id' => isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null,
                'key' => isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null,
                'source_url' => $sourceUrl,
            ];
        }

        return $result;
    }

    public function loadConfig(?string $selector = null): GuiConfig
    {
        $configs = $this->listConfigs();

        if ($selector === null || trim($selector) === '') {
            if (count($configs) === 1) {
                $selected = $configs[0];
            } else {
                throw new TeletaskException(
                    'Multiple configurations are available; choose one with --config',
                    ['configurations' => array_map(static fn (array $c): string => $c['name'], $configs)]
                );
            }
        } else {
            $selected = $this->selectConfig($configs, $selector);
        }

        return $this->downloadConfig($selected);
    }

    public function loadDefaultConfig(): GuiConfig
    {
        return $this->loadConfig(null);
    }

    /**
     * @param list<array{line:string,name:string,central_file:string,id:?string,key:?string,source_url:string}> $configs
     * @return array{line:string,name:string,central_file:string,id:?string,key:?string,source_url:string}
     */
    private function selectConfig(array $configs, string $selector): array
    {
        $needle = Text::lower(trim($selector));
        $exact = [];
        $partial = [];
        foreach ($configs as $config) {
            $name = Text::lower($config['name']);
            $file = Text::lower($config['central_file']);
            $line = Text::lower($config['line']);
            if ($name === $needle || $file === $needle || $line === $needle) {
                $exact[] = $config;
            } elseif (str_contains($name, $needle) || str_contains($file, $needle)) {
                $partial[] = $config;
            }
        }

        $matches = $exact !== [] ? $exact : $partial;
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            throw new TeletaskException('Configuration not found: ' . $selector, [
                'configurations' => array_map(static fn (array $c): string => $c['name'], $configs),
            ]);
        }

        throw new TeletaskException('Configuration name is ambiguous: ' . $selector, [
            'matches' => array_map(static fn (array $c): string => $c['name'], $matches),
        ]);
    }

    /**
     * @param array{line:string,name:string,central_file:string,id:?string,key:?string,source_url:string} $config
     */
    private function downloadConfig(array $config): GuiConfig
    {
        $centralFile = $config['central_file'];
        $cacheFile = $this->cacheDir !== ''
            ? $this->cacheDir . '/gui_' . md5($this->baseUrl . '|' . $centralFile) . '.gui'
            : '';

        if (!$this->refresh && $cacheFile !== '' && $this->isFresh($cacheFile)) {
            $bytes = @file_get_contents($cacheFile);
            if ($bytes !== false) {
                return GuiConfig::fromGuiBytes(
                    $bytes,
                    $this->baseUrl . '/gui_files/' . Http::encodePath($centralFile . '.gui')
                );
            }
        }

        $encodedName = Http::encodePath($centralFile . '.gui');
        $urls = [
            $this->baseUrl . '/gui_files/' . $encodedName,
        ];
        $rawName = str_replace(' ', '%20', $centralFile . '.gui');
        if ($rawName !== $encodedName) {
            $urls[] = $this->baseUrl . '/gui_files/' . $rawName;
        }

        $errors = [];
        $bytes = null;
        $sourceUrl = '';
        foreach ($urls as $url) {
            try {
                $response = Http::get($url, $this->timeout);
                if ($response->ok()) {
                    $bytes = $response->body;
                    $sourceUrl = $response->url;
                    break;
                }
                $errors[] = $url . ' -> HTTP ' . $response->status;
            } catch (TeletaskException $e) {
                $errors[] = $url . ' -> ' . $e->getMessage();
            }
        }

        if ($bytes === null) {
            if ($cacheFile !== '' && is_file($cacheFile)) {
                $bytes = @file_get_contents($cacheFile);
                if ($bytes !== false) {
                    return GuiConfig::fromGuiBytes(
                        $bytes,
                        $this->baseUrl . '/gui_files/' . Http::encodePath($centralFile . '.gui')
                    );
                }
            }

            throw new TeletaskException(
                'Could not download the TELETASK configuration file',
                ['central_file' => $centralFile, 'errors' => $errors]
            );
        }

        if ($cacheFile !== '') {
            @file_put_contents($cacheFile, $bytes);
        }

        return GuiConfig::fromGuiBytes($bytes, $sourceUrl);
    }

    private function isFresh(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $mtime = @filemtime($file);
        if ($mtime === false) {
            return false;
        }

        return (time() - $mtime) <= $this->cacheTtl;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        $content = @file_get_contents($file);
        if ($content === false || $content === '') {
            return [];
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function ensureCacheDir(): void
    {
        if ($this->cacheDir === '') {
            return;
        }

        if (!is_dir($this->cacheDir)) {
            if (!@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
                // Caching is optional; continue without it.
                $this->cacheDir = '';

                return;
            }
        }

        if (!is_writable($this->cacheDir)) {
            $this->cacheDir = '';
        }
    }

    private static function defaultCacheDir(): string
    {
        $xdg = getenv('XDG_CACHE_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return rtrim($xdg, '/') . '/teletask';
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/') . '/.cache/teletask';
        }

        return rtrim(sys_get_temp_dir(), '/') . '/teletask-cache';
    }
}
