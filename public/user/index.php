<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image_generation.php';
require_once __DIR__ . '/../../src/migration.php';

$user = require_login();
ensure_generation_records_soft_delete();
ensure_generation_records_queue_status();
cleanup_stale_running_generation_records();

ensure_ai_models_table();
ensure_ai_models_type_column();
$aiModels = active_ai_models();
$hasGlobalImageConfig = trim((string) app_setting('image_base_url', '')) !== '' && trim((string) app_setting('image_api_key', '')) !== '';
$noActiveModel = empty($aiModels) && !$hasGlobalImageConfig;
$stmt = db()->prepare(
    "SELECT id, user_id, status, mode, model, prompt, size, quality, output_format,
            input_images_json,
            image_url, mime_type, credits_charged, error_message, started_at, finished_at,
            deleted_at, created_at, image_base64 IS NOT NULL AS has_image_base64
     FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL AND (mode IS NULL OR mode != 'video')
     ORDER BY created_at DESC
     LIMIT 5"
);
$stmt->execute([$user['id']]);
$records = $stmt->fetchAll();
$rawNotice = trim((string) app_setting('generation_notice', ''));
// 防御乱码：检测已知乱码模式或 PUA 字符
if ($rawNotice !== '') {
    $knownCorrupted = ['姝ｆ', '鎻愪', '鐢', '鍥', '璇', '缁', '缂', '寮', '褰', '鏀'];
    foreach ($knownCorrupted as $pattern) {
        if (strpos($rawNotice, $pattern) !== false) {
            $rawNotice = '';
            break;
        }
    }
}
if ($rawNotice !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{FFFD}]/u', $rawNotice)) {
    $rawNotice = '';
}
$generationNotice = $rawNotice ?: '注意：因AI算力产图较慢，预计可能3-5分钟不止，请耐心等待，生成失败不消耗次数！';
$balanceLabel = balance_label();
$maxEditImages = max_edit_images();
$drawCost = generation_cost_for('draw');
$editCost = generation_cost_for('edit');
$sizeOptions = [
    'auto' => 'Auto',
    '1:1' => '1:1',
    '3:2' => '3:2',
    '2:3' => '2:3',
    '4:3' => '4:3',
    '3:4' => '3:4',
    '5:4' => '5:4',
    '4:5' => '4:5',
    '16:9' => '16:9',
    '9:16' => '9:16',
    '2:1' => '2:1',
    '1:2' => '1:2',
    '21:9' => '21:9',
    '9:21' => '9:21',
];
$promptOptimizeEnabled = (bool) app_setting('prompt_optimize_enabled', '0');

