<?php

declare(strict_types=1);

namespace Teletask\Tests;

use Teletask\Frame;
use Teletask\Protocol;
use Teletask\RsaSigner;

/**
 * A tiny mock TELETASK central unit used by the integration test.
 */
final class MockCentral
{
    /** @var callable(string): void|null */
    private static $logger = null;

    public static function run($server, ?callable $logger = null): void
    {
        self::$logger = $logger;
        $client = @stream_socket_accept($server, 30);
        if ($client === false) {
            self::log('accept timed out');

            return;
        }
        stream_set_timeout($client, 10);
        self::log('client connected');

        $buffer = '';
        $act = 0x00123456;
        $key = (($act >> 24) & 0xFF) ^ (($act >> 16) & 0xFF) ^ (($act >> 8) & 0xFF) ^ ($act & 0xFF);

        self::sendFrame($client, [
            Protocol::CMD_LICENSE_SIGN,
            ($act >> 24) & 0xFF,
            ($act >> 16) & 0xFF,
            ($act >> 8) & 0xFF,
            $act & 0xFF,
        ], 0);

        $raw = self::readRaw($client, $buffer);
        if ($raw === null) {
            self::log('no license response');

            return;
        }
        $frame = self::decodeWith($raw, [$key, 0]);
        if ($frame === null || $frame->command() !== Protocol::CMD_LICENSE_SIGN) {
            self::log('bad license response frame');

            return;
        }

        $payload = $frame->payload;
        $payloadText = '';
        $i = 1;
        for (; $i < count($payload); $i++) {
            if ($payload[$i] === 0x3B) {
                break;
            }
            $payloadText .= chr($payload[$i]);
        }
        $signatureB64 = '';
        for ($j = $i + 1; $j < count($payload); $j++) {
            $signatureB64 .= chr($payload[$j]);
        }
        $signature = base64_decode($signatureB64, true);

        // iSGUI signs the prefix plus the act number, but sends only the
        // prefix in the payload. The central unit knows the act from its own
        // challenge and reconstructs the signed string.
        $signedText = $payloadText . $act;
        $expectedPayload = 'TDS15101:1/normal/881000415/0/';

        self::log('license payload = ' . $payloadText);
        self::log('license signed  = ' . $signedText);
        if ($signature === false || !RsaSigner::verify($signedText, $signature)) {
            self::log('signature verification FAILED');

            return;
        }
        if ($payloadText !== $expectedPayload) {
            self::log('unexpected license payload; expected ' . $expectedPayload);

            return;
        }

        self::sendFrame($client, [Protocol::CMD_LICENSE, Protocol::LIC_FULL], 0);
        self::log('handshake OK');

        $states = [
            '1:1:17' => [1],
            '1:2:10' => [50, 50, 0, 0],
            '1:19:1' => [2],
            '1:6:1' => [1, 1, 0, 50, 50, 0, 100, 0, 0],
            '1:217:2' => [255, 136, 0],
            '1:220:3' => [0x0A, 0x8C, 0x0B, 0xB8, 0x0A, 0x8C, 80],
            '1:20:1' => [0x0B, 0x74, 0x0B, 0x88, 0, 0, 0, 0, 0, 26, 95, 0, 1, 0, 0, 0, 0, 0, 0, 0],
            '1:31:1' => [38, 42, 0, 0, 0, 0],
        ];

        while (true) {
            $raw = self::readRaw($client, $buffer);
            if ($raw === null) {
                break;
            }
            $frame = self::decodeWith($raw, [$key, 0]);
            if ($frame === null) {
                self::log('could not decode frame');
                continue;
            }

            $command = $frame->command();
            self::log('RX ' . $frame->commandName() . ' ' . $frame->payloadHex());

            if ($command === Protocol::CMD_GET) {
                $cen = $frame->byte(1);
                $fnc = $frame->byte(2);
                $load = $frame->uint16(3);
                $data = $states[$cen . ':' . $fnc . ':' . $load] ?? [1];
                self::report($client, $cen, $fnc, $load, $data, $key);
                continue;
            }

            if ($command === Protocol::CMD_SET) {
                $cen = $frame->byte(1);
                $fnc = $frame->byte(2);
                $load = $frame->uint16(3);
                $action = $frame->byte(5);
                $data = array_slice($frame->payload, 6);
                $states[$cen . ':' . $fnc . ':' . $load] = match ($fnc) {
                    Protocol::FNC_RELAY, Protocol::FNC_FLAG, Protocol::FNC_LOCAL_MOOD,
                    Protocol::FNC_GENERAL_MOOD, Protocol::FNC_REGIME => [$action === 0 ? 0 : 1],
                    Protocol::FNC_DIMMER, Protocol::FNC_DIMMER_GROUP => [$action, $action, 0, 0],
                    Protocol::FNC_VENTILATION => [$action],
                    Protocol::FNC_MOTOR, Protocol::FNC_MOTOR_GROUP => [
                        $action === Protocol::SET_MTRUP ? 1 : 2,
                        $action === Protocol::SET_MTRSTOP ? 0 : 1,
                        0, 50, 50, 0, 100, 0, 0,
                    ],
                    Protocol::FNC_RGB, Protocol::FNC_RGB_GROUP => $data,
                    Protocol::FNC_TUNABLE_WHITE, Protocol::FNC_TUNABLE_WHITE_GROUP => [
                        0x0A, 0x8C, 0x0B, 0xB8,
                        $data[0] ?? 0x0A, $data[1] ?? 0x8C, $data[2] ?? 0,
                    ],
                    Protocol::FNC_AUDIO_ZONE => [$action, 42],
                    Protocol::FNC_SENSOR => [0x0B, 0x74, 0x0B, 0x88, 0, 0, 0, 0, 0, 26, 95, 0, 1, 0, 0, 0, 0, 0, 0, 0],
                    default => [1],
                };
                self::report($client, $cen, $fnc, $load, $states[$cen . ':' . $fnc . ':' . $load], $key);
                continue;
            }

            if ($command === Protocol::CMD_SUBSCRIBE) {
                $fnc = $frame->byte(1);
                $action = $frame->byte(2);
                if ($action === 255) {
                    foreach ($states as $address => $data) {
                        [$cen, $stateFnc, $load] = array_map('intval', explode(':', $address));
                        if ($stateFnc === $fnc) {
                            self::report($client, $cen, $stateFnc, $load, $data, $key);
                            break;
                        }
                    }
                }
                continue;
            }
        }

        self::log('client disconnected');
        @fclose($client);
    }

