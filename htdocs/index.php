<?php
/**
 * MySQL API - 前端控制器 / 路由器
 * 把所有请求路由到对应的处理逻辑。
 */
define('APP_RUNNING', true);
require __DIR__ . '/src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 去掉部署目录前缀（支持部署在子目录，例如 InfinityFree 的 /api/）
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base !== '' && $base !== '/' && strpos($uri, $base) === 0) {
    $uri = substr($uri, strlen($base));
}

$segments = array_values(array_filter(explode('/', trim($uri, '/'))));

// 非 /api 请求 -> 返回 API 说明
if (empty($segments) || $segments[0] !== 'api') {
    Response::success([
        'name'    => 'MySQL API',
        'version' => '1.1.0',
        'health'  => rtrim($base, '/') . '/api/health',
        'docs'    => 'See README.md for endpoint reference.',
    ]);
}

$endpoint = isset($segments[1]) ? $segments[1] : '';
$sub = isset($segments[2]) ? $segments[2] : null;

// ---------- 鉴权 ----------
// 公开：/api/health、/api/auth/login
// 其余：需要 JWT 或 master API key
$isPublic = ($endpoint === 'health') || ($endpoint === 'auth' && $sub === 'login');
if (!$isPublic) {
    Auth::requireAuth();
}

switch ($endpoint) {
    // ---- 健康检查（公开）----
    case 'health':
        Response::success([
            'status'   => 'ok',
            'time'     => date('c'),
            'database' => Database::ping() ? 'connected' : 'error',
        ]);
        break;

    // ---- 认证：登录 / 签发 token ----
    case 'auth':
        handleAuth($sub, $method);
        break;

    // ---- 列出所有表 ----
    case 'tables':
        if ($method !== 'GET') {
            Response::error('method_not_allowed', 'Only GET is allowed.', 405);
        }
        Response::success(TableController::listTables());
        break;

    // ---- 表结构 ----
    case 'schema':
        if ($method !== 'GET') {
            Response::error('method_not_allowed', 'Only GET is allowed.', 405);
        }
        Response::success(TableController::schema($segments[2] ?? ''));
        break;

    // ---- 表 CRUD ----
    case 'table':
        $table = $segments[2] ?? '';
        $id = isset($segments[3]) ? $segments[3] : null;

        if ($table === '') {
            Response::error('missing_table', 'Table name is required.', 400);
        }

        if ($id !== null) {
            // /api/table/{table}/{id}
            switch ($method) {
                case 'GET':
                    Response::success(TableController::getRow($table, $id));
                    break;
                case 'PUT':
                case 'PATCH':
                    Response::success(TableController::updateRow($table, $id, json_body()));
                    break;
                case 'DELETE':
                    Response::success(TableController::deleteRow($table, $id));
                    break;
                default:
                    Response::error('method_not_allowed', 'Method not allowed.', 405);
            }
        } else {
            // /api/table/{table}
            switch ($method) {
                case 'GET':
                    $result = TableController::listRows($table, $_GET);
                    Response::success($result['rows'], $result['meta']);
                    break;
                case 'POST':
                    Response::success(TableController::insertRow($table, json_body()), [], 201);
                    break;
                default:
                    Response::error('method_not_allowed', 'Method not allowed.', 405);
            }
        }
        break;

    // ---- 裸 SQL（默认关闭）----
    case 'query':
        if ($method !== 'POST') {
            Response::error('method_not_allowed', 'Only POST is allowed.', 405);
        }
        $body = json_body();
        if (!isset($body['sql'])) {
            Response::error('missing_sql', 'Field "sql" is required.', 400);
        }
        Response::success(TableController::rawQuery($body['sql']));
        break;

    default:
        Response::error('not_found', 'Unknown endpoint.', 404);
}

/**
 * 处理 /api/auth/* 两个端点
 */
function handleAuth($sub, $method)
{
    if ($method !== 'POST') {
        Response::error('method_not_allowed', 'Only POST is allowed.', 405);
    }

    if ($sub === 'login') {
        // 用户名密码登录 -> 签发 JWT（公开端点）
        $body = json_body();
        $username = isset($body['username']) ? trim($body['username']) : '';
        $password = isset($body['password']) ? $body['password'] : '';
        if ($username === '' || $password === '') {
            Response::error('invalid_login', 'username and password are required.', 400);
        }
        $token = Auth::login($username, $password);
        if ($token === null) {
            Response::error('invalid_login', 'Invalid username or password.', 401);
        }
        Response::success($token);
    }

    if ($sub === 'token') {
        // 用 master key 签发 JWT（可自定义 ttl / 限表 / 裸 SQL 权限）
        Auth::requireMasterKey();
        $body = json_body();
        $claims = [];
        $ttl = null;

        if (isset($body['ttl'])) {
            $ttl = (int) $body['ttl'];
        }
        if (isset($body['tables'])) {
            if (!is_array($body['tables'])) {
                Response::error('invalid_tables', 'tables must be an array.', 400);
            }
            foreach ($body['tables'] as $t) {
                if (!preg_match(Auth::IDENTIFIER_RE, (string) $t)) {
                    Response::error('invalid_table', 'Invalid table name in tables: ' . $t, 400);
                }
            }
            $claims['tables'] = $body['tables'];
        }
        if (array_key_exists('allow_query', $body)) {
            $claims['allow_query'] = (bool) $body['allow_query'];
        }

        Response::success(Auth::issueToken($claims, $ttl));
    }

    Response::error('not_found', 'Unknown auth endpoint.', 404);
}
