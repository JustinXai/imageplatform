<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$admin = require_admin();

$pages = [
    'login'    => ['title' => '登录页',   'icon' => '🔐'],
    'register' => ['title' => '注册页',   'icon' => '📝'],
    'forgot'   => ['title' => '找回密码页', 'icon' => '🔑'],
    'index'    => ['title' => '首页（生成页）', 'icon' => '🏠'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($pages as $key => $info) {
        set_app_setting("page_notice_{$key}_enabled", (string) ($_POST["{$key}_enabled"] ?? 'off'));
        set_app_setting("page_notice_{$key}_content", trim(strip_tags((string) ($_POST["{$key}_content"] ?? ''), '<b><i><u><s><a><br><p><span><strong><em><ol><ul><li><h1><h2><h3><h4><h5><h6><img><blockquote><pre><code><hr><div><table><thead><tbody><tr><th><td>')));
    }
    flash('success', '公告配置已保存。');
    redirect('/admin/page_notices');
}

render_header('页面公告', 'admin');
render_admin_nav('page_notices');
?>
<style>
.notice-page { max-width: 800px; margin: 0 auto; }

.notice-card {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    margin-bottom: 20px;
    overflow: hidden;
    transition: border-color .2s;
}
.notice-card.enabled { border-color: #a7f3d0; }

.notice-card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 24px;
    border-bottom: 1px solid var(--line);
    background: var(--main-surface-soft);
    gap: 14px;
}
.notice-card.enabled .notice-card-header { background: #f0fdf4; border-color: #bbf7d0; }

.notice-card-left {
    display: flex; align-items: center; gap: 12px;
    min-width: 0;
}
.notice-card-icon {
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    border-radius: 10px;
    font-size: 18px;
    flex-shrink: 0;
    background: #f3f4f6;
}
.notice-card.enabled .notice-card-icon { background: #d1fae5; }

.notice-card-info h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--text); }
.notice-card-info p { margin: 2px 0 0; font-size: 12px; color: var(--text-muted); }

/* toggle */
.notice-toggle {
    display: inline-flex; align-items: center; gap: 8px;
    cursor: pointer; user-select: none; flex-shrink: 0;
}
.notice-toggle input {
    position: relative;
    width: 42px; height: 24px;
    appearance: none; -webkit-appearance: none;
    border-radius: 12px;
    background: #d1d5db;
    cursor: pointer;
    transition: background .25s;
}
.notice-toggle input::after {
    content: '';
    position: absolute; top: 2px; left: 2px;
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.15);
    transition: transform .25s;
}
.notice-toggle input:checked { background: #10b981; }
.notice-toggle input:checked::after { transform: translateX(18px); }
.notice-toggle-label {
    font-size: 12px; font-weight: 600;
    color: var(--text-muted);
    min-width: 32px;
}

.notice-card-body { padding: 24px; }
.notice-card-body textarea {
    width: 100%;
    padding: 12px 14px;
    font-size: 14px;
    font-family: 'SF Mono', 'Cascadia Code', 'Consolas', monospace;
    line-height: 1.6;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--main-surface);
    color: var(--text);
    resize: vertical;
    min-height: 100px;
    box-sizing: border-box;
    transition: border-color .2s, box-shadow .2s;
}
.notice-card-body textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.notice-card-body .hint {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 6px;
    line-height: 1.6;
}
.notice-card-body .hint code {
    background: var(--main-surface-soft);
    padding: 1px 5px;
    border-radius: 4px;
    border: 1px solid var(--line);
    font-size: 11px;
}

/* submit */
.notice-submit {
    display: flex; justify-content: flex-end;
    padding-top: 4px;
}
.notice-btn {
    padding: 12px 32px;
    font-size: 15px; font-weight: 700;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: opacity .15s, transform .15s;
}
.notice-btn:hover { opacity: .9; transform: translateY(-1px); }

/* empty hint */
.notice-empty { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.notice-empty-icon { font-size: 40px; margin-bottom: 12px; }
</style>

<main class="notice-page">
    <form method="post">
        <?= csrf_field() ?>

        <?php foreach ($pages as $key => $info):
            $enabled = app_setting("page_notice_{$key}_enabled", 'off');
            $content = (string) app_setting("page_notice_{$key}_content", '');
            $isOn = $enabled === 'on';
        ?>
        <div class="notice-card<?= $isOn ? ' enabled' : '' ?>" data-notice-card>
            <div class="notice-card-header">
                <div class="notice-card-left">
                    <div class="notice-card-icon"><?= e($info['icon']) ?></div>
                    <div class="notice-card-info">
                        <h3><?= e($info['title']) ?></h3>
                        <p><?= $isOn ? '已开启' : '已关闭' ?> — 用户打开此页面时显示弹窗</p>
                    </div>
                </div>
                <label class="notice-toggle" data-notice-toggle>
                    <input type="checkbox" name="<?= e($key) ?>_enabled" value="on" <?= $isOn ? 'checked' : '' ?>
                        onchange="var card=this.closest('[data-notice-card]');var lbl=this.nextElementSibling;var headP=card.querySelector('.notice-card-info p');card.classList.toggle('enabled',this.checked);lbl.textContent=this.checked?'开启':'关闭';headP.textContent=(this.checked?'已开启':'已关闭')+' — 用户打开此页面时显示弹窗'">
                    <span class="notice-toggle-label"><?= $isOn ? '开启' : '关闭' ?></span>
                </label>
            </div>
            <div class="notice-card-body">
                <textarea name="<?= e($key) ?>_content" rows="4"
                    placeholder="输入公告内容，支持 &lt;br&gt; &lt;b&gt; 等 HTML 标签"><?= e($content) ?></textarea>
                <div class="hint">
                    💡 支持标签：<code>&lt;b&gt;</code> <code>&lt;i&gt;</code> <code>&lt;br&gt;</code> <code>&lt;a&gt;</code> <code>&lt;p&gt;</code> <code>&lt;img&gt;</code> <code>&lt;table&gt;</code> 等
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="notice-submit">
            <button type="submit" class="notice-btn">💾 保存全部配置</button>
        </div>
    </form>
</main>

<?php render_footer(); ?>
