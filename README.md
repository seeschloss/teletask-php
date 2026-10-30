# teletask-php

A small, dependency-free PHP client for the local interface of TELETASK home
automation central units (iSGUI / GUI protocol).

It was written by reverse-engineering the official `TELETASK-2.6.57.apk`
(Adobe AIR / ActionScript). It talks to the central unit directly; the
TELETASK cloud/AMQP connection is intentionally **not** implemented.

## What works

- Discover `.gui` configurations over HTTP (port 55959 by default).
- Decode and parse the `.gui` XML (rooms, items, device types, sensors,
  motors, ventilation, audio zones, RGB/tunable-white lights).
- List rooms and the actions available for every device.
- Connect to the local control port (normally 55956).
- Perform the iSGUI RSA license handshake.
- Send `GET`, `SET`, `SUBSCRIBE` and `KEEPALIVE` commands.
- Read current state and monitor live state updates.
- Human-readable and JSON output.

Like iSGUI itself, the library adds six regime buttons (AUTO, WORKDAY,
WEEKEND, SIMULATION, NONE, CUSTOM) to every room. They are marked with
`"synthetic": true` in the JSON output.

## Requirements

- PHP 8.0 or newer.
- PHP extensions: `openssl`, `simplexml` (both standard).
- `curl` is used when available; otherwise PHP streams are used.
- `mbstring` is optional (ASCII fallbacks are built in).

No Composer packages, no native libraries, no external binaries.

## Quick start

The host argument is the central unit's **HTTP** endpoint. If no port is
given, `55959` is assumed. The control port is taken from the `.gui`
configuration (normally `55956`), or from the HTTP port minus 3 when the
configuration uses `useLocationIP="True"`.

```bash
# List available configurations
php bin/teletask discover 192.168.0.82

# List rooms
php bin/teletask rooms 192.168.0.82

# List every room/device and the actions it supports
php bin/teletask list 192.168.0.82

# Only one room
php bin/teletask list 192.168.0.82 --room Kitchen

# Machine-readable output
php bin/teletask list 192.168.0.82 --json
```

Example output:

```text
Configuration: Mobile Demo
Source: http://isgui.teletask.be:55959/gui_files/Mobile%20Demo.gui
Control: isgui.teletask.be:55956 (license option: normal)

Kitchen (index 1)
  [devices  ] Dishwasher                     relay              1/1/17         actions: on, off, toggle
  [lights   ] Cooking island                 dimmer             1/2/10         actions: on, off, toggle, level
  [lights   ] Wall                           tunable white      1/220/3        actions: temperature
  [motors   ] Blinds                         motor              1/6/1          actions: up, down, stop, startstop, upstop, downstop
  [sensors  ] Downstairs                     sensor             1/20/1         actions: up, down, day, night, standby, low, med, high, auto, mode, onoff
```

## Reading and controlling

Select a device by room/name or by its raw address (`cen/fnc/ld`):

```bash
# Read state
php bin/teletask get 192.168.0.82 --room Kitchen --device "Cooking island"

# Switch a relay
php bin/teletask on 192.168.0.82 --room Kitchen --device Dishwasher
php bin/teletask off 192.168.0.82 --room Kitchen --device Dishwasher
php bin/teletask toggle 192.168.0.82 --room Kitchen --device Dishwasher

# Set a dimmer to 40%
php bin/teletask set 192.168.0.82 --room Kitchen --device "Cooking island" --action 40

# Or use the named form
php bin/teletask set 192.168.0.82 --room Kitchen --device "Cooking island" --action level --value 40

# RGB colour
php bin/teletask set 192.168.0.82 --room Living --device RGB --color ff8800

# Tunable white: 2700 K at 80%
php bin/teletask set 192.168.0.82 --room Kitchen --device Wall --temperature 2700 --brightness 80

# Raw address (no configuration lookup needed for the action itself)
php bin/teletask set 192.168.0.82 --cen 1 --fnc 2 --ld 10 --action 40

# Wait for the state report after sending
php bin/teletask set 192.168.0.82 --room Kitchen --device "Cooking island" --action 40 --wait
```

Live updates:

```bash
# Monitor one room for 30 seconds
php bin/teletask monitor 192.168.0.82 --room Kitchen --duration 30

# Monitor everything until Ctrl-C
php bin/teletask monitor 192.168.0.82

# JSON lines
php bin/teletask monitor 192.168.0.82 --room Kitchen --json --duration 10
```

If several configurations exist on the central unit, choose one with
`--config NAME` (the name is shown by `discover`).

## Diagnostics

```bash
php bin/teletask probe 192.168.0.82 --debug
```

`probe` prints the HTTP endpoint, configuration, control endpoint, the license
challenge, the signed response, and the license status. It is the first thing
to run if control does not work.

