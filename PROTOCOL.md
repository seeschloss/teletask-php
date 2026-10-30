# Protocol

## Configuration over HTTP

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

## Local TCP protocol

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

## License handshake

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

## Commands

| Command | Payload |
| --- | --- |
| `GET` (6) | `[6, cen, fnc, load_hi, load_lo]` |
| `SET` (7) | `[7, cen, fnc, load_hi, load_lo, action, data...]` |
| `SUBSCRIBE` (3) | `[3, fnc, 255]` to subscribe, `[3, fnc, 0]` to unsubscribe |
| `KEEPALIVE` (11) | `[11]` |
| `EXT_REPORT` (16) | `[16, cen, fnc, load_hi, load_lo, marker, data...]` |

The central unit pushes state changes as `EXT_REPORT` frames. The marker byte
at payload offset 5 is not used by iSGUI; data starts at offset 6.

## Device actions

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

## Mock server

```bash
php tests/mock_server.php 45956
php bin/teletask probe isgui.teletask.be --control-host 127.0.0.1 --control-port 45956
```

(The `isgui.teletask.be` argument is only used to load a configuration; the
mock is contacted through the `--control-*` overrides.)

