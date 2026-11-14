# psql-api

通过 PHP 把 MySQL 数据库暴露成 RESTful API，适合放在 **InfinityFree** 这类免费 PHP 主机上，给你的前端 / App / 脚本当后端用。

- 纯 PHP，零依赖，不需要 Composer
- PDO 预处理语句，防 SQL 注入
- **JWT 认证**（登录签发 / master key 签发，支持限表、限裸 SQL 的权限声明）
- **文件上传**（multipart/form-data，支持单/多文件，MIME 白名单、大小限制、日期分目录）
- 适配 InfinityFree（LiteSpeed / Apache + PHP 7.4~8.3，无 SSH，FTP 上传即可）

---

## 一、本地开发（Docker）

在装有 Docker 的机器上：

```bash
docker compose up -d --build
```

然后访问 <http://localhost:8080/api/health>，看到 `"database":"connected"` 就成功了。

> 本地默认配置（`htdocs/config.php`）：数据库 `apidb`、用户 `apiuser`、密码 `apipass`。
> 本地已内置示例表 `users` 和 `posts`（见 `db/init.sql`），`users` 表里有个账号
> `xiaofen` / `xiaofen123` 可以直接测登录。

---

## 二、部署到 InfinityFree

1. 在 InfinityFree 控制面板 **「MySQL Databases」** 创建一个数据库，记下它给你的：
   - MySQL 主机名（形如 `sqlXXX.infinityfree.com`，**不是 localhost**）
   - 数据库名、用户名、密码
2. 编辑 `htdocs/config.php`：

   ```php
   'host' => 'sqlXXX.infinityfree.com',  // 换成你的主机名
   'name' => 'epiz_xxxxxxx_dbname',      // 你的库名
   'user' => 'epiz_xxxxxxx_user',        // 你的用户名
   'pass' => '你的密码',
   'api_key' => '改成一段超长随机字符串',      // master key
   'jwt' => ['secret' => '改成另一段超长随机串'], // JWT 签名密钥
   'upload' => [
       'enabled' => true,
       'dir' => 'uploads',
       'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'],
       'max_size' => 10 * 1024 * 1024,
   ],
   'chunked_upload' => [
       'enabled' => true,
       'temp_dir' => 'uploads/.chunks',
       'chunk_max_size' => 5 * 1024 * 1024,
       'file_max_size' => 2 * 1024 * 1024 * 1024,
       'chunk_ttl' => 86400,
   ],
   'debug' => false,
   ```

   > `api_key` 和 `jwt.secret` 都用 `openssl rand -hex 32` 生成，两者别用同一个值。

3. 用 InfinityFree 的 **File Manager** 或 FTP，把 `htdocs/` 目录里的**所有文件**（`.htaccess`、`config.php`、`index.php`、`src/`）上传到网站根目录 `htdocs/` 下（同名目录）。
4. 在控制面板把 PHP 版本设为 **8.1 / 8.2 / 8.3**（推荐 8.2）。
5. 用 phpMyAdmin 建表、导数据，然后就能通过你的域名调 API 了。

---

## 三、认证（JWT）

系统同时支持两种凭证：

| 凭证               | 说明                                   | 何时用                 |
| ------------------ | -------------------------------------- | ---------------------- |
| **master API key** | 最高权限，可直接访问所有端点、签发 JWT | 服务端脚本、你自己调试 |
| **JWT**            | 带过期时间，可限表、限裸 SQL           | 前端 / App / 第三方    |

### 获取 JWT 的两种方式

**方式一：用户名密码登录**（需在 `config.php` 里配置 `auth.table`，密码列存 `password_hash()` 值）

```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"xiaofen","password":"xiaofen123"}'
# -> { "success": true, "data": { "token": "eyJ...", "expires_in": 3600, "token_type": "Bearer" } }
```

**方式二：用 master key 签发**（可自定义有效期和权限）

```bash
curl -X POST http://localhost:8080/api/auth/token \
  -H "X-API-Key: <你的master key>" \
  -H "Content-Type: application/json" \
  -d '{"ttl": 600, "tables": ["users"], "allow_query": false}'
```

- `ttl`：有效期秒数，默认 3600
- `tables`：该 token 只允许访问这些表（与全局白名单取交集）
- `allow_query`：是否允许该 token 使用裸 SQL（覆盖全局开关）

### 携带凭证调接口

```bash
# 用 JWT
-H "Authorization: Bearer <jwt>"

# 用 master key
-H "X-API-Key: <master key>"
```

---

## 四、API 文档

### 接口一览

