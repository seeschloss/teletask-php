<?php

declare(strict_types=1);

namespace Teletask;

/**
 * One decoded TDS frame.
 *
 * Wire format (short frame):
 *   02 <payload length + 2> <payload...> <checksum>
 *
 * Wire format (extended frame, payload >= 250 bytes):
 *   02 FF <total length high> <total length low> <payload...> <checksum>
 *
 * The two header bytes are never XOR-obfuscated. Payload and checksum are
 * XOR-obfuscated with a one-byte key after the license handshake.
 */
final class Frame
{
    /** @var list<int> */
    public array $payload;

    public bool $checksumValid;

    public int $declaredLength;

    public int $headerLength;

    public string $raw;

    /**
     * @param list<int> $payload
     */
    public function __construct(
        string $raw,
        array $payload,
        bool $checksumValid,
        int $declaredLength,
        int $headerLength
    ) {
        $this->raw = $raw;
        $this->payload = $payload;
        $this->checksumValid = $checksumValid;
        $this->declaredLength = $declaredLength;
        $this->headerLength = $headerLength;
    }

    public function command(): int
    {
        return $this->payload[0] ?? -1;
    }

    public function byte(int $offset): int
    {
        return $this->payload[$offset] ?? 0;
    }

    public function uint16(int $offset): int
    {
        return (($this->payload[$offset] ?? 0) << 8) | ($this->payload[$offset + 1] ?? 0);
    }

    public function uint32(int $offset): int
    {
        return (($this->payload[$offset] ?? 0) << 24)
            | (($this->payload[$offset + 1] ?? 0) << 16)
            | (($this->payload[$offset + 2] ?? 0) << 8)
            | ($this->payload[$offset + 3] ?? 0);
    }

    /**
     * @return list<int>
     */
    public function dataFrom(int $offset): array
    {
        return array_values(array_slice($this->payload, $offset));
    }

    public function hex(): string
    {
        return strtoupper(implode(' ', array_map(
            static fn (int $b): string => sprintf('%02X', $b),
            array_values(unpack('C*', $this->raw))
        )));
    }

    public function payloadHex(): string
    {
        return strtoupper(implode(' ', array_map(
            static fn (int $b): string => sprintf('%02X', $b),
            $this->payload
        )));
    }

    public function commandName(): string
    {
        return Protocol::commandName($this->command());
    }
}
