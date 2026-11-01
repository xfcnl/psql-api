<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * PDO 封装：懒连接 + 单例。
 * 只有在真正需要时才连接数据库，这样 /api/health 在数据库挂掉时也能返回。
 */
class Database
{
    private static $pdo = null;
    private static $cfg = null;
    private static $error = null;

    public static function configure(array $cfg)
    {
        self::$cfg = $cfg;
    }

    public static function get()
    {
        if (self::$pdo === null) {
            if (self::$error !== null) {
                throw new RuntimeException('Database connection failed: ' . self::$error);
            }
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    self::$cfg['host'],
                    self::$cfg['port'],
                    self::$cfg['name'],
                    self::$cfg['charset']
                );
                self::$pdo = new PDO($dsn, self::$cfg['user'], self::$cfg['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (Exception $e) {
                self::$error = $e->getMessage();
                throw new RuntimeException('Database connection failed', 0, $e);
            }
        }
        return self::$pdo;
    }

    /** 供 health 检查使用，不会抛异常 */
    public static function ping()
    {
        try {
            self::get()->query('SELECT 1');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