| 方法   | 路径                                       | 说明                           | 鉴权       |
| ------ | ------------------------------------------ | ------------------------------ | ---------- |
| GET    | `/api/health`                              | 健康检查                       | 无         |
| POST   | `/api/auth/login`                          | 用户名密码登录，签发 JWT       | 无         |
| POST   | `/api/auth/token`                          | 用 master key 签发 JWT         | master key |
| GET    | `/api/tables`                              | 列出所有表                     | JWT/key    |
| GET    | `/api/schema/{table}`                      | 表结构 + 主键 + 列信息         | JWT/key    |
| GET    | `/api/table/{table}`                       | 查多条（分页/筛选/搜索）       | JWT/key    |
| GET    | `/api/table/{table}/{id}`                  | 查单条（按主键）               | JWT/key    |
| POST   | `/api/table/{table}`                       | 插入一行                       | JWT/key    |
| PUT    | `/api/table/{table}/{id}`                  | 更新一行                       | JWT/key    |
| DELETE | `/api/table/{table}/{id}`                  | 删除一行                       | JWT/key    |
| POST   | `/api/query`                               | 裸 SQL（默认关闭）             | JWT/key    |
| GET    | `/api/upload`                              | 列出上传的文件                 | 无         |
| POST   | `/api/upload`                              | 上传文件（单/多）              | JWT/key    |
| GET    | `/api/upload/{filename}`                   | 下载/查看文件                  | 无         |
| DELETE | `/api/upload/{filename}`                   | 删除文件                       | JWT/key    |
| POST   | `/api/upload/chunked/init`                 | 初始化分片上传，获取 upload_id | JWT/key    |
| POST   | `/api/upload/chunked/{upload_id}`          | 上传单个分片                   | JWT/key    |
| GET    | `/api/upload/chunked/{upload_id}`          | 查询上传进度（轮询）           | JWT/key    |
| GET    | `/api/upload/chunked/{upload_id}/progress` | 实时进度流                     | JWT/key    |
| POST   | `/api/upload/chunked/{upload_id}/complete` | 合并分片，生成最终文件         | JWT/key    |
| DELETE | `/api/upload/chunked/{upload_id}`          | 取消上传，清理分片             | JWT/key    |

### 查询参数（GET `/api/table/{table}`）

| 参数      | 说明                                     |
| --------- | ---------------------------------------- |
| `limit`   | 每页条数，默认 50，最大 500              |
| `offset`  | 偏移量，默认 0                           |
| `order`   | 排序列名                                 |
| `dir`     | `asc` / `desc`                           |
| `columns` | 只返回这些列，逗号分隔，如 `id,username` |
| `where`   | JSON 筛选条件（见下）                    |
| `q`       | 在所有文本列上做模糊搜索                 |

`where` 示例：

```where
?where={"age":18}                          -> age = 18
?where={"age":{"op":">","value":18}}       -> age > 18
?where={"id":{"op":"IN","value":[1,2,3]}}  -> id IN (1,2,3)
?where={"age":{"op":">=","value":10},"active":1}   -> 多条件 AND
```

### 示例（curl）

