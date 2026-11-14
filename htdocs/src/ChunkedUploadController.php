<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 分片上传控制器（断点续传）
 *
 * 流程：
 * 1. POST /api/upload/chunked/init          初始化上传，返回 upload_id
 * 2. POST /api/upload/chunked/{upload_id}   上传单个分片（可并发、可乱序、可重试）
 * 3. GET  /api/upload/chunked/{upload_id}   查询上传进度、已上传分片列表
 * 4. GET  /api/upload/chunked/{upload_id}/progress  SSE 实时进度流
 * 5. POST /api/upload/chunked/{upload_id}/complete  合并分片，生成最终文件
 * 6. DELETE /api/upload/chunked/{upload_id} 取消上传，清理分片
 *
 * 前端切片建议：5MB/片，并发 3~5 个；分片 hash 可用 SparkMD5 等算前几片+尾片+大小组合，防秒传碰撞。
 */
class ChunkedUploadController
{
    // 分片上传会话前缀
    private const SESSION_PREFIX = 'chunked_';

    /**
     * 初始化分片上传
     * POST /api/upload/chunked/init
     * Body: {
     *   "filename": "video.mp4",
     *   "size": 104857600,
     *   "total_chunks": 20,
     *   "chunk_size": 5242880,
     *   "mime": "video/mp4",
     *   "file_hash": "optional_sha256_or_md5"  // 可选，用于秒传校验
     * }
     */
    public static function init()
    {
        $config = self::getChunkConfig();
        if (empty($config['enabled'])) {
            Response::error('chunked_disabled', 'Chunked upload is disabled.', 403);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('method_not_allowed', 'Only POST is allowed.', 405);
        }

        $body = json_body();
        $required = ['filename', 'size', 'total_chunks', 'chunk_size'];
        foreach ($required as $field) {
            if (!isset($body[$field])) {
                Response::error('missing_field', "Field '$field' is required.", 400);
            }
        }

        $filename = basename($body['filename']);
        $filename = preg_replace('/[^A-Za-z0-9_\-\.\(\)\[\] ]/', '_', $filename);
        $size = (int) $body['size'];
        $totalChunks = (int) $body['total_chunks'];
        $chunkSize = (int) $body['chunk_size'];
        $mime = isset($body['mime']) ? $body['mime'] : '';
        $fileHash = isset($body['file_hash']) ? $body['file_hash'] : null;

        // 校验
        if ($size <= 0 || $totalChunks <= 0 || $chunkSize <= 0) {
            Response::error('invalid_params', 'size, total_chunks, chunk_size must be positive.', 400);
        }
        if ($size > $config['file_max_size']) {
            Response::error('file_too_large', 'File exceeds maximum allowed size: ' . self::formatBytes($config['file_max_size']), 400);
        }
        if ($chunkSize > $config['chunk_max_size']) {
            Response::error('chunk_too_large', 'Chunk size exceeds maximum: ' . self::formatBytes($config['chunk_max_size']), 400);
        }

        // MIME 白名单检查（同普通上传）
        $allowedTypes = Config::get('upload.allowed_types', ['*']);
        if (!in_array('*', $allowedTypes, true) && $mime !== '' && !in_array($mime, $allowedTypes, true)) {
            Response::error('type_not_allowed', 'File type not allowed: ' . $mime, 400);
        }

        // 生成 upload_id
        $uploadId = self::generateUploadId();
        $tempDir = self::getTempDir($config) . $uploadId . '/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // 保存会话元数据
        $session = [
            'upload_id'    => $uploadId,
            'filename'     => $filename,
            'size'         => $size,
            'total_chunks' => $totalChunks,
            'chunk_size'   => $chunkSize,
            'mime'         => $mime,
            'file_hash'    => $fileHash,
            'temp_dir'     => $tempDir,
            'uploaded'     => [],           // 已上传分片索引 [0, 2, 5...]
            'received'     => 0,            // 已接收字节数
            'created_at'   => time(),
            'updated_at'   => time(),
        ];
        self::saveSession($uploadId, $session);

        Response::success([
            'upload_id'    => $uploadId,
            'chunk_size'   => $chunkSize,
            'total_chunks' => $totalChunks,
            'expires_in'   => $config['chunk_ttl'],
        ]);
    }

