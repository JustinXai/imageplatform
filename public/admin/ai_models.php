<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$admin = require_admin();
ensure_ai_models_table();
ensure_ai_models_type_column();
ensure_ai_models_capability_columns();
ensure_generation_records_selection_columns();

function normalize_model_type(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['image', 'video', 'chat'], true) ? $value : 'image';
}

function normalize_invoke_mode(string $value, string $modelType): string
{
    $value = strtolower(trim($value));
    if ($modelType === 'video') {
        return $value === 'kaiyuncode' ? 'kaiyuncode' : 'relay';
    }

    return $value === 'curl' ? 'curl' : 'relay';
}

function normalize_edit_adapter(string $value): string
{
    $value = strtolower(trim($value));
    // 图片编辑适配器（仅限 Nano Banana 等图片编辑模型）
    $allowed = ['none', 'nano_banana_image_urls', 'openai_edits_multipart', 'newtoken_async_reference'];
    return in_array($value, $allowed, true) ? $value : 'none';
}

function normalize_edit_image_field(string $value): string
{
    $value = strtolower(trim($value));
    $allowed = ['image_urls', 'reference_images'];
    return in_array($value, $allowed, true) ? $value : 'image_urls';
}

function normalize_supports_reference(string $value): int
{
    return (int) $value === 1 ? 1 : 0;
}

function normalize_reference_required(string $value): int
{
    return (int) $value === 1 ? 1 : 0;
}

function normalize_video_adapter(string $value): string
{
    $value = strtolower(trim($value));
    // 视频适配器（仅限视频模型）
    $allowed = ['none', 'kaiyuncode', 'newtoken_video_async'];
    return in_array($value, $allowed, true) ? $value : 'none';
}

function render_edit_adapter_options(string $selected): string
{
    // 图片编辑适配器（仅用于 Nano Banana 等图片编辑模型）
    $options = [
        'none' => '不支持编辑（默认）',
        'nano_banana_image_urls' => 'nano_banana_image_urls',
        'openai_edits_multipart' => 'openai_edits_multipart',
        'newtoken_async_reference' => 'newtoken_async_reference（Nano Banana）',
    ];
    $selected = normalize_edit_adapter($selected);
    $html = '';
    foreach ($options as $value => $label) {
        $isSelected = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $isSelected . '>' . e($label) . '</option>';
    }
    return $html;
}

function render_edit_image_field_options(string $selected): string
{
    $options = [
        'image_urls' => 'image_urls（默认）',
        'reference_images' => 'reference_images',
    ];
    $selected = normalize_edit_image_field($selected);
    $html = '';
    foreach ($options as $value => $label) {
        $isSelected = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $isSelected . '>' . e($label) . '</option>';
    }
    return $html;
}

function invoke_mode_options_for_type(string $modelType): array
{
    if ($modelType === 'video') {
        return [
            'relay' => '默认',
            'kaiyuncode' => 'kaiyuncode',
        ];
    }

    return [
        'relay' => '中转站（默认）',
        'curl' => 'curl',
    ];
}

