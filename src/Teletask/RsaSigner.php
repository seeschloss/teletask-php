<?php

declare(strict_types=1);

namespace Teletask;

/**
 * The RSA license signer used by iSGUI.
 *
 * iSGUI does not use a normal "SHA1 with RSA" signature. It hashes the
 * challenge string with SHA-1 and then applies PKCS#1 v1.5 type-1 padding
 * directly to the 20-byte hash (no DigestInfo wrapper), followed by the raw
 * RSA private-key operation. PHP's openssl_sign() adds the DigestInfo wrapper,
 * so it cannot be used directly; openssl_private_encrypt(..., OPENSSL_NO_PADDING)
 * is used for the raw operation instead.
 */
final class RsaSigner
{
    private const N = 'ghS5j2oREz7bJuvQLv1JgLtkT7iCkn3IZFJYAu6FV+wr4wzRGfyGyL2FkyAYLldqv2h0YTBQrMr0Jl39mxVsXg9rNf0kskdhHwpa3xgNf3RGQ6dva9BvCOI0C3VwIZLf98yBHVrGs2tCxwJ/XhLJNJg+rD5gQ7MrkwxDzV9hP78=';
    private const D = 'b52s4zwVzre+gBUTm15GtakVl7owf9ZiHoLxdgKIdDr4JWiX+35NgO3uMaH2Xb0woXDVe5djggDbohhARdQKGSot0kGcOxH2gEmbjzPvcFoTQjKZsL6aDksTWZn5SqJU/Yn0itJhFbJhF+aOMo1Z2KhIdTvsQsvJ8viUBVyBjPE=';
    private const P = 'vUHkTw8Z6IBHFHos17NbrOmAvhSqZ9dJ9oI1Fj7MTmFTkwvkEHNt7KfRlzMY+upqt5oZaUCPdXbAUqcY4OoAXQ==';
    private const Q = 'r/RmYLpHqiipRT68eCJmF2CQ4ZkEZoNiIzMBGD5zSa+nPjEpapyCnhq3U28LlIgTBmU7zEIP9nLdSxfpYaduyw==';
    private const DMP1 = 'L+xGdqp5QgFxfkLtpfT5ytAZ1IgBGmhOh0gzTx25uEM22W5SYsQH2rwn+2Rp9+dx/Gnd82Pwr6rRSlyblSBL1Q==';
    private const DMQ1 = 'bbLm3SlmIq0lE1nQsOlpUuvOiAgZWTxSQ/W5mf7da7RKhZiq5J/dQ2sOnsLdqmXHzrXwuSD6yMDGPNoUk+CKTw==';
    private const IQMP = 'qyJM85i0P0XhvL6hRgVHXbbIPbr+k/iUtS82MiKHQs37BwKNGyEs45Y5FlRhnDPabXpjOQOXmvPtdZmbtTaBFQ==';
    private const E = 65537;

    private static ?\OpenSSLAsymmetricKey $privateKey = null;
    private static ?string $privateKeyPem = null;
    private static ?string $publicKeyPem = null;

    public static function keySize(): int
    {
        return strlen((string) base64_decode(self::N, true));
    }

    /**
     * Sign the license challenge string exactly as iSGUI does.
     */
    public static function sign(string $data): string
    {
        $keySize = self::keySize();
        $hash = sha1($data, true);
        $paddingLength = $keySize - 3 - strlen($hash);
        if ($paddingLength < 8) {
            throw new TeletaskException('RSA key is too small for the license signature');
        }

        $block = "\x00\x01" . str_repeat("\xff", $paddingLength) . "\x00" . $hash;
        $signature = '';
        $ok = openssl_private_encrypt($block, $signature, self::privateKey(), OPENSSL_NO_PADDING);
        if (!$ok) {
            throw new TeletaskException('RSA signing failed: ' . self::opensslErrors());
        }

        return str_pad($signature, $keySize, "\x00", STR_PAD_LEFT);
    }

    /**
     * Verify a signature produced by sign(). Used by the test-suite.
     */
    public static function verify(string $data, string $signature): bool
    {
        $keySize = self::keySize();
        if (strlen($signature) !== $keySize) {
            return false;
        }

        $decrypted = '';
        $ok = openssl_public_decrypt($signature, $decrypted, self::publicKey(), OPENSSL_NO_PADDING);
        if (!$ok || strlen($decrypted) !== $keySize) {
            return false;
        }

        $hash = sha1($data, true);
        $paddingLength = $keySize - 3 - strlen($hash);
        $expected = "\x00\x01" . str_repeat("\xff", $paddingLength) . "\x00" . $hash;

        return hash_equals($expected, $decrypted);
    }

    public static function publicKeyPem(): string
    {
        if (self::$publicKeyPem === null) {
            $rsa = self::derSequence(
                self::derInteger((string) base64_decode(self::N, true))
                . self::derInteger(pack('N', self::E))
            );
            self::$publicKeyPem = "-----BEGIN RSA PUBLIC KEY-----\n"
                . chunk_split(base64_encode($rsa), 64, "\n")
                . "-----END RSA PUBLIC KEY-----\n";
        }

        return self::$publicKeyPem;
    }

    private static function privateKey(): \OpenSSLAsymmetricKey
    {
        if (self::$privateKey === null) {
            $key = openssl_pkey_get_private(self::privateKeyPem());
            if ($key === false) {
                throw new TeletaskException('Could not load the embedded RSA key: ' . self::opensslErrors());
            }
            self::$privateKey = $key;
        }

        return self::$privateKey;
    }

    private static function publicKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public(self::publicKeyPem());
        if ($key === false) {
            throw new TeletaskException('Could not load the embedded RSA public key: ' . self::opensslErrors());
        }

        return $key;
    }

    private static function privateKeyPem(): string
    {
        if (self::$privateKeyPem === null) {
            $rsa = "\x02\x01\x00"
                . self::derInteger((string) base64_decode(self::N, true))
                . self::derInteger(pack('N', self::E))
                . self::derInteger((string) base64_decode(self::D, true))
                . self::derInteger((string) base64_decode(self::P, true))
                . self::derInteger((string) base64_decode(self::Q, true))
                . self::derInteger((string) base64_decode(self::DMP1, true))
                . self::derInteger((string) base64_decode(self::DMQ1, true))
                . self::derInteger((string) base64_decode(self::IQMP, true));

            self::$privateKeyPem = "-----BEGIN RSA PRIVATE KEY-----\n"
                . chunk_split(base64_encode(self::derSequence($rsa)), 64, "\n")
                . "-----END RSA PRIVATE KEY-----\n";
        }

        return self::$privateKeyPem;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) >= 0x80) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'unknown OpenSSL error' : implode('; ', $errors);
    }
}
