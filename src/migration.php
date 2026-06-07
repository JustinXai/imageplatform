<?php

declare(strict_types=1);

/**
 * 确保 shop_packages 表存在
 */
function ensure_shop_packages_table(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo = db();
    $stmt = $pdo->query("SHOW TABLES LIKE 'shop_packages'");
    if ($stmt->fetch()) {
        $checked = true;
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `shop_packages` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `name` VARCHAR(64) NOT NULL,
          `description` TEXT NULL,
          `credits` INT NOT NULL,
          `price` DECIMAL(10,2) NOT NULL,
          `sort_order` INT NOT NULL DEFAULT 0,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_packages_active` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $checked = true;
}

/**
 * 确保 orders 表存在
 */
function ensure_orders_table(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo = db();
    $stmt = $pdo->query("SHOW TABLES LIKE 'orders'");
    if ($stmt->fetch()) {
        $checked = true;
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `orders` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` BIGINT UNSIGNED NOT NULL,
          `order_no` VARCHAR(64) NOT NULL,
          `trade_no` VARCHAR(64) NULL,
          `package_id` INT UNSIGNED NULL,
          `package_name` VARCHAR(64) NOT NULL,
          `credits` INT NOT NULL,
          `amount` DECIMAL(10,2) NOT NULL,
          `pay_type` VARCHAR(32) NULL,
          `status` ENUM('pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
          `paid_at` DATETIME NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_orders_order_no` (`order_no`),
          KEY `idx_orders_user` (`user_id`, `created_at`),
          KEY `idx_orders_status` (`status`),
          KEY `idx_orders_trade_no` (`trade_no`),
          CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_orders_package` FOREIGN KEY (`package_id`) REFERENCES `shop_packages` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $checked = true;
}

/**
 * 确保所有数据表存在
 */
function ensure_all_tables(): void
{
    ensure_shop_packages_table();
    ensure_orders_table();
    ensure_auth_features();
    ensure_api_tokens_table();
    ensure_chat_records_table();
    ensure_chat_conversations_table();
    ensure_chat_records_conversation_column();
}

/**
 * 确保 users 表认证相关字段存在
 */
function ensure_auth_features(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo = db();
    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM users');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['email_verified_at'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email');
    }
    if (empty($columns['email_verify_token'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_verify_token VARCHAR(64) NULL AFTER email_verified_at');
    }
    if (empty($columns['reset_token'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL AFTER email_verify_token');
    }
    if (empty($columns['reset_token_expires_at'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN reset_token_expires_at DATETIME NULL AFTER reset_token');
    }

    $checked = true;
}

/**
 * 确保 ai_models 表存在
 */
function ensure_ai_models_table(): void
{
    static $checked = false;
    if ($checked) return;

    $pdo = db();
    $stmt = $pdo->query("SHOW TABLES LIKE 'ai_models'");
    if (!$stmt->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ai_models` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(64) NOT NULL,
              `model_id` VARCHAR(80) NOT NULL,
              `base_url` VARCHAR(255) NOT NULL,
              `api_key` VARCHAR(255) NOT NULL DEFAULT '',
              `model_type` VARCHAR(16) NOT NULL DEFAULT 'image',
              `credits` INT UNSIGNED DEFAULT NULL,
              `invoke_mode` VARCHAR(16) NOT NULL DEFAULT 'relay',
              `sort_order` INT NOT NULL DEFAULT 0,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_models_active` (`is_active`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    $checked = true;
}

/**
 * 获取所有启用的图片 AI 模型
 * @return array
 */
function active_ai_models(): array
{
    ensure_ai_models_table();
    ensure_ai_models_type_column();
    $stmt = db()->query(
        "SELECT * FROM ai_models WHERE is_active = 1 AND model_type = 'image' ORDER BY sort_order ASC, id ASC"
    );
    return $stmt->fetchAll();
}

/**
 * 获取所有启用的视频 AI 模型
 * @return array
 */
function active_video_ai_models(): array
{
    ensure_ai_models_table();
    ensure_ai_models_type_column();
    $stmt = db()->query(
        "SELECT * FROM ai_models WHERE is_active = 1 AND model_type = 'video' ORDER BY sort_order ASC, id ASC"
    );
    return $stmt->fetchAll();
}

/**
 * 获取所有启用的对话 AI 模型
 * @return array
 */
function active_chat_ai_models(): array
{
    ensure_ai_models_table();
    ensure_ai_models_type_column();
    $stmt = db()->query(
        "SELECT * FROM ai_models WHERE is_active = 1 AND model_type = 'chat' ORDER BY sort_order ASC, id ASC"
    );
    return $stmt->fetchAll();
}

/**
 * 初始化默认 AI 模型（空表时）
 * 初始化默认 AI 模型（空表时）
 */
function seed_default_ai_model(): void
{
    $stmt = db()->query('SELECT COUNT(*) FROM ai_models');
    if ((int) $stmt->fetchColumn() > 0) return;

    $stmt = db()->prepare(
        'INSERT INTO ai_models (name, model_id, base_url, api_key, sort_order) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        '默认模型',
        app_setting('image_model', 'gpt-image-2'),
        rtrim((string) app_setting('image_base_url', 'https://api.kbl6.cn'), '/'),
        (string) app_setting('image_api_key', ''),
        1,
    ]);
}

/**
 * 初始化默认商城套餐（空表时）
 */
function seed_default_packages(): void
{
    $stmt = db()->query('SELECT COUNT(*) FROM shop_packages');
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $packages = [
        ['name' => '体验套餐', 'description' => '适合初次体验AI绘画功能', 'credits' => 10, 'price' => 1.00, 'sort_order' => 1],
        ['name' => '入门套餐', 'description' => '适合轻度使用，日常尝鲜', 'credits' => 50, 'price' => 5.00, 'sort_order' => 2],
        ['name' => '标准套餐', 'description' => '最受欢迎，适合日常创作', 'credits' => 200, 'price' => 18.00, 'sort_order' => 3],
        ['name' => '进阶套餐', 'description' => '适合频繁使用的创作者', 'credits' => 500, 'price' => 40.00, 'sort_order' => 4],
        ['name' => '专业套餐', 'description' => '适合重度使用，超值之选', 'credits' => 1200, 'price' => 88.00, 'sort_order' => 5],
        ['name' => '旗舰套餐', 'description' => '不限量创作，畅享AI绘画', 'credits' => 3000, 'price' => 198.00, 'sort_order' => 6],
    ];

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO shop_packages (name, description, credits, price, sort_order) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($packages as $pkg) {
        $stmt->execute([$pkg['name'], $pkg['description'], $pkg['credits'], $pkg['price'], $pkg['sort_order']]);
    }
}

/**
 * 确保 api_tokens 表存在
 */
function ensure_api_tokens_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    db()->exec('
        CREATE TABLE IF NOT EXISTS `api_tokens` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`     INT UNSIGNED NOT NULL,
            `name`        VARCHAR(100) NOT NULL DEFAULT "",
            `token_hash`  VARCHAR(64) NOT NULL,
            `permissions` TEXT,
            `last_used_at` DATETIME DEFAULT NULL,
            `expires_at`  DATETIME DEFAULT NULL,
            `is_revoked`  TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_token_hash` (`token_hash`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * 确保 chat_records 表存在
 */
function ensure_chat_records_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    db()->exec('
        CREATE TABLE IF NOT EXISTS `chat_records` (
            `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`        INT UNSIGNED NOT NULL,
            `prompt`         TEXT NOT NULL,
            `reply`          TEXT NOT NULL,
            `tokens`         INT UNSIGNED NOT NULL DEFAULT 0,
            `model`          VARCHAR(100) NOT NULL DEFAULT "",
            `credits_cost`   INT UNSIGNED NOT NULL DEFAULT 0,
            `user_ip`        VARCHAR(45) NOT NULL DEFAULT "",
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_user_id` (`user_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * 确保 chat_conversations 表存在
 */
function ensure_chat_conversations_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    db()->exec('
        CREATE TABLE IF NOT EXISTS `chat_conversations` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT UNSIGNED NOT NULL,
            `title`      VARCHAR(200) NOT NULL DEFAULT "新对话",
            `model_id`   INT UNSIGNED DEFAULT NULL,
            `message_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_user_id` (`user_id`),
            KEY `idx_updated_at` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * 确保 chat_records 表有 conversation_id 列
 */
function ensure_chat_records_conversation_column(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    ensure_chat_records_table();

    $columns = [];
    $stmt = db()->query('SHOW COLUMNS FROM chat_records');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['conversation_id'])) {
        db()->exec("ALTER TABLE chat_records ADD COLUMN conversation_id INT UNSIGNED DEFAULT NULL AFTER user_id, ADD KEY idx_conversation_id (conversation_id)");
    }
}
