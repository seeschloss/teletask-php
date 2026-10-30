<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/MockCentral.php';

use Teletask\ConfigRepository;
use Teletask\Device;
use Teletask\GuiConfig;
use Teletask\LocalConnection;
use Teletask\Protocol;
use Teletask\RsaSigner;

$passed = 0;
$failed = 0;
$skipped = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "PASS  $name\n");
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDOUT, "FAIL  $name\n      " . $e->getMessage() . "\n");
    }
}

function skip(string $name, string $reason): void
{
    global $skipped;
    $skipped++;
    fwrite(STDOUT, "SKIP  $name ($reason)\n");
}

function assertTrue(bool $value, string $message = 'expected true'): void
{
    if (!$value) {
        throw new \RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException(($message !== '' ? $message . ': ' : '') . "missing '$needle' in '$haystack'");
    }
}

function syntheticXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<HUIS name="Test House" ipaddress="127.0.0.1" portnr="55956" centrales="/881000415" useLocationIP="False" jaar="2020" maand="1" dag="1" prosoftversion="TEST">
  <PAGES>
    <PAGE index="1" naam="Kitchen" roomsection="1" red="255" green="255" blue="255" visible="True">
      <ITEMS>
        <ITEM cen="1" fnc="1" ld="17" icon="2" name="Dishwasher" readonly="False" pwdprotect="0" visible="True" />
        <ITEM cen="1" fnc="2" ld="10" icon="1" name="Cooking island" readonly="False" pwdprotect="0" visible="True" />
        <ITEM cen="1" fnc="217" ld="2" icon="1" name="RGB" readonly="False" pwdprotect="0" visible="True" />
        <ITEM cen="1" fnc="220" ld="3" icon="1" name="Wall" readonly="False" pwdprotect="0" visible="True" />
        <ITEM cen="1" fnc="6" ld="1" icon="4" name="Blinds" readonly="False" pwdprotect="0" visible="True" />
        <ITEM cen="1" fnc="19" ld="1" icon="5" name="Fan" readonly="False" pwdprotect="0" visible="True" />
        <ITEM cen="1" fnc="20" ld="1" icon="5" name="Temperature" readonly="False" pwdprotect="0" visible="True" />
        <ITEM cen="1" fnc="31" ld="1" icon="6" name="Audio" readonly="False" pwdprotect="0" visible="True" />
        <ITEM cen="0" fnc="14" ld="0" icon="8" name="Regime auto" readonly="False" pwdprotect="0" visible="True" />
      </ITEMS>
    </PAGE>
    <PAGE index="2" naam="Living" roomsection="1" red="255" green="255" blue="255" visible="True">
      <ITEMS>
        <ITEM cen="0" fnc="8" ld="2" icon="3" name="Watch TV" readonly="False" pwdprotect="0" visible="True" />
      </ITEMS>
    </PAGE>
  </PAGES>
  <SENSORSZONES>
    <SENSORZONE cen="1" ld="1" name="Temperature" type="1" speedcount="0" iscontrolled="False" nomeasurement="False" />
  </SENSORSZONES>
  <VENTILATIONFUNCTIONS>
    <VENTILATIONFUNCTION cen="1" ld="1" name="Fan" speedmode="3" />
  </VENTILATIONFUNCTIONS>
  <MOTORS>
    <MOTOR cen="1" ld="1" zeroText="OPEN" hundredText="CLOSED" />
  </MOTORS>
  <AUDIOSOURCES>
    <AUDIOSOURCE nr="1" name="Tuner" />
  </AUDIOSOURCES>
  <AUDIOZONES>
    <AUDIOZONE cen="1" ld="1" name="Audio" input0="Tuner" source0="1" />
  </AUDIOZONES>
</HUIS>
XML;
}

function encodeGui(string $xml): string
{
    $encoded = '';
    for ($i = 0; $i < strlen($xml); $i++) {
        $encoded .= chr(ord($xml[$i]) ^ 0xFF);
    }

    return $encoded . "\x00\x00\x00";
}

// ---------------------------------------------------------------------------
// Protocol
// ---------------------------------------------------------------------------

test('protocol: decodes the real demo challenge', function (): void {
    $raw = hex2bin('02070d000c2e62b2');
    $frame = Protocol::decode($raw, 0);
    assertSame(Protocol::CMD_LICENSE_SIGN, $frame->command());
    assertSame([13, 0, 12, 46, 98], $frame->payload);
    assertTrue($frame->checksumValid);
    assertSame(0x000C2E62, $frame->uint32(1));
});

test('protocol: encodes the demo challenge byte-for-byte', function (): void {
    $raw = Protocol::encode([13, 0, 12, 46, 98], 0);
    assertSame('02070d000c2e62b2', bin2hex($raw));
});

