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

function normalize_credit_input($raw, bool $allowNull = false): ?string
{
    $value = trim((string) $raw);
    if ($value === '') {
        if ($allowNull) {
            return null;
        }
        throw new InvalidArgumentException('点数字段不能为空。');
    }
    if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
        throw new InvalidArgumentException('点数字段只能填写正整数或正小数。');
    }
    if ((float) $value <= 0) {
        throw new InvalidArgumentException('点数字段必须大于 0。');
    }
    return $value;
}

function normalize_int_input($raw, string $label, int $min, int $max, ?int $fallback = null): int
{
    $value = trim((string) $raw);
    if ($value === '') {
        if ($fallback !== null) {
            return $fallback;
        }
        throw new InvalidArgumentException($label . '不能为空。');
    }
    if (!preg_match('/^\d+$/', $value)) {
        throw new InvalidArgumentException($label . '只能填写整数。');
    }
    $int = (int) $value;
    if ($int < $min || $int > $max) {
        throw new InvalidArgumentException($label . '必须在 ' . $min . ' 到 ' . $max . ' 之间。');
    }
    return $int;
}

function normalize_sort_input($raw): int
{
    return normalize_int_input($raw, '排序', 0, 999999, 0);
}

function normalize_aspect_options(string $raw): array
{
    $allowed = ['auto', '1:1', '16:9', '9:16', '4:3', '3:4', '21:9'];
    $options = parse_csv_options($raw);
    if (count($options) === 0) {
        return ['auto', '16:9', '9:16'];
    }
    foreach ($options as $option) {
        if (!in_array($option, $allowed, true)) {
            throw new InvalidArgumentException('比例选项只允许 auto、1:1、16:9、9:16、4:3、3:4、21:9。');
        }
    }
    return $options;
}

function normalize_duration_options(string $raw): array
{
    $options = parse_csv_options($raw);
    if (count($options) === 0) {
        throw new InvalidArgumentException('可选时长不能为空。');
    }
    $normalized = [];
    foreach ($options as $option) {
        if (!preg_match('/^\d+$/', $option)) {
            throw new InvalidArgumentException('可选时长只能填写整数。');
        }
        $value = (int) $option;
        if ($value < 1 || $value > 120) {
            throw new InvalidArgumentException('可选时长必须在 1 到 120 之间。');
        }
        $normalized[] = (string) $value;
    }
    return array_values(array_unique($normalized));
}

function ensure_default_duration_in_options(int $default, array $options): int
{
    $stringOptions = array_map('strval', $options);
    if (!in_array((string) $default, $stringOptions, true)) {
        throw new InvalidArgumentException('默认时长必须在可选时长列表内。');
    }
    return $default;
}

function resolve_hidden_json_csv(array $source, string $postKey, ?string $currentJson, array $fallback): ?string
{
    if (array_key_exists($postKey, $source)) {
        $opts = parse_csv_options((string) ($source[$postKey] ?? ''));
        if (count($opts) === 0) {
            $opts = $fallback;
        }
        return encode_json_or_null($opts);
    }
    if ($currentJson !== null && $currentJson !== '') {
        return $currentJson;
    }
    return encode_json_or_null($fallback);
}

function resolve_hidden_scalar(array $source, string $postKey, ?string $currentValue, string $fallback): string
{
    if (array_key_exists($postKey, $source)) {
        $value = trim((string) ($source[$postKey] ?? ''));
        return $value !== '' ? $value : $fallback;
    }
    $currentValue = trim((string) $currentValue);
    return $currentValue !== '' ? $currentValue : $fallback;
}

function classify_model(array $m): string
{
    $type = strtolower(trim($m['model_type'] ?? 'image'));
    $name = strtolower(trim($m['name'] ?? ''));
    $mid  = strtolower(trim($m['model_id'] ?? ''));

    if ($type === 'image') return 'image';
    if ($type === 'video') return 'video';

    if ($type === 'chat') {
        if (preg_match('/\b(gpt|grok|claude|chat|llama|qwen|yi|deepseek|gemini|o1|o3|o4)\b/', $name)
            && !preg_match('/\b(gpt-image|banana|nana|veo|seedance|video|sora)\b/', $name)
            && !preg_match('/\b(gpt-image|banana|nana|veo|seedance|video|sora)\b/', $mid)) {
            return 'chat';
        }
        return 'other';
    }

    foreach (['banana', 'nana', 'gpt-image'] as $kw) {
        if (strpos($name, $kw) !== false || strpos($mid, $kw) !== false) return 'image';
    }

    foreach (['veo', 'seedance', 'video-pro', 'sora'] as $kw) {
        if (strpos($name, $kw) !== false || strpos($mid, $kw) !== false) return 'video';
    }

    return 'other';
}

