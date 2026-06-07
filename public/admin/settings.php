<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $platformName = trim((string) ($_POST['platform_name'] ?? ''));
    $balanceLabel = trim((string) ($_POST['balance_label'] ?? '余额'));
    $maxEditImages = max(1, min(16, (int) ($_POST['max_edit_images'] ?? 4)));
    $maxEditImageMb = max(1, min(50, (int) ($_POST['max_edit_image_mb'] ?? 10)));
    $maxEditImageDimension = max(512, min(12000, (int) ($_POST['max_edit_image_dimension'] ?? 8000)));
    $imageStorageMode = (string) ($_POST['image_storage_mode'] ?? 'file');
    $generationNotice = trim((string) ($_POST['generation_notice'] ?? ''));
    $videoNotice = trim((string) ($_POST['video_notice'] ?? ''));

    if ($platformName === '') { flash('error', '网站名称不能为空。'); redirect('/admin/settings'); }
    if ($balanceLabel === '') { flash('error', '余额名称不能为空。'); redirect('/admin/settings'); }
    if (!in_array($imageStorageMode, ['file', 'base64'], true)) { flash('error', '图片存储方式不合法。'); redirect('/admin/settings'); }

    set_app_setting('platform_name', $platformName);
    set_app_setting('balance_label', $balanceLabel);
    set_app_setting('max_edit_images', (string) $maxEditImages);
    set_app_setting('max_edit_image_mb', (string) $maxEditImageMb);
    set_app_setting('max_edit_image_dimension', (string) $maxEditImageDimension);
    set_app_setting('image_storage_mode', $imageStorageMode);
    set_app_setting('generation_notice', $generationNotice);
    set_app_setting('video_notice', $videoNotice);

    $imageGeneratePath = trim((string) ($_POST['image_generate_path'] ?? ''));
    $imageEditPath = trim((string) ($_POST['image_edit_path'] ?? ''));
    if ($imageGeneratePath !== '') set_app_setting('image_generate_path', $imageGeneratePath);
    if ($imageEditPath !== '') set_app_setting('image_edit_path', $imageEditPath);
    $videoGeneratePath = trim((string) ($_POST['video_generate_path'] ?? ''));
    if ($videoGeneratePath !== '') set_app_setting('video_generate_path', $videoGeneratePath);

    $promptOptimizeEnabled = (string) (int) (!empty($_POST['prompt_optimize_enabled']));
    $promptOptimizeBaseUrl = rtrim(trim((string) ($_POST['prompt_optimize_base_url'] ?? '')), '/');
    $promptOptimizeApiKey = trim((string) ($_POST['prompt_optimize_api_key'] ?? ''));
    $promptOptimizeModel = trim((string) ($_POST['prompt_optimize_model'] ?? ''));
    $promptOptimizeSystemPrompt = trim((string) ($_POST['prompt_optimize_system_prompt'] ?? ''));
    set_app_setting('prompt_optimize_enabled', $promptOptimizeEnabled);
    if ($promptOptimizeBaseUrl !== '') set_app_setting('prompt_optimize_base_url', $promptOptimizeBaseUrl);
    if ($promptOptimizeApiKey !== '') set_app_setting('prompt_optimize_api_key', $promptOptimizeApiKey);
    if ($promptOptimizeModel !== '') set_app_setting('prompt_optimize_model', $promptOptimizeModel);
    if ($promptOptimizeSystemPrompt !== '') set_app_setting('prompt_optimize_system_prompt', $promptOptimizeSystemPrompt);

    $inviteEnabled = !empty($_POST['invite_enabled']) ? 'on' : 'off';
    set_app_setting('invite_enabled', $inviteEnabled);
    set_app_setting('invite_commission_percent', (string) max(0, min(100, (int) ($_POST['invite_commission_percent'] ?? '10'))));
    set_app_setting('invite_bonus_credits', (string) max(0, (int) ($_POST['invite_bonus_credits'] ?? '0')));

    flash('success', '系统配置已保存。');
    redirect('/admin/settings');
}