function render_invoke_mode_options(string $modelType, string $selected): string
{
    $options = invoke_mode_options_for_type($modelType);
    $selected = normalize_invoke_mode($selected, $modelType);
    $html = '';

    foreach ($options as $value => $label) {
        $isSelected = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $isSelected . '>' . e($label) . '</option>';
    }

    return $html;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $modelId = trim((string) ($_POST['model_id'] ?? ''));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $modelType = normalize_model_type((string) ($_POST['model_type'] ?? 'image'));
        $invokeMode = normalize_invoke_mode((string) ($_POST['invoke_mode'] ?? 'relay'), $modelType);

        if ($name === '' || $modelId === '' || $baseUrl === '' || $apiKey === '') {
            flash('error', '请填写完整信息。');
            redirect('/admin/ai_models');
        }

        $credits = $_POST['credits'] !== '' ? max(1, (int) $_POST['credits']) : null;
        $supportsEdit = (int) ($_POST['supports_edit'] ?? 0);
        $editAdapter = normalize_edit_adapter((string) ($_POST['edit_adapter'] ?? 'none'));
        $editImageField = normalize_edit_image_field((string) ($_POST['edit_image_field'] ?? 'image_urls'));
        $supportsReference = (int) ($_POST['supports_reference'] ?? 0);
        $referenceRequired = (int) ($_POST['reference_required'] ?? 0);
        $maxReferenceImages = max(1, min(16, (int) ($_POST['max_reference_images'] ?? 1)));
        $videoAdapter = normalize_video_adapter((string) ($_POST['video_adapter'] ?? 'none'));
        $fixedSeconds = max(0, (int) ($_POST['fixed_seconds'] ?? 0));
        $videoResolution = trim((string) ($_POST['video_resolution'] ?? 'auto'));
        $videoAspectRatio = trim((string) ($_POST['video_aspect_ratio'] ?? 'auto'));

        if ($supportsEdit && $editAdapter === 'none') {
            flash('error', '如果要启用编辑功能，请选择有效的编辑适配器（不能选择"不支持编辑"）。');
            redirect('/admin/ai_models');
        }

        $stmt = db()->prepare(
            'INSERT INTO ai_models (name, model_id, base_url, api_key, model_type, credits, invoke_mode, supports_edit, edit_adapter, edit_image_field, supports_reference, reference_required, max_reference_images, video_adapter, fixed_seconds, video_resolution, video_aspect_ratio, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $modelId, $baseUrl, $apiKey, $modelType, $credits, $invokeMode, $supportsEdit, $editAdapter, $editImageField, $supportsReference, $referenceRequired, $maxReferenceImages, $videoAdapter, $fixedSeconds, $videoResolution, $videoAspectRatio, $sortOrder]);
        flash('success', '模型已添加。');
        redirect('/admin/ai_models');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $modelId = trim((string) ($_POST['model_id'] ?? ''));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $isActive = (int) ($_POST['is_active'] ?? 1);
        $modelType = normalize_model_type((string) ($_POST['model_type'] ?? 'image'));
        $invokeMode = normalize_invoke_mode((string) ($_POST['invoke_mode'] ?? 'relay'), $modelType);

        if ($id < 1 || $name === '' || $modelId === '' || $baseUrl === '') {
            flash('error', '参数不合法。');
            redirect('/admin/ai_models');
        }

        $credits = $_POST['credits'] !== '' ? max(1, (int) $_POST['credits']) : null;
        $supportsEdit = (int) ($_POST['supports_edit'] ?? 0);
        $editAdapter = normalize_edit_adapter((string) ($_POST['edit_adapter'] ?? 'none'));
        $editImageField = normalize_edit_image_field((string) ($_POST['edit_image_field'] ?? 'image_urls'));
        $supportsReference = (int) ($_POST['supports_reference'] ?? 0);
        $referenceRequired = (int) ($_POST['reference_required'] ?? 0);
        $maxReferenceImages = max(1, min(16, (int) ($_POST['max_reference_images'] ?? 1)));
        $videoAdapter = normalize_video_adapter((string) ($_POST['video_adapter'] ?? 'none'));
        $fixedSeconds = max(0, (int) ($_POST['fixed_seconds'] ?? 0));
        $videoResolution = trim((string) ($_POST['video_resolution'] ?? 'auto'));
        $videoAspectRatio = trim((string) ($_POST['video_aspect_ratio'] ?? 'auto'));

        if ($apiKey !== '') {
            $stmt = db()->prepare(
                'UPDATE ai_models SET name=?, model_id=?, base_url=?, api_key=?, model_type=?, credits=?, invoke_mode=?, supports_edit=?, edit_adapter=?, edit_image_field=?, supports_reference=?, reference_required=?, max_reference_images=?, video_adapter=?, fixed_seconds=?, video_resolution=?, video_aspect_ratio=?, sort_order=?, is_active=? WHERE id=?'
            );
            $stmt->execute([$name, $modelId, $baseUrl, $apiKey, $modelType, $credits, $invokeMode, $supportsEdit, $editAdapter, $editImageField, $supportsReference, $referenceRequired, $maxReferenceImages, $videoAdapter, $fixedSeconds, $videoResolution, $videoAspectRatio, $sortOrder, $isActive, $id]);
        } else {
            $stmt = db()->prepare(
                'UPDATE ai_models SET name=?, model_id=?, base_url=?, model_type=?, credits=?, invoke_mode=?, supports_edit=?, edit_adapter=?, edit_image_field=?, supports_reference=?, reference_required=?, max_reference_images=?, video_adapter=?, fixed_seconds=?, video_resolution=?, video_aspect_ratio=?, sort_order=?, is_active=? WHERE id=?'
            );
            $stmt->execute([$name, $modelId, $baseUrl, $modelType, $credits, $invokeMode, $supportsEdit, $editAdapter, $editImageField, $supportsReference, $referenceRequired, $maxReferenceImages, $videoAdapter, $fixedSeconds, $videoResolution, $videoAspectRatio, $sortOrder, $isActive, $id]);
        }

        flash('success', '模型已更新。');
        redirect('/admin/ai_models');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            flash('error', '参数不合法。');
            redirect('/admin/ai_models');
        }

        $stmt = db()->prepare('DELETE FROM ai_models WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', '模型已删除。');
        redirect('/admin/ai_models');
    }

    flash('error', '未知操作。');
    redirect('/admin/ai_models');
}

