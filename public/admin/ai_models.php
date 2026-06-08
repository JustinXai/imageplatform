<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$admin = require_admin();
ensure_ai_models_table();
ensure_ai_models_type_column();
ensure_ai_models_capability_columns();
ensure_generation_records_selection_columns();

/* ------------------------------------------------------------------ */
/*  Normalization helpers                                               */
/* ------------------------------------------------------------------ */

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
    $allowed = ['none', 'nano_banana_image_urls', 'openai_edits_multipart', 'newtoken_async_reference'];
    return in_array($value, $allowed, true) ? $value : 'none';
}

function normalize_edit_image_field(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['image_urls', 'reference_images'], true) ? $value : 'image_urls';
}

function normalize_video_adapter(string $value): string
{
    $value = strtolower(trim($value));
    $allowed = ['none', 'kaiyuncode', 'newtoken_video_async'];
    return in_array($value, $allowed, true) ? $value : 'none';
}

function normalize_video_mode(string $value): string
{
    $allowed = ['text_to_video', 'first_frame', 'first_last_frame', 'multi_reference'];
    return in_array($value, $allowed, true) ? $value : 'text_to_video';
}

/** 解析逗号分隔的文本为 JSON 数组，非法时返回 [] */
function parse_csv_options(string $raw): array
{
    $parts = array_map('trim', explode(',', $raw));
    $parts = array_filter($parts, fn($v) => $v !== '');
    return array_values(array_unique($parts));
}