$platformName = app_setting('platform_name', trim((string) config('generation.platform_name', '')) ?: 'AI 图片视频创作系统');
$balanceLabel = app_setting('balance_label', '余额');
$maxEditImages = app_setting('max_edit_images', '4');
$maxEditImageMb = app_setting('max_edit_image_mb', '10');
$maxEditImageDimension = app_setting('max_edit_image_dimension', '8000');
$imageStorageMode = app_setting('image_storage_mode', 'file');
$generationNotice = app_setting('generation_notice', '');
$videoNotice = app_setting('video_notice', '');
$imageGeneratePath = app_setting('image_generate_path', '');
$imageEditPath = app_setting('image_edit_path', '');
$videoGeneratePath = app_setting('video_generate_path', '');

$promptOptimizeEnabled = (bool) app_setting('prompt_optimize_enabled', '0');
$promptOptimizeBaseUrl = app_setting('prompt_optimize_base_url', '');
$promptOptimizeApiKey = app_setting('prompt_optimize_api_key', '');
$promptOptimizeModel = app_setting('prompt_optimize_model', 'gpt-4o-mini');
$promptOptimizeSystemPrompt = app_setting('prompt_optimize_system_prompt', '');

$inviteEnabled = app_setting('invite_enabled', 'off') === 'on';
$inviteCommissionPercent = (int) app_setting('invite_commission_percent', '10');
$inviteBonusCredits = (int) app_setting('invite_bonus_credits', '0');

render_header('系统配置', 'admin');
render_admin_nav('settings');
?>
<style>
.settings-page { max-width: 860px; margin: 0 auto; }