const VIDEO_MODE_OPTIONS = [
    'text_to_video'       => '文生视频',
    'first_frame'         => '首帧参考',
    'first_last_frame'    => '首尾帧',
    'multi_reference'     => '多帧参考',
    'video_edit'          => '视频编辑',
    'video_reference'     => '视频参考',
    'audio_reference'     => '音频参考',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $modelId = trim((string) ($_POST['model_id'] ?? ''));
            $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
            $apiKey = trim((string) ($_POST['api_key'] ?? ''));
            $sortOrder = normalize_sort_input($_POST['sort_order'] ?? 0);
            $modelType = strtolower(trim((string) ($_POST['model_type'] ?? 'image')));
            if (!in_array($modelType, ['image', 'video', 'chat'], true)) $modelType = 'image';
            $invokeMode = $modelType === 'video'
                ? (strtolower(trim((string) ($_POST['invoke_mode'] ?? ''))) === 'kaiyuncode' ? 'kaiyuncode' : 'relay')
                : (strtolower(trim((string) ($_POST['invoke_mode'] ?? ''))) === 'curl' ? 'curl' : 'relay');

            if ($name === '' || $modelId === '' || $baseUrl === '' || $apiKey === '') {
                throw new InvalidArgumentException('请填写完整信息。');
            }

            $credits = normalize_credit_input($_POST['credits'] ?? '', true);
            $supportsEdit = (int) ($_POST['supports_edit'] ?? 0);
            $editAdapterRaw = strtolower(trim((string) ($_POST['edit_adapter'] ?? '')));
            $editAdapter = in_array($editAdapterRaw, ['none','nano_banana_image_urls','openai_edits_multipart','newtoken_async_reference'], true)
                ? $editAdapterRaw : 'none';
            $editImageField = $editAdapterRaw === 'reference_images' ? 'reference_images' : 'image_urls';
            $supportsReference = (int) ($_POST['supports_reference'] ?? 0);
            $referenceRequired = (int) ($_POST['reference_required'] ?? 0);
            $maxRefImages = normalize_int_input($_POST['max_reference_images'] ?? 1, '最大参考图数', 1, 16, 1);
            $maxRefVideos = normalize_int_input($_POST['max_reference_videos'] ?? 0, '最大参考视频数', 0, 9, 0);
            $maxRefAudios = normalize_int_input($_POST['max_reference_audios'] ?? 0, '最大参考音频数', 0, 9, 0);
            $videoAdapter = in_array(strtolower(trim((string) ($_POST['video_adapter'] ?? ''))), ['none','kaiyuncode','newtoken_video_async'], true)
                ? strtolower(trim((string) ($_POST['video_adapter'] ?? ''))) : 'none';

            $imgAspList = normalize_aspect_options((string) ($_POST['image_aspect_options'] ?? 'auto,1:1,16:9,9:16,4:3,3:4'));
            $imgAspOpts = encode_json_or_null($imgAspList);
            $imgDefAsp = resolve_hidden_scalar($_POST, 'image_default_aspect', null, 'auto');
            $imgSzOpts = resolve_hidden_json_csv($_POST, 'image_size_options', null, ['auto']);
            $imgDefSz = resolve_hidden_scalar($_POST, 'image_default_size', null, 'auto');

            $vidDurList = normalize_duration_options((string) ($_POST['video_duration_options'] ?? ''));
            $vidDurOpts = encode_json_or_null($vidDurList);
            $vidDefDur = ensure_default_duration_in_options(
                normalize_int_input($_POST['video_default_duration'] ?? '', '默认时长', 1, 120),
                $vidDurList
            );
            $vidAspList = normalize_aspect_options((string) ($_POST['video_aspect_options'] ?? ''));
            $vidAspOpts = encode_json_or_null($vidAspList);
            $vidDefAsp = trim((string) ($_POST['video_default_aspect'] ?? '16:9'));
            if (!in_array($vidDefAsp, $vidAspList, true)) {
                throw new InvalidArgumentException('默认比例必须在可选比例列表内。');
            }
            $vidSzOpts = resolve_hidden_json_csv($_POST, 'video_size_options', null, ['auto']);
            $vidDefSz = resolve_hidden_scalar($_POST, 'video_default_size', null, 'auto');

            $allModeKeys = array_keys(VIDEO_MODE_OPTIONS);
            $postModes = array_filter(array_map('trim', explode(',', (string) ($_POST['video_mode_options'] ?? ''))), fn($v) => $v !== '');
            $vidModeOpts = encode_json_or_null(array_values(array_filter($postModes, fn($v) => in_array($v, $allModeKeys, true))));
            $vidDefMode = in_array((string) ($_POST['video_default_mode'] ?? ''), $allModeKeys, true)
                ? (string) $_POST['video_default_mode'] : 'text_to_video';

            $vidRefField = trim((string) ($_POST['video_reference_field'] ?? 'reference_images')) ?: 'reference_images';
            $vidDurField = trim((string) ($_POST['video_duration_field'] ?? 'duration')) ?: 'duration';
            $vidAspField = trim((string) ($_POST['video_aspect_field'] ?? 'aspect_ratio')) ?: 'aspect_ratio';
            $vidSzField = resolve_hidden_scalar($_POST, 'video_size_field', null, 'size');
            $vidImField = trim((string) ($_POST['video_input_mode_field'] ?? 'input_mode')) ?: 'input_mode';
            $vidRefVidField = trim((string) ($_POST['video_reference_video_field'] ?? 'extra_videos')) ?: 'extra_videos';
            $vidRefAudField = trim((string) ($_POST['video_reference_audio_field'] ?? 'extra_audios')) ?: 'extra_audios';

            if ($supportsEdit && $editAdapter === 'none') {
                throw new InvalidArgumentException('如果要启用编辑功能，请选择有效的图片编辑接口类型。');
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
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $modelId = trim((string) ($_POST['model_id'] ?? ''));
            $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
            $apiKey = trim((string) ($_POST['api_key'] ?? ''));
            $sortOrder = normalize_sort_input($_POST['sort_order'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 1);

            if ($id < 1 || $name === '' || $modelId === '' || $baseUrl === '') {
                throw new InvalidArgumentException('参数不合法。');
            }

            $existingStmt = db()->prepare('SELECT * FROM ai_models WHERE id = ? LIMIT 1');
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('模型不存在。');
            }

            $modelType = strtolower(trim((string) ($_POST['model_type'] ?? ($existing['model_type'] ?? 'image'))));
            if (!in_array($modelType, ['image', 'video', 'chat'], true)) $modelType = 'image';
            $invokeMode = $modelType === 'video'
                ? (strtolower(trim((string) ($_POST['invoke_mode'] ?? ($existing['invoke_mode'] ?? 'relay')))) === 'kaiyuncode' ? 'kaiyuncode' : 'relay')
                : (strtolower(trim((string) ($_POST['invoke_mode'] ?? ($existing['invoke_mode'] ?? 'relay')))) === 'curl' ? 'curl' : 'relay');

            $credits = normalize_credit_input($_POST['credits'] ?? ($existing['credits'] ?? ''), false);
            $supportsEdit = (int) ($_POST['supports_edit'] ?? ($existing['supports_edit'] ?? 0));
            $editAdapterRaw = strtolower(trim((string) ($_POST['edit_adapter'] ?? ($existing['edit_adapter'] ?? 'none'))));
            $editAdapter = in_array($editAdapterRaw, ['none','nano_banana_image_urls','openai_edits_multipart','newtoken_async_reference'], true)
                ? $editAdapterRaw : 'none';
            $editImageField = $editAdapterRaw === 'reference_images' ? 'reference_images' : 'image_urls';
            $supportsReference = (int) ($_POST['supports_reference'] ?? ($existing['supports_reference'] ?? 0));
            $referenceRequired = (int) ($_POST['reference_required'] ?? ($existing['reference_required'] ?? 0));
            $maxRefImages = normalize_int_input($_POST['max_reference_images'] ?? ($existing['max_reference_images'] ?? 1), '最大参考图数', 1, 16, 1);
            $maxRefVideos = normalize_int_input($_POST['max_reference_videos'] ?? ($existing['max_reference_videos'] ?? 0), '最大参考视频数', 0, 9, 0);
            $maxRefAudios = normalize_int_input($_POST['max_reference_audios'] ?? ($existing['max_reference_audios'] ?? 0), '最大参考音频数', 0, 9, 0);
            $videoAdapter = in_array(strtolower(trim((string) ($_POST['video_adapter'] ?? ($existing['video_adapter'] ?? 'none')))), ['none','kaiyuncode','newtoken_video_async'], true)
                ? strtolower(trim((string) ($_POST['video_adapter'] ?? ($existing['video_adapter'] ?? 'none')))) : 'none';

            $imgAspList = normalize_aspect_options((string) ($_POST['image_aspect_options'] ?? safe_json_implode($existing['image_aspect_options_json'] ?? null)));
            $imgAspOpts = encode_json_or_null($imgAspList);
            $imgDefAsp = resolve_hidden_scalar($_POST, 'image_default_aspect', $existing['image_default_aspect'] ?? 'auto', 'auto');
            $imgSzOpts = resolve_hidden_json_csv($_POST, 'image_size_options', $existing['image_size_options_json'] ?? null, ['auto']);
            $imgDefSz = resolve_hidden_scalar($_POST, 'image_default_size', $existing['image_default_size'] ?? 'auto', 'auto');

            $vidDurList = normalize_duration_options((string) ($_POST['video_duration_options'] ?? safe_json_implode($existing['video_duration_options_json'] ?? null)));
            $vidDurOpts = encode_json_or_null($vidDurList);
            $vidDefDur = ensure_default_duration_in_options(
                normalize_int_input($_POST['video_default_duration'] ?? ($existing['video_default_duration'] ?? ''), '默认时长', 1, 120),
                $vidDurList
            );
            $vidAspList = normalize_aspect_options((string) ($_POST['video_aspect_options'] ?? safe_json_implode($existing['video_aspect_options_json'] ?? null)));
            $vidAspOpts = encode_json_or_null($vidAspList);
            $vidDefAsp = trim((string) ($_POST['video_default_aspect'] ?? ($existing['video_default_aspect'] ?? '16:9')));
            if (!in_array($vidDefAsp, $vidAspList, true)) {
                throw new InvalidArgumentException('默认比例必须在可选比例列表内。');
            }
            $vidSzOpts = resolve_hidden_json_csv($_POST, 'video_size_options', $existing['video_size_options_json'] ?? null, ['auto']);
            $vidDefSz = resolve_hidden_scalar($_POST, 'video_default_size', $existing['video_default_size'] ?? 'auto', 'auto');

            $allModeKeys = array_keys(VIDEO_MODE_OPTIONS);
            $postModes = array_filter(array_map('trim', explode(',', (string) ($_POST['video_mode_options'] ?? safe_json_implode($existing['video_mode_options_json'] ?? null)))), fn($v) => $v !== '');
            $vidModeOpts = encode_json_or_null(array_values(array_filter($postModes, fn($v) => in_array($v, $allModeKeys, true))));
            $vidDefMode = in_array((string) ($_POST['video_default_mode'] ?? ($existing['video_default_mode'] ?? '')), $allModeKeys, true)
                ? (string) ($_POST['video_default_mode'] ?? $existing['video_default_mode']) : 'text_to_video';

            $vidRefField = trim((string) ($_POST['video_reference_field'] ?? ($existing['video_reference_field'] ?? 'reference_images'))) ?: 'reference_images';
            $vidDurField = trim((string) ($_POST['video_duration_field'] ?? ($existing['video_duration_field'] ?? 'duration'))) ?: 'duration';
            $vidAspField = trim((string) ($_POST['video_aspect_field'] ?? ($existing['video_aspect_field'] ?? 'aspect_ratio'))) ?: 'aspect_ratio';
            $vidSzField = resolve_hidden_scalar($_POST, 'video_size_field', $existing['video_size_field'] ?? 'size', 'size');
            $vidImField = trim((string) ($_POST['video_input_mode_field'] ?? ($existing['video_input_mode_field'] ?? 'input_mode'))) ?: 'input_mode';
            $vidRefVidField = trim((string) ($_POST['video_reference_video_field'] ?? ($existing['video_reference_video_field'] ?? 'extra_videos'))) ?: 'extra_videos';
            $vidRefAudField = trim((string) ($_POST['video_reference_audio_field'] ?? ($existing['video_reference_audio_field'] ?? 'extra_audios'))) ?: 'extra_audios';

            if ($supportsEdit && $editAdapter === 'none') {
                throw new InvalidArgumentException('如果要启用编辑功能，请选择有效的图片编辑接口类型。');
            }

            $base = 'model_id=?, base_url=?, model_type=?, credits=?, invoke_mode=?, '
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
                $modelId, $baseUrl, $modelType, $credits, $invokeMode,
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
                $sql = "UPDATE ai_models SET name=?, api_key=?, $base WHERE id=?";
                $vals = array_merge([$name, $apiKey], $vals, [$id]);
            } else {
                $sql = "UPDATE ai_models SET name=?, $base WHERE id=?";
                $vals = array_merge([$name], $vals, [$id]);
            }

            $stmt = db()->prepare($sql);
            $stmt->execute($vals);
            flash('success', '模型已更新。');
            redirect('/admin/ai_models');
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new InvalidArgumentException('参数不合法。');
            }
            db()->prepare('DELETE FROM ai_models WHERE id = ?')->execute([$id]);
            flash('success', '模型已删除。');
            redirect('/admin/ai_models');
        }

        flash('error', '未知操作。');
        redirect('/admin/ai_models');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
        redirect('/admin/ai_models');
    }
}

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