    private static function readRaw($client, string &$buffer, float $timeout = 10.0): ?string
    {
        $end = microtime(true) + $timeout;
        while (true) {
            $position = strpos($buffer, "\x02");
            if ($position !== false) {
                if ($position > 0) {
                    $buffer = substr($buffer, $position);
                }
                if (strlen($buffer) >= 2) {
                    $second = ord($buffer[1]);
                    $total = null;
                    if ($second === 0xFF) {
                        if (strlen($buffer) >= 4) {
                            $declared = (ord($buffer[2]) << 8) | ord($buffer[3]);
                            $total = $declared + 1;
                        }
                    } else {
                        $total = $second + 1;
                    }
                    if ($total !== null && strlen($buffer) >= $total) {
                        $frame = substr($buffer, 0, $total);
                        $buffer = substr($buffer, $total);

                        return $frame;
                    }
                }
            } else {
                $buffer = '';
            }

            $remaining = $end - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $read = [$client];
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            $micros = (int) (($remaining - $seconds) * 1_000_000);
            $selected = @stream_select($read, $write, $except, $seconds, $micros);
            if ($selected === false) {
                return null;
            }
            if ($selected === 0) {
                return null;
            }
            $chunk = @fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($client)) {
                    return null;
                }
                continue;
            }
            $buffer .= $chunk;
        }
    }

    private static function sendFrame($client, array $payload, int $xor): void
    {
        $raw = Protocol::encode($payload, $xor);
        self::log('TX ' . Protocol::commandName($payload[0] ?? -1) . ' ' . strtoupper(bin2hex($raw)));
        fwrite($client, $raw);
        fflush($client);
    }

    private static function decodeWith(string $raw, array $xors): ?Frame
    {
        foreach (array_unique($xors) as $xor) {
            $frame = Protocol::decode($raw, $xor);
            if ($frame->checksumValid) {
                return $frame;
            }
        }

        return null;
    }

    private static function report($client, int $cen, int $fnc, int $load, array $data, int $xor): void
    {
        $payload = array_merge(
            [Protocol::CMD_EXT_REPORT, $cen, $fnc],
            Protocol::uint16Bytes($load),
            [0],
            array_map(static fn ($b): int => ((int) $b) & 0xFF, $data)
        );
        self::sendFrame($client, $payload, $xor);
    }

    private static function log(string $message): void
    {
        if (self::$logger !== null) {
            (self::$logger)($message);
        }
    }
}
