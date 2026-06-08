<?php
/**
 * AI 模型管理后台
 *
 * 拆分为三个清晰区域：
 *   - 图片模型（绘画/编辑）
 *   - 视频模型（文生视频/图生视频/首尾帧/多帧/视频编辑）
 *   - AI 对话模型（聊天，不参与图/视频生成）
 *
 * 技术字段（adapter / field 映射）放入「展开高级接口字段」折叠区。
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$admin = require_admin();
ensure_ai_models_table();
ensure_ai_models_type_column();
ensure_ai_models_capability_columns();
ensure_generation_records_selection_columns();

/* ====================================================================
 * 工具函数
 * ==================================================================== */

function decode_json(?string $json): array
{
    if ($json === null || $json === '') return [];
    $dec = json_decode($json, true);
    return is_array($dec) ? $dec : [];
}

function encode_json_or_null(array $arr): ?string
{
    if (!is_array($arr) || count($arr) === 0) return null;
    $j = json_encode(array_values($arr), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $j !== false ? $j : null;
}

function parse_csv_options(string $raw): array
{
    $parts = array_map('trim', explode(',', $raw));
    $parts = array_filter($parts, fn($v) => $v !== '');
    return array_values(array_unique($parts));
}

function safe_json_implode(?string $json): string
{
    return implode(',', decode_json($json));
}

/* ====================================================================
 * 模型分类
 * ==================================================================== */

function classify_model(array $m): string
{
    $type = strtolower(trim($m['model_type'] ?? 'image'));
    $name = strtolower(trim($m['name'] ?? ''));
    $mid  = strtolower(trim($m['model_id'] ?? ''));

    if ($type === 'image') return 'image';
    if ($type === 'video') return 'video';

    // 图片模式关键词
    if ($type === 'chat') {
        if (preg_match('/\b(gpt|grok|claude|chat|llama|qwen|yi|deepseek|gemini|o1|o3|o4)\b/', $name)
            && !preg_match('/\b(gpt-image|banana|nana|veo|seedance|video|sora)\b/', $name)
            && !preg_match('/\b(gpt-image|banana|nana|veo|seedance|video|sora)\b/', $mid)) {
            return 'chat';
        }
        return 'other';
    }

    // 显式图片模式关键词
    foreach (['banana', 'nana', 'gpt-image'] as $kw) {
        if (strpos($name, $kw) !== false || strpos($mid, $kw) !== false) return 'image';
    }

    // 显式视频模式关键词
    foreach (['veo', 'seedance', 'video-pro', 'sora'] as $kw) {
        if (strpos($name, $kw) !== false || strpos($mid, $kw) !== false) return 'video';
    }

    return 'other';
}

/* ====================================================================
 * POST 处理
 * ==================================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name     = trim((string) ($_POST['name'] ?? ''));
        $modelId  = trim((string) ($_POST['model_id'] ?? ''));
        $baseUrl  = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey   = trim((string) ($_POST['api_key'] ?? ''));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $modelType = strtolower(trim((string) ($_POST['model_type'] ?? 'image')));
        if (!in_array($modelType, ['image', 'video', 'chat'], true)) $modelType = 'image';
        $invokeMode = $modelType === 'video'
            ? (strtolower(trim((string) ($_POST['invoke_mode'] ?? ''))) === 'kaiyuncode' ? 'kaiyuncode' : 'relay')
            : (strtolower(trim((string) ($_POST['invoke_mode'] ?? ''))) === 'curl' ? 'curl' : 'relay');

        if ($name === '' || $modelId === '' || $baseUrl === '' || $apiKey === '') {
            flash('error', '请填写完整信息。');
            redirect('/admin/ai_models');
        }

        $credits = $_POST['credits'] !== '' ? max(1, (int) $_POST['credits']) : null;
        $supportsEdit = (int) ($_POST['supports_edit'] ?? 0);
        $editAdapterRaw = strtolower(trim((string) ($_POST['edit_adapter'] ?? '')));
        $editAdapter  = in_array($editAdapterRaw, ['none','nano_banana_image_urls','openai_edits_multipart','newtoken_async_reference'], true)
            ? $editAdapterRaw : 'none';
        $editImageField = $editAdapterRaw === 'reference_images'
            ? 'reference_images' : 'image_urls';
        $supportsReference = (int) ($_POST['supports_reference'] ?? 0);
        $referenceRequired = (int) ($_POST['reference_required'] ?? 0);
        $maxRefImages = max(1, min(16, (int) ($_POST['max_reference_images'] ?? 1)));
        $maxRefVideos = max(0, (int) ($_POST['max_reference_videos'] ?? 0));
        $maxRefAudios = max(0, (int) ($_POST['max_reference_audios'] ?? 0));
        $videoAdapter = in_array(strtolower(trim((string) ($_POST['video_adapter'] ?? ''))),
            ['none','kaiyuncode','newtoken_video_async'], true)
            ? strtolower(trim((string) ($_POST['video_adapter'] ?? ''))) : 'none';

        $imgAspOpts  = encode_json_or_null(parse_csv_options((string) ($_POST['image_aspect_options'] ?? '')));
        $imgDefAsp   = trim((string) ($_POST['image_default_aspect'] ?? 'auto'));
        $imgSzOpts   = encode_json_or_null(parse_csv_options((string) ($_POST['image_size_options'] ?? '')));
        $imgDefSz    = trim((string) ($_POST['image_default_size'] ?? 'auto'));

        $vidDurOpts  = encode_json_or_null(parse_csv_options((string) ($_POST['video_duration_options'] ?? '')));
        $vidDefDur   = max(1, (int) ($_POST['video_default_duration'] ?? 0)) ?: null;
        $vidAspOpts  = encode_json_or_null(parse_csv_options((string) ($_POST['video_aspect_options'] ?? '')));
        $vidDefAsp   = trim((string) ($_POST['video_default_aspect'] ?? '16:9'));
        $vidSzOpts   = encode_json_or_null(parse_csv_options((string) ($_POST['video_size_options'] ?? '')));
        $vidDefSz    = trim((string) ($_POST['video_default_size'] ?? 'auto'));

        $allModeKeys = ['text_to_video','first_frame','first_last_frame','multi_reference','video_edit','video_reference','audio_reference'];
        $postModes = array_filter(
            array_map('trim', explode(',', (string) ($_POST['video_mode_options'] ?? ''))),
            fn($v) => $v !== ''
        );
        $vidModeOpts = encode_json_or_null(array_values(array_filter(
            $postModes, fn($v) => in_array($v, $allModeKeys, true)
        )));
        $vidDefMode  = in_array((string) ($_POST['video_default_mode'] ?? ''), $allModeKeys, true)
            ? (string) $_POST['video_default_mode'] : 'text_to_video';

        $vidRefField     = trim((string) ($_POST['video_reference_field'] ?? 'reference_images')) ?: 'reference_images';
        $vidDurField     = trim((string) ($_POST['video_duration_field'] ?? 'duration')) ?: 'duration';
        $vidAspField     = trim((string) ($_POST['video_aspect_field'] ?? 'aspect_ratio')) ?: 'aspect_ratio';
        $vidSzField      = trim((string) ($_POST['video_size_field'] ?? 'size')) ?: 'size';
        $vidImField      = trim((string) ($_POST['video_input_mode_field'] ?? 'input_mode')) ?: 'input_mode';
        $vidRefVidField  = trim((string) ($_POST['video_reference_video_field'] ?? 'extra_videos')) ?: 'extra_videos';
        $vidRefAudField  = trim((string) ($_POST['video_reference_audio_field'] ?? 'extra_audios')) ?: 'extra_audios';

        if ($supportsEdit && $editAdapter === 'none') {
            flash('error', '如果要启用编辑功能，请选择有效的图片编辑接口类型。');
            redirect('/admin/ai_models');
        }

        $stmt = db()->prepare(
            'INSERT INTO ai_models (name, model_id, base_url, api_key, model_type, credits, invoke_mode, '
            . 'supports_edit, edit_adapter, edit_image_field, '
            . 'supports_reference, reference_required, max_reference_images, '
            . 'max_reference_videos, max_reference_audios, video_adapter, '
            . 'image_aspect_options_json, image_default_aspect, image_size_options_json, image_default_size, '
            . 'video_duration_options_json, video_default_duration, '
            . 'video_aspect_options_json, video_default_aspect, '
            . 'video_size_options_json, video_default_size, '
            . 'video_mode_options_json, video_default_mode, '
            . 'video_reference_field, video_duration_field, video_aspect_field, video_size_field, '
            . 'video_input_mode_field, video_reference_video_field, video_reference_audio_field, '
            . 'sort_order) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name, $modelId, $baseUrl, $apiKey, $modelType, $credits, $invokeMode,
            $supportsEdit, $editAdapter, $editImageField,
            $supportsReference, $referenceRequired, $maxRefImages,
            $maxRefVideos, $maxRefAudios, $videoAdapter,
            $imgAspOpts, $imgDefAsp, $imgSzOpts, $imgDefSz,
            $vidDurOpts, $vidDefDur, $vidAspOpts, $vidDefAsp,
            $vidSzOpts, $vidDefSz, $vidModeOpts, $vidDefMode,
            $vidRefField, $vidDurField, $vidAspField, $vidSzField,
            $vidImField, $vidRefVidField, $vidRefAudField,
            $sortOrder,
        ]);
        flash('success', '模型已添加。');
        redirect('/admin/ai_models');
    }

    if ($action === 'update') {
        $id        = (int) ($_POST['id'] ?? 0);
        $name      = trim((string) ($_POST['name'] ?? ''));
        $modelId   = trim((string) ($_POST['model_id'] ?? ''));
        $baseUrl   = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey    = trim((string) ($_POST['api_key'] ?? ''));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $isActive  = (int) ($_POST['is_active'] ?? 1);
        $modelType = strtolower(trim((string) ($_POST['model_type'] ?? 'image')));
        if (!in_array($modelType, ['image', 'video', 'chat'], true)) $modelType = 'image';
        $invokeMode = $modelType === 'video'
            ? (strtolower(trim((string) ($_POST['invoke_mode'] ?? ''))) === 'kaiyuncode' ? 'kaiyuncode' : 'relay')
            : (strtolower(trim((string) ($_POST['invoke_mode'] ?? ''))) === 'curl' ? 'curl' : 'relay');

        if ($id < 1 || $name === '' || $modelId === '' || $baseUrl === '') {
            flash('error', '参数不合法。');
            redirect('/admin/ai_models');
        }

        $credits = $_POST['credits'] !== '' ? max(1, (int) $_POST['credits']) : null;
        $supportsEdit = (int) ($_POST['supports_edit'] ?? 0);
        $editAdapterRaw = strtolower(trim((string) ($_POST['edit_adapter'] ?? '')));
        $editAdapter  = in_array($editAdapterRaw, ['none','nano_banana_image_urls','openai_edits_multipart','newtoken_async_reference'], true)
            ? $editAdapterRaw : 'none';
        $editImageField = $editAdapterRaw === 'reference_images'
            ? 'reference_images' : 'image_urls';
        $supportsReference = (int) ($_POST['supports_reference'] ?? 0);
        $referenceRequired = (int) ($_POST['reference_required'] ?? 0);
        $maxRefImages = max(1, min(16, (int) ($_POST['max_reference_images'] ?? 1)));
        $maxRefVideos = max(0, (int) ($_POST['max_reference_videos'] ?? 0));
        $maxRefAudios = max(0, (int) ($_POST['max_reference_audios'] ?? 0));
        $videoAdapter = in_array(strtolower(trim((string) ($_POST['video_adapter'] ?? ''))),
            ['none','kaiyuncode','newtoken_video_async'], true)
            ? strtolower(trim((string) ($_POST['video_adapter'] ?? ''))) : 'none';

        $imgAspOpts  = encode_json_or_null(parse_csv_options((string) ($_POST['image_aspect_options'] ?? '')));
        $imgDefAsp   = trim((string) ($_POST['image_default_aspect'] ?? 'auto'));
        $imgSzOpts   = encode_json_or_null(parse_csv_options((string) ($_POST['image_size_options'] ?? '')));
        $imgDefSz    = trim((string) ($_POST['image_default_size'] ?? 'auto'));

        $vidDurOpts  = encode_json_or_null(parse_csv_options((string) ($_POST['video_duration_options'] ?? '')));
        $vidDefDur   = max(1, (int) ($_POST['video_default_duration'] ?? 0)) ?: null;
        $vidAspOpts  = encode_json_or_null(parse_csv_options((string) ($_POST['video_aspect_options'] ?? '')));
        $vidDefAsp   = trim((string) ($_POST['video_default_aspect'] ?? '16:9'));
        $vidSzOpts   = encode_json_or_null(parse_csv_options((string) ($_POST['video_size_options'] ?? '')));
        $vidDefSz    = trim((string) ($_POST['video_default_size'] ?? 'auto'));

        $allModeKeys = ['text_to_video','first_frame','first_last_frame','multi_reference','video_edit','video_reference','audio_reference'];
        $postModes = array_filter(
            array_map('trim', explode(',', (string) ($_POST['video_mode_options'] ?? ''))),
            fn($v) => $v !== ''
        );
        $vidModeOpts = encode_json_or_null(array_values(array_filter(
            $postModes, fn($v) => in_array($v, $allModeKeys, true)
        )));
        $vidDefMode  = in_array((string) ($_POST['video_default_mode'] ?? ''), $allModeKeys, true)
            ? (string) $_POST['video_default_mode'] : 'text_to_video';

        $vidRefField     = trim((string) ($_POST['video_reference_field'] ?? 'reference_images')) ?: 'reference_images';
        $vidDurField     = trim((string) ($_POST['video_duration_field'] ?? 'duration')) ?: 'duration';
        $vidAspField     = trim((string) ($_POST['video_aspect_field'] ?? 'aspect_ratio')) ?: 'aspect_ratio';
        $vidSzField      = trim((string) ($_POST['video_size_field'] ?? 'size')) ?: 'size';
        $vidImField      = trim((string) ($_POST['video_input_mode_field'] ?? 'input_mode')) ?: 'input_mode';
        $vidRefVidField  = trim((string) ($_POST['video_reference_video_field'] ?? 'extra_videos')) ?: 'extra_videos';
        $vidRefAudField  = trim((string) ($_POST['video_reference_audio_field'] ?? 'extra_audios')) ?: 'extra_audios';

        $base = 'name=?, model_id=?, base_url=?, model_type=?, credits=?, invoke_mode=?, '
            . 'supports_edit=?, edit_adapter=?, edit_image_field=?, '
            . 'supports_reference=?, reference_required=?, max_reference_images=?, '
            . 'max_reference_videos=?, max_reference_audios=?, video_adapter=?, '
            . 'image_aspect_options_json=?, image_default_aspect=?, image_size_options_json=?, image_default_size=?, '
            . 'video_duration_options_json=?, video_default_duration=?, '
            . 'video_aspect_options_json=?, video_default_aspect=?, '
            . 'video_size_options_json=?, video_default_size=?, '
            . 'video_mode_options_json=?, video_default_mode=?, '
            . 'video_reference_field=?, video_duration_field=?, video_aspect_field=?, video_size_field=?, '
            . 'video_input_mode_field=?, video_reference_video_field=?, video_reference_audio_field=?, '
            . 'sort_order=?, is_active=?';
        $vals = [
            $name, $modelId, $baseUrl, $modelType, $credits, $invokeMode,
            $supportsEdit, $editAdapter, $editImageField,
            $supportsReference, $referenceRequired, $maxRefImages,
            $maxRefVideos, $maxRefAudios, $videoAdapter,
            $imgAspOpts, $imgDefAsp, $imgSzOpts, $imgDefSz,
            $vidDurOpts, $vidDefDur, $vidAspOpts, $vidDefAsp,
            $vidSzOpts, $vidDefSz, $vidModeOpts, $vidDefMode,
            $vidRefField, $vidDurField, $vidAspField, $vidSzField,
            $vidImField, $vidRefVidField, $vidRefAudField,
            $sortOrder, $isActive,
        ];

        if ($apiKey !== '') {
            $sql = "UPDATE ai_models SET name=?, model_id=?, base_url=?, api_key=?, $base WHERE id=?";
            $vals = array_merge([$name, $modelId, $baseUrl, $apiKey], $vals, [$id]);
        } else {
            $sql = "UPDATE ai_models SET name=?, model_id=?, base_url=?, $base WHERE id=?";
            $vals = array_merge([$name, $modelId, $baseUrl], $vals, [$id]);
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($vals);
        flash('success', '模型已更新。');
        redirect('/admin/ai_models');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            flash('error', '参数不合法。');
            redirect('/admin/ai_models');
        }
        db()->prepare('DELETE FROM ai_models WHERE id = ?')->execute([$id]);
        flash('success', '模型已删除。');
        redirect('/admin/ai_models');
    }

    flash('error', '未知操作。');
    redirect('/admin/ai_models');
}

/* ====================================================================
 * 加载数据
 * ==================================================================== */

$allModels = db()->query('SELECT * FROM ai_models ORDER BY sort_order ASC, id ASC')->fetchAll();

$imageModels = [];
$videoModels = [];
$chatModels  = [];
$otherModels = [];

foreach ($allModels as $m) {
    $cat = classify_model($m);
    if ($cat === 'image') $imageModels[] = $m;
    elseif ($cat === 'video') $videoModels[] = $m;
    elseif ($cat === 'chat') $chatModels[] = $m;
    else $otherModels[] = $m;
}

/* ====================================================================
 * 视频模式配置
 * ==================================================================== */

const VIDEO_MODE_OPTIONS = [
    'text_to_video'       => '文生视频',
    'first_frame'         => '首帧参考',
    'first_last_frame'    => '首尾帧',
    'multi_reference'     => '多帧参考',
    'video_edit'          => '视频编辑',
    'video_reference'     => '视频参考',
    'audio_reference'     => '音频参考',
];

function video_mode_checkboxes(array $savedModes): string
{
    $html = '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
    foreach (VIDEO_MODE_OPTIONS as $k => $label) {
        $checked = in_array($k, $savedModes, true) ? ' checked' : '';
        $id = 'mode_' . $k;
        $html .= '<label style="display:flex;align-items:center;gap:3px;font-size:12px;cursor:pointer;">'
            . "<input type=\"checkbox\" name=\"video_mode_cb_$k\" id=\"$id\" value=\"$k\"$checked "
            . 'onchange="syncVideoModeFromCheckboxes(this)">'
            . e($label) . '</label>';
    }
    $html .= '</div>';
    $savedStr = implode(',', $savedModes);
    $html .= '<input type="hidden" name="video_mode_options" id="videoModeHidden" value="' . e($savedStr) . '">';
    return $html;
}

function video_mode_select(string $selected): string
{
    $html = '<select name="video_default_mode" class="compact-input" style="width:100px;">';
    foreach (VIDEO_MODE_OPTIONS as $k => $label) {
        $sel = $k === $selected ? ' selected' : '';
        $html .= '<option value="' . e($k) . '"' . $sel . '>' . e($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/* ====================================================================
 * 渲染页面
 * ==================================================================== */

render_header('AI 模型管理', 'admin');
render_admin_nav('ai_models');
?>
<style>
.inline-model-form { display: contents; }
.inline-delete-form { display: inline; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); font-size: 12px; vertical-align: middle; }
th { font-weight: 700; color: var(--text-soft); text-transform: uppercase; font-size: 10px; letter-spacing: 0.03em; white-space: nowrap; }
.compact-input { width: 100%; min-width: 40px; padding: 3px 5px; font-size: 12px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--main-surface); color: var(--text); box-sizing: border-box; }
.table-action-group { display: flex; gap: 4px; align-items: center; white-space: nowrap; }
.table-action-group .button { padding: 3px 8px; font-size: 11px; }
.section-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; margin-bottom: 4px; }
.badge-image   { background: #e8f5e9; color: #2e7d32; }
.badge-video   { background: #e3f2fd; color: #1565c0; }
.badge-chat    { background: #fff8e1; color: #f57f17; }
.badge-other   { background: #f3e5f5; color: #6a1b9a; }
.muted-hint { font-size: 10px; color: var(--text-muted); display: block; }
.field-group { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
.field { display: flex; flex-direction: column; gap: 4px; }
.field label { font-size: 12px; color: var(--text-soft); font-weight: 600; }
.field .hint { font-size: 10px; color: var(--text-muted); }
.adv-toggle { background: none; border: none; color: var(--accent); cursor: pointer; font-size: 12px; padding: 4px 0; text-align: left; }
.adv-toggle:hover { text-decoration: underline; }
.adv-section { display: none; margin-top: 8px; padding: 8px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--main-surface-soft); }
.adv-section.open { display: block; }
.adv-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 11px; color: var(--text-muted); }
.adv-row strong { color: var(--text-soft); min-width: 90px; }
</style>

<main>

<!-- =================================================================== -->
<!-- 新增模型 -->
<!-- =================================================================== -->
<section class="card" style="margin-bottom:24px;">
    <div class="card-head">
        <div><p class="eyebrow">Add Model</p><h2>新增 AI 模型</h2></div>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field-group">
            <div class="field">
                <label>显示名称</label>
                <input name="name" class="compact-input" placeholder="例如：GPT Image 2" required style="font-size:13px;padding:6px;">
            </div>
            <div class="field">
                <label>模型 ID</label>
                <input name="model_id" class="compact-input" placeholder="例如：gpt-image-2" required style="font-size:13px;padding:6px;">
            </div>
            <div class="field">
                <label>Base URL</label>
                <input name="base_url" class="compact-input" placeholder="https://api.example.com" required style="font-size:13px;padding:6px;">
            </div>
            <div class="field">
                <label>API Key</label>
                <input name="api_key" class="compact-input" placeholder="sk-..." required style="font-size:13px;padding:6px;">
            </div>
        </div>
        <div class="field-group">
            <div class="field">
                <label>排序</label>
                <input name="sort_order" type="number" min="0" value="0" class="compact-input" style="padding:6px;">
            </div>
            <div class="field">
                <label>点数/秒</label>
                <input name="credits" type="number" min="1" placeholder="留空使用默认值" class="compact-input" style="padding:6px;">
            </div>
            <div class="field">
                <label>模型类型</label>
                <select name="model_type" id="createModelType" class="compact-input" style="padding:6px;" onchange="onCreateTypeChange()">
                    <option value="image" selected>图片生成</option>
                    <option value="video">视频生成</option>
                    <option value="chat">AI 对话</option>
                </select>
            </div>
            <div class="field">
                <label>调用方式</label>
                <select name="invoke_mode" id="createInvokeMode" class="compact-input" style="padding:6px;">
                    <option value="relay">中转站（默认）</option>
                    <option value="curl">curl</option>
                </select>
            </div>
        </div>

        <!-- 图片配置（图片模型） -->
        <div id="createImageFields">
            <fieldset style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px;margin-bottom:12px;">
                <legend style="font-size:12px;font-weight:600;color:var(--text-soft);padding:0 6px;">图片模型配置</legend>
                <div class="field-group">
                    <div class="field">
                        <label>支持图片编辑</label>
                        <select name="supports_edit" class="compact-input" style="padding:6px;">
                            <option value="0" selected>不支持编辑</option>
                            <option value="1">支持编辑</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>图片编辑接口类型</label>
                        <span class="hint">仅用于 Nano Banana 等图片参考图编辑模型</span>
                        <select name="edit_adapter" class="compact-input" style="padding:6px;">
                            <option value="none">不支持编辑（默认）</option>
                            <option value="newtoken_async_reference">newtoken_async_reference（Nano Banana）</option>
                            <option value="nano_banana_image_urls">nano_banana_image_urls</option>
                            <option value="openai_edits_multipart">openai_edits_multipart</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>参考图字段</label>
                        <select name="edit_image_field" class="compact-input" style="padding:6px;">
                            <option value="image_urls">image_urls（默认）</option>
                            <option value="reference_images">reference_images</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>图片比例选项</label>
                        <span class="hint">逗号分隔，如: auto,1:1,16:9</span>
                        <input name="image_aspect_options" class="compact-input" value="auto,1:1,16:9,9:16,4:3,3:4" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>默认图片比例</label>
                        <input name="image_default_aspect" class="compact-input" value="auto" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>图片尺寸选项</label>
                        <span class="hint">逗号分隔，如: auto,1024x1024</span>
                        <input name="image_size_options" class="compact-input" placeholder="auto,1024x1024" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>默认图片尺寸</label>
                        <input name="image_default_size" class="compact-input" value="auto" style="padding:6px;">
                    </div>
                </div>
            </fieldset>
        </div>

        <!-- 视频配置（视频模型） -->
        <div id="createVideoFields" style="display:none;">
            <fieldset style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px;margin-bottom:12px;">
                <legend style="font-size:12px;font-weight:600;color:var(--text-soft);padding:0 6px;">视频模型配置</legend>
                <div class="field-group">
                    <div class="field">
                        <label>视频接口类型</label>
                        <span class="hint">用于 veo、seedance 等视频模型</span>
                        <select name="video_adapter" class="compact-input" style="padding:6px;">
                            <option value="none">不支持视频（默认）</option>
                            <option value="newtoken_video_async" selected>newtoken_video_async（NewToken 视频）</option>
                            <option value="kaiyuncode">kaiyuncode</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>支持参考素材</label>
                        <select name="supports_reference" class="compact-input" style="padding:6px;">
                            <option value="0">不支持</option>
                            <option value="1" selected>支持</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>参考素材必填</label>
                        <select name="reference_required" class="compact-input" style="padding:6px;">
                            <option value="0" selected>可选</option>
                            <option value="1">必填</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>最大参考图片数</label>
                        <input name="max_reference_images" type="number" min="1" max="16" value="9" class="compact-input" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>最大参考视频数</label>
                        <input name="max_reference_videos" type="number" min="0" max="9" value="0" class="compact-input" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>最大参考音频数</label>
                        <input name="max_reference_audios" type="number" min="0" max="9" value="0" class="compact-input" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>可选时长（秒）</label>
                        <span class="hint">逗号分隔，最多3个，如: 8,10,15</span>
                        <input name="video_duration_options" class="compact-input" placeholder="8,10,15" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>默认时长（秒）</label>
                        <input name="video_default_duration" type="number" min="1" max="120" class="compact-input" placeholder="留空取第一个" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>可选比例</label>
                        <span class="hint">逗号分隔，如: 16:9,9:16,1:1</span>
                        <input name="video_aspect_options" class="compact-input" value="16:9,9:16,1:1,4:3,3:4,21:9" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>默认比例</label>
                        <input name="video_default_aspect" class="compact-input" value="16:9" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>可选分辨率/尺寸</label>
                        <span class="hint">逗号分隔，如: auto,1280x720</span>
                        <input name="video_size_options" class="compact-input" placeholder="auto,1280x720" style="padding:6px;">
                    </div>
                    <div class="field">
                        <label>默认分辨率/尺寸</label>
                        <input name="video_default_size" class="compact-input" value="auto" style="padding:6px;">
                    </div>
                </div>
                <div style="margin-top:8px;">
                    <div style="font-size:12px;color:var(--text-soft);font-weight:600;margin-bottom:6px;">可选生成模式（勾选）</div>
                    <div id="createModeCheckboxes">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <?php foreach (VIDEO_MODE_OPTIONS as $k => $label): ?>
                            <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;">
                                <input type="checkbox" value="<?= e($k) ?>" onchange="syncCreateModeFromCheckboxes()"><?= e($label) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="video_mode_options" id="createModeHidden" value="text_to_video">
                    </div>
                    <div style="margin-top:8px;display:flex;align-items:center;gap:8px;font-size:12px;">
                        <strong>默认模式：</strong>
                        <select name="video_default_mode" class="compact-input" style="width:120px;padding:4px;">
                            <?php foreach (VIDEO_MODE_OPTIONS as $k => $label): ?>
                            <option value="<?= e($k) ?>" <?= $k === 'multi_reference' ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="button" class="adv-toggle" onclick="toggleAdv(this)">展开高级接口字段 ▼</button>
                <div class="adv-section">
                    <div class="adv-row">
                        <strong>提交路径：</strong><input name="edit_endpoint" class="compact-input" placeholder="/v1/images" style="width:160px;">
                        <strong>轮询路径：</strong><input name="edit_poll_endpoint" class="compact-input" placeholder="/v1/videos/{task_id}" style="width:180px;">
                    </div>
                    <div class="adv-row">
                        <strong>参考图字段：</strong><input name="video_reference_field" class="compact-input" value="reference_images" style="width:140px;">
                        <strong>参考视频字段：</strong><input name="video_reference_video_field" class="compact-input" value="extra_videos" style="width:140px;">
                        <strong>参考音频字段：</strong><input name="video_reference_audio_field" class="compact-input" value="extra_audios" style="width:140px;">
                    </div>
                    <div class="adv-row">
                        <strong>时长字段：</strong><input name="video_duration_field" class="compact-input" value="duration" style="width:120px;">
                        <strong>比例字段：</strong><input name="video_aspect_field" class="compact-input" value="aspect_ratio" style="width:120px;">
                        <strong>尺寸字段：</strong><input name="video_size_field" class="compact-input" value="size" style="width:120px;">
                        <strong>模式字段：</strong><input name="video_input_mode_field" class="compact-input" value="input_mode" style="width:120px;">
                    </div>
                </div>
            </fieldset>
        </div>

        <button class="button primary" type="submit">添加模型</button>
    </form>
</section>

<!-- =================================================================== -->
<!-- 图片模型 -->
<!-- =================================================================== -->
<section class="card" style="margin-bottom:24px;">
    <div class="card-head">
        <div>
            <div class="section-badge badge-image">图片模型</div>
            <h2>图片生成 / 编辑模型</h2>
            <p style="font-size:12px;color:var(--text-muted);margin-top:2px;">用于图片绘画、图片编辑、参考图改图</p>
        </div>
        <span class="badge">共 <?= count($imageModels) ?> 个</span>
    </div>
    <?php if (!empty($imageModels)): ?>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>排序</th><th>名称</th><th>模型 ID</th><th>Base URL</th><th>API Key</th>
                    <th>点数/次</th><th>状态</th><th>编辑</th>
                    <th>编辑接口类型</th><th>最大参考图数</th>
                    <th>比例选项</th><th>默认比例</th>
                    <th>尺寸选项</th><th>默认尺寸</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($imageModels as $m): ?>
                <?php
                $mid = (int) $m['id'];
                $isActive = (int) $m['is_active'];
                $supportsEdit = (int) ($m['supports_edit'] ?? 0);
                $editAdapter = strtolower(trim((string) ($m['edit_adapter'] ?? 'none')));
                $imgAspOpts = decode_json($m['image_aspect_options_json'] ?? null);
                $imgDefAsp = trim((string) ($m['image_default_aspect'] ?? 'auto'));
                $imgSzOpts = decode_json($m['image_size_options_json'] ?? null);
                $imgDefSz = trim((string) ($m['image_default_size'] ?? 'auto'));
                $maxRefImg = max(1, (int) ($m['max_reference_images'] ?? 1));
                $editAdapterLabel = [
                    'none' => '不支持（默认）',
                    'newtoken_async_reference' => 'newtoken_async_reference（Nano Banana）',
                    'nano_banana_image_urls' => 'nano_banana_image_urls',
                    'openai_edits_multipart' => 'openai_edits_multipart',
                ][$editAdapter] ?? $editAdapter;
                ?>
                <tr>
                    <form method="post" class="inline-model-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $mid ?>">
                        <td><input name="sort_order" type="number" min="0" class="compact-input" value="<?= (int) $m['sort_order'] ?>" style="width:52px;"></td>
                        <td><input name="name" class="compact-input" value="<?= e($m['name']) ?>" required style="min-width:100px;"></td>
                        <td><input name="model_id" class="compact-input" value="<?= e($m['model_id']) ?>" required style="min-width:110px;"></td>
                        <td><input name="base_url" class="compact-input" value="<?= e($m['base_url']) ?>" required style="min-width:140px;"></td>
                        <td><span class="muted-hint">已配置</span><input name="api_key" type="password" class="compact-input" placeholder="留空不修改" autocomplete="off" style="width:80px;"></td>
                        <td><input name="credits" type="number" min="1" class="compact-input" value="<?= (int) ($m['credits'] ?? 0) ?: '' ?>" placeholder="默认" style="width:52px;"></td>
                        <td>
                            <select name="is_active" class="compact-input" style="width:60px;">
                                <option value="1" <?= $isActive===1?'selected':'' ?>>启用</option>
                                <option value="0" <?= $isActive!==1?'selected':'' ?>>关闭</option>
                            </select>
                        </td>
                        <td>
                            <select name="supports_edit" class="compact-input" style="width:56px;">
                                <option value="0" <?= $supportsEdit===0?'selected':'' ?>>否</option>
                                <option value="1" <?= $supportsEdit===1?'selected':'' ?>>是</option>
                            </select>
                        </td>
                        <td>
                            <select name="edit_adapter" class="compact-input" style="min-width:140px;">
                                <option value="none" <?= $editAdapter==='none'?'selected':'' ?>>不支持（默认）</option>
                                <option value="newtoken_async_reference" <?= $editAdapter==='newtoken_async_reference'?'selected':'' ?>>newtoken_async_reference</option>
                                <option value="nano_banana_image_urls" <?= $editAdapter==='nano_banana_image_urls'?'selected':'' ?>>nano_banana_image_urls</option>
                                <option value="openai_edits_multipart" <?= $editAdapter==='openai_edits_multipart'?'selected':'' ?>>openai_edits_multipart</option>
                            </select>
                        </td>
                        <td><input name="max_reference_images" type="number" min="1" max="16" class="compact-input" value="<?= $maxRefImg ?>" style="width:44px;"></td>
                        <td><input name="image_aspect_options" class="compact-input" value="<?= e(implode(',', $imgAspOpts)) ?>" placeholder="auto,1:1,16:9" style="width:100px;"></td>
                        <td><input name="image_default_aspect" class="compact-input" value="<?= e($imgDefAsp) ?>" style="width:60px;"></td>
                        <td><input name="image_size_options" class="compact-input" value="<?= e(implode(',', $imgSzOpts)) ?>" placeholder="auto,1024x1024" style="width:100px;"></td>
                        <td><input name="image_default_size" class="compact-input" value="<?= e($imgDefSz) ?>" style="width:80px;"></td>
                        <td>
                            <div class="table-action-group">
                                <button class="button secondary small" type="submit">保存</button>
                            </div>
                        </form>
                            <form method="post" class="inline-delete-form" onsubmit="return confirm('确定删除「<?= e($m['name']) ?>」吗？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $mid ?>">
                                <button class="button danger small" type="submit">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="padding:16px;color:var(--text-muted);">暂无图片模型，请在上方添加。</p>
    <?php endif; ?>
</section>

<!-- =================================================================== -->
<!-- 视频模型 -->
<!-- =================================================================== -->
<section class="card" style="margin-bottom:24px;">
    <div class="card-head">
        <div>
            <div class="section-badge badge-video">视频模型</div>
            <h2>视频生成模型</h2>
            <p style="font-size:12px;color:var(--text-muted);margin-top:2px;">用于文生视频、图生视频、首尾帧、多帧参考、视频编辑</p>
        </div>
        <span class="badge">共 <?= count($videoModels) ?> 个</span>
    </div>
    <?php if (!empty($videoModels)): ?>
    <div style="overflow-x:auto;">
        <table style="min-width:1400px;">
            <thead>
                <tr>
                    <th>排序</th><th>名称</th><th>模型 ID</th><th>Base URL</th><th>API Key</th>
                    <th>点/秒</th><th>状态</th><th>视频接口类型</th>
                    <th>参图</th><th>参视</th><th>参音</th>
                    <th>可选时长</th><th>默认时长</th>
                    <th>可选比例</th><th>默认比例</th>
                    <th>可选分辨率</th><th>默认分辨率</th>
                    <th>生成模式（勾选）</th><th>默认模式</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($videoModels as $m): ?>
                <?php
                $mid = (int) $m['id'];
                $isActive = (int) $m['is_active'];
                $credits = (int) ($m['credits'] ?? 0);
                $vidAdapter = strtolower(trim((string) ($m['video_adapter'] ?? 'none')));
                $vidAdapterLabel = [
                    'none' => '无（默认）',
                    'newtoken_video_async' => 'newtoken（NewToken 视频）',
                    'kaiyuncode' => 'kaiyuncode',
                ][$vidAdapter] ?? $vidAdapter;
                $supportsRef = (int) ($m['supports_reference'] ?? 0);
                $maxRefImg = max(0, (int) ($m['max_reference_images'] ?? 0));
                $maxRefVid = max(0, (int) ($m['max_reference_videos'] ?? 0));
                $maxRefAud = max(0, (int) ($m['max_reference_audios'] ?? 0));
                $durOpts = decode_json($m['video_duration_options_json'] ?? null);
                $defDur  = (int) ($m['video_default_duration'] ?? 0);
                $aspOpts = decode_json($m['video_aspect_options_json'] ?? null);
                $defAsp  = trim((string) ($m['video_default_aspect'] ?? '16:9'));
                $szOpts  = decode_json($m['video_size_options_json'] ?? null);
                $defSz   = trim((string) ($m['video_default_size'] ?? 'auto'));
                $modeOpts = decode_json($m['video_mode_options_json'] ?? null);
                $defMode  = trim((string) ($m['video_default_mode'] ?? 'text_to_video'));
                ?>
                <tr>
                    <form method="post" class="inline-model-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $mid ?>">
                        <td><input name="sort_order" type="number" min="0" class="compact-input" value="<?= (int) $m['sort_order'] ?>" style="width:52px;"></td>
                        <td><input name="name" class="compact-input" value="<?= e($m['name']) ?>" required style="min-width:110px;"></td>
                        <td><input name="model_id" class="compact-input" value="<?= e($m['model_id']) ?>" required style="min-width:120px;"></td>
                        <td><input name="base_url" class="compact-input" value="<?= e($m['base_url']) ?>" required style="min-width:130px;"></td>
                        <td><span class="muted-hint">已配置</span><input name="api_key" type="password" class="compact-input" placeholder="留空不修改" autocomplete="off" style="width:80px;"></td>
                        <td><input name="credits" type="number" min="1" class="compact-input" value="<?= $credits ?: '' ?>" placeholder="默认" style="width:44px;"></td>
                        <td>
                            <select name="is_active" class="compact-input" style="width:60px;">
                                <option value="1" <?= $isActive===1?'selected':'' ?>>启用</option>
                                <option value="0" <?= $isActive!==1?'selected':'' ?>>关闭</option>
                            </select>
                        </td>
                        <td>
                            <select name="video_adapter" class="compact-input" style="min-width:130px;">
                                <option value="none" <?= $vidAdapter==='none'?'selected':'' ?>>无（默认）</option>
                                <option value="newtoken_video_async" <?= $vidAdapter==='newtoken_video_async'?'selected':'' ?>>newtoken（NewToken）</option>
                                <option value="kaiyuncode" <?= $vidAdapter==='kaiyuncode'?'selected':'' ?>>kaiyuncode</option>
                            </select>
                        </td>
                        <td><input name="max_reference_images" type="number" min="0" max="16" class="compact-input" value="<?= $maxRefImg ?>" style="width:40px;" title="最大参考图片数"></td>
                        <td><input name="max_reference_videos" type="number" min="0" max="9" class="compact-input" value="<?= $maxRefVid ?>" style="width:40px;" title="最大参考视频数"></td>
                        <td><input name="max_reference_audios" type="number" min="0" max="9" class="compact-input" value="<?= $maxRefAud ?>" style="width:40px;" title="最大参考音频数"></td>
                        <td><input name="video_duration_options" class="compact-input" value="<?= e(implode(',', $durOpts)) ?>" placeholder="8,10,15" style="width:80px;"></td>
                        <td><input name="video_default_duration" type="number" min="1" max="120" class="compact-input" value="<?= $defDur ?: '' ?>" placeholder="留空" style="width:44px;"></td>
                        <td><input name="video_aspect_options" class="compact-input" value="<?= e(implode(',', $aspOpts)) ?>" placeholder="16:9,9:16" style="width:80px;"></td>
                        <td><input name="video_default_aspect" class="compact-input" value="<?= e($defAsp) ?>" style="width:56px;"></td>
                        <td><input name="video_size_options" class="compact-input" value="<?= e(implode(',', $szOpts)) ?>" placeholder="auto,1280x720" style="width:100px;"></td>
                        <td><input name="video_default_size" class="compact-input" value="<?= e($defSz) ?>" style="width:70px;"></td>
                        <td>
                            <div style="display:flex;flex-wrap:wrap;gap:3px;font-size:10px;min-width:200px;">
                                <?php foreach (VIDEO_MODE_OPTIONS as $k => $label): ?>
                                <label style="display:flex;align-items:center;gap:2px;cursor:pointer;">
                                    <input type="checkbox" class="mode-cb" data-model="<?= $mid ?>" data-key="<?= e($k) ?>" <?= in_array($k, $modeOpts, true) ? 'checked' : '' ?> onchange="syncModeHidden(<?= $mid ?>)"><?= e($label) ?>
                                </label>
                                <?php endforeach; ?>
                                <input type="hidden" name="video_mode_options" id="modeHidden_<?= $mid ?>" value="<?= e(implode(',', $modeOpts)) ?>">
                            </div>
                        </td>
                        <td>
                            <select name="video_default_mode" class="compact-input" style="width:90px;">
                                <?php foreach (VIDEO_MODE_OPTIONS as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= $defMode===$k?'selected':'' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <div class="table-action-group">
                                <button class="button secondary small" type="submit">保存</button>
                            </div>
                        </form>
                            <form method="post" class="inline-delete-form" onsubmit="return confirm('确定删除「<?= e($m['name']) ?>」吗？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $mid ?>">
                                <button class="button danger small" type="submit">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="padding:16px;color:var(--text-muted);">暂无视频模型，请在上方添加。</p>
    <?php endif; ?>
</section>

<!-- =================================================================== -->
<!-- AI 对话模型 -->
<!-- =================================================================== -->
<section class="card" style="margin-bottom:24px;">
    <div class="card-head">
        <div>
            <div class="section-badge badge-chat">AI 对话模型</div>
            <h2>AI 对话模型</h2>
            <p style="font-size:12px;color:var(--text-muted);margin-top:2px;">用于聊天、文本、工具调用，不参与图片/视频生成</p>
        </div>
        <span class="badge">共 <?= count($chatModels) ?> 个</span>
    </div>
    <?php if (!empty($chatModels)): ?>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>排序</th><th>名称</th><th>模型 ID</th><th>Base URL</th><th>API Key</th>
                    <th>调用方式</th><th>点数</th><th>状态</th><th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chatModels as $m): ?>
                <?php
                $mid = (int) $m['id'];
                $isActive = (int) $m['is_active'];
                $invokeMode = strtolower(trim((string) ($m['invoke_mode'] ?? 'relay')));
                ?>
                <tr>
                    <form method="post" class="inline-model-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $mid ?>">
                        <td><input name="sort_order" type="number" min="0" class="compact-input" value="<?= (int) $m['sort_order'] ?>" style="width:52px;"></td>
                        <td><input name="name" class="compact-input" value="<?= e($m['name']) ?>" required style="min-width:100px;"></td>
                        <td><input name="model_id" class="compact-input" value="<?= e($m['model_id']) ?>" required style="min-width:110px;"></td>
                        <td><input name="base_url" class="compact-input" value="<?= e($m['base_url']) ?>" required style="min-width:140px;"></td>
                        <td><span class="muted-hint">已配置</span><input name="api_key" type="password" class="compact-input" placeholder="留空不修改" autocomplete="off" style="width:80px;"></td>
                        <td>
                            <select name="invoke_mode" class="compact-input" style="width:100px;">
                                <option value="relay" <?= $invokeMode==='relay'?'selected':'' ?>>中转站</option>
                                <option value="curl" <?= $invokeMode==='curl'?'selected':'' ?>>curl</option>
                            </select>
                        </td>
                        <td><input name="credits" type="number" min="1" class="compact-input" value="<?= (int) ($m['credits'] ?? 0) ?: '' ?>" placeholder="默认" style="width:52px;"></td>
                        <td>
                            <select name="is_active" class="compact-input" style="width:60px;">
                                <option value="1" <?= $isActive===1?'selected':'' ?>>启用</option>
                                <option value="0" <?= $isActive!==1?'selected':'' ?>>关闭</option>
                            </select>
                        </td>
                        <td>
                            <div class="table-action-group">
                                <button class="button secondary small" type="submit">保存</button>
                            </div>
                        </form>
                            <form method="post" class="inline-delete-form" onsubmit="return confirm('确定删除「<?= e($m['name']) ?>」吗？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $mid ?>">
                                <button class="button danger small" type="submit">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="padding:16px;color:var(--text-muted);">暂无 AI 对话模型，请在上方添加。</p>
    <?php endif; ?>
</section>

<!-- =================================================================== -->
<!-- 其它/未分类模型 -->
<!-- =================================================================== -->
<?php if (!empty($otherModels)): ?>
<section class="card">
    <div class="card-head">
        <div>
            <div class="section-badge badge-other">其它模型</div>
            <h2>未分类模型</h2>
        </div>
        <span class="badge">共 <?= count($otherModels) ?> 个</span>
    </div>
    <p style="padding:8px 16px;font-size:13px;color:var(--text-muted);">
        以下模型未匹配图片、视频或对话分类规则，不会出现在用户界面中。
        请修改模型名称/ID 或选择正确的模型类型。
    </p>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr><th>ID</th><th>名称</th><th>模型 ID</th><th>类型</th><th>状态</th></tr>
            </thead>
            <tbody>
                <?php foreach ($otherModels as $m): ?>
                <tr>
                    <td><?= (int) $m['id'] ?></td>
                    <td><?= e($m['name']) ?></td>
                    <td><?= e($m['model_id']) ?></td>
                    <td><?= e($m['model_type'] ?? 'image') ?></td>
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
    // Create form: type switcher
    window.onCreateTypeChange = function () {
        var type = document.getElementById('createModelType').value;
        document.getElementById('createImageFields').style.display = type === 'image' ? '' : 'none';
        document.getElementById('createVideoFields').style.display = type === 'video' ? '' : 'none';
        var invokeSel = document.getElementById('createInvokeMode');
        if (type === 'video') {
            invokeSel.innerHTML = '<option value="relay">默认（中转）</option><option value="kaiyuncode">kaiyuncode</option>';
        } else if (type === 'chat') {
            invokeSel.innerHTML = '<option value="relay">中转站（默认）</option><option value="curl">curl</option>';
        } else {
            invokeSel.innerHTML = '<option value="relay">中转站（默认）</option><option value="curl">curl</option>';
        }
    };

    // Create form: sync mode checkboxes to hidden input
    window.syncCreateModeFromCheckboxes = function () {
        var hidden = document.getElementById('createModeHidden');
        var checked = [];
        document.querySelectorAll('#createModeCheckboxes input[type="checkbox"]').forEach(function (cb) {
            if (cb.checked) checked.push(cb.value);
        });
        hidden.value = checked.join(',') || 'text_to_video';
    };

    // Advanced toggle
    window.toggleAdv = function (btn) {
        var section = btn.nextElementSibling;
        section.classList.toggle('open');
        btn.textContent = section.classList.contains('open')
            ? '收起高级接口字段 ▲'
            : '展开高级接口字段 ▼';
    };

    // Video row: sync checkboxes to hidden input
    window.syncModeHidden = function (mid) {
        var hidden = document.getElementById('modeHidden_' + mid);
        if (!hidden) return;
        var checked = [];
        document.querySelectorAll('.mode-cb[data-model="' + mid + '"]').forEach(function (cb) {
            if (cb.checked) checked.push(cb.value);
        });
        hidden.value = checked.join(',');
    };

    // Init
    onCreateTypeChange();
    // Init all mode hidden fields
    document.querySelectorAll('.mode-cb').forEach(function (cb) {
        syncModeHidden(cb.dataset.model);
    });
    syncCreateModeFromCheckboxes();
})();
</script>
<?php render_footer(); ?>
