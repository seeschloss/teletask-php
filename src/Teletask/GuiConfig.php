<?php

declare(strict_types=1);

namespace Teletask;

/**
 * Parsed .gui configuration (rooms, items, devices and metadata).
 */
final class GuiConfig
{
    public string $name = '';
    public string $serverName = '';
    public int $portNr = 55956;
    public string $centrales = '';
    public bool $useLocationIp = false;
    public ?\DateTimeImmutable $creationDate = null;
    public string $prosoftVersion = '';
    public string $password = '0';
    public string $regimesPassword = '0';
    public bool $audioFeedback = false;
    public int $width = 1024;
    public int $height = 768;
    public string $sourceUrl = '';

    /** @var list<Room> */
    public array $rooms = [];

    /** @var array<string, Device> */
    public array $devices = [];

    /** @var array<int, array<string, mixed>> */
    public array $audioSources = [];

    /** @var array<string, array<string, mixed>> */
    public array $videoDoorPhones = [];

    /** @var array<int, array{name:string,hash:string}> */
    public array $groups = [];

    /** @var array<string, mixed> */
    public array $metadata = [];

    /**
     * Decode the .gui container: XOR every byte with 0xFF and strip the
     * three-byte trailer.
     */
    public static function decodeGui(string $bytes): string
    {
        $length = strlen($bytes);
        if ($length <= 3) {
            throw new TeletaskException('The .gui file is too short', ['length' => $length]);
        }

        $decoded = '';
        $payloadLength = $length - 3;
        for ($i = 0; $i < $payloadLength; $i++) {
            $decoded .= chr(ord($bytes[$i]) ^ 0xFF);
        }

        return $decoded;
    }

    public static function fromGuiBytes(string $bytes, string $sourceUrl = ''): self
    {
        $decoded = self::decodeGui($bytes);
        try {
            return self::fromXml($decoded, $sourceUrl);
        } catch (TeletaskException $e) {
            // Some installations serve a plain (non-XOR) .gui file. Try the
            // raw bytes (with and without the trailer) before giving up.
            foreach ([substr($bytes, 0, -3), $bytes] as $candidate) {
                if (str_contains($candidate, '<')) {
                    try {
                        return self::fromXml($candidate, $sourceUrl);
                    } catch (TeletaskException) {
                        // Try the next candidate.
                    }
                }
            }

            throw $e;
        }
    }

    public static function fromXml(string $xml, string $sourceUrl = ''): self
    {
        $xml = self::normaliseXml($xml);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($root === false) {
            $messages = array_map(static fn (\LibXMLError $e): string => trim($e->message), $errors);
            throw new TeletaskException('Could not parse the .gui XML: ' . implode('; ', $messages));
        }

        $config = new self();
        $config->sourceUrl = $sourceUrl;
        $config->name = self::attr($root, 'name');
        $config->serverName = self::attr($root, 'ipaddress');
        $config->portNr = (int) (self::attr($root, 'portnr') ?: 55956);
        $config->centrales = self::attr($root, 'centrales');
        $config->useLocationIp = self::boolAttr($root, 'useLocationIP');
        $config->prosoftVersion = self::attr($root, 'prosoftversion');
        $config->password = self::attr($root, 'password', '0');
        $config->regimesPassword = self::attr($root, 'regimespwd', '0');
        $config->audioFeedback = self::boolAttr($root, 'audiofb');
        $config->width = (int) (self::attr($root, 'width') ?: 1024);
        $config->height = (int) (self::attr($root, 'height') ?: 768);

        $year = (int) self::attr($root, 'jaar', '0');
        $month = (int) self::attr($root, 'maand', '0');
        $day = (int) self::attr($root, 'dag', '0');
        if ($year > 1970 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
            $config->creationDate = (new \DateTimeImmutable('now'))
                ->setDate($year, $month, $day)
                ->setTime(0, 0, 0);
        }

        foreach ($root->attributes() as $name => $value) {
            $config->metadata[(string) $name] = (string) $value;
        }

        $config->parseAudioSources($root);
        $config->parseVideoDoorPhones($root);
        $config->parsePages($root);
        $config->parseSensors($root);
        $config->parseVentilators($root);
        $config->parseMotors($root);
        $config->parseAudioZones($root);
        $config->parseGroups($root);
        $config->addSyntheticRegimes();

        return $config;
    }