```bash
KEY="dev-local-please-change-me-0123456789abcdef"
BASE="http://localhost:8080"

# 登录拿 JWT
TOKEN=$(curl -s -X POST $BASE/api/auth/login -H "Content-Type: application/json" \
  -d '{"username":"xiaofen","password":"xiaofen123"}' | grep -oP '"token":"\K[^"]+')

# 健康检查（无需鉴权）
curl $BASE/api/health

# 列出所有表
curl -H "Authorization: Bearer $TOKEN" $BASE/api/tables

# ===== 文件上传示例 =====

# 列出已上传文件（无需鉴权）
curl $BASE/api/upload

# 上传单文件（需鉴权）
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -F "file=@/path/to/image.png" \
  $BASE/api/upload

# 上传多文件（需鉴权）
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -F "files[]=@/path/to/image1.png" \
  -F "files[]=@/path/to/image2.jpg" \
  $BASE/api/upload

# 下载/查看文件（无需鉴权）
curl $BASE/api/upload/abc123.png

# 删除文件（需鉴权）
curl -X DELETE -H "Authorization: Bearer $TOKEN" \
  $BASE/api/upload/abc123.png

# ===== 分片上传示例（大文件/断点续传）=====

# 1. 初始化分片上传
INIT=$(curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"filename":"video.mp4","size":104857600,"total_chunks":20,"chunk_size":5242880,"mime":"video/mp4"}' \
  $BASE/api/upload/chunked/init)
UPLOAD_ID=$(echo $INIT | grep -oP '"upload_id":"\K[^"]+')

# 2. 上传分片（可并发、可乱序、可重试，分片索引 0-based）
# 示例：上传第 0 片
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -F "chunk_index=0" \
  -F "file=@/path/to/video.part0" \
  $BASE/api/upload/chunked/$UPLOAD_ID

# 3a. 查询进度（轮询）
curl -H "Authorization: Bearer $TOKEN" \
  $BASE/api/upload/chunked/$UPLOAD_ID

# 3b. 实时进度流 —— 用 EventSource（浏览器原生 SSE）
# 在前端 JS 中：
# const evt = new EventSource('$BASE/api/upload/chunked/' + uploadId + '/progress',
#   { headers: { 'Authorization': 'Bearer ' + token } });
# evt.addEventListener('progress', e => {
#   const data = JSON.parse(e.data);
#   console.log('Progress:', data.progress + '%');
#   updateProgressBar(data.progress);
# });
# evt.addEventListener('complete', e => {
#   console.log('All chunks uploaded, ready to merge');
#   evt.close();
# });
# evt.addEventListener('error', e => { console.error('SSE error:', e); evt.close(); });
# evt.onerror = () => evt.close();

# 4. 全部分片上传完成后，合并
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"file_hash":"optional_sha256_for_verification"}' \
  $BASE/api/upload/chunked/$UPLOAD_ID/complete

# 5. 如需取消，清理分片
curl -X DELETE -H "Authorization: Bearer $TOKEN" \
  $BASE/api/upload/chunked/$UPLOAD_ID

# 查 users 表
curl -H "Authorization: Bearer $TOKEN" "$BASE/api/table/users?limit=10&order=id&dir=desc"

# 筛选：年龄大于等于 16
curl -H "Authorization: Bearer $TOKEN" "$BASE/api/table/users?where=%7B%22age%22%3A%7B%22op%22%3A%22%3E%3D%22%2C%22value%22%3A16%7D%7D"

# 只返回指定列（避免把 password 这类敏感列带出来）
curl -H "Authorization: Bearer $TOKEN" "$BASE/api/table/users?columns=id,username,age"

# 查单条 / 插入 / 更新 / 删除
curl -H "Authorization: Bearer $TOKEN" $BASE/api/table/users/1
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"username":"newbie","email":"new@example.com","age":15}' $BASE/api/table/users
curl -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"age":16}' $BASE/api/table/users/4
curl -X DELETE -H "Authorization: Bearer $TOKEN" $BASE/api/table/users/4
```

### 响应格式

```json
// 成功
{ "success": true, "data": [...], "meta": { "count": 3, "total": 3, "limit": 50, "offset": 0 } }

// 失败
{ "success": false, "error": { "code": "unauthorized", "message": "Missing or invalid credentials." } }
```

---

## 五、文件上传注意事项

### 部署到 InfinityFree 时的限制

InfinityFree 等免费主机通常有以下限制：

- **上传文件大小限制**：受 PHP 配置 `upload_max_filesize` 和 `post_max_size` 限制（通常 10MB~20MB）
- **执行时间限制**：`max_execution_time` 可能限制大文件上传
- **存储空间**：免费主机通常有磁盘配额限制

**建议**：

- 在 `config.php` 的 `upload.max_size` 设置略小于主机限制的值
- 大文件建议使用分片上传或直接上传到对象存储（S3、COS、OSS 等），本 API 只存文件元数据
- 上传目录 `uploads/` 需要可写权限（InfinityFree 通常默认可写）

### MIME 类型检测

上传时会使用 PHP 的 `finfo` 扩展检测真实 MIME 类型，**不信任**浏览器发送的 `$_FILES['type']`，防止伪造。

### 文件访问 URL

上传成功后返回的 `url` 字段为直接访问链接（如 `https://your-site.com/api/upload/abc123.png`），可直接用于 `<img src="...">` 或前端下载。

### 分片上传（大文件/断点续传）

针对大文件（> 主机 `post_max_size` 限制）或不稳定网络环境，提供分片上传接口：

- **切片建议**：5MB/片，并发 3~5 个
- **分片幂等**：同一分片重复上传会跳过，安全重试
- **乱序上传**：分片可按任意顺序上传，合并时按索引排序
- **断点续传**：前端记录已上传分片索引，刷新页面后调用进度接口恢复
- **秒传支持**：可选 `file_hash`（SHA-256），服务端可扩展秒传逻辑
- **分片校验**：可选 `chunk_hash`（SHA-256），上传时校验分片完整性
- **自动清理**：超过 `chunk_ttl`（默认 24h）未完成的分片会被标记过期，下次访问时自动清理

**流程**：

