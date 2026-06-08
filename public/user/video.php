<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image_generation.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/video_generation.php';

$user = require_login();
ensure_generation_records_video_columns();
cleanup_stale_running_generation_records();

ensure_ai_models_table();
ensure_ai_models_type_column();
$videoModels = active_video_ai_models();
$noActiveModel = empty($videoModels);

// 预计算视频模型的 fixed_seconds 配置
$modelFixedSeconds = [];
$modelSupportsRef = [];
$modelRefRequired = [];
$modelResolutions = [];
$modelAspectRatios = [];
foreach ($videoModels as $vm) {
    $vid = (int) $vm['id'];
    $modelFixedSeconds[$vid] = max(0, (int) ($vm['fixed_seconds'] ?? 0));
    $modelSupportsRef[$vid] = (int) ($vm['supports_reference'] ?? 0);
    $modelRefRequired[$vid] = (int) ($vm['reference_required'] ?? 0);
    $modelResolutions[$vid] = trim((string) ($vm['video_resolution'] ?? 'auto'));
    $modelAspectRatios[$vid] = trim((string) ($vm['video_aspect_ratio'] ?? 'auto'));
}

$stmt = db()->prepare(
    "SELECT id, user_id, status, mode, model, prompt, size, quality, output_format,
            input_images_json,
            image_url, mime_type, credits_charged, error_message, started_at, finished_at,
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
$maxEditImages = max_edit_images();
$videoNotice = trim((string) app_setting('video_notice', ''));
if ($videoNotice !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{FFFD}]/u', $videoNotice)) {
    $videoNotice = '';
}
$generationNotice = $videoNotice !== '' ? $videoNotice : '注意：视频生成耗时通常较长，请耐心等待，生成失败不扣除次数。';
$videoCost = generation_cost_for('video');
$videoAspectRatioOptions = [
    '16:9' => '16:9 横屏',
    '9:16' => '9:16 竖屏',
    '1:1' => '1:1 方形',
    '4:3' => '4:3 标准横屏',
    '3:4' => '3:4 标准竖屏',
    '21:9' => '21:9 电影宽屏',
    '9:21' => '9:21 超长竖屏',
];
$videoResolutionOptions = [
    'auto' => '自动',
    '720p' => '720p',
    '1080p' => '1080p',
];

render_header('视频生成', 'video');
?>
<main>
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
                    <input type="hidden" name="video_mode" value="text">

                    <div class="mode-toggle" role="group" aria-label="视频生成模式">
                        <label>
                            <input type="radio" name="video_mode_switch" value="text" checked>
                            <span>文生视频</span>
                        </label>
                        <label data-video-mode-with-image>
                            <input type="radio" name="video_mode_switch" value="image">
                            <span>图生视频</span>
                        </label>
                    </div>

                    <div class="field-v3 edit-upload-field hidden" data-video-upload>
                        <label>参考图片（最多 <span data-max-ref-count>1</span> 张）</label>
                        <div class="edit-upload-box" data-video-upload-box>
                            <input name="edit_images[]" type="file" accept="image/png,image/jpeg,image/webp" multiple data-max-files="<?= $maxEditImages ?>">
                            <div class="edit-upload-icon" aria-hidden="true">+</div>
                            <div>
                                <strong>点击上传参考图片</strong>
                                <small data-video-upload-hint>支持 PNG / JPG / WEBP，可多次选择</small>
                            </div>
                        </div>
                        <div class="edit-upload-preview" data-video-preview></div>
                    </div>

                    <div class="field-v3">
                        <label for="prompt">视频描述</label>
                        <textarea id="prompt" name="prompt" rows="5" placeholder="描述你想生成的视频内容，例如镜头、动作、主体和风格..." required></textarea>
                    </div>
                    <div class="field-v3">
                        <label for="size">视频比例</label>
                        <select name="size" id="size">
                            <?php foreach ($videoAspectRatioOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $value === '16:9' ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-v3">
                        <label for="resolution">视频分辨率</label>
                        <select name="resolution" id="resolution">
                            <?php foreach ($videoResolutionOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $value === 'auto' ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($videoModels): ?>
                    <div class="field-v3">
                        <label for="ai_model_id">AI 模型</label>
                        <select name="ai_model_id" id="ai_model_id"
                            data-video-models="<?= e(json_encode(array_map(function ($m) use ($modelSupportsRef, $modelRefRequired, $modelFixedSeconds, $modelResolutions, $modelAspectRatios) {
                                $mid = (int) $m['id'];
                                return [
                                    'id' => $mid,
                                    'credits' => (int) ($m['credits'] ?? 0),
                                    'supports_reference' => $modelSupportsRef[$mid] ?? 0,
                                    'reference_required' => $modelRefRequired[$mid] ?? 0,
                                    'fixed_seconds' => $modelFixedSeconds[$mid] ?? 0,
                                    'video_resolution' => $modelResolutions[$mid] ?? 'auto',
                                    'video_aspect_ratio' => $modelAspectRatios[$mid] ?? 'auto',
                                ];
                            }, $videoModels))) ?>"
                        >
                            <?php foreach ($videoModels as $m): ?>
                                <option value="<?= (int) $m['id'] ?>"
                                    data-credits="<?= (int) ($m['credits'] ?? 0) ?>"
                                    data-supports-ref="<?= (int) ($modelSupportsRef[(int) $m['id']] ?? 0) ?>"
                                    data-ref-required="<?= (int) ($modelRefRequired[(int) $m['id']] ?? 0) ?>"
                                    data-fixed-seconds="<?= (int) ($modelFixedSeconds[(int) $m['id']] ?? 0) ?>"
                                    data-video-res="<?= e($modelResolutions[(int) $m['id']] ?? 'auto') ?>"
                                    data-video-ratio="<?= e($modelAspectRatios[(int) $m['id']] ?? 'auto') ?>"
                                ><?= e($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="cost-hint" data-cost-display data-default-cost="<?= $videoCost ?>">
                        当前消耗：<strong data-cost-value><?= $videoCost ?></strong> <?= e($balanceLabel) ?>/次
                        <span data-cost-seconds-info style="display:none;margin-left:8px;font-size:12px;color:var(--text-muted);"></span>
                    </div>
                    <button id="generateButton" class="btn btn-primary btn-lg" type="submit" style="width:100%;">生成视频</button>
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
                    <?php if (!$records): ?>
                        <div class="history-empty-inline">暂无视频记录，开始你的第一次视频创作吧</div>
                    <?php endif; ?>
                    <?php foreach ($records as $record): ?>
                        <?php $videoSrc = generation_record_video_src($record); ?>
                        <?php $inputImageCount = generation_input_image_count($record); ?>
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
                            data-credits="<?= (int) $record['credits_charged'] ?>"
                            data-created="<?= e($record['created_at']) ?>"
                            data-finished="<?= e($record['finished_at'] ?: '-') ?>"
                            data-error="<?= e($record['error_message'] ?: '') ?>"
                            data-input-count="<?= $inputImageCount ?>"
                        >
                            <?php if ($videoSrc): ?>
                                <video src="<?= e($videoSrc) ?>" controls></video>
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

<script src="/assets/video.js?v=<?= e((string) (@filemtime(__DIR__ . '/../assets/video.js') ?: time())) ?>"></script>
<?php render_footer(); ?>