$stmt = db()->query('SELECT * FROM ai_models ORDER BY sort_order ASC, id ASC');
$models = $stmt->fetchAll();

render_header('AI 模型', 'admin');
render_admin_nav('ai_models');
?>
<style>
.inline-model-form {
    display: contents;
}
.model-create-form .field-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}
.model-create-form .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.model-create-form .field span {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-soft);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.model-create-form input,
.model-create-form select {
    padding: 8px 10px;
    font-size: 14px;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    background: var(--main-surface);
    color: var(--text);
}
.model-create-form input:focus,
.model-create-form select:focus,
.compact-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
}
.compact-input {
    width: 100%;
    min-width: 60px;
    padding: 5px 8px;
    font-size: 13px;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    background: var(--main-surface);
    color: var(--text);
    box-sizing: border-box;
}
.table-action-group {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: nowrap;
}
.table-action-group .button {
    white-space: nowrap;
    padding: 5px 12px;
    font-size: 12px;
}
.inline-delete-form {
    display: inline-flex;
}
table[data-admin-models] th,
table[data-admin-models] td {
    padding: 10px 12px;
    vertical-align: middle;
}
table[data-admin-models] th {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-soft);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
table[data-admin-models] td:last-child {
    min-width: 140px;
}
.card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.muted-hint {
    font-size: 11px;
    color: var(--text-muted);
}
</style>
<main class="grid">
    <section class="card code-create-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Add Model</p>
                <h2>新增模型</h2>
            </div>
        </div>
        <form method="post" class="model-create-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="field-grid">
                <label class="field">
                    <span>显示名称</span>
                    <input name="name" placeholder="例如：GPT Image 2" required>
                </label>
                <label class="field">
                    <span>模型 ID</span>
                    <input name="model_id" placeholder="例如：gpt-image-2" required>
                </label>
            </div>
            <div class="field-grid">
                <label class="field">
                    <span>Base URL</span>
                    <input name="base_url" placeholder="https://api.kbl6.cn" required>
                </label>
                <label class="field">
                    <span>API Key</span>
                    <input name="api_key" placeholder="sk-..." required>
                </label>
            </div>
            <div class="field-grid">
                <label class="field">
                    <span>排序</span>
                    <input name="sort_order" type="number" min="0" value="0" required>
                </label>
                <label class="field">
                    <span>消耗点数</span>
                    <input name="credits" type="number" min="1" placeholder="留空使用默认值">
                </label>
                <label class="field">
                    <span>模型类型</span>
                    <select name="model_type" data-model-type-select>
                        <option value="image" selected>图片生成</option>
                        <option value="video">视频生成</option>
                        <option value="chat">AI 对话</option>
                    </select>
                </label>
                <label class="field">
                    <span>调用方式</span>
                    <select name="invoke_mode" data-invoke-mode-select>
                        <?= render_invoke_mode_options('image', 'relay') ?>
                    </select>
                </label>
            </div>
            <div class="field-grid">
                <label class="field">
                    <span>支持编辑</span>
                    <select name="supports_edit">
                        <option value="0">不支持编辑</option>
                        <option value="1">支持编辑</option>
                    </select>
                </label>
                <label class="field">
                    <span>图片编辑适配器</span>
                    <span class="field-label-hint">仅用于 Nano Banana 等图片编辑模型</span>
                    <select name="edit_adapter">
                        <?= render_edit_adapter_options('none') ?>
                    </select>
                </label>
                <label class="field">
                    <span>编辑图片字段</span>
                    <select name="edit_image_field">
                        <?= render_edit_image_field_options('image_urls') ?>
                    </select>
                </label>
            </div>
            <div class="field-grid" id="videoFieldsSection" style="display:none;">
                <label class="field">
                    <span>支持参考图</span>
                    <select name="supports_reference">
                        <option value="0">不支持</option>
                        <option value="1">支持</option>
                    </select>
                </label>
                <label class="field">
                    <span>参考图必填</span>
                    <select name="reference_required">
                        <option value="0">可选</option>
                        <option value="1">必填</option>
                    </select>
                </label>
                <label class="field">
                    <span>最大参考图数</span>
                    <input name="max_reference_images" type="number" min="1" max="16" value="1">
                </label>
                <label class="field">
                    <span>视频适配器</span>
                    <span class="field-label-hint">仅用于视频模型（veo、seedance 等）</span>
                    <select name="video_adapter">
                        <option value="none">不支持视频（默认）</option>
                        <option value="kaiyuncode">kaiyuncode</option>
                        <option value="newtoken_video_async">newtoken_video_async（NewToken 视频）</option>
                    </select>
                </label>
            </div>
            <div class="field-grid" id="videoTimingSection" style="display:none;">
                <label class="field">
                    <span>固定时长(秒)</span>
                    <input name="fixed_seconds" type="number" min="0" max="60" value="0" placeholder="0=用户自选">
                </label>
                <label class="field">
                    <span>分辨率</span>
                    <select name="video_resolution">
                        <option value="auto">自动</option>
                        <option value="720p">720p</option>
                        <option value="1080p">1080p</option>
                    </select>
                </label>
                <label class="field">
                    <span>比例</span>
                    <select name="video_aspect_ratio">
                        <option value="auto">自动</option>
                        <option value="16:9">16:9 横屏</option>
                        <option value="9:16">9:16 竖屏</option>
                        <option value="1:1">1:1 方形</option>
                    </select>
                </label>
            </div>
            <button class="button primary" type="submit">添加模型</button>
        </form>
    </section>

    <section class="card section-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Models</p>
                <h2>已配置模型</h2>
            </div>
            <span class="badge">共 <?= count($models) ?> 个</span>
        </div>
        <div class="table-wrap">
            <table data-admin-models>
                <thead>
                    <tr>
                        <th>排序</th>
                        <th>名称</th>
                        <th>模型 ID</th>
                        <th>Base URL</th>
                        <th>API Key</th>
                        <th>类型</th>
                        <th>调用</th>
                        <th>编辑</th>
                        <th>图片编辑适配器</th>
                        <th>视频适配器</th>
                        <th>参图</th>
                        <th>时长</th>
                        <th>点</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($models as $m): ?>
                        <?php $mid = (int) $m['id']; ?>
                        <tr>
                            <form method="post" class="inline-model-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= $mid ?>">
                                <td>
                                    <input class="compact-input" name="sort_order" type="number" min="0" value="<?= (int) $m['sort_order'] ?>">
                                </td>
                                <td>
                                    <input class="compact-input" name="name" value="<?= e($m['name']) ?>" required style="min-width: 120px;">
                                </td>
                                <td>
                                    <input class="compact-input" name="model_id" value="<?= e($m['model_id']) ?>" required style="min-width: 130px;">
                                </td>
                                <td>
                                    <input class="compact-input" name="base_url" value="<?= e($m['base_url']) ?>" required style="min-width: 180px;">
                                </td>
                                <td>
                                    <input class="compact-input" name="api_key" type="password" placeholder="留空不修改" autocomplete="off">
                                    <span class="muted-hint">已配置</span>
                                </td>
                                <td>
                                    <select name="model_type" class="compact-input" style="min-width: 88px;" data-model-type-select>
                                        <option value="image" <?= ($m['model_type'] ?? 'image') === 'image' ? 'selected' : '' ?>>图片</option>
                                        <option value="video" <?= ($m['model_type'] ?? 'image') === 'video' ? 'selected' : '' ?>>视频</option>
                                        <option value="chat" <?= ($m['model_type'] ?? 'image') === 'chat' ? 'selected' : '' ?>>对话</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="invoke_mode" class="compact-input" style="min-width: 120px;" data-invoke-mode-select>
                                        <?= render_invoke_mode_options(
                                            normalize_model_type((string) ($m['model_type'] ?? 'image')),
                                            (string) ($m['invoke_mode'] ?? 'relay')
                                        ) ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="supports_edit" class="compact-input" style="min-width: 80px;">
                                        <option value="0" <?= (int) ($m['supports_edit'] ?? 0) === 0 ? 'selected' : '' ?>>否</option>
                                        <option value="1" <?= (int) ($m['supports_edit'] ?? 0) === 1 ? 'selected' : '' ?>>是</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="edit_adapter" class="compact-input" style="min-width: 140px;">
                                        <?= render_edit_adapter_options((string) ($m['edit_adapter'] ?? 'none')) ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="video_adapter" class="compact-input" style="min-width: 120px;">
                                        <option value="none" <?= ($m['video_adapter'] ?? 'none') === 'none' ? 'selected' : '' ?>>无</option>
                                        <option value="kaiyuncode" <?= ($m['video_adapter'] ?? '') === 'kaiyuncode' ? 'selected' : '' ?>>kaiyuncode</option>
                                        <option value="newtoken_video_async" <?= ($m['video_adapter'] ?? '') === 'newtoken_video_async' ? 'selected' : '' ?>>newtoken_video_async</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="supports_reference" class="compact-input" style="min-width: 56px;">
                                        <option value="0" <?= (int) ($m['supports_reference'] ?? 0) === 0 ? 'selected' : '' ?>>否</option>
                                        <option value="1" <?= (int) ($m['supports_reference'] ?? 0) === 1 ? 'selected' : '' ?>>是</option>
                                    </select>
                                </td>
                                <td>
                                    <input class="compact-input" name="fixed_seconds" type="number" min="0" max="60" value="<?= (int) ($m['fixed_seconds'] ?? 0) ?>" placeholder="0" style="min-width: 52px;" title="固定时长(秒)，0=用户自选">
                                </td>
                                <td>
                                    <input class="compact-input" name="credits" type="number" min="1" value="<?= (int) ($m['credits'] ?? 0) ?: '' ?>" placeholder="默认" style="min-width: 72px;">
                                </td>
                                <td>
                                    <select name="is_active" class="compact-input" style="min-width: 74px;">
                                        <option value="1" <?= (int) $m['is_active'] === 1 ? 'selected' : '' ?>>启用</option>
                                        <option value="0" <?= (int) $m['is_active'] !== 1 ? 'selected' : '' ?>>关闭</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="table-action-group">
                                        <button class="button secondary small" type="submit">保存</button>
                            </form>
                                        <form method="post" class="inline-delete-form" onsubmit="return confirm('确定删除「<?= e($m['name']) ?>」吗？此操作不可撤销。')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $mid ?>">
                                            <button class="button danger small" type="submit">删除</button>
                                        </form>
                                    </div>
                                </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$models): ?>
                        <tr><td colspan="10" class="muted">暂无模型，请先添加。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