/** 序列化 JSON，非法时返回 null */
function encode_json_or_null(array $arr): ?string
{
    if (!is_array($arr) || count($arr) === 0) {
        return null;
    }
    $json = json_encode(array_values($arr), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : null;
}

/* ------------------------------------------------------------------ */
/*  Determine image / video model classification                       */
/* ------------------------------------------------------------------ */

function is_image_model_area(array $m): bool
{
    $type = strtolower(trim($m['model_type'] ?? 'image'));
    $name = strtolower(trim($m['name'] ?? ''));
    $mid = strtolower(trim($m['model_id'] ?? ''));
    if ($type === 'image') return true;
    if ($type === 'chat') return true;
    // Explicit image model name patterns
    if (strpos($name, 'banana') !== false) return true;
    if (strpos($mid, 'banana') !== false) return true;
    if (strpos($name, 'nana') !== false) return true;
    if (strpos($mid, 'nana') !== false) return true;
    if (strpos($name, 'gpt-image') !== false) return true;
    if (strpos($mid, 'gpt-image') !== false) return true;
    return false;
}

function is_video_model_area(array $m): bool
{
    $type = strtolower(trim($m['model_type'] ?? 'image'));
    $name = strtolower(trim($m['name'] ?? ''));
    $mid = strtolower(trim($m['model_id'] ?? ''));
    if ($type === 'video') return true;
    // Explicit video model name patterns
    if (strpos($name, 'veo') !== false) return true;
    if (strpos($mid, 'veo') !== false) return true;
    if (strpos($name, 'seedance') !== false) return true;
    if (strpos($mid, 'seedance') !== false) return true;
    if (strpos($name, 'video-pro') !== false) return true;
    if (strpos($mid, 'video-pro') !== false) return true;
    if (strpos($name, 'sora') !== false) return true;
    if (strpos($mid, 'sora') !== false) return true;
    return false;
}

/* ------------------------------------------------------------------ */
/*  POST handlers                                                      */
/* ------------------------------------------------------------------ */

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

        // New capability fields
        $imageAspectOpts = encode_json_or_null(parse_csv_options((string) ($_POST['image_aspect_options'] ?? '')));
        $imageDefaultAspect = trim((string) ($_POST['image_default_aspect'] ?? 'auto'));
        $imageSizeOpts = encode_json_or_null(parse_csv_options((string) ($_POST['image_size_options'] ?? '')));
        $imageDefaultSize = trim((string) ($_POST['image_default_size'] ?? 'auto'));

        $videoDurationOpts = encode_json_or_null(parse_csv_options((string) ($_POST['video_duration_options'] ?? '')));
        $videoDefaultDuration = max(1, (int) ($_POST['video_default_duration'] ?? 0)) ?: null;
        $videoAspectOpts = encode_json_or_null(parse_csv_options((string) ($_POST['video_aspect_options'] ?? '')));
        $videoDefaultAspect = trim((string) ($_POST['video_default_aspect'] ?? '16:9'));
        $videoSizeOpts = encode_json_or_null(parse_csv_options((string) ($_POST['video_size_options'] ?? '')));
        $videoDefaultSize = trim((string) ($_POST['video_default_size'] ?? 'auto'));
        $videoModeOpts = encode_json_or_null(
            array_values(array_filter(
                array_map('normalize_video_mode',
                    array_map('trim', explode(',', (string) ($_POST['video_mode_options'] ?? '')))
                ),
                fn($v) => $v !== ''
            ))
        );
        $videoDefaultMode = normalize_video_mode((string) ($_POST['video_default_mode'] ?? 'text_to_video'));
        $videoRefField = trim((string) ($_POST['video_reference_field'] ?? 'reference_images')) ?: 'reference_images';
        $videoDurationField = trim((string) ($_POST['video_duration_field'] ?? 'duration')) ?: 'duration';
        $videoAspectField = trim((string) ($_POST['video_aspect_field'] ?? 'aspect_ratio')) ?: 'aspect_ratio';
        $videoSizeField = trim((string) ($_POST['video_size_field'] ?? 'size')) ?: 'size';
        $videoInputModeField = trim((string) ($_POST['video_input_mode_field'] ?? 'input_mode')) ?: 'input_mode';

        if ($supportsEdit && $editAdapter === 'none') {
            flash('error', '如果要启用编辑功能，请选择有效的编辑适配器（不能选择"不支持编辑"）。');
            redirect('/admin/ai_models');
        }

        $stmt = db()->prepare(
            'INSERT INTO ai_models (name, model_id, base_url, api_key, model_type, credits, invoke_mode, '
            . 'supports_edit, edit_adapter, edit_image_field, supports_reference, reference_required, '
            . 'max_reference_images, video_adapter, fixed_seconds, video_resolution, video_aspect_ratio, '
            . 'sort_order, '
            . 'image_aspect_options_json, image_default_aspect, image_size_options_json, image_default_size, '
            . 'video_duration_options_json, video_default_duration, video_aspect_options_json, video_default_aspect, '
            . 'video_size_options_json, video_default_size, video_mode_options_json, video_default_mode, '
            . 'video_reference_field, video_duration_field, video_aspect_field, video_size_field, video_input_mode_field'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name, $modelId, $baseUrl, $apiKey, $modelType, $credits, $invokeMode,
            $supportsEdit, $editAdapter, $editImageField,
            $supportsReference, $referenceRequired, $maxReferenceImages,
            $videoAdapter, $fixedSeconds, $videoResolution, $videoAspectRatio,
            $sortOrder,
            $imageAspectOpts, $imageDefaultAspect, $imageSizeOpts, $imageDefaultSize,
            $videoDurationOpts, $videoDefaultDuration,
            $videoAspectOpts, $videoDefaultAspect,
            $videoSizeOpts, $videoDefaultSize,
            $videoModeOpts, $videoDefaultMode,
            $videoRefField, $videoDurationField, $videoAspectField, $videoSizeField, $videoInputModeField,
        ]);
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

        // New capability fields
        $imageAspectOpts = encode_json_or_null(parse_csv_options((string) ($_POST['image_aspect_options'] ?? '')));
        $imageDefaultAspect = trim((string) ($_POST['image_default_aspect'] ?? 'auto'));
        $imageSizeOpts = encode_json_or_null(parse_csv_options((string) ($_POST['image_size_options'] ?? '')));
        $imageDefaultSize = trim((string) ($_POST['image_default_size'] ?? 'auto'));

        $videoDurationOpts = encode_json_or_null(parse_csv_options((string) ($_POST['video_duration_options'] ?? '')));
        $videoDefaultDuration = max(1, (int) ($_POST['video_default_duration'] ?? 0)) ?: null;
        $videoAspectOpts = encode_json_or_null(parse_csv_options((string) ($_POST['video_aspect_options'] ?? '')));
        $videoDefaultAspect = trim((string) ($_POST['video_default_aspect'] ?? '16:9'));
        $videoSizeOpts = encode_json_or_null(parse_csv_options((string) ($_POST['video_size_options'] ?? '')));
        $videoDefaultSize = trim((string) ($_POST['video_default_size'] ?? 'auto'));
        $videoModeOpts = encode_json_or_null(
            array_values(array_filter(
                array_map('normalize_video_mode',
                    array_map('trim', explode(',', (string) ($_POST['video_mode_options'] ?? '')))
                ),
                fn($v) => $v !== ''
            ))
        );
        $videoDefaultMode = normalize_video_mode((string) ($_POST['video_default_mode'] ?? 'text_to_video'));
        $videoRefField = trim((string) ($_POST['video_reference_field'] ?? 'reference_images')) ?: 'reference_images';
        $videoDurationField = trim((string) ($_POST['video_duration_field'] ?? 'duration')) ?: 'duration';
        $videoAspectField = trim((string) ($_POST['video_aspect_field'] ?? 'aspect_ratio')) ?: 'aspect_ratio';
        $videoSizeField = trim((string) ($_POST['video_size_field'] ?? 'size')) ?: 'size';
        $videoInputModeField = trim((string) ($_POST['video_input_mode_field'] ?? 'input_mode')) ?: 'input_mode';

        // Common fields for both with/without apiKey paths
        $commonFields = 'name=?, model_id=?, base_url=?, model_type=?, credits=?, invoke_mode=?, '
            . 'supports_edit=?, edit_adapter=?, edit_image_field=?, '
            . 'supports_reference=?, reference_required=?, max_reference_images=?, '
            . 'video_adapter=?, fixed_seconds=?, video_resolution=?, video_aspect_ratio=?, '
            . 'sort_order=?, is_active=?, '
            . 'image_aspect_options_json=?, image_default_aspect=?, image_size_options_json=?, image_default_size=?, '
            . 'video_duration_options_json=?, video_default_duration=?, '
            . 'video_aspect_options_json=?, video_default_aspect=?, '
            . 'video_size_options_json=?, video_default_size=?, '
            . 'video_mode_options_json=?, video_default_mode=?, '
            . 'video_reference_field=?, video_duration_field=?, video_aspect_field=?, '
            . 'video_size_field=?, video_input_mode_field=?';
        $commonValues = [
            $name, $modelId, $baseUrl, $modelType, $credits, $invokeMode,
            $supportsEdit, $editAdapter, $editImageField,
            $supportsReference, $referenceRequired, $maxReferenceImages,
            $videoAdapter, $fixedSeconds, $videoResolution, $videoAspectRatio,
            $sortOrder, $isActive,
            $imageAspectOpts, $imageDefaultAspect, $imageSizeOpts, $imageDefaultSize,
            $videoDurationOpts, $videoDefaultDuration,
            $videoAspectOpts, $videoDefaultAspect,
            $videoSizeOpts, $videoDefaultSize,
            $videoModeOpts, $videoDefaultMode,
            $videoRefField, $videoDurationField, $videoAspectField,
            $videoSizeField, $videoInputModeField,
        ];

        if ($apiKey !== '') {
            $sql = "UPDATE ai_models SET name=?, model_id=?, base_url=?, api_key=?, $commonFields WHERE id=?";
            $values = array_merge([$name, $modelId, $baseUrl, $apiKey], $commonValues, [$id]);
        } else {
            $sql = "UPDATE ai_models SET name=?, model_id=?, base_url=?, $commonFields WHERE id=?";
            $values = array_merge([$name, $modelId, $baseUrl], $commonValues, [$id]);
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($values);
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

/* ------------------------------------------------------------------ */
/*  Load models                                                        */
/* ------------------------------------------------------------------ */

$stmt = db()->query('SELECT * FROM ai_models ORDER BY sort_order ASC, id ASC');
$allModels = $stmt->fetchAll();

$imageModels = array_filter($allModels, 'is_image_model_area');
$videoModels = array_filter($allModels, 'is_video_model_area');
$otherModels = array_filter($allModels, fn($m) => !is_image_model_area($m) && !is_video_model_area($m));

/* ------------------------------------------------------------------ */
/*  Helpers for rendering options                                      */
/* ------------------------------------------------------------------ */

function render_edit_adapter_options(string $selected): string
{
    $options = [
        'none' => '不支持编辑（默认）',
        'nano_banana_image_urls' => 'nano_banana_image_urls',
        'openai_edits_multipart' => 'openai_edits_multipart',
        'newtoken_async_reference' => 'newtoken_async_reference（Nano Banana）',
    ];
    $selected = normalize_edit_adapter($selected);
    $html = '';
    foreach ($options as $value => $label) {
        $sel = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
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
        $sel = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
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
        $sel = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

function decode_json_str(?string $json): array
{
    if ($json === null || $json === '') return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function render_image_model_row(array $m): string
{
    $mid = (int) $m['id'];
    $name = e($m['name']);
    $modelId = e($m['model_id']);
    $baseUrl = e($m['base_url']);

    $type = strtolower(trim($m['model_type'] ?? 'image'));
    $invokeMode = strtolower(trim($m['invoke_mode'] ?? 'relay'));
    $editAdapter = strtolower(trim($m['edit_adapter'] ?? 'none'));
    $editImageField = strtolower(trim($m['edit_image_field'] ?? 'image_urls'));
    $credits = (int) ($m['credits'] ?? 0);
    $isActive = (int) $m['is_active'];
    $supportsEdit = (int) ($m['supports_edit'] ?? 0);

    $imageAspectOpts = decode_json_str($m['image_aspect_options_json'] ?? null);
    $imageDefaultAspect = e(trim((string) ($m['image_default_aspect'] ?? 'auto')));
    $imageSizeOpts = decode_json_str($m['image_size_options_json'] ?? null);
    $imageDefaultSize = e(trim((string) ($m['image_default_size'] ?? 'auto')));

    $imageAspectStr = implode(',', $imageAspectOpts) ?: '';
    $imageSizeStr = implode(',', $imageSizeOpts) ?: '';

    ob_start();
    ?>
    <tr>
        <form method="post" class="inline-model-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $mid ?>">
            <td>
                <input class="compact-input" name="sort_order" type="number" min="0"
                       value="<?= (int) $m['sort_order'] ?>" style="width:52px;">
            </td>
            <td>
                <input class="compact-input" name="name" value="<?= $name ?>" required style="min-width:110px;">
            </td>
            <td>
                <input class="compact-input" name="model_id" value="<?= $modelId ?>" required style="min-width:120px;">
            </td>
            <td>
                <input class="compact-input" name="base_url" value="<?= $baseUrl ?>" required style="min-width:150px;">
            </td>
            <td>
                <span class="muted-hint">已配置</span>
                <input class="compact-input" name="api_key" type="password" placeholder="留空不修改"
                       autocomplete="off" style="width:80px;">
            </td>
            <td>
                <select name="model_type" class="compact-input model-type-select" style="width:72px;">
                    <option value="image" selected>图片</option>
                    <option value="chat">对话</option>
                </select>
            </td>
            <td>
                <select name="invoke_mode" class="compact-input invoke-mode-select" style="width:100px;">
                    <?= render_invoke_mode_options('image', $invokeMode) ?>
                </select>
            </td>
            <td>
                <select name="supports_edit" class="compact-input" style="width:56px;">
                    <option value="0" <?= $supportsEdit === 0 ? 'selected' : '' ?>>否</option>
                    <option value="1" <?= $supportsEdit === 1 ? 'selected' : '' ?>>是</option>
                </select>
            </td>
            <td>
                <select name="edit_adapter" class="compact-input" style="min-width:120px;">
                    <?= render_edit_adapter_options($editAdapter) ?>
                </select>
            </td>
            <td>
                <select name="edit_image_field" class="compact-input" style="width:110px;">
                    <?= render_edit_image_field_options($editImageField) ?>
                </select>
            </td>
            <td>
                <input class="compact-input" name="credits" type="number" min="1"
                       value="<?= $credits ?: '' ?>" placeholder="默认" style="width:52px;">
            </td>
            <td>
                <select name="is_active" class="compact-input" style="width:60px;">
                    <option value="1" <?= $isActive === 1 ? 'selected' : '' ?>>启用</option>
                    <option value="0" <?= $isActive !== 1 ? 'selected' : '' ?>>关闭</option>
                </select>
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:3px;font-size:11px;color:var(--text-muted);">
                    <span title="图片比例选项">
                        <strong>比例：</strong>
                        <input class="compact-input" name="image_aspect_options"
                               value="<?= e($imageAspectStr) ?>"
                               placeholder="auto,1:1,16:9"
                               style="width:100px;" title="逗号分隔，如: auto,1:1,16:9">
                    </span>
                    <span title="默认比例">
                        <strong>默认：</strong>
                        <input class="compact-input" name="image_default_aspect"
                               value="<?= $imageDefaultAspect ?>"
                               style="width:60px;">
                    </span>
                </div>
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:3px;font-size:11px;color:var(--text-muted);">
                    <span title="图片尺寸选项">
                        <strong>尺寸：</strong>
                        <input class="compact-input" name="image_size_options"
                               value="<?= e($imageSizeStr) ?>"
                               placeholder="auto,1024x1024"
                               style="width:100px;">
                    </span>
                    <span title="默认尺寸">
                        <strong>默认：</strong>
                        <input class="compact-input" name="image_default_size"
                               value="<?= $imageDefaultSize ?>"
                               style="width:80px;">
                    </span>
                </div>
            </td>
            <td>
                <div class="table-action-group">
                    <button class="button secondary small" type="submit">保存</button>
                </div>
            </form>
                <form method="post" class="inline-delete-form"
                      onsubmit="return confirm('确定删除「<?= $name ?>」吗？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $mid ?>">
                    <button class="button danger small" type="submit">删除</button>
                </form>
            </td>
        </tr>
    <?php
    return ob_get_clean();
}

function render_video_model_row(array $m): string
{
    $mid = (int) $m['id'];
    $name = e($m['name']);
    $modelId = e($m['model_id']);
    $baseUrl = e($m['base_url']);

    $invokeMode = strtolower(trim($m['invoke_mode'] ?? 'relay'));
    $videoAdapter = strtolower(trim($m['video_adapter'] ?? 'none'));
    $credits = (int) ($m['credits'] ?? 0);
    $isActive = (int) $m['is_active'];
    $supportsRef = (int) ($m['supports_reference'] ?? 0);
    $refRequired = (int) ($m['reference_required'] ?? 0);
    $maxRef = (int) ($m['max_reference_images'] ?? 1);
    $fixedSec = (int) ($m['fixed_seconds'] ?? 0);

    $durationOpts = decode_json_str($m['video_duration_options_json'] ?? null);
    $defaultDuration = (int) ($m['video_default_duration'] ?? 0);
    $aspectOpts = decode_json_str($m['video_aspect_options_json'] ?? null);
    $defaultAspect = e(trim((string) ($m['video_default_aspect'] ?? '16:9')));
    $sizeOpts = decode_json_str($m['video_size_options_json'] ?? null);
    $defaultSize = e(trim((string) ($m['video_default_size'] ?? 'auto')));
    $modeOpts = decode_json_str($m['video_mode_options_json'] ?? null);
    $defaultMode = e(trim((string) ($m['video_default_mode'] ?? 'text_to_video')));
    $refField = e(trim((string) ($m['video_reference_field'] ?? 'reference_images')));
    $durField = e(trim((string) ($m['video_duration_field'] ?? 'duration')));
    $aspField = e(trim((string) ($m['video_aspect_field'] ?? 'aspect_ratio')));
    $szField = e(trim((string) ($m['video_size_field'] ?? 'size')));
    $imField = e(trim((string) ($m['video_input_mode_field'] ?? 'input_mode')));

    $durationStr = implode(',', $durationOpts) ?: '';
    $aspectStr = implode(',', $aspectOpts) ?: '';
    $sizeStr = implode(',', $sizeOpts) ?: '';
    $modeStr = implode(',', $modeOpts) ?: '';

    $modeLabelMap = [
        'text_to_video' => '文生视频',
        'first_frame' => '首帧',
        'first_last_frame' => '首尾帧',
        'multi_reference' => '多帧',
    ];
    $modeOptions = [
        'text_to_video' => '文生视频',
        'first_frame' => '首帧参考',
        'first_last_frame' => '首尾帧',
        'multi_reference' => '多帧参考',
    ];
    $modeSelected = in_array($defaultMode, array_keys($modeOptions)) ? $defaultMode : 'text_to_video';

    ob_start();
    ?>
    <tr>
        <form method="post" class="inline-model-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $mid ?>">
            <td>
                <input class="compact-input" name="sort_order" type="number" min="0"
                       value="<?= (int) $m['sort_order'] ?>" style="width:52px;">
            </td>
            <td>
                <input class="compact-input" name="name" value="<?= $name ?>" required style="min-width:120px;">
            </td>
            <td>
                <input class="compact-input" name="model_id" value="<?= $modelId ?>" required style="min-width:140px;">
            </td>
            <td>
                <input class="compact-input" name="base_url" value="<?= $baseUrl ?>" required style="min-width:150px;">
            </td>
            <td>
                <span class="muted-hint">已配置</span>
                <input class="compact-input" name="api_key" type="password" placeholder="留空不修改"
                       autocomplete="off" style="width:80px;">
            </td>
            <td>
                <select name="model_type" class="compact-input model-type-select" style="width:72px;">
                    <option value="video" selected>视频</option>
                </select>
            </td>
            <td>
                <select name="invoke_mode" class="compact-input invoke-mode-select" style="width:100px;">
                    <?= render_invoke_mode_options('video', $invokeMode) ?>
                </select>
            </td>
            <td>
                <select name="credits" class="compact-input" style="width:52px;" title="每秒点数">
                    <option value="">默认</option>
                    <?php for ($i = 1; $i <= 50; $i++): ?>
                        <option value="<?= $i ?>" <?= $credits === $i ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </td>
            <td>
                <select name="video_adapter" class="compact-input" style="min-width:140px;">
                    <option value="none" <?= $videoAdapter === 'none' ? 'selected' : '' ?>>不支持视频（默认）</option>
                    <option value="kaiyuncode" <?= $videoAdapter === 'kaiyuncode' ? 'selected' : '' ?>>kaiyuncode</option>
                    <option value="newtoken_video_async" <?= $videoAdapter === 'newtoken_video_async' ? 'selected' : '' ?>>newtoken_video_async（NewToken 视频）</option>
                </select>
            </td>
            <td>
                <select name="supports_reference" class="compact-input" style="width:50px;">
                    <option value="0" <?= $supportsRef === 0 ? 'selected' : '' ?>>否</option>
                    <option value="1" <?= $supportsRef === 1 ? 'selected' : '' ?>>是</option>
                </select>
            </td>
            <td>
                <select name="reference_required" class="compact-input" style="width:50px;">
                    <option value="0" <?= $refRequired === 0 ? 'selected' : '' ?>>否</option>
                    <option value="1" <?= $refRequired === 1 ? 'selected' : '' ?>>是</option>
                </select>
            </td>
            <td>
                <input class="compact-input" name="max_reference_images" type="number" min="1" max="16"
                       value="<?= $maxRef ?>" style="width:44px;" title="最大参考图数">
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:2px;font-size:11px;color:var(--text-muted);min-width:120px;">
                    <span title="可选时长，逗号分隔，最多3个">
                        <input class="compact-input" name="video_duration_options"
                               value="<?= e($durationStr) ?>"
                               placeholder="8,10,15" style="width:100px;" maxlength="30"
                               title="逗号分隔，如: 8,10,15">
                    </span>
                    <span>
                        <strong>默认：</strong>
                        <input class="compact-input" name="video_default_duration"
                               type="number" min="1" max="120"
                               value="<?= $defaultDuration ?: '' ?>"
                               placeholder="留空自动取第一个" style="width:52px;">
                    </span>
                </div>
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:2px;font-size:11px;color:var(--text-muted);min-width:140px;">
                    <span>
                        <input class="compact-input" name="video_aspect_options"
                               value="<?= e($aspectStr) ?>"
                               placeholder="16:9,9:16,1:1" style="width:120px;"
                               title="逗号分隔，如: 16:9,9:16,1:1">
                    </span>
                    <span>
                        <strong>默认：</strong>
                        <input class="compact-input" name="video_default_aspect"
                               value="<?= $defaultAspect ?>" style="width:70px;">
                    </span>
                </div>
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:2px;font-size:11px;color:var(--text-muted);min-width:150px;">
                    <span>
                        <input class="compact-input" name="video_size_options"
                               value="<?= e($sizeStr) ?>"
                               placeholder="auto,1280x720" style="width:130px;"
                               title="逗号分隔，如: auto,1280x720">
                    </span>
                    <span>
                        <strong>默认：</strong>
                        <input class="compact-input" name="video_default_size"
                               value="<?= $defaultSize ?>" style="width:70px;">
                    </span>
                </div>
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:2px;font-size:11px;color:var(--text-muted);min-width:140px;">
                    <span>
                        <input class="compact-input" name="video_mode_options"
                               value="<?= e($modeStr) ?>"
                               placeholder="first_frame,multi_reference" style="width:130px;"
                               title="逗号分隔: text_to_video,first_frame,first_last_frame,multi_reference">
                    </span>
                    <span>
                        <strong>默认：</strong>
                        <select name="video_default_mode" class="compact-input" style="width:90px;">
                            <?php foreach ($modeOptions as $mv => $ml): ?>
                                <option value="<?= e($mv) ?>" <?= $modeSelected === $mv ? 'selected' : '' ?>><?= e($ml) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                </div>
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:1px;font-size:10px;color:var(--text-muted);min-width:80px;">
                    <span title="参考图字段映射">ref: <?= $refField ?></span>
                    <span title="时长字段映射">dur: <?= $durField ?></span>
                    <span title="比例字段映射">asp: <?= $aspField ?></span>
                    <span title="尺寸字段映射">sz: <?= $szField ?></span>
                    <span title="模式字段映射">im: <?= $imField ?></span>
                </div>
            </td>
            <td>
                <select name="is_active" class="compact-input" style="width:60px;">
                    <option value="1" <?= $isActive === 1 ? 'selected' : '' ?>>启用</option>
                    <option value="0" <?= $isActive !== 1 ? 'selected' : '' ?>>关闭</option>
                </select>
            </td>
            <td>
                <div class="table-action-group">
                    <button class="button secondary small" type="submit">保存</button>
                </div>
            </form>
                <form method="post" class="inline-delete-form"
                      onsubmit="return confirm('确定删除「<?= $name ?>」吗？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $mid ?>">
                    <button class="button danger small" type="submit">删除</button>
                </form>
            </td>
        </tr>
    <?php
    return ob_get_clean();
}

/* ------------------------------------------------------------------ */
/*  Render page                                                        */
/* ------------------------------------------------------------------ */

render_header('AI 模型', 'admin');
render_admin_nav('ai_models');
?>
<style>
.inline-model-form { display: contents; }
.inline-delete-form { display: inline; }
table[data-admin-models] th,
table[data-admin-models] td {
    padding: 6px 8px;
    vertical-align: middle;
    font-size: 12px;
}
table[data-admin-models] th {
    font-weight: 700;
    color: var(--text-soft);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.03em;
    white-space: nowrap;
}
.compact-input {
    width: 100%;
    min-width: 40px;
    padding: 3px 5px;
    font-size: 12px;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    background: var(--main-surface);
    color: var(--text);
    box-sizing: border-box;
}
.table-action-group {
    display: flex;
    gap: 4px;
    align-items: center;
    white-space: nowrap;
}
.table-action-group .button { padding: 3px 8px; font-size: 11px; }
.model-section-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 6px;
}
.badge-image { background: #e8f5e9; color: #2e7d32; }
.badge-video { background: #e3f2fd; color: #1565c0; }
.muted-hint { font-size: 10px; color: var(--text-muted); display: block; }
</style>

<main>
    <!-- ===================================================== -->
    <!--  新增模型表单                                          -->
    <!-- ===================================================== -->
    <section class="card" style="margin-bottom:24px;">
        <div class="card-head">
            <div>
                <p class="eyebrow">Add Model</p>
                <h2>新增 AI 模型</h2>
            </div>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:12px;">
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                    显示名称
                    <input name="name" class="compact-input" placeholder="例如：GPT Image 2" required style="font-size:13px;padding:6px;">
                </label>
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                    模型 ID
                    <input name="model_id" class="compact-input" placeholder="例如：gpt-image-2" required style="font-size:13px;padding:6px;">
                </label>
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                    Base URL
                    <input name="base_url" class="compact-input" placeholder="https://api.example.com" required style="font-size:13px;padding:6px;">
                </label>
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                    API Key
                    <input name="api_key" class="compact-input" placeholder="sk-..." required style="font-size:13px;padding:6px;">
                </label>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:12px;">
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                    排序
                    <input name="sort_order" type="number" min="0" value="0" class="compact-input" style="padding:6px;">
                </label>
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                    点数/每秒
                    <input name="credits" type="number" min="1" placeholder="留空使用默认值" class="compact-input" style="padding:6px;">
                </label>
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                    模型类型
                    <select name="model_type" id="createModelType" class="compact-input" style="padding:6px;">
                        <option value="image" selected>图片生成</option>
                        <option value="video">视频生成</option>
                        <option value="chat">AI 对话</option>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                    调用方式
                    <select name="invoke_mode" id="createInvokeMode" class="compact-input" style="padding:6px;">
                        <?= render_invoke_mode_options('image', 'relay') ?>
                    </select>
                </label>
            </div>

            <!-- 图片编辑区域 -->
            <fieldset style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px;margin-bottom:12px;">
                <legend style="font-size:12px;font-weight:600;color:var(--text-soft);padding:0 6px;">图片编辑配置</legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        支持编辑
                        <select name="supports_edit" class="compact-input" style="padding:6px;">
                            <option value="0" selected>不支持编辑</option>
                            <option value="1">支持编辑</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        图片编辑适配器
                        <span style="font-size:10px;color:var(--text-muted);">仅用于 Nano Banana 等图片参考图编辑模型</span>
                        <select name="edit_adapter" class="compact-input" style="padding:6px;">
                            <?= render_edit_adapter_options('none') ?>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        编辑图片字段
                        <select name="edit_image_field" class="compact-input" style="padding:6px;">
                            <?= render_edit_image_field_options('image_urls') ?>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        图片比例选项
                        <span style="font-size:10px;color:var(--text-muted);">逗号分隔，如: auto,1:1,16:9</span>
                        <input name="image_aspect_options" class="compact-input"
                               value="auto,1:1,16:9,9:16,4:3,3:4" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        默认图片比例
                        <input name="image_default_aspect" class="compact-input" value="auto" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        图片尺寸选项
                        <span style="font-size:10px;color:var(--text-muted);">逗号分隔，如: auto,1024x1024</span>
                        <input name="image_size_options" class="compact-input" placeholder="auto,1024x1024" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        默认图片尺寸
                        <input name="image_default_size" class="compact-input" value="auto" style="padding:6px;">
                    </label>
                </div>
            </fieldset>

            <!-- 视频配置区域 -->
            <fieldset id="createVideoFields" style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px;margin-bottom:12px;display:none;">
                <legend style="font-size:12px;font-weight:600;color:var(--text-soft);padding:0 6px;">视频模型配置</legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        视频适配器
                        <span style="font-size:10px;color:var(--text-muted);">仅用于 veo、seedance 等视频模型</span>
                        <select name="video_adapter" class="compact-input" style="padding:6px;">
                            <option value="none">不支持视频（默认）</option>
                            <option value="kaiyuncode">kaiyuncode</option>
                            <option value="newtoken_video_async" selected>newtoken_video_async（NewToken 视频）</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        支持参考图
                        <select name="supports_reference" class="compact-input" style="padding:6px;">
                            <option value="0">不支持</option>
                            <option value="1" selected>支持</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        参考图必填
                        <select name="reference_required" class="compact-input" style="padding:6px;">
                            <option value="0" selected>可选</option>
                            <option value="1">必填</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        最大参考图数
                        <input name="max_reference_images" type="number" min="1" max="16" value="9" class="compact-input" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        可选时长(秒)
                        <span style="font-size:10px;color:var(--text-muted);">逗号分隔，最多3个，如: 8,10,15</span>
                        <input name="video_duration_options" class="compact-input" placeholder="8,10,15" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        默认时长(秒)
                        <input name="video_default_duration" type="number" min="1" max="120" class="compact-input" placeholder="留空取第一个" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        可选视频比例
                        <span style="font-size:10px;color:var(--text-muted);">逗号分隔，如: 16:9,9:16,1:1</span>
                        <input name="video_aspect_options" class="compact-input"
                               value="16:9,9:16,1:1,4:3,3:4,21:9" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        默认视频比例
                        <input name="video_default_aspect" class="compact-input" value="16:9" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        可选视频尺寸
                        <span style="font-size:10px;color:var(--text-muted);">逗号分隔，如: auto,1280x720</span>
                        <input name="video_size_options" class="compact-input" placeholder="auto,1280x720" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        默认视频尺寸
                        <input name="video_default_size" class="compact-input" value="auto" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        可选参考模式
                        <span style="font-size:10px;color:var(--text-muted);">逗号分隔: text_to_video,first_frame,first_last_frame,multi_reference</span>
                        <input name="video_mode_options" class="compact-input"
                               value="text_to_video,first_frame,first_last_frame,multi_reference" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        默认参考模式
                        <select name="video_default_mode" class="compact-input" style="padding:6px;">
                            <option value="text_to_video">文生视频</option>
                            <option value="first_frame">首帧参考</option>
                            <option value="first_last_frame">首尾帧</option>
                            <option value="multi_reference" selected>多帧参考</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        参考图字段
                        <input name="video_reference_field" class="compact-input" value="reference_images" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        时长字段
                        <input name="video_duration_field" class="compact-input" value="duration" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        比例字段
                        <input name="video_aspect_field" class="compact-input" value="aspect_ratio" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        尺寸字段
                        <input name="video_size_field" class="compact-input" value="size" style="padding:6px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-soft);">
                        模式字段
                        <input name="video_input_mode_field" class="compact-input" value="input_mode" style="padding:6px;">
                    </label>
                </div>
            </fieldset>

            <button class="button primary" type="submit">添加模型</button>
        </form>
    </section>

    <!-- ===================================================== -->
    <!--  图片模型区域                                          -->
    <!-- ===================================================== -->
    <section class="card" style="margin-bottom:24px;">
        <div class="card-head">
            <div>
                <span class="model-section-badge badge-image">图片模型</span>
                <h2>图片生成 / 编辑模型</h2>
            </div>
            <span class="badge">共 <?= count($imageModels) ?> 个</span>
        </div>
        <?php if (!empty($imageModels)): ?>
        <div style="overflow-x:auto;">
            <table data-admin-models style="width:100%;min-width:900px;">
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
                        <th>编辑适配器</th>
                        <th>编辑字段</th>
                        <th>点数</th>
                        <th>状态</th>
                        <th>比例选项</th>
                        <th>尺寸选项</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imageModels as $m): ?>
                        <?= render_image_model_row($m) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="muted" style="padding:16px;">暂无图片模型，请在上方添加。</p>
        <?php endif; ?>
    </section>

    <!-- ===================================================== -->
    <!--  视频模型区域                                          -->
    <!-- ===================================================== -->
    <section class="card">
        <div class="card-head">
            <div>
                <span class="model-section-badge badge-video">视频模型</span>
                <h2>视频生成模型</h2>
            </div>
            <span class="badge">共 <?= count($videoModels) ?> 个</span>
        </div>
        <?php if (!empty($videoModels)): ?>
        <div style="overflow-x:auto;">
            <table data-admin-models style="width:100%;min-width:1400px;">
                <thead>
                    <tr>
                        <th>排序</th>
                        <th>名称</th>
                        <th>模型 ID</th>
                        <th>Base URL</th>
                        <th>API Key</th>
                        <th>类型</th>
                        <th>调用</th>
                        <th>点/秒</th>
                        <th>视频适配器</th>
                        <th>参图</th>
                        <th>必填</th>
                        <th>最大</th>
                        <th>可选时长</th>
                        <th>可选比例</th>
                        <th>可选尺寸</th>
                        <th>参考模式</th>
                        <th>字段映射</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($videoModels as $m): ?>
                        <?= render_video_model_row($m) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="muted" style="padding:16px;">暂无视频模型，请在上方添加。</p>
        <?php endif; ?>
    </section>

    <?php if (!empty($otherModels)): ?>
    <section class="card" style="margin-bottom:24px;">
        <div class="card-head">
            <div>
                <span class="model-section-badge" style="background:#f3e5f5;color:#6a1b9a;">其它模型</span>
                <h2>未分类模型</h2>
            </div>
            <span class="badge">共 <?= count($otherModels) ?> 个</span>
        </div>
        <p style="padding:8px 16px;font-size:13px;color:var(--text-muted);">
            以下模型未匹配图片或视频分类规则，不会出现在图片/视频用户界面中。
            如需启用，请修改模型名称或类型，或联系管理员。
        </p>
        <div style="overflow-x:auto;">
            <table data-admin-models style="width:100%;min-width:600px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>名称</th>
                        <th>模型 ID</th>
                        <th>类型</th>
                        <th>视频适配器</th>
                        <th>编辑适配器</th>
                        <th>状态</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($otherModels as $m): ?>
                    <tr>
                        <td><?= (int) $m['id'] ?></td>
                        <td><?= e($m['name']) ?></td>
                        <td><?= e($m['model_id']) ?></td>
                        <td><?= e($m['model_type'] ?? 'image') ?></td>
                        <td><?= e($m['video_adapter'] ?? '—') ?></td>
                        <td><?= e($m['edit_adapter'] ?? '—') ?></td>
                        <td><?= (int) $m['is_active'] === 1 ? '启用' : '关闭' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</main>

<script>
(function () {
    // Show/hide video fields based on model type in create form
    var createTypeSelect = document.getElementById('createModelType');
    var createVideoFields = document.getElementById('createVideoFields');
    var createInvokeMode = document.getElementById('createInvokeMode');

    var optionMap = {
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

    function syncInvokeMode(typeSelect, invokeModeSelect) {
        if (!typeSelect || !invokeModeSelect) return;
        var modelType = typeSelect.value;
        var opts = optionMap[modelType] || optionMap.image;
        var current = invokeModeSelect.value;
        invokeModeSelect.innerHTML = '';
        opts.forEach(function (o) {
            var node = document.createElement('option');
            node.value = o.value;
            node.textContent = o.label;
            if (o.value === current) node.selected = true;
            invokeModeSelect.appendChild(node);
        });
    }

    function showVideoFields(typeSelect, videoSection) {
        if (!typeSelect || !videoSection) return;
        videoSection.style.display = typeSelect.value === 'video' ? '' : 'none';
    }

    if (createTypeSelect && createVideoFields) {
        syncInvokeMode(createTypeSelect, createInvokeMode);
        showVideoFields(createTypeSelect, createVideoFields);
        createTypeSelect.addEventListener('change', function () {
            syncInvokeMode(createTypeSelect, createInvokeMode);
            showVideoFields(createTypeSelect, createVideoFields);
        });
    }

    // Sync invoke mode options in row-level selects
    document.querySelectorAll('.model-type-select').forEach(function (typeSel) {
        var form = typeSel.closest('form');
        if (!form) return;
        var invokeSel = form.querySelector('.invoke-mode-select');
        if (!invokeSel) return;

        function sync() {
            var modelType = typeSel.value;
            var opts = optionMap[modelType] || optionMap.image;
            var current = invokeSel.value;
            invokeSel.innerHTML = '';
            opts.forEach(function (o) {
                var node = document.createElement('option');
                node.value = o.value;
                node.textContent = o.label;
                if (o.value === current) node.selected = true;
                invokeSel.appendChild(node);
            });
        }

        sync();
        typeSel.addEventListener('change', sync);
    });
})();
</script>
<?php render_footer(); ?>
