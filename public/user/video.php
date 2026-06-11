<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image_generation.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/video_generation.php';
require_once __DIR__ . '/../../src/generation_record_view_helpers.php';

$user = require_login();
ensure_generation_records_video_columns();
cleanup_stale_running_generation_records();

ensure_ai_models_table();
ensure_ai_models_type_column();
ensure_ai_models_capability_columns();
ensure_generation_records_selection_columns();
$videoModels = active_video_ai_models();
$noActiveModel = empty($videoModels);

// Parse model capabilities from DB JSON fields
$modelDurationOptions = [];
$modelDefaultDuration = [];
$modelAspectOptions = [];
$modelDefaultAspect = [];
$modelSizeOptions = [];
$modelDefaultSize = [];
$modelModeOptions = [];
$modelDefaultMode = [];
$modelMaxRefImages = [];
$modelMaxRefVideos = [];
$modelMaxRefAudios = [];
$modelCredits = [];
$modelSupportsRef = [];

foreach ($videoModels as $vm) {
    $vid = (int) $vm['id'];
    $modelSupportsRef[$vid] = (int) ($vm['supports_reference'] ?? 0);
    $modelMaxRefImages[$vid] = max(0, (int) ($vm['max_reference_images'] ?? 0));
    $modelMaxRefVideos[$vid] = max(0, (int) ($vm['max_reference_videos'] ?? 0));
    $modelMaxRefAudios[$vid] = max(0, (int) ($vm['max_reference_audios'] ?? 0));
    $modelCredits[$vid] = max(0, (int) ($vm['credits'] ?? 0));

    $dOpts = json_decode((string) ($vm['video_duration_options_json'] ?? ''), true);
    $modelDurationOptions[$vid] = is_array($dOpts) ? $dOpts : [];
    $modelDefaultDuration[$vid] = (int) ($vm['video_default_duration'] ?? 0);

    $aOpts = json_decode((string) ($vm['video_aspect_options_json'] ?? ''), true);
    $modelAspectOptions[$vid] = is_array($aOpts) ? $aOpts : [];
    $modelDefaultAspect[$vid] = trim((string) ($vm['video_default_aspect'] ?? '16:9'));

    $sOpts = json_decode((string) ($vm['video_size_options_json'] ?? ''), true);
    $modelSizeOptions[$vid] = is_array($sOpts) ? $sOpts : [];
    $modelDefaultSize[$vid] = trim((string) ($vm['video_default_size'] ?? 'auto'));

    $mOpts = json_decode((string) ($vm['video_mode_options_json'] ?? ''), true);
    $modelModeOptions[$vid] = is_array($mOpts) ? $mOpts : [];
    $modelDefaultMode[$vid] = trim((string) ($vm['video_default_mode'] ?? 'text_to_video'));
}

// Build JSON config for JS (name included for JS-side disambiguation if needed)
$modelConfigJson = [];
foreach ($videoModels as $vm) {
    $vid = (int) $vm['id'];
    $dOpts = $modelDurationOptions[$vid] ?? [];
    $aOpts = $modelAspectOptions[$vid] ?? [];
    $sOpts = $modelSizeOptions[$vid] ?? [];
    $mOpts = $modelModeOptions[$vid] ?? [];
    $credits = $modelCredits[$vid] ?? 0;
    $defaultDuration = $modelDefaultDuration[$vid] ?? 0;
    $defaultAspect = $modelDefaultAspect[$vid] ?? '16:9';
    $defaultSize = $modelDefaultSize[$vid] ?? 'auto';
    $defaultMode = $modelDefaultMode[$vid] ?? 'text_to_video';
    $maxRef = $modelMaxRefImages[$vid] ?? 0;
    $maxRefVid = $modelMaxRefVideos[$vid] ?? 0;
    $maxRefAud = $modelMaxRefAudios[$vid] ?? 0;
    $defaultCost = $credits * max(1, $defaultDuration);

    $modelConfigJson[$vid] = [
        'id' => $vid,
        'name' => $vm['name'] ?? '',
        'model_id' => $vm['model_id'] ?? '',
        'duration_options' => array_values($dOpts),
        'default_duration' => $defaultDuration,
        'aspect_options' => array_values($aOpts),
        'default_aspect' => $defaultAspect,
        'size_options' => array_values($sOpts),
        'default_size' => $defaultSize,
        'mode_options' => array_values($mOpts),
        'default_mode' => $defaultMode,
        'max_ref_images' => $maxRef,
        'max_ref_videos' => $maxRefVid,
        'max_ref_audios' => $maxRefAud,
        'credits' => $credits,
        'default_cost' => $defaultCost,
        'supports_ref' => $modelSupportsRef[$vid] ?? 0,
    ];
}

