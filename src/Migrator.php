<?php

declare(strict_types=1);

/**
 * 数据库迁移运行器
 *
 * 管理数据库表结构变更，替代原有的 ensure_* 运行时迁移函数。
 * 迁移文件存放于 storage/migrations/ 目录，
 * 执行状态记录在 app_settings 的 _migration_ 前缀键中。
 */
class MigrationRunner
{
    private PDO $pdo;
    private string $migrationsDir;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->migrationsDir = STORAGE_PATH . '/migrations';
    }

    /**
     * 获取所有已执行的迁移列表
     */
    public function getExecuted(): array
    {
        $raw = app_setting('_migrations_executed', '');
        if ($raw === '') {
            return [];
        }
        $list = json_decode($raw, true);
        return is_array($list) ? $list : [];
    }

    /**
     * 标记迁移为已执行
     */
    public function markExecuted(string $name): void
    {
        $executed = $this->getExecuted();
        if (!in_array($name, $executed, true)) {
            $executed[] = $name;
        }
        set_app_setting('_migrations_executed', json_encode($executed, JSON_UNESCAPED_UNICODE));
        app_setting_clear_cache();
    }

    /**
     * 运行所有未执行的迁移
     *
     * @return int 执行的迁移数量
     */
    public function runAll(): int
    {
        if (!is_dir($this->migrationsDir)) {
            return 0;
        }

        $executed = $this->getExecuted();
        $files = glob($this->migrationsDir . '/*.php');
        if ($files === false) {
            return 0;
        }
        sort($files);

        $count = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $executed, true)) {
                continue;
            }

            try {
                $migration = require $file;
                if (is_callable($migration)) {
                    $migration($this->pdo);
                }
                $this->markExecuted($name);
                $count++;
                Logger::info("迁移执行成功: {$name}");
            } catch (Throwable $e) {
                Logger::error("迁移执行失败: {$name}", [
                    'error' => $e->getMessage(),
                ]);
                throw new RuntimeException("迁移 {$name} 执行失败: " . $e->getMessage());
            }
        }

        return $count;
    }
}
