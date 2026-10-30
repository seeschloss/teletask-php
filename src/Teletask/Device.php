<?php

declare(strict_types=1);

namespace Teletask;

/**
 * A controllable TELETASK function (address + type + optional metadata).
 */
final class Device
{
    public int $cen;
    public int $fnc;
    public int $load;
    public string $type;

    /** @var array<string, mixed> */
    public array $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(int $cen, int $fnc, int $load, array $meta = [])
    {
        $this->cen = $cen;
        $this->fnc = $fnc;
        $this->load = $load;
        $this->type = Protocol::functionName($fnc);
        $this->meta = $meta;
    }

    public function key(): string
    {
        return $this->cen . ':' . $this->fnc . ':' . $this->load;
    }

    public function typeName(): string
    {
        return $this->type;
    }

    /**
     * Canonical action names shown by the CLI and in JSON.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return match ($this->fnc) {
            Protocol::FNC_RELAY,
            Protocol::FNC_LOCAL_MOOD,
            Protocol::FNC_TIMED_LOCAL_MOOD,
            Protocol::FNC_GENERAL_MOOD,
            Protocol::FNC_FLAG => ['on', 'off', 'toggle'],

            Protocol::FNC_DIMMER,
            Protocol::FNC_DIMMER_GROUP => ['on', 'off', 'toggle', 'level'],

            Protocol::FNC_MOTOR,
            Protocol::FNC_MOTOR_GROUP => ['up', 'down', 'stop', 'startstop', 'upstop', 'downstop'],

            Protocol::FNC_VENTILATION => ['off', 'low', 'med', 'high', 'max', 'toggle', 'up', 'down'],

            Protocol::FNC_SENSOR => [
                'up', 'down', 'day', 'night', 'standby',
                'low', 'med', 'high', 'auto', 'mode', 'onoff',
            ],

            Protocol::FNC_AUDIO_ZONE => [
                'on', 'off', 'volumeup', 'volumedown', 'mute',
                'source1', 'source2', 'source3', 'source4',
                'source5', 'source6', 'source7', 'source8',
                'key0', 'key1', 'key2', 'key3', 'key4',
                'key5', 'key6', 'key7', 'key8', 'key9',
            ],

            Protocol::FNC_RGB,
            Protocol::FNC_RGB_GROUP => ['color'],

            Protocol::FNC_TUNABLE_WHITE,
            Protocol::FNC_TUNABLE_WHITE_GROUP => ['temperature'],

            Protocol::FNC_REGIME => ['on', 'off'],

            default => [],
        };
    }

    /**
     * Translate an action name/value into the byte sequence sent with CMD_SET.
     *
     * @param array<string, mixed> $options
     * @return array{action:int,data:list<int>}
     */
    public function resolveAction(string $action, ?int $value = null, array $options = []): array
    {
        $action = strtolower(trim($action));
        $numericAction = ctype_digit($action) ? (int) $action : null;

        // RGB/colour temperature are special: they always use a data payload.
        if ($this->isRgb()) {
            if (in_array($action, ['color', 'colour', 'rgb', 'set', 'level'], true) || isset($options['color'])) {
                $color = $this->parseColor((string) ($options['color'] ?? $action));
                if ($color === null && $value !== null) {
                    $color = $this->parseColor((string) $value);
                }
                if ($color === null) {
                    throw new TeletaskException('RGB action needs --color R,G,B or --color #RRGGBB');
                }

                return ['action' => Protocol::FNC_DIMMER, 'data' => $color];
            }
        }

        if ($this->isTunableWhite()) {
            if (in_array($action, ['on', 'off', 'toggle'], true) && !isset($options['temperature']) && !isset($options['brightness'])) {
                $brightness = match ($action) {
                    'on' => 100,
                    'off' => 0,
                    default => 50,
                };

                return [
                    'action' => Protocol::SET_TC,
                    'data' => [0x0A, 0x8C, $brightness],
                ];
            }

            if (in_array($action, ['temperature', 'temp', 'ct', 'color-temperature', 'colour-temperature', 'tunable-white', 'set'], true)
                || isset($options['temperature']) || isset($options['brightness'])) {
                $temperature = (int) ($options['temperature'] ?? 2700);
                $brightness = (int) ($options['brightness'] ?? 100);
                if ($temperature < 0 || $temperature > 65535) {
                    throw new TeletaskException('Colour temperature must be between 0 and 65535 Kelvin');
                }
                if ($brightness < 0 || $brightness > 100) {
                    throw new TeletaskException('Brightness must be between 0 and 100');
                }

                return [
                    'action' => Protocol::SET_TC,
                    'data' => [
                        ($temperature >> 8) & 0xFF,
                        $temperature & 0xFF,
                        $brightness & 0xFF,
                    ],
                ];
            }
        }

        $mapped = $this->mapNamedAction($action);
        if ($mapped !== null) {
            return ['action' => $mapped, 'data' => $this->rawData($options)];
        }

        if ($numericAction !== null) {
            // For dimmers/fans a bare number is a level/speed; elsewhere it is
            // the raw action byte.
            if ($this->isDimmer()) {
                return ['action' => $this->clamp($numericAction, 0, 100), 'data' => $this->rawData($options)];
            }
            if ($this->fnc === Protocol::FNC_VENTILATION && $numericAction >= 0 && $numericAction <= 4) {
                return ['action' => $numericAction, 'data' => $this->rawData($options)];
            }

            return ['action' => $numericAction & 0xFF, 'data' => $this->rawData($options)];
        }

        if ($action === 'level' || $action === 'dim' || $action === 'percentage') {
            if (!$this->isDimmer()) {
                throw new TeletaskException('Action "' . $action . '" is only valid for dimmers');
            }
            if ($value === null) {
                throw new TeletaskException('Action "' . $action . '" needs --value');
            }

            return ['action' => $this->clamp($value, 0, 100), 'data' => $this->rawData($options)];
        }

        if ($action === 'speed') {
            if ($this->fnc !== Protocol::FNC_VENTILATION) {
                throw new TeletaskException('Action "speed" is only valid for ventilation');
            }
            if ($value === null) {
                throw new TeletaskException('Action "speed" needs --value');
            }

            return ['action' => $this->clamp($value, 0, 4), 'data' => $this->rawData($options)];
        }

        if ($action === 'raw' || $action === 'custom') {
            if ($value === null) {
                throw new TeletaskException('Action "raw" needs a numeric --value action byte');
            }

            return ['action' => $value & 0xFF, 'data' => $this->rawData($options)];
        }

        throw new TeletaskException(
            'Unknown action "' . $action . '" for ' . $this->type,
            ['available_actions' => $this->actions()]
        );
    }

