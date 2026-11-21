<?php

namespace App\Modules\Identity\Support;

/**
 * The device key: an ECDSA P-256 public key in SPKI PEM; its fingerprint is the SHA-256 of the key's
 * DER bytes, in lowercase hexadecimal (D-013).
 */
final class DeviceCrypto
{
    /** The key in canonical PEM, or null when it is not a P-256 public key. */
    public static function canonicalPublicKey(string $pem): ?string
    {
        $key = @openssl_pkey_get_public(trim($pem)."\n");

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return null;
        }

        return trim($details['key'])."\n";
    }

    public static function fingerprint(string $canonicalPem): string
    {
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $canonicalPem) ?? '';

        return hash('sha256', (string) base64_decode($body, true));
    }

    /** As shown to people: upper case, in groups of four. */
    public static function displayFingerprint(string $fingerprint): string
    {
        return implode(' ', str_split(strtoupper($fingerprint), 4));
    }

    /** What an administrator typed, compared as hexadecimal: spaces and colons ignored, any case. */
    public static function normalizeFingerprint(string $typed): string
    {
        return strtolower(preg_replace('/[\s:]+/', '', $typed) ?? '');
    }

    /** A DER-encoded ECDSA-SHA256 signature, base64, over the payload. */
    public static function verify(string $canonicalPem, string $payload, string $base64Signature): bool
    {
        $signature = base64_decode($base64Signature, true);

        if ($signature === false || $signature === '') {
            return false;
        }

        $key = @openssl_pkey_get_public($canonicalPem);

        if ($key === false) {
            return false;
        }

        return @openssl_verify($payload, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** The payload a device signs to log in (code-documentation.md, section 7.6). */
    public static function loginPayload(string $challengeId, string $challenge, string $deviceId, string $username): string
    {
        return implode("\n", [config('staff_auth.payload_prefix'), $challengeId, $challenge, $deviceId, $username]);
    }
}
