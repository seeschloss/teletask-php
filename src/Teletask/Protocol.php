<?php

declare(strict_types=1);

namespace Teletask;

/**
 * TDS frame and command constants.
 *
 * Everything in this class was derived from the iSGUI 2.6.57 ActionScript
 * (be.teletask.tdsport.tdsport.TdsPort / LocalPort and the device classes).
 */
final class Protocol
{
    // Commands (first payload byte).
    public const CMD_HANDSHAKE = 1;
    public const CMD_SUBSCRIBE = 3;
    public const CMD_GET = 6;
    public const CMD_SET = 7;
    public const CMD_KEEPALIVE = 11;
    public const CMD_LOG = 12;
    public const CMD_LICENSE_SIGN = 13;
    public const CMD_LICENSE = 14;
    public const CMD_READ = 15;
    public const CMD_EXT_REPORT = 16;
    public const CMD_SD = 17;
    public const CMD_UPGRADE = 18;
    public const CMD_CHART = 19;

    // License states returned in CMD_LICENSE.
    public const LIC_NOLIC = 0;
    public const LIC_TEST = 1;
    public const LIC_FULL = 2;
    public const LIC_SN_ERR = 3;

    // Generic on/off/toggle values.
    public const SET_OFF = 0;
    public const SET_ON = 255;
    public const SET_TOGGLE = 103;

    // Functions.
    public const FNC_NO = 0;
    public const FNC_RELAY = 1;
    public const FNC_DIMMER = 2;
    public const FNC_MOTOR = 6;
    public const FNC_LOCAL_MOOD = 8;
    public const FNC_TIMED_LOCAL_MOOD = 9;
    public const FNC_GENERAL_MOOD = 10;
    public const FNC_DIMMER_GROUP = 13;
    public const FNC_REGIME = 14;
    public const FNC_FLAG = 15;
    public const FNC_VENTILATION = 19;
    public const FNC_SENSOR = 20;
    public const FNC_AUDIO_ZONE = 31;
    public const FNC_MOTOR_GROUP = 40;
    public const FNC_RGB_GROUP = 41;
    public const FNC_TUNABLE_WHITE_GROUP = 42;
    public const FNC_AUDIO_SOURCE = 119;
    public const FNC_RGB = 217;
    public const FNC_VIDEO_DOORPHONE = 218;
    public const FNC_TUNABLE_WHITE = 220;
    public const FNC_GOTO_PAGE = 1002;
    public const FNC_CAMERA = 1003;

    // Sensor action values.
    public const SET_TEMPUP = 21;
    public const SET_TEMPDOWN = 22;
    public const SET_TEMPNIGHT = 25;
    public const SET_TEMPDAY = 26;
    public const SET_TEMPMODE = 30;
    public const SET_TEMPSPAUTO = 89;
    public const SET_TEMPSTANDBY = 93;
    public const SET_TEMPSPLOW = 97;
    public const SET_TEMPSPMED = 98;
    public const SET_TEMPSPHIGH = 99;
    public const SET_TEMPONOFF = 104;

    // Audio action values.
    public const SET_AUDIOUP = 32;
    public const SET_AUDIODOWN = 33;
    public const SET_AUDIOON = 36;
    public const SET_AUDIOOFF = 37;
    public const SET_AUDIOMUTE = 77;
    public const SET_AUDIOKEY0 = 78;
    public const SET_AUDIOKEY1 = 79;
    public const SET_AUDIOKEY2 = 80;
    public const SET_AUDIOKEY3 = 81;
    public const SET_AUDIOKEY4 = 82;
    public const SET_AUDIOKEY5 = 83;
    public const SET_AUDIOKEY6 = 84;
    public const SET_AUDIOKEY7 = 85;
    public const SET_AUDIOKEY8 = 86;
    public const SET_AUDIOKEY9 = 87;

    /** Source-select actions used by the iSGUI audio screen. */
    public const SET_AUDIO_SOURCE = [
        1 => 47,
        2 => 48,
        3 => 49,
        4 => 43,
        5 => 35,
        6 => 74,
        7 => 75,
        8 => 76,
    ];

