<?php

namespace App\Services\Oidc;

/**
 * Just enough JOSE for OpenID Connect: decoding a compact JWT and checking an RS256/384/512
 * signature against an RSA key from a JWKS, with openssl (no JWT library in this project).
 */
class Jwt
{
    private const ALGORITHMS = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];

    /**
     * @return array{header: array<string, mixed>, claims: array<string, mixed>, signed: string, signature: string}
     */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new OidcException('The token is not a JWT.');
        }

        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $claims = json_decode(self::base64UrlDecode($parts[1]), true);

        if (! is_array($header) || ! is_array($claims)) {
            throw new OidcException('The token is not a JWT.');
        }

        return ['header' => $header, 'claims' => $claims, 'signed' => $parts[0].'.'.$parts[1], 'signature' => self::base64UrlDecode($parts[2])];
    }

    /**
     * The claims of `$jwt` once its signature verifies with one of `$keys` (a JWKS `keys` list).
     *
     * @param  list<array<string, mixed>>  $keys
     * @return array<string, mixed>
     */
    public static function verify(string $jwt, array $keys): array
    {
        $token = self::decode($jwt);
        $algorithm = self::ALGORITHMS[$token['header']['alg'] ?? ''] ?? null;

        if ($algorithm === null) {
            throw new OidcException('Unsupported token algorithm.');
        }

        $kid = $token['header']['kid'] ?? null;
        $candidates = array_filter($keys, fn (array $key) => ($key['kty'] ?? null) === 'RSA'
            && ($kid === null || ($key['kid'] ?? null) === $kid)
            && (($key['use'] ?? 'sig') === 'sig'));

        if ($candidates === []) {
            throw new UnknownSigningKey('No signing key matches the token.');
        }

        foreach ($candidates as $key) {
            $publicKey = openssl_pkey_get_public(self::rsaPem($key));

            if ($publicKey !== false && openssl_verify($token['signed'], $token['signature'], $publicKey, $algorithm) === 1) {
                return $token['claims'];
            }
        }

        throw new OidcException('The token signature is invalid.');
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new OidcException('The token is not valid base64url.');
        }

        return $decoded;
    }

    /**
     * A PEM SubjectPublicKeyInfo for the RSA JWK's modulus `n` and exponent `e`.
     *
     * @param  array<string, mixed>  $jwk
     */
    public static function rsaPem(array $jwk): string
    {
        $modulus = self::derInteger(self::base64UrlDecode((string) ($jwk['n'] ?? '')));
        $exponent = self::derInteger(self::base64UrlDecode((string) ($jwk['e'] ?? '')));
        $rsaPublicKey = self::der(0x30, $modulus.$exponent);
        $algorithm = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = self::der(0x30, $algorithm.self::der(0x03, "\x00".$rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);

        if ($length < 0x80) {
            return chr($tag).chr($length).$value;
        }

        $lengthBytes = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($lengthBytes)).$lengthBytes.$value;
    }
}