test('protocol: XOR round-trip', function (): void {
    $payload = [6, 1, 2, 0, 10];
    $raw = Protocol::encode($payload, 7);
    $frame = Protocol::decode($raw, 7);
    assertSame($payload, $frame->payload);
    assertTrue($frame->checksumValid);
    $wrong = Protocol::decode($raw, 0);
    assertTrue(!$wrong->checksumValid, 'wrong XOR should fail the checksum');
});

test('protocol: extended frame round-trip', function (): void {
    $payload = array_fill(0, 300, 7);
    $raw = Protocol::encode($payload, 9);
    assertSame(0x02, ord($raw[0]));
    assertSame(0xFF, ord($raw[1]));
    assertSame(304, (ord($raw[2]) << 8) | ord($raw[3]));
    $frame = Protocol::decode($raw, 9);
    assertSame($payload, $frame->payload);
    assertTrue($frame->checksumValid);
});

test('protocol: parses EXT_REPORT offsets', function (): void {
    $raw = Protocol::encode([16, 1, 2, 0, 10, 0, 50, 50, 0, 0], 0);
    $frame = Protocol::decode($raw, 0);
    $report = Protocol::parseReport($frame);
    assertSame(1, $report['cen']);
    assertSame(2, $report['fnc']);
    assertSame(10, $report['load']);
    assertSame([50, 50, 0, 0], $report['data']);
});

// ---------------------------------------------------------------------------
// RSA signing
// ---------------------------------------------------------------------------

test('rsa: known SHA1/type-1 signature vector', function (): void {
    $data = 'TDS15101:1/normal/881000415/0/954373';
    $expected = '3bcb285f6262aa395430365f165bd99c29267d9eb9960744e1e8d1ada2512b28ab285789eb27e40f7a1e1e487f876228aa2e66124e2baf4fe1f8801b1e44ed9777a4871f142f0e6911e410eee58843f7d495f57fb3674c6d3299845012d6c4494cc421e4ff42d4e3779d6878cc2fa7fbcd144c56a971d24d25ef9c2e602502f3';
    $signature = RsaSigner::sign($data);
    assertSame($expected, bin2hex($signature));
    assertTrue(RsaSigner::verify($data, $signature));
    assertTrue(!RsaSigner::verify($data . 'x', $signature));
});

test('license: payload omits the act but the signature covers it', function (): void {
    $strings = LocalConnection::licenseStrings('normal', '/813103795', 0, 982988);
    assertSame('TDS15101:1/normal/813103795/0/982988', $strings['signed']);
    assertSame('TDS15101:1/normal/813103795/0/', $strings['payload']);

    // Signature captured from the working old-phone app.
    $captured = '2ecc7465d029cfe78c75093b069a8c490a760829f9bbde71bc8ada3ccf5f0332'
        . 'bb801916d1d15f6f7d01f5ef646d38f5e279131caf996c367f7be2995fad2864'
        . 'bcc2eef080db9563fbb9119ca251c6b26f42689690b53f5c373a8453da06a0b8'
        . '89b9740667e745f6e63bb9d3bb15b565abad5aed92b8e02de0b184bb9992b1cd';
    assertSame($captured, bin2hex(RsaSigner::sign($strings['signed'])));
});

test('license: act is rendered as a signed 32-bit integer', function (): void {
    $strings = LocalConnection::licenseStrings('normal', '/123', 0, 0x80000001);
    assertSame('TDS15101:1/normal/123/0/-2147483647', $strings['signed']);
    assertSame('TDS15101:1/normal/123/0/', $strings['payload']);
});

// ---------------------------------------------------------------------------
// Configuration parsing
// ---------------------------------------------------------------------------

test('config: decodes and parses a synthetic .gui', function (): void {
    $config = GuiConfig::fromGuiBytes(encodeGui(syntheticXml()), 'test://gui');
    assertSame('Test House', $config->name);
    assertSame('127.0.0.1', $config->serverName);
    assertSame(55956, $config->portNr);
    assertSame('/881000415', $config->centrales);
    assertSame(2, count($config->rooms));
    assertSame('Kitchen', $config->rooms[0]->name);
    $nonSynthetic = array_filter(
        $config->rooms[0]->items,
        static fn ($item): bool => empty($item->extra['synthetic'])
    );
    assertSame(9, count($nonSynthetic));
    assertSame(6, count($config->rooms[0]->items) - count($nonSynthetic));
    assertSame('Cooking island', $config->findItem('Kitchen', 'Cooking island')->name);
    assertSame(10, $config->findItem('Kitchen', 'Cooking island')->load);
    assertSame('normal', $config->getOption());
});

