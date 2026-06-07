<?php

declare(strict_types=1);

/**
 * 分级日志系统
 * 
 * 支持 debug / info / warning / error 四级日志
 * 日志文件按日期自动分割，存放于 storage/logs/ 目录
 */
class Logger
{
    const LEVELS = ['debug', 'info', 'warning', 'error'];

    private static ?string $logDir = null;

    /**
     * 初始化日志目录
     */
    private static function init(): void
    {
        if (self::$logDir !== null) {
            return;
        }
        self::$logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }

    /**
     * 写入 debug 日志
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * 写入 info 日志
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * 写入 warning 日志
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * 写入 error 日志
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * 记录 API 请求/响应日志
     */
    public static function api(string $endpoint, string $method, array $request, array $response, float $duration): void
    {
        $context = [
            'endpoint' => $endpoint,
            'method' => $method,
            'duration_ms' => round($duration * 1000),
            'http_code' => $response['http_code'] ?? 0,
        ];
        $logLevel = ($response['http_code'] ?? 200) >= 400 ? 'error' : 'info';
        self::log($logLevel, "API: {$method} {$endpoint}", $context);
    }

    /**
     * 写入日志
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        self::init();

        $date = date('Y-m-d H:i:s');
        $contextStr = $context ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$date}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        $logFile = self::$logDir . '/' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        // 错误级别同时写入专用错误文件
        if ($level === 'error') {
            $errorFile = self::$logDir . '/error.log';
            @file_put_contents($errorFile, $line, FILE_APPEND | LOCK_EX);
        }
    }
}