// Build deduped labels: if same name appears >1 time, append (model_id) for disambiguation
$nameCount = [];
foreach ($videoModels as $vm) {
    $n = $vm['name'] ?? '';
    $nameCount[$n] = ($nameCount[$n] ?? 0) + 1;
}
$seenNames = [];
$videoModelLabels = [];
foreach ($videoModels as $vm) {
    $vid = (int) $vm['id'];
    $n = $vm['name'] ?? '';
    $mid = $vm['model_id'] ?? '';
    if (($nameCount[$n] ?? 0) > 1) {
        // Duplicate name — append model_id in parentheses
        $label = $n . ' (' . $mid . ')';
    } else {
        $label = $n;
    }
    $videoModelLabels[$vid] = $label;
}

// Recent records
$stmt = db()->prepare(
    "SELECT id, user_id, status, mode, model, prompt, size, quality, output_format,
            input_images_json,
            output_url, mime_type, credits_cost, error_message, started_at, finished_at,
            deleted_at, created_at,
            video_url, video_base64, video_mime_type
     FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL AND mode = 'video'
     ORDER BY created_at DESC
     LIMIT 5"
);
$stmt->execute([$user['id']]);
$records = $stmt->fetchAll();

$balanceLabel = balance_label();
$videoNotice = trim((string) app_setting('video_notice', ''));
if ($videoNotice !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{FFFD}]/u', $videoNotice)) {
    $videoNotice = '';
}
$generationNotice = $videoNotice !== '' ? $videoNotice : '注意：视频生成耗时通常较长，请耐心等待，生成失败不扣除次数。';