test('config: parses the TT configuration index', function (): void {
    $repository = new ConfigRepository('127.0.0.1:1', ['cache_dir' => sys_get_temp_dir() . '/teletask-test-' . getmypid()]);
    $entries = $repository->parseConfigList("TT\nMobile Demo;1787934463216;1281\nTT\n", 'test://index');
    assertSame(1, count($entries));
    assertSame('Mobile Demo', $entries[0]['name']);
    assertSame('Mobile Demo', $entries[0]['central_file']);
    assertSame('1281', $entries[0]['key']);
});

// ---------------------------------------------------------------------------
// Device actions
// ---------------------------------------------------------------------------

test('device: relay actions', function (): void {
    $device = new Device(1, Protocol::FNC_RELAY, 17);
    assertSame(['on', 'off', 'toggle'], $device->actions());
    assertSame(['action' => 255, 'data' => []], $device->resolveAction('on'));
    assertSame(['action' => 0, 'data' => []], $device->resolveAction('off'));
    assertSame(['action' => 103, 'data' => []], $device->resolveAction('toggle'));
});

test('device: dimmer and fan actions', function (): void {
    $dimmer = new Device(1, Protocol::FNC_DIMMER, 10);
    assertSame(['action' => 40, 'data' => []], $dimmer->resolveAction('level', 40));
    assertSame(['action' => 255, 'data' => []], $dimmer->resolveAction('on'));

    $fan = new Device(1, Protocol::FNC_VENTILATION, 1);
    assertSame(['action' => 2, 'data' => []], $fan->resolveAction('med'));
    assertSame(['action' => 3, 'data' => []], $fan->resolveAction('toggle'));
    assertSame(['action' => 10, 'data' => []], $fan->resolveAction('up'));
});

test('device: motor, sensor, audio and regime actions', function (): void {
    $motor = new Device(1, Protocol::FNC_MOTOR, 1);
    assertSame(['action' => 1, 'data' => []], $motor->resolveAction('up'));
    assertSame(['action' => 3, 'data' => []], $motor->resolveAction('stop'));

    $sensor = new Device(1, Protocol::FNC_SENSOR, 1);
    assertSame(['action' => 21, 'data' => []], $sensor->resolveAction('up'));
    assertSame(['action' => 97, 'data' => []], $sensor->resolveAction('low'));

    $audio = new Device(1, Protocol::FNC_AUDIO_ZONE, 1);
    assertSame(['action' => 47, 'data' => []], $audio->resolveAction('source1'));
    assertSame(['action' => 87, 'data' => []], $audio->resolveAction('key9'));
    assertSame(['action' => 32, 'data' => []], $audio->resolveAction('volumeup'));

    $regime = new Device(0, Protocol::FNC_REGIME, 0);
    assertSame(['action' => 255, 'data' => []], $regime->resolveAction('on'));
});

test('device: RGB and tunable-white payloads', function (): void {
    $rgb = new Device(1, Protocol::FNC_RGB, 2);
    assertSame(
        ['action' => 2, 'data' => [255, 136, 0]],
        $rgb->resolveAction('color', null, ['color' => '#ff8800'])
    );
    assertSame(
        ['action' => 2, 'data' => [1, 2, 3]],
        $rgb->resolveAction('color', null, ['color' => '1,2,3'])
    );

    $ct = new Device(1, Protocol::FNC_TUNABLE_WHITE, 3);
    assertSame(
        ['action' => 230, 'data' => [0x0A, 0x8C, 80]],
        $ct->resolveAction('temperature', null, ['temperature' => 2700, 'brightness' => 80])
    );
});

// ---------------------------------------------------------------------------
// Device state parsing
// ---------------------------------------------------------------------------

test('state: relay, dimmer, fan and motor', function (): void {
    $relay = new Device(1, Protocol::FNC_RELAY, 17);
    $state = $relay->parseState([1]);
    assertSame(true, $state['on']);
    assertSame('on', $state['summary']);

    $dimmer = new Device(1, Protocol::FNC_DIMMER, 10);
    $state = $dimmer->parseState([50, 50, 0, 0]);
    assertSame(50, $state['level']);
    assertSame(50, $state['target']);
    assertSame('50%', $state['summary']);

    $fan = new Device(1, Protocol::FNC_VENTILATION, 1);
    $state = $fan->parseState([2]);
    assertSame(2, $state['speed']);
    assertSame('med', $state['speed_name']);

    $motor = new Device(1, Protocol::FNC_MOTOR, 1);
    $state = $motor->parseState([1, 1, 0, 50, 50, 0, 100, 0, 0]);
    assertSame('up', $state['direction']);
    assertSame(true, $state['running']);
    assertSame(50, $state['position']);
});

