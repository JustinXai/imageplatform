<?php

declare(strict_types=1);

/**
 * 渲染 HTML 页面头部
 * @param  string $title  
 * @param  string $section  
 */
function render_header(string $title, string $section = 'app'): void
{
    $user = current_user();
    $platformName = platform_name();
    $platformMark = platform_mark();
    $cssVersion = (string) (@filemtime(dirname(__DIR__) . '/public/assets/app.css') ?: time());
    $userCssVersion = (string) (@filemtime(dirname(__DIR__) . '/public/user/assets/user.css') ?: time());
    $adminCssVersion = (string) (@filemtime(dirname(__DIR__) . '/public/admin/assets/admin.css') ?: time());
    ?>
    <!doctype html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title><?= e($platformName) ?> — <?= e($title) ?></title>
        <link rel="stylesheet" href="/assets/app.css?v=<?= e($cssVersion) ?>">
        <?php if ($section === 'admin'): ?>
        <link rel="stylesheet" href="/admin/assets/admin.css?v=<?= e($adminCssVersion) ?>">
        <?php else: ?>
        <link rel="stylesheet" href="/user/assets/user.css?v=<?= e($userCssVersion) ?>">
        <?php endif; ?>
    </head>
    <body class="page-<?= e($section) ?>">
    <div class="app-layout">
        <?php if ($section !== 'auth' && $user): ?>
            <?php /* ── Sidebar ── */ ?>
            <?php render_sidebar($section, $user, $platformName, $platformMark); ?>
            <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-label="切换菜单">
                <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="sidebar-overlay" data-sidebar-overlay></div>
        <?php endif; ?>

        <?php /* ── Main Content Area ── */ ?>
        <div class="main-area" id="mainArea">
            <?php /* Flash messages */ ?>
            <?php foreach (flashes() as $item): ?>
                <div
                    class="server-flash hidden"
                    data-flash-type="<?= e($item['type']) ?>"
                    data-flash-message="<?= e($item['message']) ?>"
                ></div>
            <?php endforeach; ?>

            <?php if ($user && $section !== 'auth'): ?>
                <?php render_redeem_dialog($user); ?>
            <?php endif; ?>
    <?php
}

/**
 * 渲染侧边栏导航
 * @param  string $section  
 * @param  array $user  
 * @param  string $platformName  
 * @param  string $platformMark  
 */
function render_sidebar(string $section, array $user, string $platformName, string $platformMark): void
{
    $balanceLabel = balance_label();
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $isAdminPage = $user['role'] === 'admin' && strpos($requestPath, '/admin') === 0;
    ?>
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-header">
            <a class="sidebar-brand" href="/user/index" aria-label="<?= e($platformName) ?>">
                <div class="sidebar-logo" aria-hidden="true">
                    <svg viewBox="0 0 40 40" width="32" height="32" role="img" focusable="false">
                        <rect fill="#3b82f6" x="9" y="12" width="22" height="18" rx="6"></rect>
                        <path fill="none" stroke="#fff" stroke-width="2.5" d="M12.5 25.5l5-5 3.5 3.5 3-3 3.5 3.5"></path>
                        <circle fill="#fff" cx="25" cy="16.5" r="2.5"></circle>
                    </svg>
                </div>
                <div class="sidebar-brand-text">
                    <h1><?= e($platformName) ?></h1>
                    <small>AI 创作平台</small>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav" aria-label="主导航">
            <div class="sidebar-nav-section">工作台</div>
            <a class="sidebar-nav-item <?= $section === 'app' && !$isAdminPage ? 'active' : '' ?>" href="/user/index">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                <span class="nav-label">图片生成</span>
            </a>
            <a class="sidebar-nav-item <?= $section === 'video' ? 'active' : '' ?>" href="/user/video">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                <span class="nav-label">视频生成</span>
            </a>
            <a class="sidebar-nav-item <?= $section === 'chat' ? 'active' : '' ?>" href="/user/chat">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span class="nav-label">AI 对话</span>
            </a>

            <div class="sidebar-nav-section" style="margin-top:4px;">资源</div>
            <a class="sidebar-nav-item <?= $section === 'shop' ? 'active' : '' ?>" href="/user/shop">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span class="nav-label">点数商城</span>
            </a>
            <a class="sidebar-nav-item <?= $section === 'records' ? 'active' : '' ?>" href="/user/records">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                <span class="nav-label">生成记录</span>
            </a>
            <a class="sidebar-nav-item <?= $section === 'gallery' ? 'active' : '' ?>" href="/gallery">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span class="nav-label">图片广场</span>
            </a>
            <a class="sidebar-nav-item <?= $section === 'invite' ? 'active' : '' ?>" href="/user/invite">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span class="nav-label">邀请有礼</span>
            </a>
            <a class="sidebar-nav-item <?= $section === 'credits' ? 'active' : '' ?>" href="/user/credits">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span class="nav-label">使用记录</span>
            </a>

            <div class="sidebar-nav-section" style="margin-top:4px;">开发者</div>
            <a class="sidebar-nav-item <?= $section === 'api-tokens' ? 'active' : '' ?>" href="/user/api_tokens">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span class="nav-label">API 令牌</span>
            </a>

            <?php if ($user['role'] === 'admin'): ?>
                <?php
                $currentPage = basename(parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? 'index');
                $currentPage = str_replace('.php', '', $currentPage);
                ?>
                <div class="sidebar-nav-section" style="margin-top:4px;">管理</div>
                <a class="sidebar-nav-item <?= ($isAdminPage && $currentPage === 'index') ? 'active' : '' ?>" href="/admin/index">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span class="nav-label">系统管理</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <a class="sidebar-user" href="/user/ucenter">
                <span class="sidebar-user-avatar"><?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></span>
                <div class="sidebar-user-info">
                    <strong><?= e($user['username']) ?></strong>
                    <span><?= $user['role'] === 'admin' ? '管理员' : '普通用户' ?></span>
                </div>
            </a>

            <div class="sidebar-balance">
                <span><?= e($balanceLabel) ?></span>
                <strong data-balance-display data-balance-label="<?= e($balanceLabel) ?>"><?= number_format((int) $user['credits']) ?></strong>
            </div>

            <div class="sidebar-actions">
                <a class="sidebar-action-btn" href="/user/shop" title="充值">
                    <span class="action-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg></span>
                    <span>充值</span>
                </a>
                <a class="sidebar-action-btn" href="/user/ucenter">
                    <span class="action-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                    <span>个人</span>
                </a>
                <a class="sidebar-action-btn" href="/logout">
                    <span class="action-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
                    <span>退出</span>
                </a>
            </div>
        </div>
    </aside>
    <?php
}

