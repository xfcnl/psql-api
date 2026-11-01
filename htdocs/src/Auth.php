<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 认证：同时支持「master API key」和「JWT」。
 *
 * 凭证获取方式（任选其一）：
 *   Authorization: Bearer <jwt 或 master key>
 *   X-API-Key: <master key>
 *
 * 权限优先级：
 *   - JWT 里带 tables  -> 限制可访问的表（与全局白名单取交集）
 *   - JWT 里带 allow_query -> 覆盖全局裸 SQL 开关
 */
class Auth
{
    // 表名/列名白名单正则（与 TableController 保持一致）
    const IDENTIFIER_RE = '/^[A-Za-z0-9_]+$/';

    /** 当前请求通过认证后解析出的 claims */
    private static $claims = null;

    public static function claims($key = null)
    {
        if ($key === null) {
            return self::$claims;
        }
        return isset(self::$claims[$key]) ? self::$claims[$key] : null;
    }

    /**
     * 校验请求凭证（master key 或 JWT）。
     * 通过则记录 claims 并返回；失败直接 401 退出。
     */
    public static function requireAuth()
    {
        if (self::authenticate() === null) {
            Response::error('unauthorized', 'Missing or invalid credentials.', 401);
        }
    }

    /** 校验 master API key（用于签发 token 等高权限操作），失败 401 退出 */
    public static function requireMasterKey()
    {
        if (!self::isMasterKey()) {
            Response::error('unauthorized', 'Master API key is required.', 401);
        }
    }

    /** 当前请求是否携带了正确的 master key */
    public static function isMasterKey()
    {
        $master = Config::get('api_key');
        if ($master === null || $master === '') {
            return false;
        }
        $apiKey = self::providedApiKey();
        if ($apiKey !== null && hash_equals($master, $apiKey)) {
            return true;
        }
        $bearer = self::providedBearer();
        // Bearer 里直接放 master key（不含 '.' 的视为 key 而非 JWT）
        if ($bearer !== null && strpos($bearer, '.') === false && hash_equals($master, $bearer)) {
            return true;
        }
        return false;
    }

    /** 签发 JWT，返回 ['token'=>..., 'expires_in'=>..., 'token_type'=>'Bearer'] */
    public static function issueToken(array $claims, $ttl = null)
    {
        $secret = Config::get('jwt.secret');
        if ($secret === null || $secret === '' || strpos($secret, 'CHANGE_ME') === 0) {
            Response::error('jwt_not_configured', 'JWT secret is not configured.', 500);
        }

        $ttl = $ttl !== null ? (int) $ttl : (int) Config::get('jwt.ttl', 3600);
        if ($ttl <= 0) {
            $ttl = 3600;
        }

        $now = time();
        $payload = array_merge([
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ], $claims);

        return [
            'token'      => Jwt::encode($payload, $secret, Config::get('jwt.alg', 'HS256')),
            'expires_in' => $ttl,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * 用户名密码登录，验证成功后签发 JWT。
     * 返回 token 信息；失败返回 null（由调用方决定是否 401）。
     */
    public static function login($username, $password)
    {
        $table = Config::get('auth.table');
        if (!$table) {
            Response::error('login_disabled', 'Password login is not configured on this server.', 403);
        }
        $userCol = Config::get('auth.user_column', 'username');
        $passCol = Config::get('auth.pass_column', 'password');

        foreach ([$table, $userCol, $passCol] as $ident) {
            if (!preg_match(self::IDENTIFIER_RE, (string) $ident)) {
                Response::error('server_error', 'Invalid auth config identifier.', 500);
            }
        }

        try {
            $stmt = Database::get()->prepare(
                "SELECT `$passCol` FROM `$table` WHERE `$userCol` = ? LIMIT 1"
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch();
        } catch (Exception $e) {
            // 配置的表不存在等数据库错误 -> 视为登录失败，不暴露细节
            return null;
        }

        if ($row === false || !isset($row[$passCol])) {
            return null; // 用户不存在（不泄露具体原因）
        }
        if (!password_verify($password, $row[$passCol])) {
            return null;
        }
        // 可选：密码已过期需要 rehash
        if (password_needs_rehash($row[$passCol], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            Database::get()->prepare("UPDATE `$table` SET `$passCol` = ? WHERE `$userCol` = ?")
                ->execute([$newHash, $username]);
        }
        return self::issueToken(['sub' => $username]);
    }

    // ---------- 内部 ----------

    private static function authenticate()
    {
        $master = Config::get('api_key');

        // 1) master API key
        $apiKey = self::providedApiKey();
        if ($apiKey !== null && $master !== null && $master !== '' && hash_equals($master, $apiKey)) {
            return self::$claims = ['sub' => 'master', 'tables' => null, 'allow_query' => null];
        }

        $bearer = self::providedBearer();
        // Bearer 里直接放 master key（向后兼容：无 '.' 视为 key）
        if ($bearer !== null && strpos($bearer, '.') === false && $master !== null && $master !== '' && hash_equals($master, $bearer)) {
            return self::$claims = ['sub' => 'master', 'tables' => null, 'allow_query' => null];
        }

        // 2) JWT
        if ($bearer !== null) {
            $secret = Config::get('jwt.secret');
            if ($secret !== null && $secret !== '') {
                $payload = Jwt::decode($bearer, $secret);
                if ($payload !== null) {
                    return self::$claims = [
                        'sub'         => isset($payload['sub']) ? $payload['sub'] : null,
                        'tables'      => isset($payload['tables']) && is_array($payload['tables']) ? $payload['tables'] : null,
                        'allow_query' => array_key_exists('allow_query', $payload) ? (bool) $payload['allow_query'] : null,
                    ];
                }
            }
        }

        return null;
    }

    private static function providedApiKey()
    {
        $headers = self::allHeaders();
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-api-key') {
                $v = trim($value);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        return null;
    }

    private static function providedBearer()
    {
        $headers = self::allHeaders();
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                return self::parseBearer($value);
            }
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return self::parseBearer($_SERVER['HTTP_AUTHORIZATION']);
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return self::parseBearer($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }
        return null;
    }

    private static function allHeaders()
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private static function parseBearer($value)
    {
        $value = trim($value);
        if (stripos($value, 'Bearer ') === 0) {
            return trim(substr($value, 7));
        }
        return $value;
    }
}