/* section card */
.settings-section {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    margin-bottom: 20px;
    overflow: hidden;
}
.settings-section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--line);
    background: var(--main-surface-soft);
}
.settings-section-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px; height: 36px;
    border-radius: 10px;
    font-size: 18px;
    flex-shrink: 0;
}
.settings-section-icon.blue  { background: #dbeafe; color: #2563eb; }
.settings-section-icon.green { background: #d1fae5; color: #059669; }
.settings-section-icon.purple { background: #ede9fe; color: #7c3aed; }
.settings-section-icon.amber { background: #fef3c7; color: #d97706; }
.settings-section-header .info h3 { font-size: 15px; font-weight: 700; margin: 0; color: var(--text); }
.settings-section-header .info p { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }

.settings-section-body {
    padding: 24px;
}

/* field rows */
.settings-field {
    margin-bottom: 18px;
}
.settings-field:last-child { margin-bottom: 0; }
.settings-field label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
}
.settings-field input,
.settings-field select,
.settings-field textarea {
    width: 100%;
    padding: 10px 14px;
    font-size: 14px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--main-surface);
    color: var(--text);
    box-sizing: border-box;
    transition: border-color .2s, box-shadow .2s;
}
.settings-field input:focus,
.settings-field select:focus,
.settings-field textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.settings-field textarea { resize: vertical; min-height: 60px; }
.settings-field select { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
.settings-field .hint {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
    line-height: 1.5;
}
.settings-field .hint code {
    font-size: 12px;
    background: var(--main-surface-soft);
    padding: 1px 6px;
    border-radius: 4px;
    border: 1px solid var(--line);
}

/* field grid */
.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 18px;
}
.settings-grid:last-child { margin-bottom: 0; }
@media (max-width: 640px) { .settings-grid { grid-template-columns: 1fr; } }
.settings-grid .settings-field { margin-bottom: 0; }

/* switch row */
.settings-switch-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px;
    background: var(--main-surface-soft);
    border-radius: 12px;
    margin-bottom: 16px;
}
.settings-switch-row .left h4 { font-size: 14px; font-weight: 700; margin: 0; color: var(--text); }
.settings-switch-row .left p { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }

/* divider */
.settings-divider {
    border: none;
    border-top: 1px solid var(--line);
    margin: 20px 0;
}

/* submit */
.settings-submit {
    display: flex;
    justify-content: flex-end;
    padding-top: 8px;
}
.settings-submit .btn-save {
    padding: 12px 32px;
    font-size: 15px;
    font-weight: 700;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: opacity .15s, transform .15s;
}
.settings-submit .btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
.settings-submit .btn-save:active { transform: scale(0.98); }

/* switch — keep existing styles from app.css + JS handled below */
</style>

<main class="settings-page">
    <form method="post">
        <?= csrf_field() ?>

        <!-- 基础设置 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon blue">⚙️</div>
                <div class="info">
                    <h3>基础设置</h3>
                    <p>网站名称、余额名称等全局配置</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>网站名称</label>
                        <input name="platform_name" value="<?= e($platformName) ?>" placeholder="AI 图片视频创作系统" required>
                    </div>
                    <div class="settings-field">
                        <label>余额名称</label>
                        <input name="balance_label" value="<?= e($balanceLabel) ?>" placeholder="积分" required>
                        <div class="hint">显示在前台的余额单位，如"积分""点数""余额"。</div>
                    </div>
                </div>
                <div class="settings-field">
                    <label>图片存储方式</label>
                    <select name="image_storage_mode">
                        <option value="file" <?= $imageStorageMode !== 'base64' ? 'selected' : '' ?>>文件路径（推荐）</option>
                        <option value="base64" <?= $imageStorageMode === 'base64' ? 'selected' : '' ?>>数据库 base64</option>
                    </select>
                    <div class="hint">文件路径模式将图片保存在 <code>uploads/</code> 目录下，推荐用于生产环境。</div>
                </div>
            </div>
        </div>

        <!-- 上传限制 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon amber">📎</div>
                <div class="info">
                    <h3>上传限制</h3>
                    <p>编辑模式下参考图片的大小和数量限制</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid" style="grid-template-columns: repeat(3, 1fr);">
                    <div class="settings-field">
                        <label>最多上传图片数</label>
                        <input name="max_edit_images" type="number" min="1" max="16" value="<?= e($maxEditImages) ?>">
                    </div>
                    <div class="settings-field">
                        <label>单张最大 (MB)</label>
                        <input name="max_edit_image_mb" type="number" min="1" max="50" value="<?= e($maxEditImageMb) ?>">
                    </div>
                    <div class="settings-field">
                        <label>最大宽高 (px)</label>
                        <input name="max_edit_image_dimension" type="number" min="512" max="12000" value="<?= e($maxEditImageDimension) ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- 接口路径 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon green">🔗</div>
                <div class="info">
                    <h3>接口路径</h3>
                    <p>自定义各生成模式的 API 端点路径</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid" style="grid-template-columns: repeat(3, 1fr);">
                    <div class="settings-field">
                        <label>图片生成路径</label>
                        <input name="image_generate_path" value="<?= e($imageGeneratePath) ?>" placeholder="/v1/images/generations">
                        <div class="hint">留空使用系统默认路径。</div>
                    </div>
                    <div class="settings-field">
                        <label>图片编辑路径</label>
                        <input name="image_edit_path" value="<?= e($imageEditPath) ?>" placeholder="/v1/images/edits">
                        <div class="hint">一般同生成接口路径。</div>
                    </div>
                    <div class="settings-field">
                        <label>视频生成路径</label>
                        <input name="video_generate_path" value="<?= e($videoGeneratePath) ?>" placeholder="/v1/videos">
                        <div class="hint">留空使用系统默认路径。</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 公告 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon purple">📢</div>
                <div class="info">
                    <h3>前台公告</h3>
                    <p>在图片/视频生成页顶部显示的通知信息</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>图片生成页公告</label>
                        <textarea name="generation_notice" rows="3" placeholder="留空则不显示公告"><?= e($generationNotice) ?></textarea>
                    </div>
                    <div class="settings-field">
                        <label>视频生成页公告</label>
                        <textarea name="video_notice" rows="3" placeholder="留空则不显示公告"><?= e($videoNotice) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- 提示词优化 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon blue">✨</div>
                <div class="info">
                    <h3>提示词优化</h3>
                    <p>调用 AI 对用户输入的提示词进行智能润色</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-switch-row">
                    <div class="left">
                        <h4>启用提示词优化</h4>
                        <p>开启后前台提示词框下方显示「优化提示词」按钮。</p>
                    </div>
                    <label class="switch">
                        <input name="prompt_optimize_enabled" type="checkbox" value="1" <?= $promptOptimizeEnabled ? 'checked' : '' ?>>
                        <span class="switch-knob"></span>
                    </label>
                </div>
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>API 地址</label>
                        <input name="prompt_optimize_base_url" value="<?= e($promptOptimizeBaseUrl) ?>" placeholder="https://api.openai.com/v1">
                        <div class="hint">留空则使用系统图片 API 地址。</div>
                    </div>
                    <div class="settings-field">
                        <label>API Key</label>
                        <input name="prompt_optimize_api_key" value="<?= e($promptOptimizeApiKey) ?>" placeholder="sk-..." type="password">
                        <div class="hint">留空则使用系统图片 API Key。</div>
                    </div>
                    <div class="settings-field">
                        <label>模型</label>
                        <input name="prompt_optimize_model" value="<?= e($promptOptimizeModel) ?>" placeholder="gpt-4o-mini">
                        <div class="hint">建议 gpt-4o-mini 或更强模型。</div>
                    </div>
                </div>
                <div class="settings-field">
                    <label>系统提示词 (System Prompt)</label>
                    <textarea name="prompt_optimize_system_prompt" rows="3" placeholder="留空则使用默认优化提示词"><?= e($promptOptimizeSystemPrompt) ?></textarea>
                    <div class="hint">自定义 AI 优化风格，留空使用系统内置提示词。</div>
                </div>
            </div>
        </div>

        <!-- 邀请功能 -->
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-section-icon green">🎁</div>
                <div class="info">
                    <h3>邀请功能</h3>
                    <p>邀请好友注册充值，双方获得奖励</p>
                </div>
            </div>
            <div class="settings-section-body">
                <div class="settings-switch-row">
                    <div class="left">
                        <h4>启用邀请功能</h4>
                        <p>开启后注册页显示邀请码输入框，用户可生成邀请码。</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="invite_enabled" <?= $inviteEnabled ? 'checked' : '' ?>>
                        <span class="switch-knob"></span>
                    </label>
                </div>
                <div class="settings-grid">
                    <div class="settings-field">
                        <label>充值佣金比例 (%)</label>
                        <input name="invite_commission_percent" type="number" min="0" max="100" value="<?= $inviteCommissionPercent ?>">
                        <div class="hint">被邀请用户充值时，邀请人获得充值<?= e($balanceLabel) ?>的 <strong><?= $inviteCommissionPercent ?>%</strong>。</div>
                    </div>
                    <div class="settings-field">
                        <label>邀请赠送 <?= e($balanceLabel) ?></label>
                        <input name="invite_bonus_credits" type="number" min="0" value="<?= $inviteBonusCredits ?>">
                        <div class="hint">被邀请用户通过邀请码注册后，双方各获 <strong><?= $inviteBonusCredits ?> <?= e($balanceLabel) ?></strong>。</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-submit">
            <button type="submit" class="btn-save">💾 保存全部配置</button>
        </div>
    </form>
</main>

<script>
// 开关组件 — 同步 checkbox 和 visual switch
document.querySelectorAll('.switch').forEach(function(sw) {
    var cb = sw.querySelector('input[type="checkbox"]');
    if (!cb) return;
    if (cb.checked) sw.classList.add('active');
    sw.addEventListener('click', function(e) {
        if (e.target === cb) return;
        cb.checked = !cb.checked;
        sw.classList.toggle('active', cb.checked);
    });
    cb.addEventListener('change', function() {
        sw.classList.toggle('active', cb.checked);
    });
});
</script>

<?php render_footer(); ?>