/**
 * 渲染积分兑换弹窗
 * @param  array $user  
 */
function render_redeem_dialog(array $user): void
{
    ?>
    <div id="redeemDialog" class="redeem-dialog hidden">
        <div class="redeem-panel" role="dialog" aria-modal="true" aria-labelledby="redeemDialogTitle">
            <div class="redeem-head">
                <div>
                    <p class="eyebrow">Credits</p>
                    <h2 id="redeemDialogTitle">兑换<?= e(balance_label()) ?></h2>
                </div>
                <button type="button" class="dialog-close" data-close-redeem>关闭</button>
            </div>
            <form method="post" action="/redeem" class="form redeem-form">
                <?= csrf_field() ?>
                <input type="hidden" name="redirect_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/index') ?>">
                <label class="field">
                    <span>兑换码</span>
                    <input name="code" placeholder="请输入兑换码" autocomplete="off" required>
                </label>
                <button class="button primary" type="submit">立即兑换</button>
            </form>
        </div>
    </div>
    <?php
}

/**
 * 渲染 HTML 页面底部
 */
function render_footer(): void
{
    $user = current_user();
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $isAdminPage = $user && $user['role'] === 'admin' && strpos($requestPath, '/admin') === 0;
    $appJsVersion = (string) (@filemtime(dirname(__DIR__) . '/public/assets/app.js') ?: time());
    ?>
        </div><!-- /.main-area -->

    </div><!-- /.app-layout -->

    <?php render_page_notice(); ?>

    <script>
    /* ── Sidebar Toggle (Mobile) ── */
    (function() {
        var toggle = document.querySelector('[data-sidebar-toggle]');
        var overlay = document.querySelector('[data-sidebar-overlay]');
        var sidebar = document.querySelector('[data-sidebar]');
        if (!toggle || !sidebar) return;

        var open = function() {
            document.body.classList.add('sidebar-open');
        };
        var close = function() {
            document.body.classList.remove('sidebar-open');
        };

        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            if (document.body.classList.contains('sidebar-open')) {
                close();
            } else {
                open();
            }
        });

        if (overlay) {
            overlay.addEventListener('click', close);
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                close();
            }
        });

        /* Auto-close sidebar on nav click (mobile) */
        sidebar.querySelectorAll('.sidebar-nav-item').forEach(function(item) {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    close();
                }
            });
        });
    })();

    /* ── Redeem Dialog ── */
    (function() {
        var dialog = document.querySelector('#redeemDialog');
        if (!dialog) return;

        document.addEventListener('click', function(e) {
            if (e.target.closest('[data-open-redeem]')) {
                dialog.classList.remove('hidden');
                document.body.classList.add('has-dialog');
                var input = dialog.querySelector('input[name="code"]');
                if (input) input.focus();
                return;
            }
            if (e.target === dialog || e.target.closest('[data-close-redeem]')) {
                dialog.classList.add('hidden');
                document.body.classList.remove('has-dialog');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dialog.classList.add('hidden');
                document.body.classList.remove('has-dialog');
            }
        });
    })();
    </script>
    <script src="/assets/app.js?v=<?= e($appJsVersion) ?>"></script>
    <script>
    /* ── Toasts ── */
    (function() {
      // 确保容器存在
      var container = document.querySelector('.toast-container');
      if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
      }

      window.showToast = function(msg, type) {
        type = type || 'info';
        var el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.textContent = msg;
        container.appendChild(el);
        setTimeout(function() {
          el.classList.add('toast-leave');
          setTimeout(function() { el.remove(); }, 300);
        }, 3000);
      };

      // 读取服务端 flash 消息
      document.querySelectorAll('.server-flash').forEach(function(el) {
        var msg = el.getAttribute('data-flash-message') || '';
        var type = el.getAttribute('data-flash-type') || 'info';
        if (msg) window.showToast(msg, type);
        el.remove();
      });
    })();
    </script>
    </body>
    </html>
    <?php
}