    private function mapNamedAction(string $action): ?int
    {
        $simple = [
            'on' => Protocol::SET_ON,
            'off' => Protocol::SET_OFF,
            'toggle' => Protocol::SET_TOGGLE,
        ];

        if ($this->isSimpleOnOff() || $this->isDimmer()) {
            if (isset($simple[$action])) {
                return $simple[$action];
            }
        }

        if ($this->fnc === Protocol::FNC_VENTILATION) {
            return match ($action) {
                'off' => Protocol::FAN_SPEED_OFF,
                'low' => Protocol::FAN_SPEED_LOW,
                'med', 'medium' => Protocol::FAN_SPEED_MED,
                'high' => Protocol::FAN_SPEED_HIGH,
                'max' => Protocol::FAN_SPEED_MAX,
                'toggle' => Protocol::FAN_TOGGLE_ACTION,
                'up' => Protocol::FAN_UP_ACTION,
                'down' => Protocol::FAN_DOWN_ACTION,
                default => null,
            };
        }

        if ($this->fnc === Protocol::FNC_MOTOR || $this->fnc === Protocol::FNC_MOTOR_GROUP) {
            return match ($action) {
                'up' => Protocol::SET_MTRUP,
                'down' => Protocol::SET_MTRDOWN,
                'stop' => Protocol::SET_MTRSTOP,
                'startstop', 'start-stop' => Protocol::MOTOR_STARTSTOP,
                'upstop', 'up-stop' => Protocol::MOTOR_UPSTOP,
                'downstop', 'down-stop' => Protocol::MOTOR_DOWNSTOP,
                default => null,
            };
        }

        if ($this->fnc === Protocol::FNC_SENSOR) {
            return match ($action) {
                'up' => Protocol::SET_TEMPUP,
                'down' => Protocol::SET_TEMPDOWN,
                'day' => Protocol::SET_TEMPDAY,
                'night' => Protocol::SET_TEMPNIGHT,
                'standby', 'stand-by' => Protocol::SET_TEMPSTANDBY,
                'mode' => Protocol::SET_TEMPMODE,
                'auto' => Protocol::SET_TEMPSPAUTO,
                'low' => Protocol::SET_TEMPSPLOW,
                'med', 'medium' => Protocol::SET_TEMPSPMED,
                'high' => Protocol::SET_TEMPSPHIGH,
                'onoff', 'on-off' => Protocol::SET_TEMPONOFF,
                'on' => Protocol::SET_ON,
                'off' => Protocol::SET_OFF,
                'toggle' => Protocol::SET_TOGGLE,
                default => null,
            };
        }

        if ($this->fnc === Protocol::FNC_AUDIO_ZONE) {
            if (preg_match('~^source([1-8])$~', $action, $m)) {
                return Protocol::SET_AUDIO_SOURCE[(int) $m[1]] ?? null;
            }
            if (preg_match('~^key([0-9])$~', $action, $m)) {
                return Protocol::SET_AUDIOKEY0 + (int) $m[1];
            }

            return match ($action) {
                'on' => Protocol::SET_AUDIOON,
                'off' => Protocol::SET_AUDIOOFF,
                'volumeup', 'vol-up', 'volup' => Protocol::SET_AUDIOUP,
                'volumedown', 'vol-down', 'voldown' => Protocol::SET_AUDIODOWN,
                'mute' => Protocol::SET_AUDIOMUTE,
                default => null,
            };
        }

        if ($this->fnc === Protocol::FNC_REGIME) {
            return $simple[$action] ?? null;
        }

        return null;
    }

