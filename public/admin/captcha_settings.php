<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/captcha.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    set_app_setting('captcha_id', trim((string) ($_POST['captcha_id'] ?? '')));
    set_app_setting('captcha_key', trim((string) ($_POST['captcha_key'] ?? '')));
    set_app_setting('captcha_enabled', (string) ($_POST['captcha_enabled'] ?? 'off'));
    flash('success', '验证码配置已保存。');
    redirect('/admin/captcha_settings');
}

$captchaId = app_setting('captcha_id', '');
$captchaKey = app_setting('captcha_key', '');
$captchaEnabled = app_setting('captcha_enabled', 'off');
$isOn = $captchaEnabled === 'on';
$hasConfig = $captchaId !== '' && $captchaKey !== '';

render_header('验证码配置', 'admin');
render_admin_nav('captcha_settings');
?>
<style>
.captcha-page { max-width: 700px; margin: 0 auto; }

.captcha-status {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 20px; border-radius: 14px; margin-bottom: 24px;
    font-size: 14px; font-weight: 600;
}
.captcha-status.on { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.captcha-status.off { background: #f3f4f6; border: 1px solid #e5e7eb; color: #6b7280; }
.captcha-status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.captcha-status.on .captcha-status-dot { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.captcha-status.off .captcha-status-dot { background: #d1d5db; }

.captcha-section {
    background: var(--card-bg); border: 1px solid var(--line);
    border-radius: 16px; margin-bottom: 20px; overflow: hidden;
}
.captcha-section-header {
    display: flex; align-items: center; gap: 12px;
    padding: 18px 24px; border-bottom: 1px solid var(--line);
    background: var(--main-surface-soft);
}
.captcha-section-header .icon {
    display: flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 10px; font-size: 16px; flex-shrink: 0;
}
.icon-green { background: #d1fae5; color: #059669; }
.icon-blue  { background: #dbeafe; color: #2563eb; }
.icon-amber { background: #fef3c7; color: #d97706; }
.captcha-section-header h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--text); }
.captcha-section-header p { margin: 2px 0 0; font-size: 12px; color: var(--text-muted); }
.captcha-section-body { padding: 24px; }

/* toggle */
.captcha-toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; padding: 16px;
    background: var(--main-surface-soft); border-radius: 12px; margin-bottom: 16px;
}
.captcha-toggle-row .left h4 { font-size: 14px; font-weight: 700; margin: 0; color: var(--text); }
.captcha-toggle-row .left p { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }

.captcha-toggle {
    display: inline-flex; align-items: center; gap: 8px;
    cursor: pointer; user-select: none; flex-shrink: 0;
}
.captcha-toggle input {
    position: relative; width: 42px; height: 24px;
    appearance: none; -webkit-appearance: none;
    border-radius: 12px; background: #d1d5db;
    cursor: pointer; transition: background .25s;
}
.captcha-toggle input::after {
    content: ''; position: absolute; top: 2px; left: 2px;
    width: 20px; height: 20px; border-radius: 50%;
    background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.15);
    transition: transform .25s;
}
.captcha-toggle input:checked { background: #10b981; }
.captcha-toggle input:checked::after { transform: translateX(18px); }
.captcha-toggle-label { font-size: 12px; font-weight: 600; color: var(--text-muted); min-width: 32px; }

/* fields */
.captcha-field { margin-bottom: 18px; }
.captcha-field:last-child { margin-bottom: 0; }
.captcha-field label {
    display: block; font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 6px;
}
.captcha-field input {
    width: 100%; padding: 10px 14px; font-size: 14px;
    border: 1px solid var(--line); border-radius: 10px;
    background: var(--main-surface); color: var(--text);
    box-sizing: border-box; font-family: 'SF Mono','Consolas',monospace;
    transition: border-color .2s, box-shadow .2s;
}
.captcha-field input:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.captcha-field .hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.6; }

.captcha-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
    margin-bottom: 18px;
}
.captcha-grid:last-child { margin-bottom: 0; }
@media (max-width: 640px) { .captcha-grid { grid-template-columns: 1fr; } }
.captcha-grid .captcha-field { margin-bottom: 0; }

/* guide */
.captcha-guide {
    padding: 16px 20px; background: var(--main-surface-soft);
    border-radius: 12px; border: 1px solid var(--line);
    font-size: 13px; color: var(--text-soft); line-height: 2;
    counter-reset: step;
}
.captcha-guide li { list-style: none; }
.captcha-guide li::before {
    counter-increment: step; content: counter(step);
    display: inline-flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; border-radius: 50%;
    background: var(--primary); color: #fff;
    font-size: 11px; font-weight: 700; margin-right: 8px;
}
.captcha-guide a { color: var(--primary); font-weight: 600; }

.captcha-submit { display: flex; justify-content: flex-end; padding-top: 4px; }
.captcha-btn {
    padding: 12px 32px; font-size: 15px; font-weight: 700;
    background: var(--primary); color: #fff; border: none;
    border-radius: 12px; cursor: pointer; transition: opacity .15s, transform .15s;
}
.captcha-btn:hover { opacity: .9; transform: translateY(-1px); }
</style>

<main class="captcha-page">
    <div class="captcha-status <?= $isOn ? 'on' : 'off' ?>">
        <span class="captcha-status-dot"></span>
        <span><?= $isOn ? '验证码已启用' : '验证码已关闭' ?></span>
        <?php if ($isOn && $hasConfig): ?>
        <span style="font-weight:400;opacity:.8;">— 登录、注册和找回密码需通过极验 V4 验证</span>
        <?php elseif ($isOn): ?>
        <span style="font-weight:400;opacity:.8;">— ⚠️ 已启用但 ID/Key 未配置</span>
        <?php endif; ?>
    </div>

    <form method="post">
        <?= csrf_field() ?>

        <!-- 启用开关 -->
        <div class="captcha-section">
            <div class="captcha-section-header">
                <div class="icon icon-green">🛡️</div>
                <div>
                    <h3>启用状态</h3>
                    <p>控制验证码功能的全局开关</p>
                </div>
            </div>
            <div class="captcha-section-body">
                <div class="captcha-toggle-row">
                    <div class="left">
                        <h4>启用极验验证码</h4>
                        <p>开启后登录、注册、找回密码页面需通过极验 V4 人机验证。</p>
                    </div>
                    <label class="captcha-toggle">
                        <input type="checkbox" name="captcha_enabled" value="on" <?= $isOn ? 'checked' : '' ?>
                            onchange="this.nextElementSibling.textContent=this.checked?'启用':'关闭';var s=document.querySelector('.captcha-status');s.className='captcha-status '+(this.checked?'on':'off')">
                        <span class="captcha-toggle-label"><?= $isOn ? '启用' : '关闭' ?></span>
                    </label>
                </div>
            </div>
        </div>

        <!-- 接口配置 -->
        <div class="captcha-section">
            <div class="captcha-section-header">
                <div class="icon icon-blue">🔑</div>
                <div>
                    <h3>接口配置</h3>
                    <p>极验 V4 应用的凭证信息</p>
                </div>
            </div>
            <div class="captcha-section-body">
                <div class="captcha-grid">
                    <div class="captcha-field">
                        <label>captcha_id</label>
                        <input name="captcha_id" value="<?= e($captchaId) ?>" placeholder="极验 V4 的 captcha_id">
                    </div>
                    <div class="captcha-field">
                        <label>captcha_key</label>
                        <input name="captcha_key" value="<?= e($captchaKey) ?>" placeholder="极验 V4 的 captcha_key">
                    </div>
                </div>
            </div>
        </div>

        <!-- 接入指引 -->
        <div class="captcha-section">
            <div class="captcha-section-header">
                <div class="icon icon-amber">📖</div>
                <div>
                    <h3>接入指引</h3>
                    <p>如何获取极验 V4 的配置信息</p>
                </div>
            </div>
            <div class="captcha-section-body">
                <ol class="captcha-guide">
                    <li>前往 <a href="https://www.geetest.com/" target="_blank" rel="noopener">极验官网</a> 注册账号</li>
                    <li>创建应用，选择「<strong>行为验测 V4</strong>」</li>
                    <li>获取应用的 <strong>captcha_id</strong> 和 <strong>captcha_key</strong></li>
                    <li>填入上方配置并保存</li>
                    <li>将「启用状态」设为「启用」即可生效</li>
                </ol>
            </div>
        </div>

        <div class="captcha-submit">
            <button type="submit" class="captcha-btn">💾 保存配置</button>
        </div>
    </form>
</main>
<?php render_footer(); ?>
