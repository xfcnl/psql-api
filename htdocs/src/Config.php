<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 简单的配置读取器，支持点号访问嵌套键：
 *   Config::get('db.host')
 *   Config::get('cors.enabled')
 */
class Config
{
    private static $data = [];

    public static function load(array $config)
    {
        self::$data = $config;
    }

    public static function get($key, $default = null)
    {
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
