<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'image_platform',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'AI 图片视频创作系统',
        'base_url' => '',
        'session_name' => 'image_platform_session',
        'timezone' => 'Asia/Shanghai',
        // 调试模式：开启后显示详细错误信息，生产环境务必关闭
        'debug' => false,
        // SSL 证书验证：生产环境务必保持 true
        'ssl_verify' => true,
    ],
    'generation' => [
        'platform_name' => 'AI 图片视频创作系统',
        'timeout' => 300,
        'worker_sleep' => 3,
        'stale_running_after' => 420,
        'version' => '1.0.0',
        'notice' => '注意：因AI算力产图较慢，预计可能3-5分钟不止，请耐心等待，生成失败不消耗次数！',
    ],
    'pay' => [
        'pid' => '',
        'key' => '',
        'notify_url' => '',
        'return_url' => '',
    ],
];
