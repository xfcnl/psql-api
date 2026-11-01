<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------- 加载依赖 ----------
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/TableController.php';

// ---------- 加载配置 ----------
$config = require __DIR__ . '/../config.php';
Config::load($config);
Database::configure($config['db']);

// ---------- 统一异常处理 -> JSON ----------
set_exception_handler(function ($e) {
    error_log($e);
    $message = Config::get('debug')
        ? $e->getMessage()
        : 'Internal server error.';
    Response::error('server_error', $message, 500);
});

// ---------- CORS ----------
if (Config::get('cors.enabled')) {
    $origins = Config::get('cors.origins', ['*']);
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

    if (in_array('*', $origins, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array($origin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');

    // 预检请求直接放行
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ---------- 工具函数 ----------

/** 读取并解析 JSON 请求体 */
function json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        Response::error('invalid_json', 'Request body must be a valid JSON object.', 400);
    }
    return $data;
}
