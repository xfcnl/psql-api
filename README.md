# psql-api

通过 PHP 把 MySQL 数据库暴露成 RESTful API，适合放在 **InfinityFree** 这类免费 PHP 主机上，给你的前端 / App / 脚本当后端用。

- 纯 PHP，零依赖，不需要 Composer
- PDO 预处理语句，防 SQL 注入
- **JWT 认证**（登录签发 / master key 签发，支持限表、限裸 SQL 的权限声明）
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
   'debug' => false,
   ```
   > `api_key` 和 `jwt.secret` 都用 `openssl rand -hex 32` 生成，两者别用同一个值。
3. 用 InfinityFree 的 **File Manager** 或 FTP，把 `htdocs/` 目录里的**所有文件**（`.htaccess`、`config.php`、`index.php`、`src/`）上传到网站根目录 `htdocs/` 下（同名目录）。
4. 在控制面板把 PHP 版本设为 **8.1 / 8.2 / 8.3**（推荐 8.2）。
5. 用 phpMyAdmin 建表、导数据，然后就能通过你的域名调 API 了。

---

## 三、认证（JWT）

系统同时支持两种凭证：

| 凭证 | 说明 | 何时用 |
|------|------|--------|
| **master API key** | 最高权限，可直接访问所有端点、签发 JWT | 服务端脚本、你自己调试 |
| **JWT** | 带过期时间，可限表、限裸 SQL | 前端 / App / 第三方 |

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

| 方法   | 路径                       | 说明                         | 鉴权 |
|--------|----------------------------|------------------------------|------|
| GET    | `/api/health`              | 健康检查                     | 无   |
| POST   | `/api/auth/login`          | 用户名密码登录，签发 JWT     | 无   |
| POST   | `/api/auth/token`          | 用 master key 签发 JWT       | master key |
| GET    | `/api/tables`              | 列出所有表                   | JWT/key |
| GET    | `/api/schema/{table}`      | 表结构 + 主键 + 列信息       | JWT/key |
| GET    | `/api/table/{table}`       | 查多条（分页/筛选/搜索）     | JWT/key |
| GET    | `/api/table/{table}/{id}`  | 查单条（按主键）             | JWT/key |
| POST   | `/api/table/{table}`       | 插入一行                     | JWT/key |
| PUT    | `/api/table/{table}/{id}`  | 更新一行                     | JWT/key |
| DELETE | `/api/table/{table}/{id}`  | 删除一行                     | JWT/key |
| POST   | `/api/query`               | 裸 SQL（默认关闭）           | JWT/key |

### 查询参数（GET `/api/table/{table}`）

| 参数      | 说明                                                         |
|-----------|--------------------------------------------------------------|
| `limit`   | 每页条数，默认 50，最大 500                                  |
| `offset`  | 偏移量，默认 0                                               |
| `order`   | 排序列名                                                     |
| `dir`     | `asc` / `desc`                                               |
| `columns` | 只返回这些列，逗号分隔，如 `id,username`                     |
| `where`   | JSON 筛选条件（见下）                                        |
| `q`       | 在所有文本列上做模糊搜索                                     |

`where` 示例：

```
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

## 五、安全提醒（重要）

> 这个项目的本质是把数据库「开」到公网，请务必：

1. **`api_key` 和 `jwt.secret` 都用强随机字符串**（`openssl rand -hex 32`），且两者不同，别用默认值。
2. 线上开 **HTTPS**（InfinityFree 有免费 SSL，控制面板点一下），否则 JWT/key 明文传输。
3. `allowed_tables` 白名单尽量写上；签发 JWT 时用 `tables` 给第三方限权。
4. `allow_raw_query` 保持 `false`，这玩意儿等于把整库交出。
5. `debug` 上线改成 `false`，别把报错细节漏给外人。
6. **别把存密码/敏感信息的表整个暴露**：查的时候用 `columns` 参数只取需要的列，或者干脆把敏感表加进 JWT 的 `tables` 之外。
7. InfinityFree 免费 MySQL 有并发连接上限（约 30），写前端时注意别狂开请求。

---

## 六、项目结构

```
psql-api/
├── Dockerfile            # 本地 PHP+Apache 镜像
├── docker-compose.yml    # 本地一键起 PHP + MariaDB
├── db/init.sql           # 本地示例数据
└── htdocs/               # ← 部署到 InfinityFree 的目录
    ├── .htaccess         # 路由 + 保护 config.php + 透传 Authorization
    ├── config.php        # 数据库 / key / JWT / 白名单 配置
    ├── index.php         # 前端控制器（路由）
    └── src/
        ├── bootstrap.php
        ├── Config.php
        ├── Response.php
        ├── Database.php
        ├── Jwt.php           # 手写 HS256/384/512 JWT，零依赖
        ├── Auth.php          # master key + JWT 认证 + 登录/签发
        └── TableController.php
```

Apache-2.0，见 `LICENSE`。
