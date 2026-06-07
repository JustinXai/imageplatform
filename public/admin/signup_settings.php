<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    set_app_setting('signup_bonus_enabled', (string) ($_POST['signup_bonus_enabled'] ?? 'off'));
    set_app_setting('signup_bonus_credits', (string) max(0, (int) ($_POST['signup_bonus_credits'] ?? 0)));
    flash('success', '注册赠送配置已保存。');
    redirect('/admin/signup_settings');
}

$bonusEnabled = app_setting('signup_bonus_enabled', 'off');
$bonusCredits = (int) app_setting('signup_bonus_credits', '10');
$isOn = $bonusEnabled === 'on';
$balanceLabel = balance_label();

render_header('注册赠送配置', 'admin');
render_admin_nav('signup_settings');
?>
<style>
.signup-page { max-width: 700px; margin: 0 auto; }

/* status bar */
.signup-status {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 20px;
    border-radius: 14px;
    margin-bottom: 24px;
    font-size: 14px; font-weight: 600;
}
.signup-status.on {
    background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;
}
.signup-status.off {
    background: #f3f4f6; border: 1px solid #e5e7eb; color: #6b7280;
}
.signup-status-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.signup-status.on .signup-status-dot { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.signup-status.off .signup-status-dot { background: #d1d5db; }

/* section card */
.signup-section {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    margin-bottom: 20px;
    overflow: hidden;
}
.signup-section-header {
    display: flex; align-items: center; gap: 12px;
    padding: 18px 24px;
    border-bottom: 1px solid var(--line);
    background: var(--main-surface-soft);
}
.signup-section-header .icon {
    display: flex; align-items: center; justify-content: center;
    width: 34px; height: 34px;
    border-radius: 10px; font-size: 16px; flex-shrink: 0;
}
.icon-green { background: #d1fae5; color: #059669; }
.icon-blue  { background: #dbeafe; color: #2563eb; }
.icon-amber { background: #fef3c7; color: #d97706; }
.signup-section-header h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--text); }
.signup-section-header p { margin: 2px 0 0; font-size: 12px; color: var(--text-muted); }

.signup-section-body { padding: 24px; }

.signup-field { margin-bottom: 18px; }
.signup-field:last-child { margin-bottom: 0; }
.signup-field label {
    display: block; font-size: 13px; font-weight: 600;
    color: var(--text); margin-bottom: 6px;
}
.signup-field input {
    width: 100%; max-width: 200px;
    padding: 10px 14px; font-size: 16px; font-weight: 700;
    border: 1px solid var(--line); border-radius: 10px;
    background: var(--main-surface); color: var(--text);
    transition: border-color .2s, box-shadow .2s;
}
.signup-field input:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.signup-field .hint {
    font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.6;
}

/* toggle row */
.signup-toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px;
    padding: 16px;
    background: var(--main-surface-soft);
    border-radius: 12px;
    margin-bottom: 16px;
}
.signup-toggle-row .left h4 { font-size: 14px; font-weight: 700; margin: 0; color: var(--text); }
.signup-toggle-row .left p { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }

/* toggle switch */
.signup-toggle {
    display: inline-flex; align-items: center; gap: 8px;
    cursor: pointer; user-select: none; flex-shrink: 0;
}
.signup-toggle input {
    position: relative; width: 42px; height: 24px;
    appearance: none; -webkit-appearance: none;
    border-radius: 12px; background: #d1d5db;
    cursor: pointer; transition: background .25s;
}
.signup-toggle input::after {
    content: ''; position: absolute; top: 2px; left: 2px;
    width: 20px; height: 20px; border-radius: 50%;
    background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.15);
    transition: transform .25s;
}
.signup-toggle input:checked { background: #10b981; }
.signup-toggle input:checked::after { transform: translateX(18px); }
.signup-toggle-label {
    font-size: 12px; font-weight: 600; color: var(--text-muted); min-width: 32px;
}

/* preview */
.signup-preview {
    padding: 20px 24px;
    border-radius: 12px;
    border: 1px solid var(--line);
    text-align: center;
    font-size: 14px; line-height: 1.7;
}
.signup-preview.on { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.signup-preview.off { background: var(--main-surface-soft); color: var(--text-muted); }
.signup-preview .big { font-size: 20px; margin-bottom: 6px; }
.signup-preview strong { color: var(--primary); font-size: 18px; }

/* submit */
.signup-submit {
    display: flex; justify-content: flex-end; padding-top: 4px;
}
.signup-btn {
    padding: 12px 32px; font-size: 15px; font-weight: 700;
    background: var(--primary); color: #fff;
    border: none; border-radius: 12px; cursor: pointer;
    transition: opacity .15s, transform .15s;
}
.signup-btn:hover { opacity: .9; transform: translateY(-1px); }
</style>

<main class="signup-page">
    <!-- 状态 -->
    <div class="signup-status <?= $isOn ? 'on' : 'off' ?>">
        <span class="signup-status-dot"></span>
        <span><?= $isOn ? '注册赠送已开启' : '注册赠送已关闭' ?></span>
        <?php if ($isOn && $bonusCredits > 0): ?>
        <span style="font-weight:400;opacity:.8;">— 新用户注册即送 <strong><?= number_format($bonusCredits) ?></strong> <?= e($balanceLabel) ?></span>
        <?php endif; ?>
    </div>

    <form method="post">
        <?= csrf_field() ?>

        <!-- 开关 -->
        <div class="signup-section">
            <div class="signup-section-header">
                <div class="icon icon-green">🎁</div>
                <div>
                    <h3>赠送开关</h3>
                    <p>开启后新用户注册自动赠送<?= e($balanceLabel) ?></p>
                </div>
            </div>
            <div class="signup-section-body">
                <div class="signup-toggle-row">
                    <div class="left">
                        <h4>启用注册赠送</h4>
                        <p>新用户注册时自动获得<?= e($balanceLabel) ?>奖励。</p>
                    </div>
                    <label class="signup-toggle">
                        <input type="checkbox" name="signup_bonus_enabled" value="on" <?= $isOn ? 'checked' : '' ?>
                            onchange="this.nextElementSibling.textContent=this.checked?'开启':'关闭';var s=document.querySelector('.signup-status');s.className='signup-status '+(this.checked?'on':'off');var dot=s.querySelector('.signup-status-dot');var p=document.querySelector('.signup-preview');if(p){p.className='signup-preview '+(this.checked?'on':'off');}">
                        <span class="signup-toggle-label"><?= $isOn ? '开启' : '关闭' ?></span>
                    </label>
                </div>
            </div>
        </div>

        <!-- 数量 -->
        <div class="signup-section">
            <div class="signup-section-header">
                <div class="icon icon-blue">🔢</div>
                <div>
                    <h3>赠送数量</h3>
                    <p>每位新用户注册时赠送的<?= e($balanceLabel) ?>点数</p>
                </div>
            </div>
            <div class="signup-section-body">
                <div class="signup-field">
                    <label>赠送 <?= e($balanceLabel) ?> 数量</label>
                    <input name="signup_bonus_credits" type="number" min="0" value="<?= $bonusCredits ?>" required>
                    <div class="hint">设为 0 则即使开启也不会赠送。</div>
                </div>
            </div>
        </div>

        <!-- 预览 -->
        <div class="signup-section">
            <div class="signup-section-header">
                <div class="icon icon-amber">👁️</div>
                <div>
                    <h3>效果预览</h3>
                    <p>当前配置下的实际效果</p>
                </div>
            </div>
            <div class="signup-section-body">
                <div class="signup-preview <?= $isOn ? 'on' : 'off' ?>">
                    <?php if ($isOn && $bonusCredits > 0): ?>
                        <div class="big">🎉</div>
                        新用户注册后将自动获得<br>
                        <strong><?= number_format($bonusCredits) ?> <?= e($balanceLabel) ?></strong>
                        <br><span style="font-size:12px;">可在商城 / AI对话 / 图片生成中使用</span>
                    <?php elseif ($isOn): ?>
                        ⚠️ 已开启但数量为 0，实际不会赠送
                    <?php else: ?>
                        🔒 注册赠送功能已关闭<br><span style="font-size:12px;">新用户注册无额外奖励</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="signup-submit">
            <button type="submit" class="signup-btn">💾 保存配置</button>
        </div>
    </form>
</main>
<?php render_footer(); ?>