    /** Source values reported back by the central unit. */
    public const AUDIO_SOURCE_NAMES = [
        38 => 'FM',
        39 => 'CD',
        40 => 'HDD',
        41 => 'MP3',
        42 => 'DVD',
        71 => 'VID',
        72 => 'SAT',
        73 => 'AUX',
        36 => 'ON',
        37 => 'OFF',
        // Some units report the source-select action value instead of the
        // source state value; map those too.
        47 => 'FM',
        48 => 'CD',
        49 => 'HDD',
        43 => 'MP3',
        35 => 'DVD',
        74 => 'VID',
        75 => 'SAT',
        76 => 'AUX',
    ];

    // Motor action values.
    public const SET_MTRUP = 1;
    public const SET_MTRDOWN = 2;
    public const SET_MTRSTOP = 3;
    public const MOTOR_STARTSTOP = 6;
    public const MOTOR_UPSTOP = 7;
    public const MOTOR_DOWNSTOP = 8;

    // Fan action values.
    public const FAN_SPEED_OFF = 0;
    public const FAN_SPEED_LOW = 1;
    public const FAN_SPEED_MED = 2;
    public const FAN_SPEED_HIGH = 3;
    public const FAN_SPEED_MAX = 4;
    public const FAN_TOGGLE_ACTION = 3;
    public const FAN_UP_ACTION = 10;
    public const FAN_DOWN_ACTION = 11;

    // Tunable-white / colour temperature action.
    public const SET_TC = 230;

    /**
     * Encode a payload into a TDS frame.
     *
     * @param list<int> $payload
     */
    public static function encode(array $payload, int $xor = 0): string
    {
        $xor &= 0xFF;
        $length = count($payload);

        if ($length >= 250) {
            $total = $length + 4;
            $out = [0x02, 0xFF, ($total >> 8) & 0xFF, $total & 0xFF];
            $sum = 2 + 0xFF + (($total >> 8) & 0xFF) + ($total & 0xFF);
        } else {
            $total = $length + 2;
            $out = [0x02, $total];
            $sum = 2 + $total;
        }

        foreach ($payload as $byte) {
            $byte &= 0xFF;
            $out[] = $byte ^ $xor;
            $sum += $byte;
        }

        $out[] = ($sum & 0xFF) ^ $xor;

        return pack('C*', ...$out);
    }

    /**
     * Decode one complete raw frame. The raw string must contain exactly one
     * frame (the reader is responsible for slicing it).
     */
    public static function decode(string $raw, int $xor = 0): Frame
    {
        $rawLength = strlen($raw);
        if ($rawLength < 3) {
            throw new TeletaskException('Frame is too short', ['length' => $rawLength]);
        }
        if (ord($raw[0]) !== 0x02) {
            throw new TeletaskException('Frame does not start with STX (0x02)', ['first' => ord($raw[0])]);
        }

        $second = ord($raw[1]);
        if ($second === 0xFF) {
            if ($rawLength < 5) {
                throw new TeletaskException('Extended frame header is incomplete');
            }
            $declared = (ord($raw[2]) << 8) | ord($raw[3]);
            $headerLength = 4;
        } else {
            $declared = $second;
            $headerLength = 2;
        }

        $total = $declared + 1;
        if ($declared < 3 || $total > $rawLength) {
            throw new TeletaskException('Frame length is invalid', [
                'declared' => $declared,
                'available' => $rawLength,
            ]);
        }

        $raw = substr($raw, 0, $total);
        $bytes = array_values(unpack('C*', $raw));
        $xor &= 0xFF;
        $payload = [];
        $sum = 0;

        for ($i = 0; $i < $total - 1; $i++) {
            $byte = $bytes[$i];
            if ($i >= $headerLength) {
                $byte ^= $xor;
                $payload[] = $byte;
            }
            $sum += $byte;
        }

        $checksum = $bytes[$total - 1] ^ $xor;
        $valid = (($sum & 0xFF) === $checksum);

        return new Frame($raw, $payload, $valid, $declared, $headerLength);
    }