(() => {
    const optionMap = {
        image: [
            { value: 'relay', label: '中转站（默认）' },
            { value: 'curl', label: 'curl' },
        ],
        chat: [
            { value: 'relay', label: '中转站（默认）' },
            { value: 'curl', label: 'curl' },
        ],
        video: [
            { value: 'relay', label: '默认' },
            { value: 'kaiyuncode', label: 'kaiyuncode' },
        ],
    };

    const syncInvokeModeOptions = (modelTypeSelect) => {
        const form = modelTypeSelect.closest('form');
        if (!form) {
            return;
        }

        const invokeModeSelect = form.querySelector('[data-invoke-mode-select]');
        if (!invokeModeSelect) {
            return;
        }

        const modelType = optionMap[modelTypeSelect.value] ? modelTypeSelect.value : 'image';
        const nextOptions = optionMap[modelType];
        const currentValue = invokeModeSelect.value;

        invokeModeSelect.innerHTML = '';
        nextOptions.forEach((option) => {
            const node = document.createElement('option');
            node.value = option.value;
            node.textContent = option.label;
            if (option.value === currentValue) {
                node.selected = true;
            }
            invokeModeSelect.appendChild(node);
        });

        if (!nextOptions.some((option) => option.value === currentValue) && nextOptions[0]) {
            invokeModeSelect.value = nextOptions[0].value;
        }
    };

    const showVideoFields = (modelType) => {
        const videoSection = document.getElementById('videoFieldsSection');
        const timingSection = document.getElementById('videoTimingSection');
        if (!videoSection || !timingSection) return;
        const isVideo = modelType === 'video';
        videoSection.style.display = isVideo ? '' : 'none';
        timingSection.style.display = isVideo ? '' : 'none';
    };

    document.querySelectorAll('[data-model-type-select]').forEach((select) => {
        syncInvokeModeOptions(select);
        showVideoFields(select.value);
        select.addEventListener('change', () => {
            syncInvokeModeOptions(select);
            showVideoFields(select.value);
        });
    });
})();
</script>
<?php render_footer(); ?>