1. `POST /api/upload/chunked/init` → 获取 `upload_id`
2. 并发 `POST /api/upload/chunked/{upload_id}` 上传各分片（字段：`chunk_index` + `file`）
3. 进度监控（二选一）：
   - **轮询**：定时 `GET /api/upload/chunked/{upload_id}`
   - **SSE 实时流**：`GET /api/upload/chunked/{upload_id}/progress`（推荐，浏览器原生 `EventSource`）
4. 全部分片完成后 `POST /api/upload/chunked/{upload_id}/complete` 合并
5. 失败/放弃时 `DELETE /api/upload/chunked/{upload_id}` 清理

### SSE 实时进度事件

连接 `GET /api/upload/chunked/{upload_id}/progress` 后，服务端会推送以下事件：

| 事件        | 说明                         | 数据字段                                                                                      |
| ----------- | ---------------------------- | --------------------------------------------------------------------------------------------- |
| `init`      | 连接建立，发送初始状态       | `upload_id`, `filename`, `size`, `total_chunks`, `uploaded_count`, `progress`, `complete`     |
| `progress`  | 分片上传完成，进度更新       | `uploaded_chunks`, `uploaded_count`, `total_chunks`, `received_bytes`, `progress`, `complete` |
| `heartbeat` | 心跳保持连接                 | `timestamp`                                                                                   |
| `complete`  | 全部分片上传完毕，可合并     | `message`, `upload_id`                                                                        |
| `timeout`   | 流超时（默认 5 分钟）        | `message`                                                                                     |
| `expired`   | 会话过期（超过 `chunk_ttl`） | `message`                                                                                     |
| `error`     | 会话丢失/异常                | `message`                                                                                     |

**前端示例**：

```js
const evt = new EventSource(`/api/upload/chunked/${uploadId}/progress`, {
  headers: { 'Authorization': `Bearer ${token}` }
});

evt.addEventListener('progress', e => {
  const data = JSON.parse(e.data);
  progressBar.value = data.progress;
  status.textContent = `${data.uploaded_count} / ${data.total_chunks} chunks`;
});

evt.addEventListener('complete', e => {
  console.log('All chunks uploaded, ready to merge');
  evt.close();
  // 调用 complete 接口合并
  await fetch(`/api/upload/chunked/${uploadId}/complete`, {
    method: 'POST', headers: { 'Authorization': `Bearer ${token}` }
  });
});

evt.addEventListener('error', e => { console.error(e); evt.close(); });
evt.onerror = () => evt.close();
```

---

## 六、安全提醒（重要）

> 这个项目的本质是把数据库「开」到公网，请务必：

1. **`api_key` 和 `jwt.secret` 都用强随机字符串**（`openssl rand -hex 32`），且两者不同，别用默认值。
2. 线上开 **HTTPS**（InfinityFree 有免费 SSL，控制面板点一下），否则 JWT/key 明文传输。
3. `allowed_tables` 白名单尽量写上；签发 JWT 时用 `tables` 给第三方限权。
4. `allow_raw_query` 保持 `false`，这玩意儿等于把整库交出。
5. `debug` 上线改成 `false`，别把报错细节漏给外人。
6. **别把存密码/敏感信息的表整个暴露**：查的时候用 `columns` 参数只取需要的列，或者干脆把敏感表加进 JWT 的 `tables` 之外。
7. InfinityFree 免费 MySQL 有并发连接上限（约 30），写前端时注意别狂开请求。
8. **文件上传**：若开启上传功能，请务必设置 `allowed_types` 白名单，限制 `max_size`，且上传目录不要在公开可执行 PHP 的路径下（本项目存放在 `uploads/` 并通过 PHP 读取返回，相对安全）。

---

## 七、项目结构

```text
psql-api/
├── Dockerfile            # 本地 PHP+Apache 镜像
├── docker-compose.yml    # 本地一键起 PHP + MariaDB
├── db/init.sql           # 本地示例数据
└── htdocs/               # ← 部署到 InfinityFree 的目录
    ├── .htaccess         # 路由 + 保护 config.php + 透传 Authorization
    ├── config.php        # 数据库 / key / JWT / 白名单 / 上传 / 分片上传 配置
    ├── index.php         # 前端控制器（路由）
    └── src/
        ├── bootstrap.php
        ├── Config.php
        ├── Response.php
        ├── Database.php
        ├── Jwt.php           # 手写 HS256/384/512 JWT，零依赖
        ├── Auth.php          # master key + JWT 认证 + 登录/签发
        ├── TableController.php
        ├── UploadController.php      # 普通文件上传/下载/列表/删除
        └── ChunkedUploadController.php  # 分片上传/断点续传/合并/清理
```

Apache-2.0，见 `LICENSE`。