    /**
     * Parse a CMD_EXT_REPORT data payload into a friendly state array.
     *
     * @param list<int> $data
     * @return array<string, mixed>
     */
    public function parseState(array $data): array
    {
        $state = [
            'type' => $this->type,
            'raw' => $data,
            'hex' => Protocol::bytesToHex($data),
        ];

        if ($this->isSimpleOnOff()) {
            $state['on'] = ($data[0] ?? 0) !== 0;
            $state['summary'] = $state['on'] ? 'on' : 'off';

            return $state;
        }

        if ($this->fnc === Protocol::FNC_REGIME) {
            $state['active_regime'] = $data[0] ?? null;
            $state['custom'] = ($data[1] ?? 0) !== 0;
            $state['active'] = ($state['active_regime'] === $this->load)
                || ($this->load === 5 && $state['custom']);
            $state['summary'] = $state['active'] ? 'active' : 'inactive';

            return $state;
        }

        if ($this->isDimmer()) {
            if (count($data) > 1) {
                $state['target'] = $data[0] ?? 0;
                $state['level'] = $data[1] ?? 0;
                $state['transition_ms'] = (($data[2] ?? 0) << 8) | ($data[3] ?? 0);
            } else {
                $state['level'] = $data[0] ?? 0;
                $state['target'] = $state['level'];
            }
            $state['on'] = $state['level'] > 0;
            $state['summary'] = $state['level'] . '%';

            return $state;
        }

        if ($this->fnc === Protocol::FNC_VENTILATION) {
            $speed = $data[0] ?? 0;
            $state['speed'] = $speed;
            $state['speed_name'] = match ($speed) {
                Protocol::FAN_SPEED_OFF => 'off',
                Protocol::FAN_SPEED_LOW => 'low',
                Protocol::FAN_SPEED_MED => 'med',
                Protocol::FAN_SPEED_HIGH => 'high',
                Protocol::FAN_SPEED_MAX => 'max',
                default => 'unknown(' . $speed . ')',
            };
            $state['summary'] = $state['speed_name'];

            return $state;
        }

        if ($this->fnc === Protocol::FNC_MOTOR || $this->fnc === Protocol::FNC_MOTOR_GROUP) {
            $state['direction'] = ($data[0] ?? 0) === Protocol::SET_MTRUP ? 'up' : 'down';
            $state['running'] = ($data[1] ?? 0) !== 0;
            $state['protection'] = $data[2] ?? null;
            if (count($data) >= 9) {
                $state['target_position'] = $data[3] ?? null;
                $state['position'] = $data[4] ?? null;
                $state['transition_ms'] = (($data[5] ?? 0) << 8) | ($data[6] ?? 0);
                $upPosition = (int) ($this->meta['upPosition'] ?? 0);
                $downPosition = (int) ($this->meta['downPosition'] ?? 100);
                if ($state['position'] === $upPosition) {
                    $state['position_text'] = (string) ($this->meta['zeroText'] ?? '0%');
                } elseif ($state['position'] === $downPosition) {
                    $state['position_text'] = (string) ($this->meta['hundredText'] ?? '100%');
                } else {
                    $state['position_text'] = $state['position'] . '%';
                }
            }
            $state['summary'] = $state['direction']
                . ($state['running'] ? ' (running)' : ' (stopped)')
                . (isset($state['position_text']) ? ', ' . $state['position_text'] : '');

            return $state;
        }

        if ($this->fnc === Protocol::FNC_SENSOR) {
            $value = (($data[0] ?? 0) << 8) | ($data[1] ?? 0);
            $target = (($data[2] ?? 0) << 8) | ($data[3] ?? 0);
            if (in_array($this->meta['type'] ?? -1, [8, 9], true) && empty($this->meta['showRealTime']) && count($data) >= 20) {
                $value = (($data[16] ?? 0) << 24) | (($data[17] ?? 0) << 16) | (($data[18] ?? 0) << 8) | ($data[19] ?? 0);
            }
            $state['value'] = $value;
            $state['target'] = $target;
            $state['preset'] = $data[9] ?? null;
            $state['mode'] = $data[10] ?? null;
            $state['active_speed'] = $data[11] ?? null;
            $state['enabled'] = ($data[12] ?? 0) !== 0;
            $state['protection'] = ($data[13] ?? 0) !== 0;
            $state['previous_action'] = $data[14] ?? null;
            $state['display'] = $this->formatSensorValue($value);
            $state['target_display'] = $this->formatSensorValue($target);
            $state['summary'] = $state['display'];

            return $state;
        }

        if ($this->isRgb()) {
            $state['red'] = $data[0] ?? 0;
            $state['green'] = $data[1] ?? 0;
            $state['blue'] = $data[2] ?? 0;
            $state['color_hex'] = sprintf('#%02X%02X%02X', $state['red'], $state['green'], $state['blue']);
            $state['summary'] = $state['color_hex'];

            return $state;
        }

        if ($this->isTunableWhite()) {
            $state['min_temperature'] = (($data[0] ?? 0) << 8) | ($data[1] ?? 0);
            $state['max_temperature'] = (($data[2] ?? 0) << 8) | ($data[3] ?? 0);
            $state['temperature'] = (($data[4] ?? 0) << 8) | ($data[5] ?? 0);
            $state['brightness'] = ($data[6] ?? 0) / 100;
            $state['summary'] = $state['temperature'] . 'K, ' . round($state['brightness'] * 100) . '%';

            return $state;
        }

        if ($this->fnc === Protocol::FNC_AUDIO_ZONE) {
            $source = $data[0] ?? 0;
            $state['source'] = $source;
            $state['source_name'] = Protocol::AUDIO_SOURCE_NAMES[$source] ?? 'source(' . $source . ')';
            $state['volume'] = $data[1] ?? null;
            $state['on'] = $source !== Protocol::SET_AUDIOOFF;
            $state['summary'] = $state['source_name']
                . (isset($state['volume']) && $state['volume'] >= 0 && $state['volume'] <= 100 ? ', ' . $state['volume'] . '%' : '');

            return $state;
        }

        $state['summary'] = Protocol::bytesToHex($data);

        return $state;
    }