Useful options:

| Option | Meaning |
| --- | --- |
| `--timeout SECONDS` | Network timeout (default 10) |
| `--debug` | Dump TX/RX frames to stderr |
| `--refresh` | Ignore the local configuration cache |
| `--cache-dir DIR` | Cache directory (default `~/.cache/teletask`) |
| `--control-host HOST` | Override the control host |
| `--control-port PORT` | Override the control port |
| `--license-xor auto\|yes\|no\|none` | How the license response is XOR-obfuscated |
| `--no-license` | Skip the license handshake (diagnostics only) |
| `--option normal\|test` | Override the license option |
| `--centrales VALUE` | Override the centrales string used for licensing |
| `--subscribe-style fnc\|full` | Subscription frame format (default `fnc`) |
| `--dry-run` | Show what would be sent without connecting |
| `--json` | Machine-readable output |

## How it works

### 1. Configuration over HTTP

The central unit serves a text index at:

```text
/gui_files/iSGUI_V2.txt     (preferred)
/gui_files/iSGUI.txt        (fallback)
```

The index looks like:

```text
TT
Mobile Demo;1787934463216;1281
TT
```

The part before the first `;` is the `.gui` file name. The file is fetched
from `/gui_files/<name>.gui`.

The `.gui` file is obfuscated: every byte is XORed with `0xFF`, and the last
three bytes are a trailer. The result is a UTF-16 XML document with root
element `HUIS`. The attributes used here include:

- `name`, `ipaddress`, `portnr`, `centrales`
- `useLocationIP`, `jaar`, `maand`, `dag`
- `PAGES/PAGE` (rooms) and `ITEMS/ITEM` (devices)
- `SENSORSZONES`, `VENTILATIONFUNCTIONS`, `MOTORS`, `AUDIOZONES`

### 2. Local TCP protocol

The iSGUI control port is normally `55956` (HTTP port minus 3 when the
configuration uses the location IP).

Frames are binary:

```text
02 <payload length + 2> <payload...> <checksum>
```

For payloads of 250 bytes or more:

```text
02 FF <total length high> <total length low> <payload...> <checksum>
```

The checksum is the low byte of:

```text
2 + declared_length + sum(payload bytes)
```

The first two header bytes are sent in clear. Payload and checksum are XORed
with a one-byte session key after the license handshake.

### 3. License handshake

On connect the central unit sends a `CCTSIG` challenge (command `13`) with a
four-byte `act` value. The session XOR key is the XOR of those four bytes.

The client signs the ASCII string:

```text
TDS15101:1/<option><centrales>/<oldAct>/<act>
```

- `option` is `normal`, or `test` for a configuration younger than four days.
- `centrales` comes from the `.gui` root (for example `/881000415`).
- `oldAct` is `0` for a new connection.

The signature is **not** a standard `openssl_sign()` SHA-1 signature. iSGUI
hashes the string with SHA-1 and applies PKCS#1 v1.5 type-1 padding directly to
the 20-byte hash (no DigestInfo wrapper), then performs the raw RSA private
operation. `RsaSigner` does exactly that with
`openssl_private_encrypt(..., OPENSSL_NO_PADDING)`.

The response payload is:

```text
[13] + ASCII("<prefix>;") + ASCII(base64(signature))
```

There is an important iSGUI quirk here: the **signature covers the full string
including the new act number**, but the **payload string omits the act
number**. For example, with `act=982988` the signature is over:

```text
TDS15101:1/normal/813103795/0/982988
```

but the payload contains:

```text
TDS15101:1/normal/813103795/0/;
```

The central unit knows the act from its own challenge and reconstructs the
signed string. This was confirmed byte-for-byte against a packet capture from
a working installation.

The response is sent as a normal frame, XOR-obfuscated with the challenge key.
The central unit replies with `CCTLIC` (command `14`) and a status:

- `0` = no license
- `1` = test license
- `2` = full license
- `3` = serial number error

Only `1` and `2` are accepted. After that, all payloads and checksums use the
session XOR key.

### 4. Commands

| Command | Payload |
| --- | --- |
| `GET` (6) | `[6, cen, fnc, load_hi, load_lo]` |
| `SET` (7) | `[7, cen, fnc, load_hi, load_lo, action, data...]` |
| `SUBSCRIBE` (3) | `[3, fnc, 255]` to subscribe, `[3, fnc, 0]` to unsubscribe |
| `KEEPALIVE` (11) | `[11]` |
| `EXT_REPORT` (16) | `[16, cen, fnc, load_hi, load_lo, marker, data...]` |

The central unit pushes state changes as `EXT_REPORT` frames. The marker byte
at payload offset 5 is not used by iSGUI; data starts at offset 6.

