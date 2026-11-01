<?php
/**
 * MySQL API 配置文件
 * ============================================================
 * 部署到 InfinityFree 之前，必须把这里的数据库信息和 API key 改成你自己的！
 * 下面的默认值对应本地 Docker 开发环境（docker-compose.yml）。
 */

return [
    // ---------- 数据库连接 ----------
    'db' => [
        // InfinityFree：控制面板「MySQL 数据库」里显示的「MySQL 主机名」，
        //   形如 sqlXXX.infinityfree.com（注意：不是 localhost！）
        // 本地 Docker：'db'（compose 里的服务名）
        'host'    => 'db',
        'port'    => 3306,
        'name'    => 'apidb',
        'user'    => 'apiuser',
        'pass'    => 'apipass',
        'charset' => 'utf8mb4',
    ],

    // ---------- Master API key（最高权限） ----------
    // 可以直接访问所有端点，也能用来签发 JWT。
    // 请求头二选一：
    //   Authorization: Bearer <key>
    //   X-API-Key: <key>
    // 务必改成一段足够长、足够随机的字符串（可用 `openssl rand -hex 32` 生成）
    'api_key' => 'dev-local-please-change-me-0123456789abcdef',

    // ---------- JWT 认证 ----------
    'jwt' => [
        // 签名密钥，务必改成超长随机串（openssl rand -hex 32）
        'secret' => 'dev-local-jwt-secret-9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c',
        // 签名算法：HS256 / HS384 / HS512
        'alg'  => 'HS256',
        // 默认有效期（秒）
        'ttl'  => 3600,
    ],

    // ---------- 用户名密码登录（签发 JWT） ----------
    // 想启用 POST /api/auth/login 就设 table 为存用户的表名，
    // 密码列必须存 password_hash() 生成的值。设 null 则禁用登录。
    'auth' => [
        'table'       => 'users',
        'user_column' => 'username',
        'pass_column' => 'password',
    ],

    // ---------- 表访问白名单 ----------
    // 留空数组 [] 表示允许访问所有表。
    // 若要限制，写表名：['users', 'posts']
    'allowed_tables' => [],

    // ---------- 裸 SQL 查询（危险） ----------
    // 开启后，POST /api/query 可以执行任意 SQL —— 等于把整个数据库交给持有 key 的人。
    // 强烈建议保持 false；真要开也请搭配一个超强 key 并使用 HTTPS。
    'allow_raw_query' => false,
    // 即使开启裸 SQL，也只允许只读语句（SELECT/SHOW/DESCRIBE/EXPLAIN）
    'raw_query_readonly' => true,

    // ---------- CORS ----------
    'cors' => [
        'enabled' => true,
        // '*' 表示允许任意来源；也可以指定：['https://your-site.com']
        'origins' => ['*'],
    ],

    // ---------- 其他 ----------
    // 单次查询最大返回行数
    'max_limit' => 500,
    // 调试模式：true 时错误信息会返回给客户端（开发用），上线请改成 false
    'debug' => true,
];
