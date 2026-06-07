<?php

/**
 * 迁移: 添加 generation_records 表的 soft delete (deleted_at) 列
 */
return function (PDO $pdo): void {
    $stmt = $pdo->query("SHOW COLUMNS FROM generation_records LIKE 'deleted_at'");
    if (!$stmt->fetch()) {
        $pdo->exec(
            'ALTER TABLE generation_records
             ADD COLUMN deleted_at DATETIME NULL AFTER finished_at,
             ADD KEY idx_generation_deleted_at (deleted_at)'
        );
    }
};
