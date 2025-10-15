<?php
namespace Bpjs\Framework\Helpers;

class Crypto
{

    public static function encrypt($data)
    {
        $method = 'AES-256-CBC';
        $key = hash('sha256', env('CRYPTO_KEY'), true);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);
        return self::base64UrlEncode($encrypted . '::' . bin2hex($iv));
    }

    public static function decrypt($data)
    {
        $method = 'AES-256-CBC';
        $key = hash('sha256', env('CRYPTO_KEY'), true);

        $decodedData = self::base64UrlDecode($data);

        $parts = explode('::', $decodedData, 2);
        
        if (count($parts) !== 2) {
            return false;
        }
        
        list($encrypted_data, $iv) = $parts;
        
        return openssl_decrypt($encrypted_data, $method, $key, 0, hex2bin($iv));
    }

    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data)
    {
        $data .= str_repeat('=', (4 - strlen($data) % 4) % 4);
        return base64_decode(strtr($data, '-_', '+/'));
    }
}