render_header('AI 模型管理', 'admin');
?>
<nav class="admin-nav-bar" aria-label="后台管理导航">
    <a class="" href="/admin/index">生成记录</a>
    <a class="" href="/admin/users">用户管理</a>
    <a class="" href="/admin/uploads">图片管理</a>
    <a class="" href="/admin/codes">兑换码管理</a>
    <a class="" href="/admin/packages">套餐管理</a>
    <a class="" href="/admin/orders">订单记录</a>
    <a class="" href="/admin/email_settings">邮件配置</a>
    <a class="" href="/admin/captcha_settings">极验配置</a>
    <a class="" href="/admin/signup_settings">注册赠送</a>
    <a class="" href="/admin/page_notices">页面公告</a>
    <a class="active" href="/admin/ai_models">AI模型</a>
    <a class="" href="/admin/chat_records">对话记录</a>
    <a class="" href="/admin/gallery">图片广场</a>
    <a class="" href="/admin/pay_settings">支付配置</a>
    <a class="" href="/admin/social_login">聚合登录</a>
    <a class="" href="/admin/settings">系统配置</a>
    <a class="" href="/admin/update">在线更新</a>
</nav>
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
.badge-image { background: #e8f5e9; color: #2e7d32; }
.badge-video { background: #e3f2fd; color: #1565c0; }
.badge-chat { background: #fff8e1; color: #f57f17; }
.badge-other { background: #f3e5f5; color: #6a1b9a; }
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
.model-num-input {
    box-sizing: border-box;
    height: 34px;
    padding: 6px 8px;
    border: 1px solid var(--line, #dbe3ef);
    border-radius: 8px;
    text-align: center;
    font-variant-numeric: tabular-nums;
    appearance: textfield;
    -moz-appearance: textfield;
}
.model-num-input::-webkit-outer-spin-button,
.model-num-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.model-num-xs { width: 58px; min-width: 58px; }
.model-num-sm { width: 72px; min-width: 72px; }
.model-num-md { width: 88px; min-width: 88px; }
.model-num-sort { width: 52px; min-width: 52px; }
.aspect-input { width: 124px; min-width: 124px; }
.duration-input { width: 84px; min-width: 84px; }
</style>

<main>
<section class="card" style="margin-bottom:24px;">
    <div class="card-head">
        <div><p class="eyebrow">Add Model</p><h2>新增 AI 模型</h2></div>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field-group">
            <div class="field"><label>显示名称</label><input name="name" class="compact-input" placeholder="例如：GPT Image 2" required style="font-size:13px;padding:6px;"></div>
            <div class="field"><label>模型 ID</label><input name="model_id" class="compact-input" placeholder="例如：gpt-image-2" required style="font-size:13px;padding:6px;"></div>
            <div class="field"><label>Base URL</label><input name="base_url" class="compact-input" placeholder="https://api.example.com" required style="font-size:13px;padding:6px;"></div>
            <div class="field"><label>API Key</label><input name="api_key" class="compact-input" placeholder="sk-..." required style="font-size:13px;padding:6px;"></div>
        </div>
        <div class="field-group">
            <div class="field"><label>排序</label><input name="sort_order" type="text" inputmode="numeric" pattern="[0-9]*" value="0" class="compact-input model-num-input model-num-sort"></div>
            <div class="field"><label>点数/次或点/秒</label><input name="credits" type="text" inputmode="decimal" pattern="[0-9]+(\.[0-9]+)?" placeholder="请输入点数" class="compact-input model-num-input model-num-sm"></div>
            <div class="field"><label>模型类型</label><select name="model_type" id="createModelType" class="compact-input" style="padding:6px;" onchange="onCreateTypeChange()"><option value="image" selected>图片生成</option><option value="video">视频生成</option><option value="chat">AI 对话</option></select></div>
            <div class="field"><label>调用方式</label><select name="invoke_mode" id="createInvokeMode" class="compact-input" style="padding:6px;"><option value="relay">中转站（默认）</option><option value="curl">curl</option></select></div>
        </div>
        <div id="createImageFields">
            <fieldset style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px;margin-bottom:12px;">
                <legend style="font-size:12px;font-weight:600;color:var(--text-soft);padding:0 6px;">图片模型配置</legend>
                <div class="field-group">
                    <div class="field"><label>支持图片编辑</label><select name="supports_edit" class="compact-input" style="padding:6px;"><option value="0" selected>不支持编辑</option><option value="1">支持编辑</option></select></div>
                    <div class="field"><label>图片编辑接口类型</label><span class="hint">仅用于 Nano Banana 等图片参考图编辑模型</span><select name="edit_adapter" class="compact-input" style="padding:6px;"><option value="none">不支持编辑（默认）</option><option value="newtoken_async_reference">newtoken_async_reference（Nano Banana）</option><option value="nano_banana_image_urls">nano_banana_image_urls</option><option value="openai_edits_multipart">openai_edits_multipart</option></select></div>
                    <div class="field"><label>参考图字段</label><select name="edit_image_field" class="compact-input" style="padding:6px;"><option value="image_urls">image_urls（默认）</option><option value="reference_images">reference_images</option></select></div>
                    <div class="field"><label>最大参考图数</label><input name="max_reference_images" type="text" inputmode="numeric" pattern="[0-9]*" value="1" class="compact-input model-num-input model-num-xs"></div>
                    <div class="field"><label>可选比例</label><input name="image_aspect_options" class="compact-input aspect-input" value="auto,1:1,16:9,9:16,4:3,3:4" placeholder="auto,16:9,9:16"></div>
                </div>
                <input type="hidden" name="image_default_aspect" value="auto">
                <input type="hidden" name="image_size_options" value="auto">
                <input type="hidden" name="image_default_size" value="auto">
            </fieldset>
        </div>
        <div id="createVideoFields" style="display:none;">
            <fieldset style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px;margin-bottom:12px;">
                <legend style="font-size:12px;font-weight:600;color:var(--text-soft);padding:0 6px;">视频模型配置</legend>
                <div class="field-group">
                    <div class="field"><label>视频接口类型</label><select name="video_adapter" class="compact-input" style="padding:6px;"><option value="none">无（默认）</option><option value="newtoken_video_async">newtoken（NewToken）</option><option value="kaiyuncode">kaiyuncode</option></select></div>
                    <div class="field"><label>最大参考图数</label><input name="max_reference_images" type="text" inputmode="numeric" pattern="[0-9]*" value="1" class="compact-input model-num-input model-num-xs"></div>
                    <div class="field"><label>最大参考视频数</label><input name="max_reference_videos" type="text" inputmode="numeric" pattern="[0-9]*" value="0" class="compact-input model-num-input model-num-xs"></div>
                    <div class="field"><label>最大参考音频数</label><input name="max_reference_audios" type="text" inputmode="numeric" pattern="[0-9]*" value="0" class="compact-input model-num-input model-num-xs"></div>
                    <div class="field"><label>可选时长</label><input name="video_duration_options" class="compact-input duration-input" value="5,8,10" placeholder="8,10,15"></div>
                    <div class="field"><label>默认时长</label><input name="video_default_duration" type="text" inputmode="numeric" pattern="[0-9]*" value="8" class="compact-input model-num-input model-num-xs"></div>
                    <div class="field"><label>可选比例</label><input name="video_aspect_options" class="compact-input aspect-input" value="auto,16:9,4:3,1:1,3:4,9:16,21:9" placeholder="auto,16:9,9:16"></div>
                    <div class="field"><label>默认比例</label><input name="video_default_aspect" class="compact-input model-num-xs" value="16:9"></div>
                </div>
                <div class="field-group">
                    <div class="field" style="grid-column:1/-1;"><label>支持的生成模式</label><div id="createModeCheckboxes" style="display:flex;flex-wrap:wrap;gap:10px;font-size:12px;"><?php foreach (VIDEO_MODE_OPTIONS as $key => $label): ?><label><input type="checkbox" value="<?= e($key) ?>" <?= in_array($key, ['text_to_video','first_frame','first_last_frame','multi_reference','video_edit'], true) ? 'checked' : '' ?> onchange="syncCreateModeFromCheckboxes()"> <?= e($label) ?></label><?php endforeach; ?></div><input type="hidden" name="video_mode_options" id="createModeHidden" value="text_to_video,first_frame,first_last_frame,multi_reference,video_edit"></div>
                    <div class="field"><label>默认模式</label><select name="video_default_mode" class="compact-input" style="padding:6px;"><?php foreach (VIDEO_MODE_OPTIONS as $key => $label): ?><option value="<?= e($key) ?>" <?= $key === 'text_to_video' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                </div>
                <input type="hidden" name="video_size_options" value="auto">
                <input type="hidden" name="video_default_size" value="auto">
                <input type="hidden" name="video_size_field" value="size">
                <button type="button" class="adv-toggle" onclick="toggleAdv(this)">展开高级接口字段 ▼</button>
                <div class="adv-section">
                    <div class="adv-row"><strong>参考图字段：</strong><input name="video_reference_field" class="compact-input" value="reference_images" style="width:120px;"></div>
                    <div class="adv-row"><strong>时长字段：</strong><input name="video_duration_field" class="compact-input" value="duration" style="width:120px;"></div>
                    <div class="adv-row"><strong>比例字段：</strong><input name="video_aspect_field" class="compact-input" value="aspect_ratio" style="width:120px;"></div>
                    <div class="adv-row"><strong>模式字段：</strong><input name="video_input_mode_field" class="compact-input" value="input_mode" style="width:120px;"></div>
                    <div class="adv-row"><strong>参考视频字段：</strong><input name="video_reference_video_field" class="compact-input" value="extra_videos" style="width:120px;"></div>
                    <div class="adv-row"><strong>参考音频字段：</strong><input name="video_reference_audio_field" class="compact-input" value="extra_audios" style="width:120px;"></div>
                </div>
            </fieldset>
        </div>
        <button class="button primary" type="submit">添加模型</button>
    </form>
</section>
<section class="card" style="margin-bottom:24px;"><div class="card-head"><div><div class="section-badge badge-image">图片模型</div><h2>图片生成 / 编辑模型</h2><p style="font-size:12px;color:var(--text-muted);margin-top:2px;">用于图片绘画、图片编辑、参考图改图</p></div><span class="badge">共 <?= count($imageModels) ?> 个</span></div><?php if (!empty($imageModels)): ?><div style="overflow-x:auto;"><table><thead><tr><th>排序</th><th>名称</th><th>模型 ID</th><th>Base URL</th><th>API Key</th><th>点数/次</th><th>状态</th><th>支持编辑</th><th>编辑接口类型</th><th>最大参考图数</th><th>可选比例</th><th>操作</th></tr></thead><tbody><?php foreach ($imageModels as $m): ?><?php $mid = (int) $m['id']; $isActive = (int) $m['is_active']; $supportsEdit = (int) ($m['supports_edit'] ?? 0); $editAdapter = strtolower(trim((string) ($m['edit_adapter'] ?? 'none'))); $imgAspOpts = decode_json($m['image_aspect_options_json'] ?? null); $imgDefAsp = trim((string) ($m['image_default_aspect'] ?? 'auto')); $imgSzOpts = decode_json($m['image_size_options_json'] ?? null); $imgDefSz = trim((string) ($m['image_default_size'] ?? 'auto')); $maxRefImg = max(1, (int) ($m['max_reference_images'] ?? 1)); ?><tr><form method="post" class="inline-model-form"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $mid ?>"><input type="hidden" name="image_default_aspect" value="<?= e($imgDefAsp !== '' ? $imgDefAsp : 'auto') ?>"><input type="hidden" name="image_size_options" value="<?= e(implode(',', $imgSzOpts ?: ['auto'])) ?>"><input type="hidden" name="image_default_size" value="<?= e($imgDefSz !== '' ? $imgDefSz : 'auto') ?>"><td><input name="sort_order" type="text" inputmode="numeric" pattern="[0-9]*" class="compact-input model-num-input model-num-sort" value="<?= (int) $m['sort_order'] ?>"></td><td><input name="name" class="compact-input" value="<?= e($m['name']) ?>" required style="min-width:100px;"></td><td><input name="model_id" class="compact-input" value="<?= e($m['model_id']) ?>" required style="min-width:110px;"></td><td><input name="base_url" class="compact-input" value="<?= e($m['base_url']) ?>" required style="min-width:140px;"></td><td><span class="muted-hint">已配置</span><input name="api_key" type="password" class="compact-input" placeholder="留空不修改" autocomplete="off" style="width:80px;"></td><td><input name="credits" type="text" inputmode="decimal" pattern="[0-9]+(\.[0-9]+)?" class="compact-input model-num-input model-num-sm" value="<?= e((string) ($m['credits'] ?? '')) ?>" placeholder="点数"></td><td><select name="is_active" class="compact-input" style="width:60px;"><option value="1" <?= $isActive===1?'selected':'' ?>>启用</option><option value="0" <?= $isActive!==1?'selected':'' ?>>关闭</option></select></td><td><select name="supports_edit" class="compact-input" style="width:72px;"><option value="0" <?= $supportsEdit===0?'selected':'' ?>>否</option><option value="1" <?= $supportsEdit===1?'selected':'' ?>>是</option></select></td><td><select name="edit_adapter" class="compact-input" style="min-width:140px;"><option value="none" <?= $editAdapter==='none'?'selected':'' ?>>不支持（默认）</option><option value="newtoken_async_reference" <?= $editAdapter==='newtoken_async_reference'?'selected':'' ?>>newtoken_async_reference</option><option value="nano_banana_image_urls" <?= $editAdapter==='nano_banana_image_urls'?'selected':'' ?>>nano_banana_image_urls</option><option value="openai_edits_multipart" <?= $editAdapter==='openai_edits_multipart'?'selected':'' ?>>openai_edits_multipart</option></select></td><td><input name="max_reference_images" type="text" inputmode="numeric" pattern="[0-9]*" class="compact-input model-num-input model-num-xs" value="<?= $maxRefImg ?>"></td><td><input name="image_aspect_options" class="compact-input aspect-input" value="<?= e(implode(',', $imgAspOpts)) ?>" placeholder="auto,16:9,9:16"></td><td><div class="table-action-group"><button class="button secondary small" type="submit">保存</button></div></form><form method="post" class="inline-delete-form" onsubmit="return confirm('确定删除「<?= e($m['name']) ?>」吗？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $mid ?>"><button class="button danger small" type="submit">删除</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p style="padding:16px;color:var(--text-muted);">暂无图片模型，请在上方添加。</p><?php endif; ?></section>
<section class="card" style="margin-bottom:24px;"><div class="card-head"><div><div class="section-badge badge-video">视频模型</div><h2>视频生成模型</h2><p style="font-size:12px;color:var(--text-muted);margin-top:2px;">用于文生视频、图生视频、首尾帧、多帧参考、视频编辑</p></div><span class="badge">共 <?= count($videoModels) ?> 个</span></div><?php if (!empty($videoModels)): ?><div style="overflow-x:auto;"><table style="min-width:1260px;"><thead><tr><th>排序</th><th>名称</th><th>模型 ID</th><th>Base URL</th><th>API Key</th><th>点/秒</th><th>状态</th><th>视频接口类型</th><th>最大参考图数</th><th>可选时长</th><th>默认时长</th><th>可选比例</th><th>默认比例</th><th>生成模式</th><th>默认模式</th><th>操作</th></tr></thead><tbody><?php foreach ($videoModels as $m): ?><?php $mid = (int) $m['id']; $isActive = (int) $m['is_active']; $vidAdapter = strtolower(trim((string) ($m['video_adapter'] ?? 'none'))); $maxRefImg = max(0, (int) ($m['max_reference_images'] ?? 0)); $durOpts = decode_json($m['video_duration_options_json'] ?? null); $defDur = (int) ($m['video_default_duration'] ?? 0); $aspOpts = decode_json($m['video_aspect_options_json'] ?? null); $defAsp = trim((string) ($m['video_default_aspect'] ?? '16:9')); $szOpts = decode_json($m['video_size_options_json'] ?? null); $defSz = trim((string) ($m['video_default_size'] ?? 'auto')); $modeOpts = decode_json($m['video_mode_options_json'] ?? null); $defMode = trim((string) ($m['video_default_mode'] ?? 'text_to_video')); ?><tr><form method="post" class="inline-model-form"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $mid ?>"><input type="hidden" name="video_size_options" value="<?= e(implode(',', $szOpts ?: ['auto'])) ?>"><input type="hidden" name="video_default_size" value="<?= e($defSz !== '' ? $defSz : 'auto') ?>"><input type="hidden" name="video_size_field" value="<?= e(trim((string) ($m['video_size_field'] ?? 'size')) ?: 'size') ?>"><td><input name="sort_order" type="text" inputmode="numeric" pattern="[0-9]*" class="compact-input model-num-input model-num-sort" value="<?= (int) $m['sort_order'] ?>"></td><td><input name="name" class="compact-input" value="<?= e($m['name']) ?>" required style="min-width:110px;"></td><td><input name="model_id" class="compact-input" value="<?= e($m['model_id']) ?>" required style="min-width:120px;"></td><td><input name="base_url" class="compact-input" value="<?= e($m['base_url']) ?>" required style="min-width:130px;"></td><td><span class="muted-hint">已配置</span><input name="api_key" type="password" class="compact-input" placeholder="留空不修改" autocomplete="off" style="width:80px;"></td><td><input name="credits" type="text" inputmode="decimal" pattern="[0-9]+(\.[0-9]+)?" class="compact-input model-num-input model-num-sm" value="<?= e((string) ($m['credits'] ?? '')) ?>" placeholder="点数"></td><td><select name="is_active" class="compact-input" style="width:60px;"><option value="1" <?= $isActive===1?'selected':'' ?>>启用</option><option value="0" <?= $isActive!==1?'selected':'' ?>>关闭</option></select></td><td><select name="video_adapter" class="compact-input" style="min-width:130px;"><option value="none" <?= $vidAdapter==='none'?'selected':'' ?>>无（默认）</option><option value="newtoken_video_async" <?= $vidAdapter==='newtoken_video_async'?'selected':'' ?>>newtoken（NewToken）</option><option value="kaiyuncode" <?= $vidAdapter==='kaiyuncode'?'selected':'' ?>>kaiyuncode</option></select></td><td><input name="max_reference_images" type="text" inputmode="numeric" pattern="[0-9]*" class="compact-input model-num-input model-num-xs" value="<?= $maxRefImg ?>"></td><td><input name="video_duration_options" class="compact-input duration-input" value="<?= e(implode(',', $durOpts)) ?>" placeholder="8,10,15"></td><td><input name="video_default_duration" type="text" inputmode="numeric" pattern="[0-9]*" class="compact-input model-num-input model-num-xs" value="<?= $defDur ?: '' ?>" placeholder="时长"></td><td><input name="video_aspect_options" class="compact-input aspect-input" value="<?= e(implode(',', $aspOpts)) ?>" placeholder="auto,16:9,9:16"></td><td><input name="video_default_aspect" class="compact-input model-num-xs" value="<?= e($defAsp) ?>"></td><td><div style="display:flex;flex-wrap:wrap;gap:3px;font-size:10px;min-width:200px;"><?php foreach (VIDEO_MODE_OPTIONS as $k => $label): ?><label style="display:flex;align-items:center;gap:2px;cursor:pointer;"><input type="checkbox" class="mode-cb" data-model="<?= $mid ?>" value="<?= e($k) ?>" <?= in_array($k, $modeOpts, true) ? 'checked' : '' ?> onchange="syncModeHidden(<?= $mid ?>)"><?= e($label) ?></label><?php endforeach; ?><input type="hidden" name="video_mode_options" id="modeHidden_<?= $mid ?>" value="<?= e(implode(',', $modeOpts)) ?>"></div></td><td><select name="video_default_mode" class="compact-input" style="width:96px;"><?php foreach (VIDEO_MODE_OPTIONS as $k => $label): ?><option value="<?= e($k) ?>" <?= $defMode===$k?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></td><td><div class="table-action-group"><button class="button secondary small" type="submit">保存</button></div></form><form method="post" class="inline-delete-form" onsubmit="return confirm('确定删除「<?= e($m['name']) ?>」吗？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $mid ?>"><button class="button danger small" type="submit">删除</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p style="padding:16px;color:var(--text-muted);">暂无视频模型，请在上方添加。</p><?php endif; ?></section>
<section class="card" style="margin-bottom:24px;"><div class="card-head"><div><div class="section-badge badge-chat">AI 对话模型</div><h2>AI 对话模型</h2><p style="font-size:12px;color:var(--text-muted);margin-top:2px;">用于聊天、文本、工具调用，不参与图片/视频生成</p></div><span class="badge">共 <?= count($chatModels) ?> 个</span></div><?php if (!empty($chatModels)): ?><div style="overflow-x:auto;"><table><thead><tr><th>排序</th><th>名称</th><th>模型 ID</th><th>Base URL</th><th>API Key</th><th>调用方式</th><th>点数</th><th>状态</th><th>操作</th></tr></thead><tbody><?php foreach ($chatModels as $m): ?><?php $mid = (int) $m['id']; $isActive = (int) $m['is_active']; $invokeMode = strtolower(trim((string) ($m['invoke_mode'] ?? 'relay'))); ?><tr><form method="post" class="inline-model-form"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $mid ?>"><td><input name="sort_order" type="text" inputmode="numeric" pattern="[0-9]*" class="compact-input model-num-input model-num-sort" value="<?= (int) $m['sort_order'] ?>"></td><td><input name="name" class="compact-input" value="<?= e($m['name']) ?>" required style="min-width:100px;"></td><td><input name="model_id" class="compact-input" value="<?= e($m['model_id']) ?>" required style="min-width:110px;"></td><td><input name="base_url" class="compact-input" value="<?= e($m['base_url']) ?>" required style="min-width:140px;"></td><td><span class="muted-hint">已配置</span><input name="api_key" type="password" class="compact-input" placeholder="留空不修改" autocomplete="off" style="width:80px;"></td><td><select name="invoke_mode" class="compact-input" style="width:100px;"><option value="relay" <?= $invokeMode==='relay'?'selected':'' ?>>中转站</option><option value="curl" <?= $invokeMode==='curl'?'selected':'' ?>>curl</option></select></td><td><input name="credits" type="text" inputmode="decimal" pattern="[0-9]+(\.[0-9]+)?" class="compact-input model-num-input model-num-sm" value="<?= e((string) ($m['credits'] ?? '')) ?>" placeholder="点数"></td><td><select name="is_active" class="compact-input" style="width:60px;"><option value="1" <?= $isActive===1?'selected':'' ?>>启用</option><option value="0" <?= $isActive!==1?'selected':'' ?>>关闭</option></select></td><td><div class="table-action-group"><button class="button secondary small" type="submit">保存</button></div></form><form method="post" class="inline-delete-form" onsubmit="return confirm('确定删除「<?= e($m['name']) ?>」吗？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $mid ?>"><button class="button danger small" type="submit">删除</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p style="padding:16px;color:var(--text-muted);">暂无 AI 对话模型，请在上方添加。</p><?php endif; ?></section>
<?php if (!empty($otherModels)): ?><section class="card"><div class="card-head"><div><div class="section-badge badge-other">其它模型</div><h2>未分类模型</h2></div><span class="badge">共 <?= count($otherModels) ?> 个</span></div><p style="padding:8px 16px;font-size:13px;color:var(--text-muted);">以下模型未匹配图片、视频或对话分类规则，不会出现在用户界面中。请修改模型名称/ID 或选择正确的模型类型。</p><div style="overflow-x:auto;"><table><thead><tr><th>ID</th><th>名称</th><th>模型 ID</th><th>类型</th><th>状态</th></tr></thead><tbody><?php foreach ($otherModels as $m): ?><tr><td><?= (int) $m['id'] ?></td><td><?= e($m['name']) ?></td><td><?= e($m['model_id']) ?></td><td><?= e($m['model_type'] ?? 'image') ?></td><td><?= (int) $m['is_active'] === 1 ? '启用' : '关闭' ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
</main>
<script>
(function () {
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
    window.syncCreateModeFromCheckboxes = function () {
        var hidden = document.getElementById('createModeHidden');
        var checked = [];
        document.querySelectorAll('#createModeCheckboxes input[type="checkbox"]').forEach(function (cb) {
            if (cb.checked) checked.push(cb.value);
        });
        hidden.value = checked.join(',') || 'text_to_video';
    };
    window.toggleAdv = function (btn) {
        var section = btn.nextElementSibling;
        section.classList.toggle('open');
        btn.textContent = section.classList.contains('open') ? '收起高级接口字段 ▲' : '展开高级接口字段 ▼';
    };
    window.syncModeHidden = function (mid) {
        var hidden = document.getElementById('modeHidden_' + mid);
        if (!hidden) return;
        var checked = [];
        document.querySelectorAll('.mode-cb[data-model="' + mid + '"]').forEach(function (cb) {
            if (cb.checked) checked.push(cb.value);
        });
        hidden.value = checked.join(',');
    };
    onCreateTypeChange();
    document.querySelectorAll('.mode-cb').forEach(function (cb) {
        syncModeHidden(cb.dataset.model);
    });
    syncCreateModeFromCheckboxes();
})();
</script>
<?php render_footer(); ?>
