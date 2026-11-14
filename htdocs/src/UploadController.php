<?php
if (!defined('APP_RUNNING')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 文件上传控制器
 * 支持 multipart/form-data 单文件/多文件上传
 * GET /api/upload/{filename} 访问上传的文件
 */
class UploadController
{
    /**
     * 处理文件上传
     * POST /api/upload
     * multipart/form-data，字段名：file（单文件）或 files[]（多文件）
     */
    public static function upload()
    {
        $uploadConfig = Config::get('upload', []);
        if (empty($uploadConfig['enabled'])) {
            Response::error('upload_disabled', 'File upload is disabled on this server.', 403);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('method_not_allowed', 'Only POST is allowed.', 405);
        }

        // 检查是否有文件上传
        if (empty($_FILES)) {
            Response::error('no_file', 'No file uploaded.', 400);
        }

        $results = [];
        $errors = [];

        // 支持单文件（file）和多文件（files[]）
        $fileKeys = array_keys($_FILES);
        foreach ($fileKeys as $key) {
            $files = self::normalizeFiles($_FILES[$key]);
            foreach ($files as $file) {
                $result = self::processFile($file, $uploadConfig);
                if (isset($result['error'])) {
                    $errors[] = $result['error'];
                } else {
                    $results[] = $result;
                }
            }
        }

        if (empty($results) && !empty($errors)) {
            Response::error('upload_failed', $errors[0], 400, ['errors' => $errors]);
        }

        Response::success($results);
    }

    /**
     * 获取上传的文件（直接返回文件内容）
     * GET /api/upload/{filename}
     */
    public static function getFile($filename)
    {
        $uploadConfig = Config::get('upload', []);
        if (empty($uploadConfig['enabled'])) {
            Response::error('upload_disabled', 'File upload is disabled on this server.', 403);
        }

        if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $filename)) {
            Response::error('invalid_filename', 'Invalid filename.', 400);
        }

        $baseDir = self::getBaseDir($uploadConfig);
        $filePath = self::findFile($baseDir, $filename);

        if ($filePath === null || !is_file($filePath)) {
            Response::error('not_found', 'File not found.', 404);
        }

        // 安全检查：防止目录遍历
        $realBase = realpath($baseDir);
        $realFile = realpath($filePath);
        if ($realFile === false || strpos($realFile, $realBase) !== 0) {
            Response::error('forbidden', 'Access denied.', 403);
        }

        // 根据扩展名推断 MIME 类型
        $mime = mime_content_type($filePath);
        if ($mime === false) {
            $mime = 'application/octet-stream';
        }

        // 发送文件
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=31536000');
        readfile($filePath);
        exit;
    }

    /**
     * 删除上传的文件
     * DELETE /api/upload/{filename}
     */
    public static function deleteFile($filename)
    {
        Auth::requireAuth();

        $uploadConfig = Config::get('upload', []);
        if (empty($uploadConfig['enabled'])) {
            Response::error('upload_disabled', 'File upload is disabled on this server.', 403);
        }

        if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $filename)) {
            Response::error('invalid_filename', 'Invalid filename.', 400);
        }

        $baseDir = self::getBaseDir($uploadConfig);
        $filePath = self::findFile($baseDir, $filename);

        if ($filePath === null || !is_file($filePath)) {
            Response::error('not_found', 'File not found.', 404);
        }

        // 安全检查
        $realBase = realpath($baseDir);
        $realFile = realpath($filePath);
        if ($realFile === false || strpos($realFile, $realBase) !== 0) {
            Response::error('forbidden', 'Access denied.', 403);
        }

        if (!unlink($filePath)) {
            Response::error('delete_failed', 'Failed to delete file.', 500);
        }

        Response::success(['deleted' => true, 'filename' => $filename]);
    }

    /**
     * 列出上传目录下的文件
     * GET /api/upload?limit=50&offset=0
     */
    public static function listFiles()
    {
        $uploadConfig = Config::get('upload', []);
        if (empty($uploadConfig['enabled'])) {
            Response::error('upload_disabled', 'File upload is disabled on this server.', 403);
        }

        $baseDir = self::getBaseDir($uploadConfig);
        if (!is_dir($baseDir)) {
            Response::success(['files' => [], 'meta' => ['total' => 0]]);
        }

        $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 50;
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $allFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getPathname(), strlen($realBase) + 1);
                $allFiles[] = [
                    'filename' => basename($file->getFilename()),
                    'path' => $relativePath,
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                    'mime' => mime_content_type($file->getPathname()) ?: 'application/octet-stream',
                ];
            }
        }

        // 按修改时间倒序
        usort($allFiles, fn($a, $b) => $b['modified'] <=> $a['modified']);

        $total = count($allFiles);
        $pagedFiles = array_slice($allFiles, $offset, $limit);

        Response::success([
            'files' => $pagedFiles,
            'meta' => [
                'total'  => $total,
                'count'  => count($pagedFiles),
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    // ---------- 内部方法 ----------

    /**
     * 标准化 $_FILES 数组格式
     * 处理单文件、多文件（files[]）、多字段的情况
     */
    private static function normalizeFiles($fileArray)
    {
        // 单文件上传
        if (isset($fileArray['error']) && is_int($fileArray['error'])) {
            return [$fileArray];
        }

        // 多文件上传（files[] 格式）
        $normalized = [];
        $count = count($fileArray['name']);
        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name'     => $fileArray['name'][$i],
                'type'     => $fileArray['type'][$i],
                'tmp_name' => $fileArray['tmp_name'][$i],
                'error'    => $fileArray['error'][$i],
                'size'     => $fileArray['size'][$i],
            ];
        }
        return $normalized;
    }

    private static function processFile(array $file, array $config)
    {
        // PHP 上传错误码检查
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => self::uploadErrorMessage($file['error'])];
        }

        // 文件大小检查
        $maxSize = (int) ($config['max_size'] ?? 10 * 1024 * 1024);
        if ($file['size'] > $maxSize) {
            return ['error' => 'File too large. Max size: ' . self::formatBytes($maxSize)];
        }

        // MIME 类型检查
        $allowedTypes = $config['allowed_types'] ?? ['*'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array('*', $allowedTypes, true) && !in_array($mime, $allowedTypes, true)) {
            return ['error' => 'File type not allowed: ' . $mime];
        }

        // 确定保存路径
        $baseDir = self::getBaseDir($config);
        if (!is_dir($baseDir)) {
            if (!mkdir($baseDir, 0755, true)) {
                return ['error' => 'Failed to create upload directory.'];
            }
        }

        $dateDir = '';
        if (!empty($config['date_dir'])) {
            $dateDir = date('Y/m/d/');
            $fullDateDir = $baseDir . $dateDir;
            if (!is_dir($fullDateDir)) {
                mkdir($fullDateDir, 0755, true);
            }
        }

        // 生成文件名
        $keepName = !empty($config['keep_name']);
        if ($keepName) {
            $filename = basename($file['name']);
            // 简单清理
            $filename = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $ext = $ext ? '.' . $ext : '';
            $filename = bin2hex(random_bytes(16)) . $ext;
        }

        $destPath = $baseDir . $dateDir . $filename;

        // 防止文件名冲突
        $counter = 1;
        $originalDest = $destPath;
        while (file_exists($destPath)) {
            $info = pathinfo($originalDest);
            $destPath = $info['dirname'] . '/' . $info['filename'] . '_' . $counter . '.' . ($info['extension'] ?? '');
            $counter++;
        }

        // 移动文件
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['error' => 'Failed to move uploaded file.'];
        }

        // 返回文件信息
        $relativePath = substr($destPath, strlen($baseDir));
        if ($relativePath[0] === '/') {
            $relativePath = substr($relativePath, 1);
        }

        return [
            'filename' => basename($destPath),
            'original_name' => $file['name'],
            'path' => $relativePath,
            'url' => self::buildFileUrl($relativePath),
            'size' => $file['size'],
            'mime' => $mime,
        ];
    }

    private static function getBaseDir(array $config)
    {
        $dir = $config['dir'] ?? 'uploads';
        // 相对于 htdocs/
        return __DIR__ . '/../' . trim($dir, '/') . '/';
    }

    private static function findFile($baseDir, $filename)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                return $file->getPathname();
            }
        }
        return null;
    }

    private static function buildFileUrl($relativePath)
    {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        if ($base !== '' && $base !== '/') {
            $base = '/' . ltrim($base, '/');
        }
        return $base . '/api/upload/' . ltrim($relativePath, '/');
    }

    private static function uploadErrorMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds upload_max_filesize / post_max_size.';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension.';
            default:
                return 'Unknown upload error.';
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
}