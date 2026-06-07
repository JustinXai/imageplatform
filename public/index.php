<?php
require_once __DIR__ . '/../src/bootstrap.php';

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($requestPath !== '/' && $requestPath !== '/index' && $requestPath !== '/index.php') {
    $relativePath = trim(rawurldecode($requestPath), '/');
    if ($relativePath !== '' && strpos($relativePath, '..') === false) {
        $hasExtension = pathinfo($relativePath, PATHINFO_EXTENSION) !== '';
        $candidates = $hasExtension
            ? [PUBLIC_PATH . '/' . $relativePath]
            : [
                PUBLIC_PATH . '/' . $relativePath . '.php',
                PUBLIC_PATH . '/' . $relativePath . '/index.php',
                PUBLIC_PATH . '/' . $relativePath . '.html',
            ];

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $realPath = realpath($candidate);
            if ($realPath === false || strpos($realPath, PUBLIC_PATH) !== 0 || $realPath === __FILE__) {
                continue;
            }

            $extension = strtolower((string) pathinfo($realPath, PATHINFO_EXTENSION));
            if ($extension === 'php') {
                require $realPath;
                exit;
            }

            if ($extension === 'html') {
                header('Content-Type: text/html; charset=utf-8');
                readfile($realPath);
                exit;
            }
        }
    }
}