    public function getEvaluation(): int
    {
        if ($this->creationDate === null) {
            return -1;
        }

        $difference = time() - $this->creationDate->getTimestamp();
        if ($difference < 0 || $difference >= 345600) {
            return -1;
        }
        if ($difference < 86400) {
            return 3;
        }
        if ($difference < 172800) {
            return 2;
        }
        if ($difference < 259200) {
            return 1;
        }

        return 0;
    }

    public function getOption(): string
    {
        return $this->getEvaluation() > 0 ? 'test' : 'normal';
    }

    public function controlHost(string $httpHost): string
    {
        if ($this->useLocationIp && $httpHost !== '') {
            return $httpHost;
        }

        return $this->serverName !== '' ? $this->serverName : $httpHost;
    }

    public function controlPort(int $httpPort): int
    {
        if ($this->useLocationIp && $httpPort > 3) {
            return $httpPort - 3;
        }

        return $this->portNr > 0 ? $this->portNr : 55956;
    }

    /**
     * @return list<Item>
     */
    public function allItems(): array
    {
        $items = [];
        foreach ($this->rooms as $room) {
            foreach ($room->items as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<int>
     */
    public function uniqueFunctions(): array
    {
        $functions = [];
        foreach ($this->allItems() as $item) {
            if ($item->isControllable() && !in_array($item->fnc, $functions, true)) {
                $functions[] = $item->fnc;
            }
        }

        return $functions;
    }

    public function findRoom(string $query): Room
    {
        $query = trim($query);
        if ($query === '') {
            throw new TeletaskException('Room name is empty');
        }

        if (ctype_digit($query)) {
            foreach ($this->rooms as $room) {
                if ($room->index === (int) $query) {
                    return $room;
                }
            }
        }

        $needle = Text::lower($query);
        $exact = [];
        $partial = [];
        foreach ($this->rooms as $room) {
            $name = Text::lower($room->name);
            if ($name === $needle) {
                $exact[] = $room;
            } elseif (str_contains($name, $needle)) {
                $partial[] = $room;
            }
        }

        $matches = $exact !== [] ? $exact : $partial;
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            throw new TeletaskException('Room not found: ' . $query, [
                'rooms' => array_map(static fn (Room $room): string => $room->name, $this->rooms),
            ]);
        }

        throw new TeletaskException('Room name is ambiguous: ' . $query, [
            'matches' => array_map(static fn (Room $room): string => $room->name, $matches),
        ]);
    }

    public function findItem(?string $roomQuery, string $deviceQuery): Item
    {
        $deviceQuery = trim($deviceQuery);
        if ($deviceQuery === '') {
            throw new TeletaskException('Device name is empty');
        }

        $rooms = $this->rooms;
        if ($roomQuery !== null && $roomQuery !== '') {
            $rooms = [$this->findRoom($roomQuery)];
        }

        $needle = Text::lower($deviceQuery);
        $exact = [];
        $partial = [];
        foreach ($rooms as $room) {
            foreach ($room->items as $item) {
                $name = Text::lower($item->name);
                if ($name === $needle) {
                    $exact[] = [$room, $item];
                } elseif (str_contains($name, $needle)) {
                    $partial[] = [$room, $item];
                }
            }
        }

        $matches = $exact !== [] ? $exact : $partial;
        if (count($matches) === 1) {
            return $matches[0][1];
        }
        if ($matches === []) {
            throw new TeletaskException('Device not found: ' . $deviceQuery);
        }

        throw new TeletaskException('Device name is ambiguous: ' . $deviceQuery, [
            'matches' => array_map(
                static fn (array $match): string => $match[0]->name . ' / ' . $match[1]->name,
                $matches
            ),
        ]);
    }

    public function findItemByAddress(int $cen, int $fnc, int $load): ?Item
    {
        foreach ($this->allItems() as $item) {
            if ($item->cen === $cen && $item->fnc === $fnc && $item->load === $load) {
                return $item;
            }
        }

        return null;
    }

    public function findRoomForItem(Item $needle): ?Room
    {
        foreach ($this->rooms as $room) {
            foreach ($room->items as $item) {
                if ($item === $needle) {
                    return $room;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeItems = true): array
    {
        $result = [
            'name' => $this->name,
            'source_url' => $this->sourceUrl,
            'server' => $this->serverName,
            'port' => $this->portNr,
            'centrales' => $this->centrales,
            'use_location_ip' => $this->useLocationIp,
            'prosoft_version' => $this->prosoftVersion,
            'created' => $this->creationDate?->format('Y-m-d'),
            'evaluation' => $this->getEvaluation(),
            'option' => $this->getOption(),
            'room_count' => count($this->rooms),
            'item_count' => count($this->allItems()),
        ];

        if ($includeItems) {
            $result['rooms'] = array_map(static fn (Room $room): array => $room->toArray(), $this->rooms);
        }

        return $result;
    }

    /**
     * iSGUI adds these six regime buttons to every room page. They are not
     * present in the .gui file; they are generated by the app itself.
     */
    private function addSyntheticRegimes(): void
    {
        if ($this->rooms === []) {
            return;
        }

        $regimes = [
            0 => 'AUTO',
            1 => 'WORKDAY',
            2 => 'WEEKEND',
            3 => 'SIMULATION',
            4 => 'NONE',
            5 => 'CUSTOM',
        ];
        $protect = (int) $this->regimesPassword;

        foreach ($this->rooms as $room) {
            foreach ($regimes as $load => $name) {
                $device = $this->deviceFor(0, Protocol::FNC_REGIME, $load);
                $room->items[] = new Item(
                    $name,
                    0,
                    Protocol::FNC_REGIME,
                    $load,
                    8,
                    false,
                    $protect,
                    $device,
                    ['synthetic' => true]
                );
            }
        }
    }

    private function parsePages(\SimpleXMLElement $root): void
    {
        $pages = isset($root->PAGES->PAGE)
            ? $root->PAGES->PAGE
            : (isset($root->PAGE) ? $root->PAGE : null);
        if ($pages === null) {
            return;
        }

        foreach ($pages as $pageNode) {
            $room = new Room(
                (int) self::attr($pageNode, 'index', '0'),
                self::attr($pageNode, 'naam', self::attr($pageNode, 'name', 'Room')),
                self::attr($pageNode, 'src'),
                (int) self::attr($pageNode, 'roomsection', '0'),
                self::boolAttr($pageNode, 'visible', true),
                [
                    'red' => (int) self::attr($pageNode, 'red', '255'),
                    'green' => (int) self::attr($pageNode, 'green', '255'),
                    'blue' => (int) self::attr($pageNode, 'blue', '255'),
                ]
            );

            $items = isset($pageNode->ITEMS->ITEM)
                ? $pageNode->ITEMS->ITEM
                : (isset($pageNode->ITEM) ? $pageNode->ITEM : null);
            if ($items !== null) {
                foreach ($items as $itemNode) {
                    $room->items[] = $this->parseItem($itemNode);
                }
            }

            $urls = isset($pageNode->URLS->URL)
                ? $pageNode->URLS->URL
                : (isset($pageNode->URL) ? $pageNode->URL : null);
            if ($urls !== null) {
                foreach ($urls as $urlNode) {
                    $room->items[] = $this->parseUrlItem($urlNode, count($room->items) + 1);
                }
            }

            $this->rooms[] = $room;
        }
    }

    private function parseItem(\SimpleXMLElement $node): Item
    {
        $cen = (int) self::attr($node, 'cen', '0');
        $fnc = (int) self::attr($node, 'fnc', '0');
        $load = (int) self::attr($node, 'ld', '0');
        if ($fnc === Protocol::FNC_MOTOR && $load > 65536) {
            $load &= 65535;
        }

        $icon = (int) self::attr($node, 'icon', '0');
        $name = self::attr($node, 'name', '');
        $readonly = self::boolAttr($node, 'readonly');
        $pwdProtect = (int) self::attr($node, 'pwdprotect', '0');

        $device = $this->deviceFor($cen, $fnc, $load);
        $extra = [
            'visible' => self::boolAttr($node, 'visible', true),
            'act' => (int) self::attr($node, 'act', '0'),
            'lvl' => (int) self::attr($node, 'lvl', '0'),
            'opt' => (int) self::attr($node, 'opt', '0'),
            'category' => (int) self::attr($node, 'category', '0'),
            'pic' => self::attr($node, 'pic', ''),
        ];

        return new Item($name, $cen, $fnc, $load, $icon, $readonly, $pwdProtect, $device, $extra);
    }

    private function parseUrlItem(\SimpleXMLElement $node, int $fallbackLoad): Item
    {
        $cen = (int) self::attr($node, 'cen', '0');
        $fnc = (int) self::attr($node, 'fnc', (string) Protocol::FNC_VIDEO_DOORPHONE);
        $load = (int) self::attr($node, 'ld', (string) $fallbackLoad);
        $icon = (int) self::attr($node, 'icon', '7');
        $name = self::attr($node, 'name', '');
        $target = self::attr($node, 'target', '');
        $pwdProtect = (int) self::attr($node, 'pwdprotect', '0');

        $device = $this->deviceFor($cen, Protocol::FNC_VIDEO_DOORPHONE, $load);
        $device->meta['url'] = $target;
        $device->meta['name'] = $name;

        return new Item($name, $cen, Protocol::FNC_VIDEO_DOORPHONE, $load, $icon, false, $pwdProtect, $device, [
            'url' => $target,
        ]);
    }

    private function parseSensors(\SimpleXMLElement $root): void
    {
        $sensors = isset($root->SENSORSZONES->SENSORZONE) ? $root->SENSORSZONES->SENSORZONE : null;
        if ($sensors === null) {
            return;
        }

        foreach ($sensors as $node) {
            $cen = (int) self::attr($node, 'cen', '0');
            $load = (int) self::attr($node, 'ld', '0');
            $device = $this->deviceFor($cen, Protocol::FNC_SENSOR, $load);
            $device->meta = array_merge($device->meta, [
                'name' => self::attr($node, 'name', ''),
                'type' => (int) self::attr($node, 'type', '0'),
                'speedcount' => (int) self::attr($node, 'speedcount', '0'),
                'iscontrolled' => self::boolAttr($node, 'iscontrolled'),
                'nomeasurement' => self::boolAttr($node, 'nomeasurement'),
                'fnc0' => (int) self::attr($node, 'fnc0', '0'),
                'fnc1' => (int) self::attr($node, 'fnc1', '0'),
                'ld10' => (int) self::attr($node, 'ld10', '0'),
                'ld11' => (int) self::attr($node, 'ld11', '0'),
                'ld20' => (int) self::attr($node, 'ld20', '0'),
                'ld21' => (int) self::attr($node, 'ld21', '0'),
                'ld30' => (int) self::attr($node, 'ld30', '0'),
                'ld31' => (int) self::attr($node, 'ld31', '0'),
                'min' => self::floatAttr($node, 'min', 0.0),
                'max' => self::floatAttr($node, 'max', 0.0),
                'unit' => self::attr($node, 'unit', ''),
                'dec' => (int) self::attr($node, 'dec', '0'),
                'sign' => (int) self::attr($node, 'sign', '0'),
                'pulsperunit' => self::floatAttr($node, 'pulsperunit', 1.0),
                'rtunit' => self::attr($node, 'rtunit', ''),
                'showRealTime' => self::boolAttr($node, 'showRealTime'),
                'rtunitsperunit' => self::floatAttr($node, 'rtunitsperunit', 1.0),
                'timebase' => (int) self::attr($node, 'timebase', '1'),
            ]);
        }
    }

    private function parseVentilators(\SimpleXMLElement $root): void
    {
        $ventilators = isset($root->VENTILATIONFUNCTIONS->VENTILATIONFUNCTION) ? $root->VENTILATIONFUNCTIONS->VENTILATIONFUNCTION : null;
        if ($ventilators === null) {
            return;
        }

        foreach ($ventilators as $node) {
            $cen = (int) self::attr($node, 'cen', '0');
            $load = (int) self::attr($node, 'ld', '0');
            $device = $this->deviceFor($cen, Protocol::FNC_VENTILATION, $load);
            $device->meta = array_merge($device->meta, [
                'name' => self::attr($node, 'name', ''),
                'speedmode' => (int) self::attr($node, 'speedmode', '0'),
            ]);
        }
    }

    private function parseMotors(\SimpleXMLElement $root): void
    {
        $motors = isset($root->MOTORS->MOTOR) ? $root->MOTORS->MOTOR : null;
        if ($motors === null) {
            return;
        }

        foreach ($motors as $node) {
            $cen = (int) self::attr($node, 'cen', '0');
            $load = (int) self::attr($node, 'ld', '0');
            $device = $this->deviceFor($cen, Protocol::FNC_MOTOR, $load);
            $device->meta = array_merge($device->meta, [
                'zeroText' => self::attr($node, 'zeroText', '0%'),
                'hundredText' => self::attr($node, 'hundredText', '100%'),
                'zeroArrow' => (int) self::attr($node, 'zeroArrow', '0'),
                'hundredArrow' => (int) self::attr($node, 'hundredArrow', '0'),
                'upPosition' => (int) self::attr($node, 'upPosition', '0'),
                'downPosition' => (int) self::attr($node, 'downPosition', '100'),
            ]);
        }
    }

    private function parseAudioSources(\SimpleXMLElement $root): void
    {
        $sources = isset($root->AUDIOSOURCES->AUDIOSOURCE) ? $root->AUDIOSOURCES->AUDIOSOURCE : null;
        if ($sources === null) {
            return;
        }

        foreach ($sources as $node) {
            $nr = (int) self::attr($node, 'nr', '0');
            $source = [
                'nr' => $nr,
                'name' => self::attr($node, 'name', ''),
                'presets' => [],
                'extras' => [],
            ];
            for ($i = 0; $i <= 9; $i++) {
                $source['presets'][$i] = self::attr($node, 'preset' . $i, (string) $i);
            }
            for ($i = 18; $i <= 27; $i++) {
                $value = self::attr($node, 'extra' . $i, '');
                if ($value !== '') {
                    $source['extras'][$i] = $value;
                }
            }
            $this->audioSources[$nr] = $source;
        }
    }

    private function parseAudioZones(\SimpleXMLElement $root): void
    {
        $zones = isset($root->AUDIOZONES->AUDIOZONE) ? $root->AUDIOZONES->AUDIOZONE : null;
        if ($zones === null) {
            return;
        }

        foreach ($zones as $node) {
            $cen = (int) self::attr($node, 'cen', '0');
            $load = (int) self::attr($node, 'ld', '0');
            $device = $this->deviceFor($cen, Protocol::FNC_AUDIO_ZONE, $load);

            $inputs = [];
            $sourceNames = [];
            for ($i = 0; $i < 8; $i++) {
                $inputs[$i] = self::attr($node, 'input' . $i, '');
                $sourceNr = (int) self::attr($node, 'source' . $i, '0');
                $sourceNames[$i] = $this->audioSources[$sourceNr]['name'] ?? '';
            }

            $device->meta = array_merge($device->meta, [
                'name' => self::attr($node, 'name', ''),
                'inputs' => $inputs,
                'source_names' => $sourceNames,
            ]);
        }
    }

    private function parseVideoDoorPhones(\SimpleXMLElement $root): void
    {
        $phones = isset($root->VIDEO_DOOR_PHONES->VIDEO_DOOR_PHONE) ? $root->VIDEO_DOOR_PHONES->VIDEO_DOOR_PHONE : null;
        if ($phones === null) {
            return;
        }

        foreach ($phones as $node) {
            $key = (int) self::attr($node, 'key', '0');
            $this->videoDoorPhones[(string) $key] = [
                'key' => $key,
                'url' => self::attr($node, 'url', ''),
                'name' => self::attr($node, 'name', ''),
                'username' => self::attr($node, 'username', ''),
                'password' => self::attr($node, 'password', ''),
                'videocodec' => self::attr($node, 'videocodec', '0'),
                'cloudport' => (int) self::attr($node, 'cloudport', '80'),
            ];
        }
    }

    private function parseGroups(\SimpleXMLElement $root): void
    {
        $groups = isset($root->GROUPS->GROUP) ? $root->GROUPS->GROUP : null;
        if ($groups === null) {
            return;
        }

        foreach ($groups as $node) {
            $id = (int) self::attr($node, 'id', '0');
            $this->groups[$id] = [
                'name' => self::attr($node, 'name', ''),
                'hash' => self::attr($node, 'hash', ''),
            ];
        }
    }

    private function deviceFor(int $cen, int $fnc, int $load): Device
    {
        $key = $cen . ':' . $fnc . ':' . $load;
        if (!isset($this->devices[$key])) {
            $this->devices[$key] = new Device($cen, $fnc, $load);
        }

        return $this->devices[$key];
    }

    private static function normaliseXml(string $xml): string
    {
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }

        $looksUtf16 = str_starts_with($xml, "\xFF\xFE")
            || str_starts_with($xml, "\xFE\xFF")
            || str_contains(substr($xml, 0, 200), "\x00");
        if ($looksUtf16) {
            $converted = Text::utf16ToUtf8($xml);
            if ($converted !== null) {
                $xml = preg_replace('~encoding\s*=\s*"[^"]*"~i', 'encoding="UTF-8"', $converted, 1) ?? $converted;
            }
        }

        return $xml;
    }

    private static function attr(\SimpleXMLElement $node, string $name, string $default = ''): string
    {
        if (!isset($node[$name])) {
            return $default;
        }

        return (string) $node[$name];
    }

    private static function floatAttr(\SimpleXMLElement $node, string $name, float $default): float
    {
        $value = self::attr($node, $name, '');
        if ($value === '') {
            return $default;
        }

        return (float) str_replace(',', '.', $value);
    }

    private static function boolAttr(\SimpleXMLElement $node, string $name, bool $default = false): bool
    {
        if (!isset($node[$name])) {
            return $default;
        }

        return strtolower((string) $node[$name]) === 'true';
    }
}