render_header('图片生成器', 'app');
?>
<main>
    <div class="page-hd">
        <div>
            <p><?= e($platformName ?? platform_name()) ?></p>
            <h1>图片生成</h1>
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
                    <h3>生成设置</h3>
                    <p class="sub">Settings</p>
                </div>
            </div>
            <div class="card-v3-body">
                <?php if ($generationNotice !== ''): ?>
                    <div class="generation-notice"><?= e($generationNotice) ?></div>
                <?php endif; ?>
                <?php if ($noActiveModel): ?>
                    <div class="alert error">
                        <strong>系统错误</strong>
                        <span>管理员尚未配置可用的 AI 模型，请前往后台 → AI 模型，至少添加并启用一个模型。</span>
                    </div>
                <?php endif; ?>
                <form id="generateForm" class="form">
                    <?= csrf_field() ?>
                    <div class="mode-toggle" role="group" aria-label="生成模式">
                        <label>
                            <input type="radio" name="mode" value="draw" checked>
                            <span>绘画</span>
                        </label>
                        <label>
                            <input type="radio" name="mode" value="edit">
                            <span>编辑</span>
                        </label>
                    </div>
                    <div class="field-v3 edit-upload-field hidden" data-edit-upload>
                        <label>参考图片（最多 <?= $maxEditImages ?> 张）</label>
                        <div class="edit-upload-box" data-edit-upload-box>
                            <input name="edit_images[]" type="file" accept="image/png,image/jpeg,image/webp" multiple data-max-files="<?= $maxEditImages ?>">
                            <div class="edit-upload-icon" aria-hidden="true">+</div>
                            <div>
                                <strong>点击上传参考图片</strong>
                                <small data-edit-upload-hint>支持 PNG / JPG / WEBP，可多次选择</small>
                            </div>
                        </div>
                        <div class="edit-upload-preview" data-edit-preview></div>
                    </div>
                    <div class="field-v3">
                        <label for="prompt">提示词</label>
                        <textarea name="prompt" id="prompt" rows="7" placeholder="描述你想生成的图片内容..." required></textarea>
                        <?php if ($promptOptimizeEnabled): ?>
                        <div class="prompt-optimize-bar">
                            <button id="optimizePromptBtn" class="btn btn-secondary btn-sm" type="button">+ 优化提示词</button>
                            <span id="optimizePromptStatus" class="hint hidden"></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="field-v3">
                        <label for="image_size">图片尺寸</label>
                        <div class="model-chip">
                            <select name="size" id="image_size">
                                <?php foreach ($sizeOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $value === 'auto' ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if ($aiModels): ?>
                    <div class="field-v3">
                        <label for="ai_model">AI 模型</label>
                        <div class="model-chip">
                            <select name="ai_model_id" id="ai_model"
                                data-image-models="<?= e(json_encode(array_map(function($m) { return ['id' => (int)$m['id'], 'name' => $m['name'], 'credits' => (int)($m['credits'] ?? 0), 'supports_edit' => (int)($m['supports_edit'] ?? 0)]; }, $aiModels))) ?>"
                            >
                                <?php foreach ($aiModels as $m): ?>
                                    <option value="<?= (int) $m['id'] ?>" data-credits="<?= (int)($m['credits'] ?? 0) ?>" data-supports-edit="<?= (int)($m['supports_edit'] ?? 0) ?>"><?= e($m['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="cost-hint" data-cost-display data-draw-cost="<?= $drawCost ?>" data-edit-cost="<?= $editCost ?>">
                        当前消耗：<strong data-cost-value="<?= $drawCost ?>"><?= $drawCost ?></strong> <?= e($balanceLabel) ?>/次
                        <span data-cost-edit class="hidden">（编辑模式：<strong><?= $editCost ?></strong> <?= e($balanceLabel) ?>/次）</span>
                    </div>
                    <button id="generateButton" class="btn btn-primary btn-lg" type="submit" style="width:100%;">生成图片</button>
                </form>
                <div id="generateMessage" class="inline-message hidden"></div>
            </div>
        </section>

        <section class="card-v3">
            <div class="card-v3-head">
                <div>
                    <h3>我的图库</h3>
                    <p class="sub">My Gallery</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="/user/records">查看全部</a>
            </div>
            <div class="card-v3-body">
                <div id="historyList" class="grid-auto" data-gallery>
                    <?php if (!$records): ?>
                        <div class="history-empty-inline" style="grid-column:1/-1;">暂无生成记录，开始你的第一次创作吧</div>
                    <?php endif; ?>
                    <?php foreach ($records as $record): ?>
                        <?php $src = record_image_src($record); ?>
                        <?php $videoSrc = generation_record_video_src($record); ?>
                        <?php $inputImageCount = generation_input_image_count($record); ?>
                        <?php $isVideo = ($record['mode'] ?? 'draw') === 'video'; ?>
                        <article class="media-card" tabindex="0" data-record-id="<?= (int) $record['id'] ?>" data-status="<?= e($record['status']) ?>" data-mode="<?= e($record['mode'] ?? 'draw') ?>" data-prompt="<?= e($record['prompt']) ?>" data-size="<?= e($record['size']) ?>" data-quality="<?= e($record['quality']) ?>" data-format="<?= e($record['output_format']) ?>" data-credits="<?= (int) $record['credits_charged'] ?>" data-created="<?= e($record['created_at']) ?>" data-finished="<?= e($record['finished_at'] ?: '-') ?>" data-error="<?= e($record['error_message'] ?: '') ?>" data-input-count="<?= $inputImageCount ?>" style="cursor:pointer;">
                            <?php if ($isVideo && $videoSrc): ?>
                                <video src="<?= e($videoSrc) ?>" controls></video>
                            <?php elseif ($src): ?>
                                <img src="<?= e($src) ?>" alt="生成图片">
                            <?php else: ?>
                                <div style="width:100%;aspect-ratio:1;display:grid;place-items:center;background:var(--main-surface-soft);color:var(--text-muted);font-weight:700;font-size:13px;">
                                    <span class="status-badge <?= e($record['status']) ?>"><?= e(generation_status_label((string) $record['status'])) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="media-card-body">
                                <div class="prompt"><?= e($record['prompt']) ?></div>
                                <div class="meta">
                                    <span class="status-badge <?= e($record['status']) ?>"><?= e(generation_status_label((string) $record['status'])) ?></span>
                                    <span><?= e(mode_display_label((string) ($record['mode'] ?? 'draw'))) ?> / <?= e($record['size']) ?> / <?= e($isVideo ? ($record['output_format'] ?: 'mp4') : $record['quality']) ?></span>
                                </div>
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:6px;">
                                    <time style="font-size:10px;color:var(--text-muted);"><?= e($record['created_at']) ?></time>
                                    <form method="post" action="/delete_record" class="record-delete-form" onsubmit="return confirm('确认删除这条生成记录？')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                        <input type="hidden" name="redirect_to" value="/user/index">
                                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);">删除</button>
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

<script src="/assets/user.js?v=<?= e((string) (@filemtime(__DIR__ . '/../assets/user.js') ?: time())) ?>"></script>
<?php render_footer(); ?>