$user = current_user();
$platformName = platform_name();
$balanceLabel = balance_label();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($platformName) ?> - AI 图片视频创作平台</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'PingFang SC', 'Microsoft YaHei', sans-serif; background: #0a0a0f; color: #e2e8f0; line-height: 1.6; }

        nav { position: fixed; top: 0; left: 0; right: 0; z-index: 100; padding: 0 clamp(16px, 3vw, 40px); height: 64px; display: flex; align-items: center; justify-content: space-between; background: rgba(10,10,15,.85); backdrop-filter: blur(16px); border-bottom: 1px solid rgba(255,255,255,.06); }
        nav .logo { font-size: 20px; font-weight: 800; color: #fff; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        nav .logo span.gradient { background: linear-gradient(135deg, #6366f1, #8b5cf6, #3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        nav .links { display: flex; gap: 32px; align-items: center; }
        nav .links a { color: #94a3b8; text-decoration: none; font-size: 14px; font-weight: 500; transition: color .15s; white-space: nowrap; }
        nav .links a:hover { color: #fff; }
        nav .btn { padding: 8px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; transition: all .15s; }
        nav .btn-outline { border: 1px solid rgba(255,255,255,.2); color: #fff; }
        nav .btn-outline:hover { background: rgba(255,255,255,.08); }
        nav .btn-primary { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; }
        nav .btn-primary:hover { opacity: .9; transform: translateY(-1px); }
        nav .hamburger { display: none; width: 36px; height: 36px; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: #94a3b8; }
        nav .hamburger svg { width: 22px; height: 22px; }
        nav .links.mobile-open { display: flex; }
        .nav-overlay { display: none; position: fixed; inset: 0; z-index: 99; background: rgba(0,0,0,.5); }

        .hero { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 120px 20px 80px; position: relative; overflow: hidden; }
        .hero::before { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 600px; height: 600px; border-radius: 50%; background: radial-gradient(circle, rgba(99,102,241,.12) 0%, transparent 60%); pointer-events: none; }
        .hero .gradient-text { font-size: clamp(36px, 6vw, 64px); font-weight: 800; line-height: 1.15; margin-bottom: 24px; background: linear-gradient(135deg, #e2e8f0 30%, #6366f1 70%, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .hero p { font-size: clamp(16px, 2vw, 20px); color: #94a3b8; max-width: 600px; margin-bottom: 40px; }
        .hero .cta-group { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; }
        .hero .cta { padding: 14px 32px; border-radius: 12px; font-size: 16px; font-weight: 700; text-decoration: none; transition: all .2s; }
        .hero .cta-primary { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; box-shadow: 0 4px 20px rgba(99,102,241,.4); }
        .hero .cta-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 28px rgba(99,102,241,.5); }
        .hero .cta-ghost { border: 1.5px solid rgba(255,255,255,.15); color: #e2e8f0; }
        .hero .cta-ghost:hover { border-color: rgba(255,255,255,.35); background: rgba(255,255,255,.04); }

        .orb { position: absolute; border-radius: 50%; pointer-events: none; filter: blur(80px); animation: float 8s ease-in-out infinite; }
        .orb-1 { width: 300px; height: 300px; background: rgba(99,102,241,.12); top: 10%; left: 5%; animation-delay: 0s; }
        .orb-2 { width: 250px; height: 250px; background: rgba(139,92,246,.1); top: 50%; right: 5%; animation-delay: -3s; }
        .orb-3 { width: 200px; height: 200px; background: rgba(59,130,246,.1); bottom: 10%; left: 30%; animation-delay: -6s; }
        @keyframes float { 0%,100%{transform:translateY(0) scale(1)} 50%{transform:translateY(-30px) scale(1.05)} }

        section { padding: 80px 20px; max-width: 1200px; margin: 0 auto; }
        .section-title { text-align: center; font-size: clamp(24px, 3vw, 36px); font-weight: 800; margin-bottom: 12px; color: #e2e8f0; }
        .section-subtitle { text-align: center; color: #64748b; font-size: 16px; margin-bottom: 60px; }

        .features { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
        .feature-card { padding: 32px; border-radius: 16px; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06); transition: all .25s; }
        .feature-card:hover { background: rgba(255,255,255,.06); border-color: rgba(99,102,241,.3); transform: translateY(-4px); }
        .feature-card .icon { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 24px; margin-bottom: 16px; background: rgba(99,102,241,.1); }
        .feature-card h3 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .feature-card p { color: #94a3b8; font-size: 14px; line-height: 1.7; }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px; margin-top: 40px; }
        .stat-card { text-align: center; padding: 32px 16px; border-radius: 16px; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06); }
        .stat-card .num { font-size: 40px; font-weight: 800; background: linear-gradient(135deg, #6366f1, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .stat-card .lbl { color: #94a3b8; font-size: 14px; margin-top: 4px; }

        .cta-section { text-align: center; padding: 80px 20px; background: linear-gradient(180deg, transparent 0%, rgba(99,102,241,.03) 50%, transparent 100%); }
        .cta-section h2 { font-size: clamp(28px, 3.5vw, 42px); font-weight: 800; margin-bottom: 16px; }
        .cta-section p { color: #94a3b8; font-size: 16px; margin-bottom: 32px; }

        footer { text-align: center; padding: 40px 20px; border-top: 1px solid rgba(255,255,255,.06); color: #475569; font-size: 13px; }

        @media (max-width: 768px) {
            nav { padding: 0 16px; }
            nav .links { position: fixed; top: 64px; left: 0; right: 0; bottom: 0; flex-direction: column; gap: 0; background: rgba(10,10,15,.98); backdrop-filter: blur(16px); padding: 20px; display: none; overflow-y: auto; }
            nav .links.mobile-open { display: flex; }
            nav .links a { padding: 16px 12px; font-size: 16px; border-bottom: 1px solid rgba(255,255,255,.06); width: 100%; }
            nav .links .btn { margin-top: 12px; text-align: center; justify-content: center; }
            nav .hamburger { display: flex; }
            .nav-overlay.is-visible { display: block; }
            .hero { padding: 100px 16px 60px; }
            .hero .gradient-text { font-size: clamp(28px, 8vw, 36px); }
            .hero p { font-size: 15px; max-width: 90vw; }
            .orb-1 { width: 150px; height: 150px; top: 5%; left: -10%; }
            .orb-2 { width: 120px; height: 120px; top: 60%; right: -10%; }
            .orb-3 { width: 100px; height: 100px; bottom: 5%; left: 20%; }
            section { padding: 60px 16px; }
            .features { grid-template-columns: 1fr; gap: 16px; }
            .stats { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 24px 12px; }
            .stat-card .num { font-size: 28px; }
            .cta-section { padding: 60px 16px; }
            .cta-section h2 { font-size: clamp(24px, 7vw, 30px); }
        }
    </style>
</head>
<body>

<nav>
    <a href="/" class="logo"><span class="gradient"><?= e($platformName) ?></span></a>
    <button class="hamburger" id="menuBtn" aria-label="菜单">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="links" id="navLinks">
        <a href="#features">功能</a>
        <a href="/gallery">图片广场</a>
        <?php if ($user): ?>
        <a href="/user/index" class="btn btn-primary">进入工作台</a>
        <?php else: ?>
        <a href="/login" class="btn btn-outline">登录</a>
        <a href="/login#register" class="btn btn-primary">免费注册</a>
        <?php endif; ?>
    </div>
</nav>
<div class="nav-overlay" id="navOverlay"></div>

<div class="hero">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <h1 class="gradient-text">AI 驱动的<br>图片视频创作平台</h1>
    <p>内置 GPT-image、DALL·E 等主流模型，无需梯子，即开即用。轻松像生成各种图像务。</p>
    <div class="cta-group">
        <?php if ($user): ?>
        <a href="/user/index" class="cta cta-primary">进入工作台</a>
        <?php else: ?>
        <a href="/login" class="cta cta-primary">免费开始使用</a>
        <a href="#features" class="cta cta-ghost">了解更多</a>
        <?php endif; ?>
    </div>
</div>

<section id="features">
    <h2 class="section-title">核心功能</h2>
    <p class="section-subtitle">一站式的 AI 创作体验</p>
    <div class="features">
        <div class="feature-card">
            <div class="icon">🎨</div>
            <h3>AI 图片生成</h3>
            <p>支持 GPT-image-2、DALL·E 等主流模型，文生图 / 图生图 / 图片编辑多模式切换。</p>
        </div>
        <div class="feature-card">
            <div class="icon">🎬</div>
            <h3>AI 视频生成</h3>
            <p>文本描述即可生成短视频，支持多种视频模型，创作从未如此简单。</p>
        </div>
        <div class="feature-card">
            <div class="icon">💬</div>
            <h3>AI 智能对话</h3>
            <p>多轮上下文对话，支持自定义模型，每次对话消耗固定 <?= e($balanceLabel) ?>。</p>
        </div>
        <div class="feature-card">
            <div class="icon">🔌</div>
            <h3>开放 API</h3>
            <p>提供标准 OpenAI 兼容接口，轻松对接你的应用，支持 API 令牌管理。</p>
        </div>
        <div class="feature-card">
            <div class="icon">🛡️</div>
            <h3>安全可靠</h3>
            <p>极验 V4 人机验证、邮箱验证、CSRF 防护，全方位保障账户安全。</p>
        </div>
        <div class="feature-card">
            <div class="icon">💰</div>
            <h3><?= e($balanceLabel) ?>商城</h3>
            <p>在线购买<?= e($balanceLabel) ?>套餐，彩虹易支付集成，支持支付宝/微信等多种支付方式。</p>
        </div>
    </div>
</section>

<section>
    <div class="stats">
        <div class="stat-card"><div class="num">GPT-image</div><div class="lbl">图片模型</div></div>
        <div class="stat-card"><div class="num">多模态</div><div class="lbl">视频 + 图片</div></div>
        <div class="stat-card"><div class="num">API</div><div class="lbl">开放接口</div></div>
        <div class="stat-card"><div class="num">24/7</div><div class="lbl">服务运行</div></div>
    </div>
</section>

<div class="cta-section">
    <h2>开始你的创作之旅</h2>
    <p>注册即送 <?= e($balanceLabel) ?>，立即体验 AI 创作的无限可能。</p>
    <?php if ($user): ?>
    <a href="/user/index" class="cta cta-primary">进入工作台</a>
    <?php else: ?>
    <a href="/login#register" class="cta cta-primary">免费注册</a>
    <?php endif; ?>
</div>

<footer>
    <?= e($platformName) ?> &copy; <?= date('Y') ?> · AI 图片视频创作平台
</footer>

<script>
(function() {
    var btn = document.getElementById('menuBtn');
    var links = document.getElementById('navLinks');
    var overlay = document.getElementById('navOverlay');
    if (!btn || !links) return;
    function open() { links.classList.add('mobile-open'); overlay.classList.add('is-visible'); }
    function close() { links.classList.remove('mobile-open'); overlay.classList.remove('is-visible'); }
    btn.addEventListener('click', function(e) { e.stopPropagation(); links.classList.contains('mobile-open') ? close() : open(); });
    overlay.addEventListener('click', close);
    links.querySelectorAll('a').forEach(function(a) { a.addEventListener('click', close); });
})();
</script>

</body>
</html>
