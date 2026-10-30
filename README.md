# teletask-php

A small PHP client for the local interface of TELETASK home
automation central units (iSGUI / GUI protocol).

## Requirements

- PHP 8.0 or newer.
- PHP extensions: `openssl`, `simplexml`, `curl`, `mbstring`.

No Composer packages, no native libraries, no external binaries.

## Quick start

The host argument is the central unit's HTTP endpoint (for example, `192.168.0.82`).
If no port is given, `55959` is assumed. The control port is taken from the `.gui`
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

Example using the public demo server `isgui.teletask.be`:

```bash
$ php bin/teletask list isgui.teletask.be

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
`--config NAME` (name shown by `discover`).

## Diagnostics

```bash
php bin/teletask probe 192.168.0.82 --debug
```

`probe` prints the HTTP endpoint, configuration, control endpoint, the license
challenge, the signed response, and the license status.

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


