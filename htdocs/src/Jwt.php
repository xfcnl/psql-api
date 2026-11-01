<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 极简 JWT（HS256/HS384/HS512），零依赖，纯 PHP 实现。
 * 参考 RFC 7519。只做我们需要的：签名 + 过期校验。
 */
class Jwt
{
    public static function encode(array $payload, $secret, $alg = 'HS256')
    {
        $header = ['alg' => $alg, 'typ' => 'JWT'];
        $h = self::b64e(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = self::b64e(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = self::b64e(hash_hmac(self::hashAlgo($alg), "$h.$p", $secret, true));
        return "$h.$p.$sig";
    }

    /**
     * 解码并验证。失败（格式错/签名错/过期/未生效）返回 null。
     */
    public static function decode($token, $secret, array $algs = ['HS256', 'HS384', 'HS512'])
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        list($h, $p, $s) = $parts;

        $header = json_decode(self::b64d($h), true);
        if (!is_array($header) || empty($header['alg']) || !in_array($header['alg'], $algs, true)) {
            return null;
        }

        $sig = self::b64d($s);
        if ($sig === false) {
            return null;
        }
        $expected = hash_hmac(self::hashAlgo($header['alg']), "$h.$p", $secret, true);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $payload = json_decode(self::b64d($p), true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
            return null; // 已过期
        }
        if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
            return null; // 尚未生效
        }
        return $payload;
    }

    private static function hashAlgo($alg)
    {
        switch ($alg) {
            case 'HS384':
                return 'sha384';
            case 'HS512':
                return 'sha512';
            default:
                return 'sha256';
        }
    }

    private static function b64e($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64d($data)
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
