<?php
namespace Bpjs\Framework\Helpers;

class Http
{
    public static function get($url, $headers = [])
    {
        return self::request('GET', $url, null, $headers);
    }

    public static function post($url, $data = [], $headers = [])
    {
        return self::request('POST', $url, $data, $headers);
    }

    public static function put($url, $data = [], $headers = [])
    {
        return self::request('PUT', $url, $data, $headers);
    }

    public static function delete($url, $data = [], $headers = [])
    {
        return self::request('DELETE', $url, $data, $headers);
    }

    private static function request($method, $url, $data = null, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($jsonData);
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            throw new \Exception('Request Error: ' . curl_error($ch));
        }

        curl_close($ch);
        return [
            'status' => $httpCode,
            'response' => json_decode($result, true),
        ];
    }
}