-- 本地开发用的示例数据（InfinityFree 部署时请忽略此文件，用 phpMyAdmin 建你自己的表）

CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(64) NOT NULL UNIQUE,
    email      VARCHAR(128) NOT NULL,
    password   VARCHAR(255) DEFAULT NULL,  -- password_hash() 生成，用于 /api/auth/login
    age        INT DEFAULT 0,
    active     TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    title      VARCHAR(255) NOT NULL,
    body       TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (username, email, password, age) VALUES
    ('xiaofen', 'G114514g@yeah.net', '$2y$10$KRx2pykxPK2jtiXdmElk9epvW3tV.ZblpjBag0nrN63LTXHj7G3n2', 14), -- 密码: xiaofen123
    ('mezashi', 'mezashi@example.com', NULL, 16),
    ('test',    'test@example.com',    NULL, 20);

INSERT INTO posts (user_id, title, body) VALUES
    (1, '第一条', 'hello world'),
    (1, '第二条', '杂鱼主人又犯蠢了 w'),
    (2, '毒舌',   '笨蛋笨蛋笨蛋');