    /**
     * Parse a CMD_EXT_REPORT payload.
     *
     * Payload layout:
     *   [0] 0x10
     *   [1] central number
     *   [2] function
     *   [3] load high
     *   [4] load low
     *   [5] marker/action byte (unused by iSGUI)
     *   [6..] data
     *
     * @return array{cen:int,fnc:int,load:int,marker:int|null,data:list<int>}
     */
    public static function parseReport(Frame $frame): array
    {
        $p = $frame->payload;

        return [
            'cen' => $p[1] ?? 0,
            'fnc' => $p[2] ?? 0,
            'load' => (($p[3] ?? 0) << 8) | ($p[4] ?? 0),
            'marker' => $p[5] ?? null,
            'data' => array_values(array_slice($p, 6)),
        ];
    }

    public static function commandName(int $command): string
    {
        return match ($command) {
            self::CMD_HANDSHAKE => 'HANDSHAKE',
            self::CMD_SUBSCRIBE => 'SUBSCRIBE',
            self::CMD_GET => 'GET',
            self::CMD_SET => 'SET',
            self::CMD_KEEPALIVE => 'KEEPALIVE',
            self::CMD_LOG => 'LOG',
            self::CMD_LICENSE_SIGN => 'LICENSE_SIGN',
            self::CMD_LICENSE => 'LICENSE',
            self::CMD_READ => 'READ',
            self::CMD_EXT_REPORT => 'EXT_REPORT',
            self::CMD_SD => 'SD',
            self::CMD_UPGRADE => 'UPGRADE',
            self::CMD_CHART => 'CHART',
            default => 'UNKNOWN(' . $command . ')',
        };
    }

    public static function functionName(int $fnc): string
    {
        return match ($fnc) {
            self::FNC_NO => 'none',
            self::FNC_RELAY => 'relay',
            self::FNC_DIMMER => 'dimmer',
            self::FNC_MOTOR => 'motor',
            self::FNC_LOCAL_MOOD => 'local mood',
            self::FNC_TIMED_LOCAL_MOOD => 'timed local mood',
            self::FNC_GENERAL_MOOD => 'general mood',
            self::FNC_DIMMER_GROUP => 'dimmer group',
            self::FNC_REGIME => 'regime',
            self::FNC_FLAG => 'flag',
            self::FNC_VENTILATION => 'ventilation',
            self::FNC_SENSOR => 'sensor',
            self::FNC_AUDIO_ZONE => 'audio zone',
            self::FNC_MOTOR_GROUP => 'motor group',
            self::FNC_RGB_GROUP => 'RGB group',
            self::FNC_TUNABLE_WHITE_GROUP => 'tunable-white group',
            self::FNC_AUDIO_SOURCE => 'audio source',
            self::FNC_RGB => 'RGB',
            self::FNC_VIDEO_DOORPHONE => 'video door phone',
            self::FNC_TUNABLE_WHITE => 'tunable white',
            self::FNC_GOTO_PAGE => 'go to page',
            self::FNC_CAMERA => 'camera',
            default => 'unknown(' . $fnc . ')',
        };
    }

    public static function iconCategory(int $icon): string
    {
        return match ($icon) {
            1 => 'lights',
            2 => 'devices',
            3 => 'moods',
            4 => 'motors',
            5 => 'sensors',
            6 => 'audio',
            7 => 'cameras',
            8 => 'regimes',
            101 => 'favorites',
            102 => 'all',
            default => 'other',
        };
    }

    public static function licenseStatusName(int $status): string
    {
        return match ($status) {
            self::LIC_NOLIC => 'no license',
            self::LIC_TEST => 'test license',
            self::LIC_FULL => 'full license',
            self::LIC_SN_ERR => 'serial number error',
            default => 'unknown(' . $status . ')',
        };
    }

    /**
     * @param list<int> $bytes
     */
    public static function bytesToHex(array $bytes): string
    {
        return strtoupper(implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $bytes)));
    }

    /**
     * @return list<int>
     */
    public static function stringToBytes(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(unpack('C*', $value));
    }

    /**
     * @return list<int>
     */
    public static function uint16Bytes(int $value): array
    {
        return [($value >> 8) & 0xFF, $value & 0xFF];
    }
}
