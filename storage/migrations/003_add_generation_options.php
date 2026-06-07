<?php

/**
 * 迁移: 添加 generation_records 表的编辑/多模型支持字段
 */
return function (PDO $pdo): void {
    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM generation_records');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['mode'])) {
        $pdo->exec("ALTER TABLE generation_records ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'draw' AFTER status");
    }
    if (empty($columns['input_images_json'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN input_images_json LONGTEXT NULL AFTER output_format');
    }
    if (empty($columns['request_id'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN request_id VARCHAR(128) NULL AFTER error_message');
    }
    if (empty($columns['started_at'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER request_id');
    }
    if (empty($columns['ai_model_id'])) {
        $pdo->exec('ALTER TABLE generation_records ADD COLUMN ai_model_id INT UNSIGNED NULL AFTER model');
    }
};