/**
 * 渲染后台横版导航栏
 * @param  string $active  当前激活的菜单键名
 */
function render_admin_nav(string $active): void
{
    $items = [
        'index'          => '生成记录',
        'users'          => '用户管理',
        'uploads'        => '图片管理',
        'codes'          => '兑换码管理',
        'packages'       => '套餐管理',
        'orders'         => '订单记录',
        'email_settings' => '邮件配置',
        'captcha_settings' => '极验配置',
        'signup_settings' => '注册赠送',
        'page_notices'   => '页面公告',
        'ai_models'      => 'AI模型',
        'chat_records'   => '对话记录',
        'gallery'        => '图片广场',
        'pay_settings'   => '支付配置',
        'social_login'  => '聚合登录',
        'settings'       => '系统配置',
        'update'         => '在线更新',
    ];
    ?>
    <nav class="admin-nav-bar" aria-label="后台管理导航">
        <?php foreach ($items as $key => $label): ?>
            <a class="<?= $key === $active ? 'active' : '' ?>" href="/admin/<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * 获取记录的图片源地址
 * @param  array $record  
 * @return ?string
 */
function record_image_src(array $record): ?string
{
    if (!empty($record['image_url'])) {
        return $record['image_url'];
    }
    if (!empty($record['image_base64'])) {
        $mime = $record['mime_type'] ?: 'image/png';
        return 'data:' . $mime . ';base64,' . $record['image_base64'];
    }
    if (!empty($record['has_image_base64']) && !empty($record['id'])) {
        return '/record_image?id=' . (int) $record['id'];
    }
    return null;
}

/**
 * 渲染页面公告弹窗
 */
function render_page_notice(): void
{
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $pageKey = '';
    if (strpos($requestPath, '/login') === 0) {
        $pageKey = 'login';
    } elseif (strpos($requestPath, '/register') === 0) {
        $pageKey = 'register';
    } elseif (strpos($requestPath, '/forgot') === 0) {
        $pageKey = 'forgot';
    } elseif (strpos($requestPath, '/user/index') === 0 || strpos($requestPath, '/index') === 0 || $requestPath === '/' || $requestPath === '') {
        $pageKey = 'index';
    }

    if ($pageKey === '') {
        return;
    }

    $enabled = app_setting("page_notice_{$pageKey}_enabled", 'off');
    if ($enabled !== 'on') {
        return;
    }

    $content = (string) app_setting("page_notice_{$pageKey}_content", '');
    $content = trim($content);
    if ($content === '') {
        return;
    }

    $noticeId = 'pageNotice-' . $pageKey;
    ?>
    <div id="<?= e($noticeId) ?>" class="page-notice-overlay hidden">
        <div class="page-notice-panel" role="dialog" aria-modal="true">
            <div class="page-notice-head">
                <strong>公告</strong>
                <button type="button" class="dialog-close" data-close-notice>关闭</button>
            </div>
            <div class="page-notice-body">
                <?= strip_tags($content, '<b><i><u><s><a><br><p><span><strong><em><ol><ul><li><h1><h2><h3><h4><h5><h6><img><blockquote><pre><code><hr><div><table><thead><tbody><tr><th><td>') ?>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var overlay = document.getElementById('<?= e($noticeId) ?>');
        if (!overlay) return;

        overlay.classList.remove('hidden');
        if (overlay.closest) {
            var layout = overlay.closest('.app-layout');
            if (layout) layout.style.position = 'relative';
        }

        var close = function() {
            overlay.classList.add('hidden');
        };

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay || e.target.closest('[data-close-notice]')) {
                close();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') close();
        });
    })();
    </script>
    <?php
}