    public function isSimpleOnOff(): bool
    {
        return in_array($this->fnc, [
            Protocol::FNC_RELAY,
            Protocol::FNC_LOCAL_MOOD,
            Protocol::FNC_TIMED_LOCAL_MOOD,
            Protocol::FNC_GENERAL_MOOD,
            Protocol::FNC_FLAG,
        ], true);
    }

    public function isDimmer(): bool
    {
        return $this->fnc === Protocol::FNC_DIMMER || $this->fnc === Protocol::FNC_DIMMER_GROUP;
    }

    public function isRgb(): bool
    {
        return $this->fnc === Protocol::FNC_RGB || $this->fnc === Protocol::FNC_RGB_GROUP;
    }

    public function isTunableWhite(): bool
    {
        return $this->fnc === Protocol::FNC_TUNABLE_WHITE
            || $this->fnc === Protocol::FNC_TUNABLE_WHITE_GROUP;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<int>
     */
    private function rawData(array $options): array
    {
        $raw = $options['data'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_map(static fn ($b): int => ((int) $b) & 0xFF, $raw));
        }

        $parts = preg_split('~[\s,]+~', trim((string) $raw)) ?: [];
        $bytes = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $bytes[] = str_starts_with(strtolower($part), '0x')
                ? hexdec(substr($part, 2)) & 0xFF
                : ((int) $part) & 0xFF;
        }