// Serialize config for JS
$modelConfigJsonStr = json_encode($modelConfigJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$videoModelIdsJson = json_encode(array_map(fn($m) => (int) $m['id'], $videoModels), JSON_UNESCAPED_SLASHES);

render_header('视频生成', 'video');
?><main>
    <div class="page-hd">
        <div>
            <p><?= e($platformName ?? platform_name()) ?></p>
            <h1>视频生成</h1>
        </div>
        <div class="page-hd-actions">
            <a href="/user/shop" class="badge-balance" title="前往商城充值">
                <span>
                    <svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </span>
                <strong class="num" data-balance-display data-balance-label="<?= e($balanceLabel) ?>"><?= number_format((int) $user['credits']) ?></strong>
                <span class="label"><?= e($balanceLabel) ?></span>
            </a>
        </div>
    </div>

    <div class="grid-2">
        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <p class="sub">Settings</p>
                    <h3>视频生成设置</h3>
                </div>
            </div>
            <div class="card-v3-body">
                <?php if ($generationNotice !== ''): ?>
                <div class="generation-notice"><?= e($generationNotice) ?></div>
                <?php endif; ?>
                <?php if ($noActiveModel): ?>
                <div class="alert error">
                    <strong>系统错误</strong>
                    <span>管理员尚未配置可用的视频 AI 模型，请前往后台 AI 模型页面至少启用一个视频模型。</span>
                </div>
                <?php endif; ?>
                <form id="videoGenerateForm" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mode" value="video">

                    <?php if (!empty($videoModels)): ?>
                    <div class="field-v3">
                        <label for="ai_model_id">AI 模型</label>
                        <select name="ai_model_id" id="ai_model_id">
                            <?php foreach ($videoModels as $m):
                                $mid = (int) $m['id'];
                                $cfg = $modelConfigJson[$mid] ?? []; ?>
                            <option value="<?= $mid ?>"
                                data-credits="<?= (int) ($cfg['credits'] ?? 0) ?>"
                                data-default-cost="<?= (int) ($cfg['default_cost'] ?? 0) ?>"
                                data-max-ref="<?= (int) ($cfg['max_ref_images'] ?? 1) ?>"
                                data-default-duration="<?= (int) ($cfg['default_duration'] ?? 0) ?>"
                            ><?= e($videoModelLabels[$mid] ?? $m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-v3" id="videoModeField">
                        <label for="video_mode_select">生成模式</label>
                        <select name="video_mode" id="video_mode_select"></select>
                    </div>

                    <div class="field-v3 edit-upload-field hidden" id="refUploadField">
                        <label>参考图片（最多 <span data-max-ref-count>1</span> 张）</label>
                        <div class="edit-upload-box" id="refUploadBox">
                            <input name="edit_images[]" type="file" accept="image/png,image/jpeg,image/webp" multiple id="refImageInput">
                            <div class="edit-upload-icon" aria-hidden="true">+</div>
                            <div>
                                <strong>点击上传参考图片</strong>
                                <small data-video-upload-hint>支持 PNG / JPG / WEBP，可多次选择</small>
                            </div>
                        </div>
                        <div class="edit-upload-preview" id="refPreview"></div>
                    </div>

                    <div class="field-v3 edit-upload-field hidden" id="videoUploadField">
                        <label>参考视频（最多 <span data-max-video-count>1</span> 个）</label>
                        <div class="edit-upload-box" id="videoUploadBox">
                            <input name="edit_videos[]" type="file" accept="video/mp4,video/webm,video/quicktime" multiple id="refVideoInput">
                            <div class="edit-upload-icon" aria-hidden="true">▶</div>
                            <div>
                                <strong>点击上传参考视频</strong>
                                <small data-video-upload-hint>支持 MP4 / WEBM / MOV，可多次选择</small>
                            </div>
                        </div>
                        <div class="edit-upload-preview" id="videoPreview"></div>
                    </div>

                    <div class="field-v3 edit-upload-field hidden" id="audioUploadField">
                        <label>参考音频（最多 <span data-max-audio-count>1</span> 个）</label>
                        <div class="edit-upload-box" id="audioUploadBox">
                            <input name="edit_audios[]" type="file" accept="audio/mpeg,audio/wav,audio/mp4" multiple id="refAudioInput">
                            <div class="edit-upload-icon" aria-hidden="true">♪</div>
                            <div>
                                <strong>点击上传参考音频</strong>
                                <small data-video-upload-hint>支持 MP3 / WAV / M4A，可多次选择</small>
                            </div>
                        </div>
                        <div class="edit-upload-preview" id="audioPreview"></div>
                    </div>
                    <?php endif; ?>

                    <div class="field-v3">
                        <label for="prompt">视频描述</label>
                        <textarea id="prompt" name="prompt" rows="5" placeholder="描述你想生成的视频内容，例如镜头、动作、主体和风格..." required></textarea>
                    </div>

                    <div id="videoOptionsContainer">
                        <div class="field-v3" id="durationField" style="display:none;">
                            <label for="video_duration_select">视频时长</label>
                            <select name="video_duration" id="video_duration_select"></select>
                        </div>
                        <div class="field-v3" id="aspectField">
                            <label for="video_aspect_select">尺寸</label>
                            <select name="video_aspect" id="video_aspect_select"></select>
                        </div>
                    </div>

                    <?php if (!empty($videoModels)): ?>
                    <div class="cost-hint" id="costDisplay">
                        当前消耗：<strong id="costValue">0</strong> <?= e($balanceLabel) ?>/次
                        <span id="costSecondsInfo" style="display:none;font-size:12px;color:var(--text-muted);"></span>
                    </div>
                    <button id="generateButton" class="btn btn-primary btn-lg" type="submit" style="width:100%;">生成视频</button>
                    <?php endif; ?>
                </form>
                <div id="generateMessage" class="inline-message hidden"></div>
            </div>
        </section>

        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>我的视频</h3>
                    <p class="sub">My Videos</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="/user/records">查看全部</a>
            </div>
            <div class="card-v3-body">
                <div id="videoHistoryList" class="grid-auto" data-gallery>
                    <?php if (empty($records)): ?>
                    <div class="history-empty-inline">暂无视频记录，开始你的第一次视频创作吧</div>
                    <?php endif; ?>
                    <?php foreach ($records as $record):
                        $videoSrc = generation_record_video_src($record);
                        $inputImageCount = generation_input_image_count($record); ?>
                    <article
                        class="media-card"
                        tabindex="0"
                        data-record-id="<?= (int) $record['id'] ?>"
                        data-status="<?= e($record['status']) ?>"
                        data-mode="video"
                        data-prompt="<?= e($record['prompt']) ?>"
                        data-size="<?= e($record['size']) ?>"
                        data-quality="<?= e($record['quality']) ?>"
                        data-format="<?= e($record['output_format']) ?>"
                        data-credits="<?= (int) ($record['credits_cost'] ?? 0) ?>"
                        data-created="<?= e($record['created_at']) ?>"
                        data-finished="<?= e($record['finished_at'] ?: '-') ?>"
                        data-error="<?= e($record['error_message'] ?: '') ?>"
                        data-input-count="<?= $inputImageCount ?>"
                    >
                        <?php if ($videoSrc): ?>
                        <video src="<?= e($videoSrc) ?>" controls preload="metadata" onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';"></video>
                        <div style="display:none;align-items:center;justify-content:center;aspect-ratio:16/9;background:var(--main-surface-soft);color:var(--text-muted);font-size:13px;">
                            <span><?= e(generation_status_label((string) $record['status'])) ?></span>
                        </div>
                        <?php else: ?>
                        <div style="display:flex;align-items:center;justify-content:center;aspect-ratio:1;background:var(--main-surface-soft);color:var(--text-muted);font-size:13px;">
                            <span><?= e(generation_status_label((string) $record['status'])) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="media-card-body">
                            <p class="prompt"><?= e($record['prompt']) ?></p>
                            <div class="meta">
                                <span class="status-badge <?= e($record['status']) ?>"><?= e(generation_status_label((string) $record['status'])) ?></span>
                                <span>
                                    <?= $inputImageCount > 0 ? '图生视频' : '文生视频' ?> / <?= e($record['size']) ?>
                                    <?php if (!empty($record['quality']) && $record['quality'] !== 'auto'): ?>
                                    / <?= e($record['quality']) ?>
                                    <?php endif; ?>
                                    / <?= e($record['output_format'] ?: 'mp4') ?>
                                </span>
                            </div>
                            <div class="record-foot" style="margin-top:6px;display:flex;align-items:center;justify-content:space-between;">
                                <time style="font-size:11px;color:var(--text-muted);"><?= e($record['created_at']) ?></time>
                                <form method="post" action="/delete_record" class="record-delete-form" onsubmit="return confirm('确认删除这条生成记录？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <input type="hidden" name="redirect_to" value="/user/video">
                                    <button type="submit" class="btn btn-ghost btn-sm">删除</button>
                                </form>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</main>
<script>
window.__videoModelConfig = <?= $modelConfigJsonStr ?>;
window.__videoModelIds = <?= $videoModelIdsJson ?>;
window.__videoModeLabels = {
    'text_to_video': '文生视频',
    'first_frame': '首帧',
    'first_last_frame': '首尾帧',
    'multi_reference': '多帧参考',
    'video_edit': '视频编辑'
};
</script>
<script src="/assets/video.js?v=<?= e((string) (@filemtime(__DIR__ . '/../assets/video.js') ?: time())) ?>"></script>
<?php render_footer(); ?>
