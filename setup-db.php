#!/usr/bin/env php
<?php
/**
 * imageplatform initial database setup
 * Creates all required tables for the platform
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=aio222;charset=utf8mb4',
    'aio222',
    'aio222',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Creating tables...\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(255) NOT NULL DEFAULT '',
  `email_verified_at` DATETIME NULL DEFAULT NULL,
  `email_verify_token` VARCHAR(64) NULL DEFAULT NULL,
  `reset_token` VARCHAR(64) NULL DEFAULT NULL,
  `reset_token_expires_at` DATETIME NULL DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'user',
  `credits` INT NOT NULL DEFAULT 0,
  `invite_code` VARCHAR(20) NULL DEFAULT NULL,
  `invited_by` INT NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `setting` TEXT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`),
  UNIQUE KEY `uniq_email` (`email`),
  UNIQUE KEY `idx_invite_code` (`invite_code`),
  KEY `idx_invited_by` (`invited_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(128) NOT NULL,
  `setting_value` TEXT NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `generation_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `prompt` TEXT NOT NULL,
  `status` ENUM('queued','running','succeeded','failed') NOT NULL DEFAULT 'running',
  `mode` VARCHAR(16) NOT NULL DEFAULT 'draw',
  `model` VARCHAR(80) NOT NULL DEFAULT '',
  `ai_model_id` INT UNSIGNED NULL DEFAULT NULL,
  `output_url` TEXT NULL DEFAULT NULL,
  `output_base64` LONGTEXT NULL DEFAULT NULL,
  `mime_type` VARCHAR(50) NOT NULL DEFAULT 'image/png',
  `width` INT NOT NULL DEFAULT 1024,
  `height` INT NOT NULL DEFAULT 1024,
  `error_message` TEXT NULL DEFAULT NULL,
  `request_id` VARCHAR(128) NULL DEFAULT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `credits_cost` INT NOT NULL DEFAULT 0,
  `user_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `input_images_json` LONGTEXT NULL DEFAULT NULL,
  `video_url` TEXT NULL DEFAULT NULL,
  `video_base64` LONGTEXT NULL DEFAULT NULL,
  `video_mime_type` VARCHAR(64) NULL DEFAULT NULL,
  `video_task_id` VARCHAR(128) NULL DEFAULT NULL,
  `video_task_status` VARCHAR(32) NULL DEFAULT NULL,
  `video_task_response` LONGTEXT NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_generation_deleted_at` (`deleted_at`),
  FULLTEXT KEY `ft_prompt` (`prompt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `ai_models` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(64) NOT NULL,
  `trade_no` VARCHAR(64) NULL DEFAULT NULL,
  `package_id` INT UNSIGNED NULL DEFAULT NULL,
  `package_name` VARCHAR(64) NOT NULL,
  `credits` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `pay_type` VARCHAR(32) NULL DEFAULT NULL,
  `status` ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `paid_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_orders_order_no` (`order_no`),
  KEY `idx_orders_user` (`user_id`, `created_at`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_trade_no` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `shop_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `credits` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_packages_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `chat_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED DEFAULT NULL,
  `prompt` TEXT NOT NULL,
  `reply` TEXT NOT NULL,
  `tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `model` VARCHAR(100) NOT NULL DEFAULT '',
  `credits_cost` INT UNSIGNED NOT NULL DEFAULT 0,
  `user_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_conversation_id` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `chat_conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL DEFAULT '新对话',
  `model_id` INT UNSIGNED DEFAULT NULL,
  `message_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `token_hash` VARCHAR(64) NOT NULL,
  `permissions` TEXT NULL DEFAULT NULL,
  `last_used_at` DATETIME NULL DEFAULT NULL,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `is_revoked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Insert default packages
$packages = [
    ['name' => '体验套餐', 'description' => '适合初次体验AI绘画功能', 'credits' => 10, 'price' => 1.00, 'sort_order' => 1],
    ['name' => '入门套餐', 'description' => '适合轻度使用，日常尝鲜', 'credits' => 50, 'price' => 5.00, 'sort_order' => 2],
    ['name' => '标准套餐', 'description' => '最受欢迎，适合日常创作', 'credits' => 200, 'price' => 18.00, 'sort_order' => 3],
    ['name' => '进阶套餐', 'description' => '适合频繁使用的创作者', 'credits' => 500, 'price' => 40.00, 'sort_order' => 4],
    ['name' => '专业套餐', 'description' => '适合重度使用，超值之选', 'credits' => 1200, 'price' => 88.00, 'sort_order' => 5],
    ['name' => '旗舰套餐', 'description' => '不限量创作，畅享AI绘画', 'credits' => 3000, 'price' => 198.00, 'sort_order' => 6],
];
$stmt = $pdo->prepare('INSERT IGNORE INTO shop_packages (name, description, credits, price, sort_order) VALUES (?, ?, ?, ?, ?)');
foreach ($packages as $pkg) {
    $stmt->execute([$pkg['name'], $pkg['description'], $pkg['credits'], $pkg['price'], $pkg['sort_order']]);
}

echo "Done. Tables created.\n";
$stmt = $pdo->query("SHOW TABLES");
echo "Tables in database:\n";
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    echo "  - " . $row[0] . "\n";
}