    /**
     * 上传单个分片
     * POST /api/upload/chunked/{upload_id}
     * multipart/form-data:
     *   - chunk_index: int (0-based)
     *   - chunk_hash: string (optional, 分片内容 hash 校验)
     *   - file: 分片二进制内容
     */
    public static function uploadChunk($uploadId)
    {
        $config = self::getChunkConfig();
        if (empty($config['enabled'])) {
            Response::error('chunked_disabled', 'Chunked upload is disabled.', 403);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('method_not_allowed', 'Only POST is allowed.', 405);
        }

        $session = self::loadSession($uploadId);
        if ($session === null) {
            Response::error('not_found', 'Upload session not found or expired.', 404);
        }

        // 检查过期
        if (time() - $session['created_at'] > $config['chunk_ttl']) {
            self::cleanup($uploadId);
            Response::error('expired', 'Upload session expired.', 410);
        }

        // 获取分片索引
        $chunkIndex = isset($_POST['chunk_index']) ? (int) $_POST['chunk_index'] : -1;
        if ($chunkIndex < 0 || $chunkIndex >= $session['total_chunks']) {
            Response::error('invalid_chunk_index', 'Invalid chunk index.', 400);
        }

        // 已上传过则跳过（幂等）
        if (in_array($chunkIndex, $session['uploaded'], true)) {
            Response::success(['skipped' => true, 'chunk_index' => $chunkIndex]);
        }

        // 获取分片文件
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('chunk_missing', 'Chunk file is missing or upload error.', 400);
        }

        $chunkFile = $_FILES['file'];
        if ($chunkFile['size'] > $config['chunk_max_size']) {
            Response::error('chunk_too_large', 'Chunk exceeds maximum size.', 400);
        }

        // 可选：分片 hash 校验
        if (isset($_POST['chunk_hash']) && $_POST['chunk_hash'] !== '') {
            $actualHash = hash_file('sha256', $chunkFile['tmp_name']);
            if (!hash_equals($actualHash, $_POST['chunk_hash'])) {
                Response::error('chunk_hash_mismatch', 'Chunk hash verification failed.', 400);
            }
        }

        // 移动分片到临时目录
        $chunkPath = $session['temp_dir'] . 'chunk_' . str_pad($chunkIndex, 6, '0', STR_PAD_LEFT);
        if (!move_uploaded_file($chunkFile['tmp_name'], $chunkPath)) {
            Response::error('chunk_save_failed', 'Failed to save chunk.', 500);
        }

        // 更新会话
        $session['uploaded'][] = $chunkIndex;
        $session['uploaded'] = array_values(array_unique($session['uploaded']));
        sort($session['uploaded']);
        $session['received'] += $chunkFile['size'];
        $session['updated_at'] = time();
        self::saveSession($uploadId, $session);