        return $bytes;
    }

    /**
     * @return list<int>|null
     */
    private function parseColor(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('~^#?([0-9a-f]{6})$~i', $value, $m)) {
            $hex = $m[1];

            return [
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2)),
            ];
        }

        if (preg_match('~^#?([0-9a-f]{3})$~i', $value, $m)) {
            $hex = $m[1];

            return [
                hexdec($hex[0] . $hex[0]),
                hexdec($hex[1] . $hex[1]),
                hexdec($hex[2] . $hex[2]),
            ];
        }

        $parts = preg_split('~[\s,]+~', $value) ?: [];
        if (count($parts) === 3 && ctype_digit($parts[0]) && ctype_digit($parts[1]) && ctype_digit($parts[2])) {
            $rgb = array_map('intval', $parts);
            if (min($rgb) >= 0 && max($rgb) <= 255) {
                return $rgb;
            }
        }

        return null;
    }

    private function formatSensorValue(int $value): string
    {
        $errors = [
            32767 => 'no data',
            32766 => 'no sensor',
            32765 => 'error',
            32764 => 'no power',
            32763 => 'waiting',
            32762 => 'no loop',
        ];
        if (isset($errors[$value])) {
            return $errors[$value];
        }

        $type = (int) ($this->meta['type'] ?? -1);
        $unit = (string) ($this->meta['unit'] ?? '');
        $decimals = (int) ($this->meta['dec'] ?? 0);

        switch ($type) {
            case 1: // temperature
            case 6: // air quality / temperature
            case 101: // IP sensor
                return number_format($value / 10 - 273, 1, '.', '') . '°C';

            case 2: // humidity
                return ($value <= 100 ? $value : 0) . '%';

            case 7: // analog
            case 102: // wind
                $min = (float) ($this->meta['min'] ?? 0);
                $max = (float) ($this->meta['max'] ?? 0);
                $signal = (int) ($this->meta['sign'] ?? 0);
                $scaled = match ($signal) {
                    0, 1 => ($max - $min) / 1023 * $value + $min,
                    2 => ($max - $min) / 880 * $value + $min,
                    3 => ($max - $min) / 704 * ($value - 176) + $min,
                    default => $value,
                };

                return number_format($scaled, $decimals, '.', '') . $unit;

            case 8: // pulse
            case 9: // power
                $pulses = (float) ($this->meta['pulsperunit'] ?? 1);
                if ($pulses == 0.0) {
                    $pulses = 1.0;
                }
                if (!empty($this->meta['showRealTime'])) {
                    $seconds = match ((int) ($this->meta['timebase'] ?? 1)) {
                        2 => 60,
                        3 => 3600,
                        4 => 86400,
                        default => 1,
                    };
                    $rt = (float) ($this->meta['rtunitsperunit'] ?? 1);
                    $scaled = $value * $rt * $seconds / (3600 * $pulses);

                    return number_format($scaled, $decimals, '.', '') . (string) ($this->meta['rtunit'] ?? '');
                }

                return number_format($value / $pulses, $decimals, '.', '') . $unit;

            default:
                // The iSGUI default branch treats all remaining sensor types
                // (darkness/light sensors and unknown types) as lux.
                $lux = $value <= 240 ? pow(10, $value / 40) - 1 : 0;

                return number_format($lux, 1, '.', '') . ' lux';
        }
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
