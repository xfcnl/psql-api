<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 统一 JSON 响应。
 * 成功：{ "success": true, "data": ..., "meta": {...} }
 * 失败：{ "success": false, "error": { "code": ..., "message": ... } }
 */
class Response
{
    public static function success($data = null, array $meta = [], $status = 200)
    {
        self::send($status, ['success' => true, 'data' => $data] + ($meta ? ['meta' => $meta] : []));
    }

    public static function error($code, $message, $status = 400, array $extra = [])
    {
        $error = ['code' => $code, 'message' => $message];
        if ($extra) {
            $error = array_merge($error, $extra);
        }
        self::send($status, ['success' => false, 'error' => $error]);
    }

    private static function send($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
