<?php

declare(strict_types=1);

namespace App\Services\Auth;

use InvalidArgumentException;

/**
 * Builds a PEM public key from a JWK's RSA modulus and exponent, so an ID
 * token signature can be checked with the OpenSSL extension PHP ships with
 * and no JWT library.
 *
 * The encoding is the fixed ASN.1 DER shape of SubjectPublicKeyInfo for
 * rsaEncryption: SEQUENCE { SEQUENCE { OID, NULL }, BIT STRING { SEQUENCE
 * { INTEGER n, INTEGER e } } }. Nothing else in the structure varies.
 */
final class RsaPublicKey
{
    /** rsaEncryption 1.2.840.113549.1.1.1, pre-encoded with its NULL parameters. */
    private const RSA_ALGORITHM_IDENTIFIER = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    /**
     * @param  array<string, mixed>  $jwk
     */
    public static function pemFromJwk(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            throw new InvalidArgumentException('Not an RSA JWK.');
        }

        $modulus = self::base64UrlDecode($jwk['n']);
        $exponent = self::base64UrlDecode($jwk['e']);

        if ($modulus === '' || $exponent === '') {
            throw new InvalidArgumentException('Malformed RSA JWK.');
        }

        $rsaPublicKey = self::sequence(self::integer($modulus).self::integer($exponent));
        $subjectPublicKeyInfo = self::sequence(self::RSA_ALGORITHM_IDENTIFIER.self::bitString($rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    public static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * DER INTEGER: unsigned big-endian, so a leading 0x00 is prepended when
     * the high bit is set to keep it positive.
     */
    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    private static function bitString(string $content): string
    {
        // Leading 0x00: no unused bits in the final octet.
        return "\x03".self::length(strlen($content) + 1)."\x00".$content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