test('state: sensor, RGB, CT and audio', function (): void {
    $sensor = new Device(1, Protocol::FNC_SENSOR, 1, ['type' => 1]);
    $state = $sensor->parseState([0x0B, 0x74, 0x0B, 0x88, 0, 0, 0, 0, 0, 26, 95, 0, 1, 0, 0, 0, 0, 0, 0, 0]);
    assertSame(2932, $state['value']);
    assertSame('20.2°C', $state['display']);

    $rgb = new Device(1, Protocol::FNC_RGB, 2);
    $state = $rgb->parseState([255, 136, 0]);
    assertSame('#FF8800', $state['color_hex']);

    $ct = new Device(1, Protocol::FNC_TUNABLE_WHITE, 3);
    $state = $ct->parseState([0x0A, 0x8C, 0x0B, 0xB8, 0x0A, 0x8C, 80]);
    assertSame(2700, $state['temperature']);
    assertSame(0.8, $state['brightness']);

    $audio = new Device(1, Protocol::FNC_AUDIO_ZONE, 1);
    $state = $audio->parseState([38, 42, 0, 0, 0, 0]);
    assertSame('FM', $state['source_name']);
    assertSame(42, $state['volume']);
});

// ---------------------------------------------------------------------------
// Integration (mock central unit)
// ---------------------------------------------------------------------------

test('integration: handshake, GET/SET and monitor', function (): void {
    if (!function_exists('pcntl_fork')) {
        throw new \RuntimeException('pcntl is not available');
    }

    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new \RuntimeException('could not create mock server: ' . $errstr);
    }
    $name = stream_socket_get_name($server, false);
    if ($name === false || !preg_match('~:(\d+)$~', $name, $m)) {
        @fclose($server);
        throw new \RuntimeException('could not determine mock server port');
    }
    $port = (int) $m[1];

    $pid = pcntl_fork();
    if ($pid === -1) {
        @fclose($server);
        throw new \RuntimeException('could not fork');
    }

    if ($pid === 0) {
        // Child: run the mock server, then exit without running the test
        // framework's shutdown code.
        \Teletask\Tests\MockCentral::run($server, null);
        @fclose($server);
        exit(0);
    }

    @fclose($server);

    $config = new GuiConfig();
    $config->name = 'Mock';
    $config->serverName = '127.0.0.1';
    $config->portNr = $port;
    $config->centrales = '/881000415';
    $config->useLocationIp = false;

    $connection = new LocalConnection('127.0.0.1', $port, $config, [
        'timeout' => 5,
        'option' => 'normal',
        'centrales' => '/881000415',
        'license' => 'yes',
    ]);

    try {
        $connection->connect();
        assertSame(2, $connection->licenseStatus());
        assertTrue($connection->isActivated());

        $connection->get(1, 2, 10);
        $report = $connection->waitForReport(1, 2, 10, 5);
        assertTrue($report !== null, 'dimmer report');
        assertSame([50, 50, 0, 0], $report['data']);

        $connection->set(1, 2, 10, 40);
        $report = $connection->waitForReport(1, 2, 10, 5);
        assertTrue($report !== null, 'dimmer set report');
        assertSame([40, 40, 0, 0], $report['data']);

        $connection->get(1, 1, 17);
        $report = $connection->waitForReport(1, 1, 17, 5);
        assertTrue($report !== null, 'relay report');
        assertSame([1], $report['data']);

        $connection->set(1, 1, 17, 0);
        $report = $connection->waitForReport(1, 1, 17, 5);
        assertTrue($report !== null, 'relay off report');
        assertSame([0], $report['data']);

        $connection->get(1, 217, 2);
        $report = $connection->waitForReport(1, 217, 2, 5);
        assertTrue($report !== null, 'RGB report');
        assertSame([255, 136, 0], $report['data']);

        $connection->get(1, 20, 1);
        $report = $connection->waitForReport(1, 20, 1, 5);
        assertTrue($report !== null, 'sensor report');
        assertSame(0x0B74, ($report['data'][0] << 8) | $report['data'][1]);

        $received = 0;
        $connection->monitor(
            static function (array $report) use (&$received): void {
                $received++;
            },
            0.7,
            [Protocol::FNC_RELAY]
        );
        assertTrue($received > 0, 'monitor should receive at least one report');
    } finally {
        $connection->close();
    }

    pcntl_waitpid($pid, $status);
    assertTrue(pcntl_wifexited($status), 'mock server should exit normally');
    assertSame(0, pcntl_wexitstatus($status));
});

fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, sprintf("Tests: %d passed, %d failed, %d skipped\n", $passed, $failed, $skipped));

exit($failed === 0 ? 0 : 1);