        Response::success([
            'chunk_index'     => $chunkIndex,
            'uploaded_chunks' => count($session['uploaded']),
            'total_chunks'    => $session['total_chunks'],
            'progress'        => round(count($session['uploaded']) / $session['total_chunks'] * 100, 2),
        ]);
    }

    /**
     * 查询上传进度（轮询用）
     * GET /api/upload/chunked/{upload_id}
     */
    public static function status($uploadId)
    {
        $session = self::loadSession($uploadId);
        if ($session === null) {
            Response::error('not_found', 'Upload session not found or expired.', 404);
        }

        $config = self::getChunkConfig();
        $expired = (time() - $session['created_at'] > $config['chunk_ttl']);

        Response::success([
            'upload_id'       => $session['upload_id'],
            'filename'        => $session['filename'],
            'size'            => $session['size'],
            'total_chunks'    => $session['total_chunks'],
            'chunk_size'      => $session['chunk_size'],
            'mime'            => $session['mime'],
            'file_hash'       => $session['file_hash'],
            'uploaded_chunks' => $session['uploaded'],
            'uploaded_count'  => count($session['uploaded']),
            'received_bytes'  => $session['received'],
            'progress'        => $session['total_chunks'] > 0
                ? round(count($session['uploaded']) / $session['total_chunks'] * 100, 2)
                : 0,
            'complete'        => count($session['uploaded']) === $session['total_chunks'],
            'expired'         => $expired,
            'created_at'      => $session['created_at'],
            'updated_at'      => $session['updated_at'],
        ]);
    }

    /**
     * SSE 实时进度流
     * GET /api/upload/chunked/{upload_id}/progress
     *
     * 前端用法：
     *   const evt = new EventSource('/api/upload/chunked/' + uploadId + '/progress');
     *   evt.addEventListener('progress', e => { const data = JSON.parse(e.data); console.log(data.progress); });
     *   evt.addEventListener('complete', e => { console.log('Done'); evt.close(); });
     *   evt.addEventListener('error', e => { console.error(e); evt.close(); });
     *   evt.onerror = () => evt.close();
     */
    public static function progressSSE($uploadId)
    {
        $config = self::getChunkConfig();
        if (empty($config['enabled'])) {
            Response::error('chunked_disabled', 'Chunked upload is disabled.', 403);
        }

        $session = self::loadSession($uploadId);
        if ($session === null) {
            Response::error('not_found', 'Upload session not found or expired.', 404);
        }

        // 设置 SSE 头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // 禁用 nginx 缓冲

        // 发送初始状态
        self::sendSSEEvent('init', [
            'upload_id'       => $session['upload_id'],
            'filename'        => $session['filename'],
            'size'            => $session['size'],
            'total_chunks'    => $session['total_chunks'],
            'uploaded_count'  => count($session['uploaded']),
            'progress'        => $session['total_chunks'] > 0
                ? round(count($session['uploaded']) / $session['total_chunks'] * 100, 2)
                : 0,
            'complete'        => count($session['uploaded']) === $session['total_chunks'],
        ]);

        // 如果已经完成，发送完成事件并关闭
        if (count($session['uploaded']) === $session['total_chunks']) {
            self::sendSSEEvent('complete', ['message' => 'Upload already complete']);
            return;
        }

        // 轮询会话文件，检测变化
        $sessionPath = self::getSessionPath($uploadId);
        $lastModTime = 0;
        $lastUploadedCount = count($session['uploaded']);
        $startTime = time();
        $timeout = 300; // 5 分钟总超时
        $interval = 1;  // 轮询间隔（秒）

        while (true) {
            // 检查客户端是否断开
            if (connection_aborted()) {
                break;
            }

            // 总超时
            if (time() - $startTime > $timeout) {
                self::sendSSEEvent('timeout', ['message' => 'Progress stream timeout']);
                break;
            }

            // 检查会话是否过期
            if (time() - $session['created_at'] > $config['chunk_ttl']) {
                self::sendSSEEvent('expired', ['message' => 'Upload session expired']);
                break;
            }

            // 检查会话文件是否更新
            clearstatcache(true, $sessionPath);
            $modTime = filemtime($sessionPath);
            if ($modTime > $lastModTime) {
                $lastModTime = $modTime;
                $session = self::loadSession($uploadId);
                if ($session === null) {
                    self::sendSSEEvent('error', ['message' => 'Session lost']);
                    break;
                }

                $currentCount = count($session['uploaded']);
                $progress = $session['total_chunks'] > 0
                    ? round($currentCount / $session['total_chunks'] * 100, 2)
                    : 0;

                // 只有进度变化才发送
                if ($currentCount !== $lastUploadedCount) {
                    $lastUploadedCount = $currentCount;
                    self::sendSSEEvent('progress', [
                        'upload_id'       => $session['upload_id'],
                        'uploaded_chunks' => $session['uploaded'],
                        'uploaded_count'  => $currentCount,
                        'total_chunks'    => $session['total_chunks'],
                        'received_bytes'  => $session['received'],
                        'progress'        => $progress,
                        'complete'        => $currentCount === $session['total_chunks'],
                    ]);

                    // 全部完成
                    if ($currentCount === $session['total_chunks']) {
                        self::sendSSEEvent('complete', [
                            'message' => 'All chunks uploaded, ready to merge',
                            'upload_id' => $uploadId,
                        ]);
                        break;
                    }
                }
            }

            // 发送心跳保持连接
            self::sendSSEEvent('heartbeat', ['timestamp' => time()]);

            // 刷新输出缓冲
            @ob_flush();
            @flush();

            sleep($interval);
        }
        exit;
    }

    /**
     * 合并分片，生成最终文件
     * POST /api/upload/chunked/{upload_id}/complete
     * Body: { "file_hash": "optional_final_hash" }
     */
    public static function complete($uploadId)
    {
        $config = self::getChunkConfig();
        if (empty($config['enabled'])) {
            Response::error('chunked_disabled', 'Chunked upload is disabled.', 403);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('method_not_allowed', 'Only POST is allowed.', 405);
        }

        $session = self::loadSession($uploadId);
        if ($session === null) {
            Response::error('not_found', 'Upload session not found or expired.', 404);
        }

        if (count($session['uploaded']) !== $session['total_chunks']) {
            Response::error('incomplete', 'Not all chunks uploaded.', 400);
        }

        // 可选：最终文件 hash 校验
        $body = json_body();
        if (isset($body['file_hash']) && $body['file_hash'] !== '') {
            // 先合并到临时文件校验，再移动
            $tempFinal = $session['temp_dir'] . '_final_' . bin2hex(random_bytes(8));
            self::mergeChunks($session, $tempFinal);
            $actualHash = hash_file('sha256', $tempFinal);
            if (!hash_equals($actualHash, $body['file_hash'])) {
                @unlink($tempFinal);
                Response::error('file_hash_mismatch', 'Final file hash verification failed.', 400);
            }
            $finalPath = $tempFinal;
        } else {
            $finalPath = null;
        }

        // 确定最终存储路径（复用普通上传的逻辑）
        $uploadConfig = Config::get('upload', []);
        $baseDir = UploadController::getBaseDir($uploadConfig);
        $dateDir = '';
        if (!empty($uploadConfig['date_dir'])) {
            $dateDir = date('Y/m/d/');
            $fullDateDir = $baseDir . $dateDir;
            if (!is_dir($fullDateDir)) {
                mkdir($fullDateDir, 0755, true);
            }
        }

        $keepName = !empty($config['keep_name']) || (!empty($uploadConfig['keep_name']));
        if ($keepName) {
            $filename = basename($session['filename']);
            $filename = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
        } else {
            $ext = pathinfo($session['filename'], PATHINFO_EXTENSION);
            $ext = $ext ? '.' . $ext : '';
            $filename = bin2hex(random_bytes(16)) . $ext;
        }

        $destPath = $baseDir . $dateDir . $filename;
        $counter = 1;
        $originalDest = $destPath;
        while (file_exists($destPath)) {
            $info = pathinfo($originalDest);
            $destPath = $info['dirname'] . '/' . $info['filename'] . '_' . $counter . '.' . ($info['extension'] ?? '');
            $counter++;
        }

        // 合并
        if ($finalPath === null) {
            self::mergeChunks($session, $destPath);
        } else {
            rename($finalPath, $destPath);
        }

        // 清理分片
        self::cleanup($uploadId);

        // 返回文件信息（格式与普通上传一致）
        $relativePath = substr($destPath, strlen($baseDir));
        if ($relativePath[0] === '/') {
            $relativePath = substr($relativePath, 1);
        }

        $url = UploadController::buildFileUrl($relativePath);
        $mime = mime_content_type($destPath) ?: ($session['mime'] ?: 'application/octet-stream');

        Response::success([
            'filename'      => basename($destPath),
            'original_name' => $session['filename'],
            'path'          => $relativePath,
            'url'           => $url,
            'size'          => $session['size'],
            'mime'          => $mime,
        ]);
    }

    /**
     * 取消上传，清理分片
     * DELETE /api/upload/chunked/{upload_id}
     */
    public static function abort($uploadId)
    {
        $session = self::loadSession($uploadId);
        if ($session === null) {
            Response::error('not_found', 'Upload session not found.', 404);
        }

        self::cleanup($uploadId);
        Response::success(['aborted' => true, 'upload_id' => $uploadId]);
    }

    // ========== 内部方法 ==========

    private static function getChunkConfig()
    {
        return Config::get('chunked_upload', [
            'enabled'        => false,
            'temp_dir'       => 'uploads/.chunks',
            'chunk_max_size' => 5 * 1024 * 1024,
            'file_max_size'  => 2 * 1024 * 1024 * 1024,
            'chunk_ttl'      => 86400,
            'keep_name'      => false,
        ]);
    }

    private static function getTempDir($config)
    {
        $dir = $config['temp_dir'] ?? 'uploads/.chunks';
        return __DIR__ . '/../' . trim($dir, '/') . '/';
    }

    private static function generateUploadId()
    {
        return self::SESSION_PREFIX . bin2hex(random_bytes(16));
    }

    private static function getSessionPath($uploadId)
    {
        $config = self::getChunkConfig();
        $tempDir = self::getTempDir($config);
        return $tempDir . $uploadId . '.session.json';
    }

    private static function saveSession($uploadId, array $session)
    {
        $path = self::getSessionPath($uploadId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($session, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function loadSession($uploadId)
    {
        $path = self::getSessionPath($uploadId);
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        $session = json_decode($content, true);
        if (!is_array($session) || !isset($session['upload_id'])) {
            return null;
        }
        // 验证 upload_id 一致，防止文件被篡改
        if ($session['upload_id'] !== $uploadId) {
            return null;
        }
        return $session;
    }

    private static function mergeChunks(array $session, string $destPath)
    {
        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            Response::error('merge_failed', 'Cannot create destination file.', 500);
        }

        $totalChunks = $session['total_chunks'];
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $session['temp_dir'] . 'chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
            if (!is_file($chunkPath)) {
                fclose($fp);
                @unlink($destPath);
                Response::error('chunk_missing', "Chunk $i is missing during merge.", 500);
            }
            $chunkFp = fopen($chunkPath, 'rb');
            if ($chunkFp === false) {
                fclose($fp);
                @unlink($destPath);
                Response::error('merge_failed', "Cannot read chunk $i.", 500);
            }
            stream_copy_to_stream($chunkFp, $fp);
            fclose($chunkFp);
        }
        fclose($fp);
    }

    private static function cleanup($uploadId)
    {
        $session = self::loadSession($uploadId);
        if ($session && isset($session['temp_dir']) && is_dir($session['temp_dir'])) {
            // 删除分片文件
            $files = glob($session['temp_dir'] . 'chunk_*');
            foreach ($files as $f) {
                @unlink($f);
            }
            // 删除可能的临时最终文件
            $finals = glob($session['temp_dir'] . '_final_*');
            foreach ($finals as $f) {
                @unlink($f);
            }
            // 删除会话文件
            @unlink(self::getSessionPath($uploadId));
            // 删除目录
            @rmdir($session['temp_dir']);
        }
    }

    private static function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * 发送 SSE 事件
     */
    private static function sendSSEEvent($event, $data)
    {
        echo "event: $event\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}