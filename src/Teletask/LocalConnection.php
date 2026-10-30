<?php

declare(strict_types=1);

namespace Teletask;

/**
 * Local TCP connection to a TELETASK central unit (the iSGUI/GUI port,
 * normally 55956).
 *
 * Protocol summary:
 *   - Connect to the control port.
 *   - The central unit sends a CMD_LICENSE_SIGN challenge (command 13).
 *   - Sign "TDS15101:1/<option><centrales>/<oldAct>/<act>" with the embedded
 *     RSA key and send it back, XOR-obfuscated with the challenge key.
 *   - The central unit replies with CMD_LICENSE (command 14) and a status.
 *   - From then on payloads and checksums are XOR-obfuscated.
 */
final class LocalConnection
{
    private string $host;
    private int $port;
    private GuiConfig $config;
    private float $timeout;

    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';
    private int $xor = 0;
    private bool $activated = false;
    private int $lastAct = 0;
    private int $licenseStatus = -1;
    private string $option;
    private string $centrales;
    private string $licenseMode;
    private string $subscribeStyle;
    private float $keepAliveInterval;

    /** @var callable(string, ?string): void|null */
    private $logger;

    /** @var array<string, mixed> */
    private array $handshakeInfo = [];

    /** @var array<string, array<string, mixed>> */
    private array $states = [];

    /** @var list<int> */
    private array $subscribedFunctions = [];

    /** @var array<string, array{cen:int,fnc:int,load:int}> */
    private array $subscribedAddresses = [];

