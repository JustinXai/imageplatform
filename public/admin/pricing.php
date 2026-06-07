<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $drawBaseCost  = max(1, (int) ($_POST['draw_base_cost'] ?? 1));
    $editBaseCost  = max(1, (int) ($_POST['edit_base_cost'] ?? 2));
    $videoBaseCost = max(1, (int) ($_POST['video_base_cost'] ?? 5));
    $chatBaseCost  = max(1, (int) ($_POST['chat_base_cost'] ?? 1));

    set_app_setting('draw_base_cost', (string) $drawBaseCost);
    set_app_setting('edit_base_cost', (string) $editBaseCost);
    set_app_setting('video_base_cost', (string) $videoBaseCost);
    set_app_setting('chat_base_cost', (string) $chatBaseCost);

    flash('success', '计费配置已保存。');
    redirect('/admin/pricing');
}

$drawBaseCost  = app_setting('draw_base_cost', '1');
$editBaseCost  = app_setting('edit_base_cost', '2');
$videoBaseCost = app_setting('video_base_cost', '5');
$chatBaseCost  = app_setting('chat_base_cost', '1');

render_header('计费配置', 'admin');
render_admin_nav('pricing');
?>
<main class="auth-wrap">
    <section class="card auth-card wide">
        <h2>计费配置</h2>
        <p class="field-hint">各功能每次使用的积分消耗。对话模型请到「AI模型」页面添加。</p>
        <form method="post" class="form" style="margin-top:14px;">
            <?= csrf_field() ?>

            <div class="field-grid">
                <label class="field">
                    <span>绘画基础消耗（积分/次）</span>
                    <input name="draw_base_cost" type="number" min="1" value="<?= e($drawBaseCost) ?>" required>
                </label>
                <label class="field">
                    <span>编辑基础消耗（积分/次）</span>
                    <input name="edit_base_cost" type="number" min="1" value="<?= e($editBaseCost) ?>" required>
                </label>
                <label class="field">
                    <span>视频基础消耗（积分/次）</span>
                    <input name="video_base_cost" type="number" min="1" value="<?= e($videoBaseCost) ?>" required>
                </label>
                <label class="field">
                    <span>AI对话消耗（积分/次）</span>
                    <input name="chat_base_cost" type="number" min="1" value="<?= e($chatBaseCost) ?>" required>
                </label>
            </div>

            <button class="button primary" type="submit" style="margin-top:16px;">保存配置</button>
        </form>
    </section>
</main>
<?php render_footer(); ?>
