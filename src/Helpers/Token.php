<?php

namespace Bpjs\Framework\Helpers;

class Token
{
    /**
     * Membuat token dengan payload id + timestamp + signature
     */
    public static function sign($id, int $ttl = 300): string
    {
        $expires = time() + $ttl;
        $payload = $id . '|' . $expires;
        $signature = hash_hmac('sha256', $payload, env('APP_KEY'));
        $token = base64_encode($payload . '|' . $signature);

        return $token;
    }

    /**
     * Memverifikasi token dan mengembalikan ID jika valid
     */
    public static function verify(string $token)
    {
        $decoded = base64_decode($token, true);

        if ($decoded === false) {
            throw new \Exception('Invalid base64 token format.');
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid token structure.');
        }

        [$id, $expires, $signature] = $parts;

        if (time() > (int) $expires) {
            throw new \Exception('Token expired.');
        }

        $expected = hash_hmac('sha256', $id . '|' . $expires, env('APP_KEY'));
        if (!hash_equals($expected, $signature)) {
            throw new \Exception('Invalid token signature.');
        }

        return $id;
    }
}
