<?php

declare(strict_types=1);

namespace Teletask;

/**
 * Command line front-end for the library.
 */
final class Cli
{
    private const VERSION = '0.1.0';

    /**
     * @param list<string> $argv
     */
    public static function run(array $argv): int
    {
        $debug = false;

        try {
            [$command, $positional, $options] = self::parseArgs($argv);
            $debug = isset($options['debug'])
                || in_array($command, ['probe', 'handshake'], true);

            if ($command === null || $command === 'help' || isset($options['help'])) {
                self::usage();

                return 0;
            }

            if ($command === 'version' || isset($options['version'])) {
                self::out('teletask-php ' . self::VERSION . PHP_EOL);

                return 0;
            }

            if (isset($options['insecure'])) {
                Http::setVerifyTls(false);
            }

            $host = $options['host'] ?? $positional[0] ?? null;

            return match ($command) {
                'discover', 'configs' => self::cmdDiscover($host, $options),
                'rooms' => self::cmdRooms($host, $options),
                'list', 'ls' => self::cmdList($host, $options),
                'get', 'state' => self::cmdGet($host, $options),
                'set' => self::cmdSet($host, $options, null),
                'on' => self::cmdSet($host, $options, 'on'),
                'off' => self::cmdSet($host, $options, 'off'),
                'toggle' => self::cmdSet($host, $options, 'toggle'),
                'monitor', 'watch' => self::cmdMonitor($host, $options),
                'cameras', 'cams', 'video' => self::cmdCameras($host, $options),
                'url', 'camera-url' => self::cmdUrl($host, $options),
                'probe', 'handshake' => self::cmdProbe($host, $options),
                default => throw new TeletaskException('Unknown command: ' . $command),
            };
        } catch (TeletaskException $e) {
            self::err('Error: ' . $e->getMessage() . PHP_EOL);
            if ($debug && $e->getContext() !== []) {
                self::err('Context: ' . json_encode(
                    $e->getContext(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) . PHP_EOL);
            }

            return 2;
        } catch (\Throwable $e) {
            self::err('Unexpected error: ' . $e->getMessage() . PHP_EOL);
            if ($debug) {
                self::err($e->getTraceAsString() . PHP_EOL);
            }

            return 1;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdDiscover(?string $host, array $options): int
    {
        $repository = self::repository($host, $options);
        $configs = $repository->listConfigs();

        if (isset($options['json'])) {
            self::json([
                'http_endpoint' => $repository->baseUrl(),
                'configurations' => $configs,
            ]);

            return 0;
        }

        self::out('HTTP endpoint: ' . $repository->baseUrl() . PHP_EOL);
        self::out('Configurations: ' . count($configs) . PHP_EOL);
        foreach ($configs as $config) {
            self::out('  - ' . $config['name']);
            if ($config['central_file'] !== $config['name']) {
                self::out(' (file: ' . $config['central_file'] . ')');
            }
            if ($config['key'] !== null) {
                self::out(' [key ' . $config['key'] . ']');
            }
            self::out(PHP_EOL);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdRooms(?string $host, array $options): int
    {
        $repository = self::repository($host, $options);
        $config = self::loadConfig($repository, $options);

        if (isset($options['json'])) {
            $data = $config->toArray(false);
            $data['rooms'] = array_map(
                static fn (Room $room): array => [
                    'index' => $room->index,
                    'name' => $room->name,
                    'section' => $room->section,
                    'items' => count($room->items),
                ],
                $config->rooms
            );
            self::json($data);

            return 0;
        }

        self::out('Configuration: ' . $config->name . PHP_EOL);
        self::out('Source: ' . $config->sourceUrl . PHP_EOL);
        self::out('Rooms: ' . count($config->rooms) . ', items: ' . count($config->allItems()) . PHP_EOL);
        self::out(PHP_EOL);

        foreach ($config->rooms as $room) {
            self::out(sprintf(
                '  %3d  %-32s %3d items%s',
                $room->index,
                $room->name,
                count($room->items),
                $room->visible ? '' : ' (hidden)'
            ) . PHP_EOL);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdList(?string $host, array $options): int
    {
        $repository = self::repository($host, $options);
        $config = self::loadConfig($repository, $options);

        $rooms = $config->rooms;
        if (isset($options['room']) && $options['room'] !== '') {
            $rooms = [$config->findRoom((string) $options['room'])];
        }

        if (isset($options['device']) && $options['device'] !== '') {
            $filtered = [];
            foreach ($rooms as $room) {
                $matches = $room->findItems((string) $options['device']);
                if ($matches !== []) {
                    $copy = clone $room;
                    $copy->items = $matches;
                    $filtered[] = $copy;
                }
            }
            $rooms = $filtered;
            if ($rooms === []) {
                throw new TeletaskException('Device not found: ' . $options['device']);
            }
        }

        if (isset($options['json'])) {
            $data = $config->toArray(false);
            [$controlHost, $controlPort] = self::controlEndpoint($config, $repository, $options);
            $data['control_host'] = $controlHost;
            $data['control_port'] = $controlPort;
            $data['rooms'] = array_map(static fn (Room $room): array => $room->toArray(), $rooms);
            self::json($data);

            return 0;
        }

        self::out('Configuration: ' . $config->name . PHP_EOL);
        self::out('Source: ' . $config->sourceUrl . PHP_EOL);
        [$controlHost, $controlPort] = self::controlEndpoint($config, $repository, $options);
        self::out('Control: ' . $controlHost . ':' . $controlPort
            . ' (license option: ' . $config->getOption() . ')' . PHP_EOL);
        self::out(PHP_EOL);

        foreach ($rooms as $room) {
            self::out($room->name . ' (index ' . $room->index . ')' . PHP_EOL);
            foreach ($room->items as $item) {
                $flags = [];
                if ($item->readonly) {
                    $flags[] = 'readonly';
                }
                if ($item->pwdProtect !== 0) {
                    $flags[] = 'password';
                }
                $actions = $item->actions();
                $actionText = $actions === [] ? 'view only' : implode(', ', $actions);
                self::out(sprintf(
                    '  [%-9s] %s %-18s %-14s actions: %s%s',
                    $item->category,
                    self::pad($item->name, 30),
                    $item->typeName(),
                    $item->address(),
                    $actionText,
                    $flags === [] ? '' : ' (' . implode(', ', $flags) . ')'
                ) . PHP_EOL);
            }
            self::out(PHP_EOL);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdGet(?string $host, array $options): int
    {
        $repository = self::repository($host, $options);
        $config = self::loadConfig($repository, $options);
        $target = self::resolveTarget($config, $options);

        if (isset($options['dry_run'])) {
            self::out('Would GET ' . self::targetLabel($target) . PHP_EOL);

            return 0;
        }

        $connection = self::connect($config, $repository, $options);
        try {
            $connection->get($target['cen'], $target['fnc'], $target['load']);
            $report = $connection->waitForReport(
                $target['cen'],
                $target['fnc'],
                $target['load'],
                self::timeout($options)
            );
        } finally {
            $connection->close();
        }

        if ($report === null) {
            throw new TeletaskException('No state report was received from the central unit');
        }

        self::printReport($target, $report, $options);

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdSet(?string $host, array $options, ?string $forcedAction): int
    {
        $repository = self::repository($host, $options);
        $config = self::loadConfig($repository, $options);
        $target = self::resolveTarget($config, $options);

        if ($target['item'] !== null && $target['item']->readonly && !isset($options['force'])) {
            throw new TeletaskException('Item is read-only: ' . $target['item']->name);
        }

        $action = $forcedAction ?? (string) ($options['action'] ?? '');
        if ($action === '') {
            if (isset($options['color'])) {
                $action = 'color';
            } elseif (isset($options['temperature']) || isset($options['brightness'])) {
                $action = 'temperature';
            }
        }
        if ($action === '') {
            throw new TeletaskException('No action supplied; use --action (or --color / --temperature)');
        }

        $value = isset($options['value']) ? (int) $options['value'] : null;
        $resolveOptions = [
            'color' => $options['color'] ?? null,
            'temperature' => isset($options['temperature']) ? (int) $options['temperature'] : null,
            'brightness' => isset($options['brightness']) ? (int) $options['brightness'] : null,
            'data' => $options['data'] ?? null,
        ];

        $resolved = $target['device']->resolveAction($action, $value, $resolveOptions);

        if (isset($options['dry_run'])) {
            self::out('Would SET ' . self::targetLabel($target)
                . ' action=' . $resolved['action']
                . ' data=[' . implode(',', $resolved['data']) . ']' . PHP_EOL);

            return 0;
        }

        $connection = self::connect($config, $repository, $options);
        try {
            $connection->set(
                $target['cen'],
                $target['fnc'],
                $target['load'],
                $resolved['action'],
                $resolved['data']
            );
            $report = null;
            if (isset($options['wait'])) {
                // Some units do not push a report for every SET. Ask for the
                // current state explicitly after a short settle delay.
                usleep(100_000);
                $connection->get($target['cen'], $target['fnc'], $target['load']);
                $report = $connection->waitForReport(
                    $target['cen'],
                    $target['fnc'],
                    $target['load'],
                    self::timeout($options)
                );
            }

            if (isset($options['json'])) {
                $result = [
                    'sent' => true,
                    'room' => $target['room']?->name ?? '',
                    'name' => $target['item']?->name ?? '',
                    'cen' => $target['cen'],
                    'fnc' => $target['fnc'],
                    'ld' => $target['load'],
                    'action' => $resolved['action'],
                    'data' => $resolved['data'],
                ];
                if ($report !== null) {
                    $result['state'] = $target['device']->parseState($report['data']);
                }
                self::json($result);
            } else {
                self::out('Sent ' . $action . ' to ' . self::targetLabel($target)
                    . ' (action=' . $resolved['action']
                    . ($resolved['data'] === [] ? '' : ', data=' . Protocol::bytesToHex($resolved['data']))
                    . ')' . PHP_EOL);

                if (isset($options['wait'])) {
                    if ($report !== null) {
                        self::printReport($target, $report, $options);
                    } else {
                        self::out('No state report received (command was sent).' . PHP_EOL);
                    }
                }
            }
        } finally {
            $connection->close();
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdMonitor(?string $host, array $options): int
    {
        $repository = self::repository($host, $options);
        $config = self::loadConfig($repository, $options);

        $items = $config->allItems();
        if (isset($options['room']) && $options['room'] !== '') {
            $items = $config->findRoom((string) $options['room'])->items;
        }
        if (isset($options['device']) && $options['device'] !== '') {
            $device = $config->findItem(
                isset($options['room']) ? (string) $options['room'] : null,
                (string) $options['device']
            );
            $items = [$device];
        }

        $items = array_values(array_filter($items, static fn (Item $item): bool => $item->isControllable()));
        if ($items === []) {
            throw new TeletaskException('No controllable items selected for monitoring');
        }

        $selected = [];
        foreach ($items as $item) {
            $selected[$item->cen . ':' . $item->fnc . ':' . $item->load] = true;
        }

        // Build the display map from the selected items first so that a device
        // which appears in several rooms is shown with the selected room.
        $addressMap = [];
        foreach ($items as $item) {
            $room = $config->findRoomForItem($item);
            $addressMap[$item->cen . ':' . $item->fnc . ':' . $item->load] = [
                'item' => $item,
                'room' => $room?->name ?? '',
            ];
        }
        foreach ($config->allItems() as $item) {
            $key = $item->cen . ':' . $item->fnc . ':' . $item->load;
            if (isset($addressMap[$key])) {
                continue;
            }
            $room = $config->findRoomForItem($item);
            $addressMap[$key] = [
                'item' => $item,
                'room' => $room?->name ?? '',
            ];
        }

        $functions = [];
        foreach ($items as $item) {
            if (!in_array($item->fnc, $functions, true)) {
                $functions[] = $item->fnc;
            }
        }

        $connection = self::connect($config, $repository, $options);
        $duration = isset($options['duration']) ? (float) $options['duration'] : 0.0;

        [$controlHost, $controlPort] = self::controlEndpoint($config, $repository, $options);
        self::err('Monitoring ' . count($items) . ' item(s) on '
            . $controlHost . ':' . $controlPort
            . ($duration > 0 ? ' for ' . $duration . 's' : ' (Ctrl-C to stop)') . PHP_EOL);

        $lastStates = [];

        try {
            if (!isset($options['no_initial'])) {
                $initialSeen = [];
                foreach ($items as $item) {
                    $key = $item->cen . ':' . $item->fnc . ':' . $item->load;
                    if (isset($initialSeen[$key])) {
                        continue;
                    }
                    $initialSeen[$key] = true;
                    $connection->get($item->cen, $item->fnc, $item->load);
                }
            }

            $connection->monitor(
                static function (array $report, Frame $frame) use ($addressMap, $selected, $options, &$lastStates): void {
                    $key = $report['cen'] . ':' . $report['fnc'] . ':' . $report['load'];
                    if (!isset($selected[$key])) {
                        return;
                    }

                    $stateSignature = $key . '|' . md5(json_encode($report['data']) ?: '');
                    if (!isset($options['no_dedup']) && ($lastStates[$key] ?? null) === $stateSignature) {
                        return;
                    }
                    $lastStates[$key] = $stateSignature;

                    $entry = $addressMap[$key] ?? null;
                    $item = $entry['item'] ?? null;
                    $room = $entry['room'] ?? '';
                    $state = $item?->device?->parseState($report['data']) ?? [
                        'type' => Protocol::functionName($report['fnc']),
                        'raw' => $report['data'],
                        'summary' => Protocol::bytesToHex($report['data']),
                    ];

                    if (isset($options['json'])) {
                        self::json([
                            'time' => date('c'),
                            'room' => $room,
                            'name' => $item?->name ?? '',
                            'cen' => $report['cen'],
                            'fnc' => $report['fnc'],
                            'ld' => $report['load'],
                            'state' => $state,
                        ], false);
                    } else {
                        self::out(sprintf(
                            '[%s] %s%s: %s',
                            date('H:i:s'),
                            $room !== '' ? $room . ' / ' : '',
                            $item?->name ?? ($report['cen'] . '/' . $report['fnc'] . '/' . $report['load']),
                            $state['summary'] ?? Protocol::bytesToHex($report['data'])
                        ) . PHP_EOL);
                    }
                },
                $duration,
                $functions,
                $items
            );
        } finally {
            $connection->close();
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdCameras(?string $host, array $options): int
    {
        $repository = self::repository($host, $options);
        $config = self::loadConfig($repository, $options);
        $cameras = $config->cameras();
        $showCredentials = isset($options['show_credentials']);

        if ($cameras === []) {
            if (isset($options['json'])) {
                self::json(['name' => $config->name, 'cameras' => []]);
            } else {
                self::out('No cameras or video door phones found in this configuration.' . PHP_EOL);
            }

            return 0;
        }

        if (isset($options['json'])) {
            $rows = [];
            foreach ($cameras as $entry) {
                $rows[] = self::cameraRow($config, $entry['room'], $entry['item'], $showCredentials);
            }
            self::json([
                'name' => $config->name,
                'source_url' => $config->sourceUrl,
                'cameras' => $rows,
            ]);

            return 0;
        }

        self::out('Configuration: ' . $config->name . PHP_EOL);
        self::out('Cameras: ' . count($cameras) . PHP_EOL);
        self::out(PHP_EOL);

        foreach ($cameras as $entry) {
            $row = self::cameraRow($config, $entry['room'], $entry['item'], $showCredentials);
            self::out(($row['room'] !== '' ? $row['room'] . ' / ' : '') . $row['name']
                . ' (' . $row['address'] . ')' . PHP_EOL);
            self::out('  url: ' . ($row['url'] !== '' ? $row['url'] : '<none>') . PHP_EOL);
            self::out('  mode: ' . $row['mode'] . PHP_EOL);
            if ($row['has_credentials']) {
                if ($showCredentials) {
                    self::out('  username: ' . $row['username'] . PHP_EOL);
                    self::out('  password: ' . $row['password'] . PHP_EOL);
                } else {
                    self::out('  auth: credentials available (use --show-credentials)' . PHP_EOL);
                }
            } else {
                self::out('  auth: none' . PHP_EOL);
            }
            self::out(PHP_EOL);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdUrl(?string $host, array $options): int
    {
        $repository = self::repository($host, $options);
        $config = self::loadConfig($repository, $options);
        $target = self::resolveTarget($config, $options);
        $item = $target['item'];

        if ($item === null || $item->fnc !== Protocol::FNC_VIDEO_DOORPHONE) {
            throw new TeletaskException('The selected item is not a camera or video door phone');
        }

        $row = self::cameraRow($config, $target['room'], $item, isset($options['show_credentials']));
        if ($row['url'] === '') {
            throw new TeletaskException('No stream URL is configured for ' . $item->name);
        }

        $url = $row['url'];
        $credentials = self::cameraCredentials($config, $item);
        if (isset($options['with_credentials']) && $credentials['username'] !== '' && $credentials['password'] !== '') {
            $url = self::withCredentials($url, $credentials['username'], $credentials['password']);
        }

        if (isset($options['json'])) {
            $result = $row;
            $result['url'] = $url;
            self::json($result);

            return 0;
        }

        if ($credentials['username'] !== '' && $credentials['password'] !== '' && !isset($options['with_credentials'])) {
            self::err('Note: this camera has credentials; use --with-credentials to embed them in the URL.' . PHP_EOL);
        }

        self::out($url . PHP_EOL);

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function cameraRow(GuiConfig $config, ?Room $room, Item $item, bool $includeCredentials): array
    {
        $meta = $item->device?->meta ?? [];
        $url = (string) ($meta['url'] ?? $item->extra['url'] ?? '');
        $videoCodec = (int) ($meta['videocodec'] ?? $item->extra['videocodec'] ?? 0);
        $credentials = self::cameraCredentials($config, $item);
        $hasCredentials = $credentials['username'] !== '' && $credentials['password'] !== '';

        $row = [
            'room' => $room?->name ?? '',
            'name' => $item->name,
            'cen' => $item->cen,
            'fnc' => $item->fnc,
            'ld' => $item->load,
            'address' => $item->address(),
            'url' => $url,
            'videocodec' => $videoCodec,
            'mode' => match ($videoCodec) {
                0 => 'still-image',
                1 => 'mjpeg',
                default => 'unknown',
            },
            'has_credentials' => $hasCredentials,
        ];

        if ($includeCredentials) {
            $row['username'] = $credentials['username'];
            $row['password'] = $credentials['password'];
        }

        return $row;
    }

    /**
     * @return array{username:string,password:string}
     */
    private static function cameraCredentials(GuiConfig $config, Item $item): array
    {
        $phone = $config->videoDoorPhoneFor($item);

        return [
            'username' => (string) ($phone['username'] ?? ''),
            'password' => (string) ($phone['password'] ?? ''),
        ];
    }

    private static function withCredentials(string $url, string $username, string $password): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $host = $parts['host'];
        if (str_contains($host, ':') && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }

        $authority = rawurlencode($username) . ':' . rawurlencode($password) . '@' . $host;
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $parts['scheme'] . '://' . $authority
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cmdProbe(?string $host, array $options): int
    {
        $repository = self::repository($host, $options);
        $config = self::loadConfig($repository, $options);
        [$controlHost, $controlPort] = self::controlEndpoint($config, $repository, $options);

        self::out('HTTP endpoint: ' . $repository->baseUrl() . PHP_EOL);
        self::out('Configuration: ' . $config->name . ' (' . $config->sourceUrl . ')' . PHP_EOL);
        self::out('Control endpoint: ' . $controlHost . ':' . $controlPort . PHP_EOL);
        self::out('License option: ' . (string) ($options['option'] ?? $config->getOption())
            . ', centrales: ' . (string) ($options['centrales'] ?? $config->centrales) . PHP_EOL);

        $options['debug'] = true;
        $connection = self::connect($config, $repository, $options);

        try {
            $info = $connection->handshakeInfo();
            self::out('Handshake OK:' . PHP_EOL);
            foreach ($info as $key => $value) {
                self::out('  ' . $key . ' = ' . (is_scalar($value) ? (string) $value : json_encode($value)) . PHP_EOL);
            }

            self::out('Sending keepalive...' . PHP_EOL);
            $connection->keepAlive();

            $end = microtime(true) + 2.0;
            while (microtime(true) < $end) {
                $frame = $connection->readNextFrame(max(0.1, $end - microtime(true)));
                if ($frame === null) {
                    continue;
                }
                self::out('RX ' . $frame->commandName() . ': ' . $frame->hex() . PHP_EOL);
            }
        } finally {
            $connection->close();
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function repository(?string $host, array $options): ConfigRepository
    {
        if ($host === null || trim($host) === '') {
            throw new TeletaskException('No host supplied; pass the central unit IP/hostname or use --host');
        }

        $repositoryOptions = [
            'http_port' => isset($options['http_port']) ? (int) $options['http_port'] : 55959,
            'timeout' => self::timeout($options),
            'refresh' => isset($options['refresh']),
            'cache_ttl' => isset($options['cache_ttl']) ? (int) $options['cache_ttl'] : 3600,
        ];
        if (isset($options['cache_dir'])) {
            $repositoryOptions['cache_dir'] = (string) $options['cache_dir'];
        }

        return new ConfigRepository($host, $repositoryOptions);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function loadConfig(ConfigRepository $repository, array $options): GuiConfig
    {
        return $repository->loadConfig(isset($options['config']) ? (string) $options['config'] : null);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{item:?Item,device:Device,cen:int,fnc:int,load:int,room:?Room}
     */
    private static function resolveTarget(GuiConfig $config, array $options): array
    {
        $hasAddress = isset($options['cen']) || isset($options['fnc']) || isset($options['ld']);
        if ($hasAddress) {
            if (!isset($options['cen'], $options['fnc'], $options['ld'])) {
                throw new TeletaskException('Address needs all three of --cen, --fnc and --ld');
            }
            $cen = (int) $options['cen'];
            $fnc = (int) $options['fnc'];
            $load = (int) $options['ld'];
            $item = $config->findItemByAddress($cen, $fnc, $load);
            $room = $item !== null ? $config->findRoomForItem($item) : null;

            return [
                'item' => $item,
                'device' => $item?->device ?? new Device($cen, $fnc, $load),
                'cen' => $cen,
                'fnc' => $fnc,
                'load' => $load,
                'room' => $room,
            ];
        }

        $deviceQuery = $options['device'] ?? null;
        if ($deviceQuery === null || $deviceQuery === '') {
            throw new TeletaskException('Select an item with --room/--device or --cen/--fnc/--ld');
        }

        $item = $config->findItem(
            isset($options['room']) ? (string) $options['room'] : null,
            (string) $deviceQuery
        );
        if ($item->device === null) {
            throw new TeletaskException('Item has no controllable device: ' . $item->name);
        }

        return [
            'item' => $item,
            'device' => $item->device,
            'cen' => $item->cen,
            'fnc' => $item->fnc,
            'load' => $item->load,
            'room' => $config->findRoomForItem($item),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0:string,1:int}
     */
    private static function controlEndpoint(GuiConfig $config, ConfigRepository $repository, array $options): array
    {
        $host = (string) ($options['control_host'] ?? $config->controlHost($repository->host()));
        $port = isset($options['control_port'])
            ? (int) $options['control_port']
            : $config->controlPort($repository->httpPort());

        return [$host, $port];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function connect(GuiConfig $config, ConfigRepository $repository, array $options): LocalConnection
    {
        [$host, $port] = self::controlEndpoint($config, $repository, $options);

        $license = 'auto';
        if (isset($options['no_license'])) {
            $license = 'none';
        } elseif (isset($options['license_xor'])) {
            $license = (string) $options['license_xor'];
        }

        $debug = isset($options['debug']);
        $logger = static function (string $message, ?string $hex = null) use ($debug): void {
            if (!$debug) {
                return;
            }
            self::err('[' . date('H:i:s') . '] ' . $message . PHP_EOL);
            if ($hex !== null && $hex !== '') {
                self::err('  ' . strtoupper(bin2hex($hex)) . PHP_EOL);
            }
        };

        $connectionOptions = [
            'timeout' => self::timeout($options),
            'license' => $license,
            'logger' => $logger,
        ];
        if (isset($options['option'])) {
            $connectionOptions['option'] = (string) $options['option'];
        }
        if (isset($options['centrales'])) {
            $connectionOptions['centrales'] = (string) $options['centrales'];
        }
        if (isset($options['subscribe_style'])) {
            $connectionOptions['subscribe_style'] = (string) $options['subscribe_style'];
        }
        if (isset($options['keepalive_interval'])) {
            $connectionOptions['keepalive_interval'] = (float) $options['keepalive_interval'];
        }

        $connection = new LocalConnection($host, $port, $config, $connectionOptions);
        $connection->connect();

        return $connection;
    }

    /**
     * @param array{item:?Item,device:Device,cen:int,fnc:int,load:int,room:?Room} $target
     * @param array{cen:int,fnc:int,load:int,marker:int|null,data:list<int>} $report
     * @param array<string, mixed> $options
     */
    private static function printReport(array $target, array $report, array $options): void
    {
        $state = $target['device']->parseState($report['data']);

        if (isset($options['json'])) {
            self::json([
                'room' => $target['room']?->name ?? '',
                'name' => $target['item']?->name ?? '',
                'cen' => $report['cen'],
                'fnc' => $report['fnc'],
                'ld' => $report['load'],
                'state' => $state,
            ]);

            return;
        }

        self::out(self::targetLabel($target) . PHP_EOL);
        self::out('  state: ' . ($state['summary'] ?? Protocol::bytesToHex($report['data'])) . PHP_EOL);

        $details = [];
        foreach ($state as $key => $value) {
            if (in_array($key, ['raw', 'hex', 'summary', 'type'], true)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = json_encode($value);
            }
            $details[] = $key . '=' . $value;
        }
        if ($details !== []) {
            self::out('  details: ' . implode(', ', $details) . PHP_EOL);
        }
        self::out('  raw: ' . Protocol::bytesToHex($report['data']) . PHP_EOL);
    }

    /**
     * @param array{item:?Item,device:Device,cen:int,fnc:int,load:int,room:?Room} $target
     */
    private static function targetLabel(array $target): string
    {
        $room = $target['room']?->name ?? '';
        $name = $target['item']?->name ?? ($target['cen'] . '/' . $target['fnc'] . '/' . $target['load']);

        return ($room !== '' ? $room . ' / ' : '') . $name
            . ' (' . $target['device']->typeName() . ', '
            . $target['cen'] . '/' . $target['fnc'] . '/' . $target['load'] . ')';
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function timeout(array $options): float
    {
        return isset($options['timeout']) ? max(0.1, (float) $options['timeout']) : 10.0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, bool $pretty = true): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        self::out(json_encode($data, $flags) . PHP_EOL);
    }

    private static function out(string $text): void
    {
        @fwrite(STDOUT, $text);
    }

    private static function err(string $text): void
    {
        @fwrite(STDERR, $text);
    }

    private static function pad(string $text, int $width): string
    {
        $length = Text::length($text);
        if ($length >= $width) {
            return $text . ' ';
        }

        return $text . str_repeat(' ', $width - $length);
    }

    /**
     * @param list<string> $argv
     * @return array{0:?string,1:list<string>,2:array<string,mixed>}
     */
    private static function parseArgs(array $argv): array
    {
        $args = array_slice($argv, 1);
        $command = null;
        $positional = [];
        $options = [];

        $valueOptions = [
            'host', 'http_port', 'control_host', 'control_port', 'config', 'room', 'device',
            'cen', 'fnc', 'ld', 'action', 'value', 'data', 'color', 'temperature',
            'brightness', 'duration', 'timeout', 'license_xor', 'option', 'centrales',
            'cache_dir', 'cache_ttl', 'subscribe_style', 'keepalive_interval',
        ];
        $flagOptions = [
            'json', 'debug', 'refresh', 'dry_run', 'no_license', 'help', 'version',
            'no_initial', 'force', 'insecure', 'wait', 'no_dedup',
            'show_credentials', 'with_credentials',
        ];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if ($arg === '--') {
                for ($j = $i + 1; $j < count($args); $j++) {
                    if ($command === null) {
                        $command = $args[$j];
                    } else {
                        $positional[] = $args[$j];
                    }
                }
                break;
            }

            if (str_starts_with($arg, '--')) {
                $name = substr($arg, 2);
                $value = null;
                $equals = strpos($name, '=');
                if ($equals !== false) {
                    $value = substr($name, $equals + 1);
                    $name = substr($name, 0, $equals);
                }
                $name = str_replace('-', '_', strtolower($name));

                if (in_array($name, $flagOptions, true)) {
                    $options[$name] = true;
                    continue;
                }
                if (!in_array($name, $valueOptions, true)) {
                    throw new TeletaskException('Unknown option: --' . $name);
                }
                if ($value === null) {
                    if (!isset($args[$i + 1])) {
                        throw new TeletaskException('Option --' . $name . ' needs a value');
                    }
                    $value = $args[++$i];
                }
                $options[$name] = $value;
                continue;
            }

            if ($arg === '-h') {
                $options['help'] = true;
                continue;
            }
            if ($arg === '-v') {
                $options['version'] = true;
                continue;
            }
            if (str_starts_with($arg, '-')) {
                throw new TeletaskException('Unknown option: ' . $arg);
            }

            if ($command === null) {
                $command = $arg;
            } else {
                $positional[] = $arg;
            }
        }

        return [$command, $positional, $options];
    }

    private static function usage(): void
    {
        $text = <<<'TXT'
teletask-php - control a TELETASK home automation system from PHP

Usage:
  teletask <command> <host[:port]> [options]

Commands:
  discover                 List .gui configurations on the central unit
  rooms                    List rooms in the selected configuration
  list                     List rooms, devices and available actions
  get                      Read the current state of one device
  set                      Send an action to one device
  on | off | toggle        Shortcuts for set --action on/off/toggle
  monitor                  Subscribe and print live state updates
  cameras                  List camera/video-door-phone stream URLs
  url                      Print just the stream URL for one camera
  probe                    Connect, perform the license handshake and dump traffic
  help                     Show this help
  version                  Show the version

Host:
  The host is the HTTP endpoint of the central unit (normally port 55959).
  The control port is taken from the .gui file (normally 55956), or from
  the HTTP port minus 3 when the configuration says useLocationIP="True".

Common options:
  --config NAME            Configuration name (needed when several exist)
  --room NAME              Room name or index
  --device NAME            Device/item name
  --cen N --fnc N --ld N   Address instead of --room/--device
  --action NAME|N          Action name or numeric action byte
  --value N                Level/speed for level/dim/speed actions
  --color R,G,B|#RRGGBB    RGB colour
  --temperature K          Colour temperature in Kelvin
  --brightness 0..100      Brightness for tunable-white lights
  --data B1,B2,...         Extra data bytes (decimal or 0xNN)
  --wait                   Wait for a state report after set
  --dry-run                Show what would be sent without connecting
  --json                   Machine-readable output
  --debug                  Dump TX/RX frames to stderr
  --timeout SECONDS        Network timeout (default 10)
  --refresh                Ignore the local configuration cache
  --cache-dir DIR          Configuration cache directory
  --control-host HOST      Override the control host
  --control-port PORT      Override the control port
  --license-xor MODE       auto (default), yes, no or none
  --no-license             Skip the license handshake (diagnostics)
  --option normal|test     Override the license option
  --centrales VALUE        Override the centrales string for licensing
  --subscribe-style fnc|full  Subscription format (default: fnc)
  --no-initial             Do not send initial GETs in monitor mode
  --no-dedup               Show repeated identical monitor reports
  --show-credentials       Include camera username/password in output
  --with-credentials       Embed camera credentials in the URL printed by 'url'
  --duration SECONDS       Monitor duration (0 = forever)

Examples:
  php bin/teletask discover 192.168.0.82
  php bin/teletask list 192.168.0.82 --room Kitchen
  php bin/teletask get 192.168.0.82 --room Kitchen --device "Cooking island"
  php bin/teletask on 192.168.0.82 --room Kitchen --device Dishwasher
  php bin/teletask set 192.168.0.82 --room Kitchen --device "Cooking island" --action 40
  php bin/teletask set 192.168.0.82 --room Living --device RGB --color ff8800
  php bin/teletask monitor 192.168.0.82 --room Kitchen --duration 30
  php bin/teletask cameras 192.168.0.82
  php bin/teletask url 192.168.0.82 --room Kitchen --device "Front door"
  php bin/teletask probe 192.168.0.82 --debug

Notes:
  - The local protocol is unauthenticated on the LAN. The license handshake is
    a client license check, not user authentication.
  - The cloud/AMQP connection method is intentionally not implemented.
TXT;

        self::out($text . PHP_EOL);
    }
}