    private float $lastKeepAlive = 0.0;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $host, int $port, GuiConfig $config, array $options = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->config = $config;
        $this->timeout = (float) ($options['timeout'] ?? 10);
        $this->option = (string) ($options['option'] ?? $config->getOption());
        $this->centrales = (string) ($options['centrales'] ?? $config->centrales);
        $this->licenseMode = (string) ($options['license'] ?? 'auto');
        $this->subscribeStyle = (string) ($options['subscribe_style'] ?? 'fnc');
        $this->keepAliveInterval = (float) ($options['keepalive_interval'] ?? 30);
        $this->logger = $options['logger'] ?? null;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): void
    {
        $modes = match ($this->licenseMode) {
            'none' => [],
            'yes' => [true],
            'no' => [false],
            default => [true, false],
        };

        if ($modes === []) {
            $this->openSocket();
            $this->activated = true;
            $this->xor = 0;
            $this->debug('Connected without license handshake (--no-license)');

            return;
        }

        $attempts = [];
        $lastError = null;
        foreach ($modes as $xorResponse) {
            $this->openSocket();
            $this->buffer = '';
            $this->xor = 0;
            $this->activated = false;
            $this->lastAct = 0;

            try {
                $this->performHandshake($xorResponse);
                $this->debug(
                    'Handshake OK: license status ' . $this->licenseStatus
                    . ' (' . Protocol::licenseStatusName($this->licenseStatus) . ')'
                );

                return;
            } catch (TeletaskException $e) {
                $attempts[] = [
                    'response_xor' => $xorResponse,
                    'error' => $e->getMessage(),
                    'context' => $e->getContext(),
                ];
                $lastError = $e;
                $this->close();
            }
        }

        throw new TeletaskException(
            'License handshake failed: ' . ($lastError?->getMessage() ?? 'unknown error'),
            [
                'host' => $this->host,
                'port' => $this->port,
                'option' => $this->option,
                'centrales' => $this->centrales,
                'attempts' => $attempts,
            ],
            $lastError
        );
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
        $this->activated = false;
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket) && !feof($this->socket);
    }

    public function isActivated(): bool
    {
        return $this->activated;
    }

    public function licenseStatus(): int
    {
        return $this->licenseStatus;
    }

    public function xorKey(): int
    {
        return $this->xor;
    }

    /**
     * @return array<string, mixed>
     */
    public function handshakeInfo(): array
    {
        return $this->handshakeInfo;
    }

    public function get(int $cen, int $fnc, int $load): void
    {
        $this->validateAddress($cen, $fnc, $load);
        $this->sendFrame(array_merge(
            [Protocol::CMD_GET, $cen, $fnc],
            Protocol::uint16Bytes($load)
        ));
    }

    /**
     * @param list<int> $data
     */
    public function set(int $cen, int $fnc, int $load, int $action, array $data = []): void
    {
        $this->validateAddress($cen, $fnc, $load);
        $payload = array_merge(
            [Protocol::CMD_SET, $cen, $fnc],
            Protocol::uint16Bytes($load),
            [$action & 0xFF],
            array_map(static fn ($b): int => ((int) $b) & 0xFF, $data)
        );
        $this->sendFrame($payload);
    }

    public function subscribe(int $fnc, int $action = 255): void
    {
        $this->sendFrame([Protocol::CMD_SUBSCRIBE, $fnc & 0xFF, $action & 0xFF]);
        if ($action === 255 && !in_array($fnc, $this->subscribedFunctions, true)) {
            $this->subscribedFunctions[] = $fnc;
        }
        if ($action === 0) {
            $this->subscribedFunctions = array_values(array_filter(
                $this->subscribedFunctions,
                static fn (int $value): bool => $value !== $fnc
            ));
        }
    }

    public function subscribeFull(int $cen, int $fnc, int $load, int $action = 255): void
    {
        $this->validateAddress($cen, $fnc, $load);
        $this->sendFrame(array_merge(
            [Protocol::CMD_SUBSCRIBE, $cen, $fnc],
            Protocol::uint16Bytes($load),
            [$action & 0xFF]
        ));

        $key = $cen . ':' . $fnc . ':' . $load;
        if ($action === 255) {
            $this->subscribedAddresses[$key] = ['cen' => $cen, 'fnc' => $fnc, 'load' => $load];
        } elseif ($action === 0) {
            unset($this->subscribedAddresses[$key]);
        }
    }

    public function subscribeItem(Item $item, int $action = 255): void
    {
        if ($this->subscribeStyle === 'full') {
            $this->subscribeFull($item->cen, $item->fnc, $item->load, $action);

            return;
        }

        $this->subscribe($item->fnc, $action);
    }

    public function keepAlive(): void
    {
        $this->sendFrame([Protocol::CMD_KEEPALIVE]);
        $this->lastKeepAlive = microtime(true);
    }

    public function chartRequest(int $cen, int $fnc, int $load, int $period, int $type): void
    {
        $this->validateAddress($cen, $fnc, $load);
        $this->sendFrame(array_merge(
            [Protocol::CMD_CHART, $cen, $fnc],
            Protocol::uint16Bytes($load),
            Protocol::uint16Bytes($period),
            [$type & 0xFF]
        ));
    }

    /**
     * Send a GET and wait for the matching EXT_REPORT.
     *
     * @return array{cen:int,fnc:int,load:int,marker:int|null,data:list<int>}|null
     */
    public function waitForReport(int $cen, int $fnc, int $load, ?float $timeout = null): ?array
    {
        $timeout ??= $this->timeout;
        $end = microtime(true) + $timeout;

        while (($remaining = $end - microtime(true)) > 0) {
            $frame = $this->readNextFrame($remaining);
            if ($frame === null) {
                return null;
            }

            if ($frame->command() === Protocol::CMD_EXT_REPORT) {
                $report = Protocol::parseReport($frame);
                $this->states[$this->stateKey($report['cen'], $report['fnc'], $report['load'])] =
                    $report;
                if ($report['cen'] === $cen && $report['fnc'] === $fnc && $report['load'] === $load) {
                    return $report;
                }
            } elseif ($frame->command() === Protocol::CMD_LOG) {
                $this->debug('Log report received', $frame->raw);
            } else {
                $this->debug('Ignoring frame while waiting for report: ' . $frame->commandName(), $frame->raw);
            }
        }

        return null;
    }

    /**
     * Subscribe to function types (or individual items) and stream reports
     * until $duration expires (0 means forever).
     *
     * @param callable(array{cen:int,fnc:int,load:int,marker:int|null,data:list<int>}, Frame): void $callback
     * @param list<int> $functions
     * @param list<Item> $items
     */
    public function monitor(callable $callback, float $duration = 0.0, array $functions = [], array $items = []): void
    {
        if ($items !== []) {
            $seenFunctions = [];
            $seenAddresses = [];
            foreach ($items as $item) {
                $addressKey = $item->cen . ':' . $item->fnc . ':' . $item->load;
                if ($this->subscribeStyle === 'full') {
                    if (!isset($seenAddresses[$addressKey])) {
                        $this->subscribeFull($item->cen, $item->fnc, $item->load, 255);
                        $seenAddresses[$addressKey] = true;
                    }
                } elseif (!isset($seenFunctions[$item->fnc])) {
                    $this->subscribe($item->fnc, 255);
                    $seenFunctions[$item->fnc] = true;
                }
            }
        } else {
            foreach ($functions as $fnc) {
                $this->subscribe($fnc, 255);
            }
        }

        $end = $duration > 0 ? microtime(true) + $duration : null;
        $this->lastKeepAlive = microtime(true);

        try {
            while ($end === null || microtime(true) < $end) {
                $remaining = $end !== null ? max(0.0, $end - microtime(true)) : 1.0;
                $frame = $this->readNextFrame(min(1.0, $remaining));

                if ($frame === null) {
                    if (microtime(true) - $this->lastKeepAlive >= $this->keepAliveInterval) {
                        $this->keepAlive();
                    }
                    continue;
                }

                if ($frame->command() === Protocol::CMD_EXT_REPORT) {
                    $report = Protocol::parseReport($frame);
                    $this->states[$this->stateKey($report['cen'], $report['fnc'], $report['load'])] =
                        $report;
                    $callback($report, $frame);
                } elseif ($frame->command() === Protocol::CMD_LOG) {
                    $this->debug('Log report', $frame->raw);
                } elseif ($frame->command() === Protocol::CMD_CHART) {
                    $this->debug('Chart report', $frame->raw);
                } else {
                    $this->debug('Ignoring frame: ' . $frame->commandName(), $frame->raw);
                }
            }
        } finally {
            foreach ($this->subscribedFunctions as $fnc) {
                try {
                    $this->subscribe($fnc, 0);
                } catch (\Throwable) {
                    // The connection may already be gone.
                }
            }
            foreach ($this->subscribedAddresses as $address) {
                try {
                    $this->subscribeFull($address['cen'], $address['fnc'], $address['load'], 0);
                } catch (\Throwable) {
                    // The connection may already be gone.
                }
            }
        }
    }

    /**
     * @return array{cen:int,fnc:int,load:int,marker:int|null,data:list<int>}|null
     */
    public function getState(int $cen, int $fnc, int $load): ?array
    {
        return $this->states[$this->stateKey($cen, $fnc, $load)] ?? null;
    }

    /**
     * Read the next application frame, transparently handling license
     * re-challenges and license status messages.
     */
    public function readNextFrame(float $timeout): ?Frame
    {
        $end = microtime(true) + $timeout;

        while (($remaining = $end - microtime(true)) > 0) {
            $frame = $this->readFrame($remaining);
            if ($frame === null) {
                return null;
            }

            if ($frame->command() === Protocol::CMD_LICENSE_SIGN) {
                $this->debug('Re-challenge received', $frame->raw);
                $this->respondToChallenge($frame);
                continue;
            }

            if ($frame->command() === Protocol::CMD_LICENSE) {
                $this->licenseStatus = $frame->byte(1);
                $this->debug('License status update: ' . Protocol::licenseStatusName($this->licenseStatus));
                continue;
            }

            return $frame;
        }

        return null;
    }

    /**
     * Read one frame. If $xor is null the current XOR key is tried first,
     * then 0. This makes the client tolerant of units that do not obfuscate
     * the session.
     */
    public function readFrame(float $timeout, ?int $xor = null): ?Frame
    {
        $raw = $this->readRawFrame($timeout);
        if ($raw === null) {
            return null;
        }

        $candidates = $xor !== null ? [$xor & 0xFF] : [$this->xor & 0xFF, 0];
        $candidates = array_values(array_unique($candidates));
        $fallback = null;

        foreach ($candidates as $candidate) {
            $frame = Protocol::decode($raw, $candidate);
            if ($frame->checksumValid) {
                if ($this->activated && $candidate !== $this->xor) {
                    $this->debug(
                        'Switching session XOR from ' . $this->xor . ' to ' . $candidate
                        . ' (frame checksum matched)'
                    );
                    $this->xor = $candidate;
                }

                return $frame;
            }
            $fallback ??= $frame;
        }

        $this->debug('Checksum error on received frame', $raw);

        return $fallback;
    }

    private function performHandshake(bool $xorResponse): void
    {
        $challenge = $this->readRawFrame($this->timeout);
        if ($challenge === null) {
            throw new TeletaskException('Timed out waiting for the license challenge');
        }

        $frame = Protocol::decode($challenge, 0);
        if ($frame->command() !== Protocol::CMD_LICENSE_SIGN) {
            throw new TeletaskException(
                'Expected a license challenge (command 13), got ' . $frame->commandName(),
                ['frame' => $frame->hex()]
            );
        }
        if (!$frame->checksumValid) {
            throw new TeletaskException('License challenge has an invalid checksum', ['frame' => $frame->hex()]);
        }

        $act = $frame->uint32(1);
        $key = $frame->byte(1) ^ $frame->byte(2) ^ $frame->byte(3) ^ $frame->byte(4);
        $this->lastAct = 0;

        $this->debug(sprintf('License challenge: act=%d xor=%d', $act, $key), $frame->raw);
        $this->sendLicenseResponse($act, 0, $key, $xorResponse);

        $license = $this->readLicenseResponse($key);
        $status = $license->byte(1);
        if (!in_array($status, [Protocol::LIC_TEST, Protocol::LIC_FULL], true)) {
            throw new TeletaskException(
                'Central unit rejected the license: ' . Protocol::licenseStatusName($status),
                ['status' => $status, 'frame' => $license->hex()]
            );
        }

        $this->licenseStatus = $status;
        $this->xor = $key;
        $this->activated = true;
        $this->lastAct = $act;
        $this->lastKeepAlive = microtime(true);
        $licenseStrings = self::licenseStrings($this->option, $this->centrales, 0, $act);
        $this->handshakeInfo = [
            'challenge_act' => $act,
            'xor' => $key,
            'response_xor' => $xorResponse,
            'license_status' => $status,
            'license_status_name' => Protocol::licenseStatusName($status),
            'option' => $this->option,
            'centrales' => $this->centrales,
            'license_string' => $licenseStrings['signed'],
            'license_payload_string' => $licenseStrings['payload'],
        ];
    }

    private function respondToChallenge(Frame $frame): void
    {
        $act = $frame->uint32(1);
        $key = $frame->byte(1) ^ $frame->byte(2) ^ $frame->byte(3) ^ $frame->byte(4);
        $old = $this->lastAct;
        $this->sendLicenseResponse($act, $old, $key, true);
        $license = $this->readLicenseResponse($key);
        $status = $license->byte(1);
        if (!in_array($status, [Protocol::LIC_TEST, Protocol::LIC_FULL], true)) {
            throw new TeletaskException(
                'Central unit rejected the re-license: ' . Protocol::licenseStatusName($status),
                ['status' => $status, 'frame' => $license->hex()]
            );
        }

        $this->licenseStatus = $status;
        $this->xor = $key;
        $this->lastAct = $act;
        $this->activated = true;
        $this->debug(sprintf('Re-license OK: act=%d xor=%d status=%d', $act, $key, $status));
    }

    private function sendLicenseResponse(int $act, int $oldAct, int $key, bool $xorResponse): void
    {
        $strings = self::licenseStrings($this->option, $this->centrales, $oldAct, $act);
        $signature = RsaSigner::sign($strings['signed']);

        // Important: iSGUI signs the full string (including the act number)
        // but sends only the prefix (without the act number) in the payload.
        // The central unit reconstructs the signed string from its challenge.
        $payload = array_merge(
            [Protocol::CMD_LICENSE_SIGN],
            Protocol::stringToBytes($strings['payload'] . ';'),
            Protocol::stringToBytes(base64_encode($signature))
        );

        $raw = Protocol::encode($payload, $xorResponse ? $key : 0);
        $this->debug(
            sprintf(
                'Sending license response: signed="%s" payload="%s" signature_b64=%d bytes xor=%s',
                $strings['signed'],
                $strings['payload'],
                strlen(base64_encode($signature)),
                $xorResponse ? (string) $key : 'off'
            ),
            $raw
        );
        $this->writeAll($raw);
    }

    private function readLicenseResponse(int $key): Frame
    {
        $raw = $this->readRawFrame($this->timeout);
        if ($raw === null) {
            throw new TeletaskException('Timed out waiting for the license response');
        }

        $fallback = null;
        foreach (array_unique([0, $key & 0xFF]) as $candidate) {
            $frame = Protocol::decode($raw, $candidate);
            if ($frame->checksumValid) {
                if ($frame->command() === Protocol::CMD_LICENSE) {
                    return $frame;
                }
                if ($frame->command() === Protocol::CMD_LICENSE_SIGN) {
                    throw new TeletaskException(
                        'Central unit sent a new license challenge instead of a license status (signature rejected)',
                        ['frame' => $frame->hex()]
                    );
                }
            }
            $fallback ??= $frame;
        }

        throw new TeletaskException(
            'Unexpected license response: ' . ($fallback?->commandName() ?? 'invalid frame'),
            ['frame' => $raw === '' ? '' : strtoupper(bin2hex($raw))]
        );
    }

    /**
     * Build the two strings used during the license handshake.
     *
     * - "signed" includes the new act number and is what the RSA signature
     *   covers.
     * - "payload" is the prefix sent to the central unit; it deliberately
     *   omits the new act number. This mirrors iSGUI exactly.
     *
     * @return array{signed:string,payload:string}
     */
    public static function licenseStrings(string $option, string $centrales, int $oldAct, int $act): array
    {
        // ActionScript bitwise operations yield signed 32-bit integers, so a
        // challenge with the high bit set is rendered as a negative decimal
        // number in the signed string. Match that exactly.
        $prefix = 'TDS15101:1/' . $option . $centrales
            . '/' . self::signed32($oldAct)
            . '/';

        return [
            'signed' => $prefix . self::signed32($act),
            'payload' => $prefix,
        ];
    }

    private static function signed32(int $value): int
    {
        $value &= 0xFFFFFFFF;

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    private function openSocket(): void
    {
        $host = $this->host;
        if (str_contains($host, ':') && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout
        );

        if ($socket === false) {
            throw new TeletaskException(
                'Could not connect to ' . $this->host . ':' . $this->port . ': ' . $errstr,
                ['errno' => $errno]
            );
        }

        stream_set_timeout($socket, max(1, (int) ceil($this->timeout)));
        stream_set_blocking($socket, true);
        $this->socket = $socket;
        $this->debug('Connected to ' . $this->host . ':' . $this->port);
    }

    /**
     * @param list<int> $payload
     */
    private function sendFrame(array $payload): void
    {
        if (!is_resource($this->socket)) {
            throw new TeletaskException('Not connected to the central unit');
        }

        $raw = Protocol::encode($payload, $this->xor);
        $this->debug('TX ' . Protocol::commandName($payload[0] ?? -1), $raw);
        $this->writeAll($raw);
    }

    private function writeAll(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new TeletaskException('Not connected to the central unit');
        }

        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = @fwrite($this->socket, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new TeletaskException('Could not write to the central unit');
            }
            $written += $result;
        }
        @fflush($this->socket);
    }

    private function readRawFrame(float $timeout): ?string
    {
        if (!is_resource($this->socket)) {
            throw new TeletaskException('Not connected to the central unit');
        }

        $end = microtime(true) + max(0.0, $timeout);

        while (true) {
            $frame = $this->extractFrame();
            if ($frame !== null) {
                $this->debug('RX raw', $frame);

                return $frame;
            }

            $remaining = $end - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $read = [$this->socket];
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new TeletaskException('stream_select failed while reading from the central unit');
            }
            if ($selected === 0) {
                return null;
            }

            $chunk = @fread($this->socket, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($this->socket)) {
                    throw new TeletaskException('The central unit closed the connection');
                }
                continue;
            }

            $this->buffer .= $chunk;
        }
    }

    private function extractFrame(): ?string
    {
        while (true) {
            $position = strpos($this->buffer, "\x02");
            if ($position === false) {
                if ($this->buffer !== '') {
                    $this->debug('Discarding non-frame bytes', $this->buffer);
                    $this->buffer = '';
                }

                return null;
            }

            if ($position > 0) {
                $this->debug('Discarding bytes before STX', substr($this->buffer, 0, $position));
                $this->buffer = substr($this->buffer, $position);
            }

            $available = strlen($this->buffer);
            if ($available < 2) {
                return null;
            }

            $second = ord($this->buffer[1]);
            if ($second === 0xFF) {
                if ($available < 4) {
                    return null;
                }
                $declared = (ord($this->buffer[2]) << 8) | ord($this->buffer[3]);
                $headerLength = 4;
            } else {
                $declared = $second;
                $headerLength = 2;
            }

            $total = $declared + 1;
            if ($declared < 3 || $total > 1_048_576) {
                // Invalid header; drop the STX and resynchronise.
                $this->buffer = substr($this->buffer, 1);
                continue;
            }

            if ($available < $total) {
                return null;
            }

            $frame = substr($this->buffer, 0, $total);
            $this->buffer = substr($this->buffer, $total);

            return $frame;
        }
    }

    private function validateAddress(int $cen, int $fnc, int $load): void
    {
        if ($cen < 0 || $cen > 255) {
            throw new TeletaskException('Central number must be 0..255, got ' . $cen);
        }
        if ($fnc < 0 || $fnc > 255) {
            throw new TeletaskException('Function number must be 0..255, got ' . $fnc);
        }
        if ($load < 0 || $load > 65535) {
            throw new TeletaskException('Load must be 0..65535, got ' . $load);
        }
    }

    private function stateKey(int $cen, int $fnc, int $load): string
    {
        return $cen . ':' . $fnc . ':' . $load;
    }

    private function debug(string $message, ?string $hex = null): void
    {
        if ($this->logger === null) {
            return;
        }

        ($this->logger)($message, $hex);
    }
}
