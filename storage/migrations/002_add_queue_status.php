<?php

/**
 * 迁移: 添加 generation_records 表的 queue/running 状态支持
 */
return function (PDO $pdo): void {
    $stmt = $pdo->query("SHOW COLUMNS FROM generation_records LIKE 'status'");
    $column = $stmt->fetch();
    $type = is_array($column) ? (string) ($column['Type'] ?? '') : '';
    if (strpos($type, "'queued'") === false) {
        $pdo->exec(
            "ALTER TABLE generation_records
             MODIFY status ENUM('queued', 'running', 'succeeded', 'failed') NOT NULL DEFAULT 'running'"
        );
    }
};
