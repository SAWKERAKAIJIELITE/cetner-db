<?php

namespace Tests\Support;

/**
 * Four fixed ECDSA P-256 key pairs for the tests (D-020). They protect nothing: loading, signing and
 * verifying existing keys needs no openssl.cnf, so the suite runs the same on every machine.
 */
final class TestDeviceKeys
{
    public const PAIRS = [
        [
            'private' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgQgIUgDyvnImEVIdZ
ubypmainfiQNijfp3Kxki9eX56WhRANCAARoBcM37d1Q9faKrSsexDYmMWEej/Dp
YVG5gxYTy3qcCQsxhxMJlZlyVL77Ch9mQDLeX3WEdFOClSdjq9BlFOCA
-----END PRIVATE KEY-----
PEM,
            'public' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEaAXDN+3dUPX2iq0rHsQ2JjFhHo/w
6WFRuYMWE8t6nAkLMYcTCZWZclS++wofZkAy3l91hHRTgpUnY6vQZRTggA==
-----END PUBLIC KEY-----
PEM,
        ],
        [
            'private' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg0h/6+4v/DKKBVA+8
IvlyoxXu4Vo19wF0HtztOJiEE4ehRANCAASrttMBM7qdjfR/jt4vROWElB+mhWrv
fkDMuAiDvyhz7fnxWSUv803OmVMFPhENapqXUkp4ew94ANuVcZqkC1jT
-----END PRIVATE KEY-----
PEM,
            'public' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEq7bTATO6nY30f47eL0TlhJQfpoVq
735AzLgIg78oc+358VklL/NNzplTBT4RDWqal1JKeHsPeADblXGapAtY0w==
-----END PUBLIC KEY-----
PEM,
        ],
        [
            'private' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg2azJabesOo7dbIBV
ZssP2NJsI7Sm6nNgf3stioSjGdKhRANCAATaWh0kwaBCLN9FI0FiMgksJd0PyuEj
wB8ZZDSZktlVTvkE0azYyObZgoce+B6Wx+m8sRDfJFPmsZDjXFY2axDP
-----END PRIVATE KEY-----
PEM,
            'public' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE2lodJMGgQizfRSNBYjIJLCXdD8rh
I8AfGWQ0mZLZVU75BNGs2Mjm2YKHHvgelsfpvLEQ3yRT5rGQ41xWNmsQzw==
-----END PUBLIC KEY-----
PEM,
        ],
        [
            'private' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgavTspepLz/GpTzVI
nDEyPMFeGix4JC4Ife1BVyv21AmhRANCAASI5ArRFbxeM2ombH2EEP2ODkqVkXsq
reMKuKI2SyyNn0IRBVLl+CW3CBi/hSTjE00LzScvLfPzuOoNkECweqvm
-----END PRIVATE KEY-----
PEM,
            'public' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEiOQK0RW8XjNqJmx9hBD9jg5KlZF7
Kq3jCriiNkssjZ9CEQVS5fgltwgYv4Uk4xNNC80nLy3z87jqDZBAsHqr5g==
-----END PUBLIC KEY-----
PEM,
        ],
    ];

    public static function privateKey(int $i = 0): string
    {
        return self::PAIRS[$i]['private'];
    }

    public static function publicKey(int $i = 0): string
    {
        return self::PAIRS[$i]['public'];
    }

    /** base64 of the DER signature (openssl's default), as the device client sends it. */
    public static function sign(string $payload, int $i = 0): string
    {
        openssl_sign($payload, $signature, openssl_pkey_get_private(self::privateKey($i)), OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