### 5. Device actions

Common action values:

- Relay / flag / mood / regime: `255` = on, `0` = off, `103` = toggle.
- Dimmer: `0..100` = level, or `255` / `0` / `103`.
- Ventilation: `0` off, `1` low, `2` medium, `3` high, `4` max,
  `3` toggle, `10` up, `11` down.
- Motor: `1` up, `2` down, `3` stop, `6` start/stop, `7` up/stop,
  `8` down/stop.
- RGB: action `2` with `[r, g, b]`.
- Tunable white: action `230` with `[temp_hi, temp_lo, brightness]`.
- Sensor: `21` up, `22` down, `25` night, `26` day, `30` mode,
  `89` auto speed, `93` standby, `97` low, `98` medium, `99` high,
  `104` on/off.
- Audio zone: `36` on, `37` off, `32` volume up, `33` volume down,
  `77` mute, `47..49/43/35/74..76` source 1..8, `78..87` preset keys 0..9.

The CLI translates names to these values; `--action` also accepts a raw
numeric byte for advanced use.

## Troubleshooting

### `Could not download the TELETASK configuration index`

- Check the IP address and that port `55959` is reachable.
- Try `--http-port` if the unit uses a different HTTP port.
- The unit must have a GUI configuration exported (the app needs one too).

### `License handshake failed` / `signature rejected`

Run `probe --debug` and look at the output. The most common causes are:

1. **Wrong control port.** The `.gui` says `portnr="55956"`; use
   `--control-port 55956` explicitly to test.
2. **Wrong `centrales` value.** It comes from the `.gui`; override it with
   `--centrales /<serial>` if needed.
3. **Test/normal option mismatch.** Try `--option test` or `--option normal`.
4. **XOR mode.** Try `--license-xor yes` or `--license-xor no`.
5. **The unit is busy or rate-limiting.** The public demo server occasionally
   closes a connection when several handshakes are made in quick succession;
   wait a second and retry. A central unit may also allow only one GUI
   connection at a time, so close the iSGUI app on the old phone while testing.

The public demo server (`isgui.teletask.be`) is known to work with this
implementation (relay, dimmer, RGB, tunable-white and monitoring were tested).

If the handshake still fails, please capture `probe --debug` output. It shows
the challenge, the exact license string, the signature length, and the
central unit's response.

### `No state report was received`

Some commands do not produce an immediate report. Use `monitor` or
`get` again. Increase `--timeout` if the unit is slow.

### Multiple configurations

Use `--config NAME`. The names are printed by `discover`.

### Password-protected items

The iSGUI password is checked by the app, not by the central unit. This CLI
does not implement the app's password prompt; commands are sent directly. Use
it only on a trusted network.

## Limitations

- Cloud/AMQP connection is not implemented.
- Video door phone streaming and camera URLs are listed but not streamed.
- Chart/history requests are not exposed by the CLI (the protocol method
  exists).
- Some exotic function types are shown as `unknown(...)`; raw numeric
  `--action` values can still be used.
- The license key is the one from iSGUI 2.6.57. Newer firmware may use a
  different key.

## Tests

```bash
php tests/run.php
```

The suite covers frame encoding/decoding, checksums, the RSA signature vector,
`.gui` decoding, configuration parsing, device actions/state parsing, and an
end-to-end integration test against a mock central unit (requires `pcntl`).

There is also a standalone mock server:

```bash
php tests/mock_server.php 45956
php bin/teletask probe isgui.teletask.be --control-host 127.0.0.1 --control-port 45956
```

(The `isgui.teletask.be` argument is only used to load a configuration; the
mock is contacted through the `--control-*` overrides.)

## File layout

```text
bin/teletask                 CLI entry point
src/autoload.php             PSR-4-style autoloader (no Composer needed)
src/Teletask/
  Cli.php                    command-line commands and output
  ConfigRepository.php       HTTP discovery, download and cache
  Device.php                 device actions and state parsing
  Frame.php                  decoded frame value object
  GuiConfig.php              .gui decoder/parser and room/device model
  Http.php                   curl/stream HTTP client
  HttpResponse.php           HTTP response value object
  Item.php                   room item
  LocalConnection.php        TCP connection, license handshake, commands
  Protocol.php               frame format and protocol constants
  Room.php                   room/page
  RsaSigner.php              iSGUI license signature
  TeletaskException.php      exception type
  Text.php                   mbstring-optional text helpers
tests/                       unit + integration tests and mock central unit
```

## Security note

The local iSGUI protocol is unauthenticated on the LAN and is not encrypted.
The license handshake is a client license check, not user authentication. Run
this only on a trusted network, and do not expose port 55956/55959 to the
internet.
