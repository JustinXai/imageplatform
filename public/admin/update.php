<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$admin = require_admin();
$currentVersion = defined('VERSION') ? VERSION : '1000';

render_header('系统更新', 'admin');
render_admin_nav('update');
?>
<main>
    <section class="card">
        <div class="card-head">
            <div>
                <p class="eyebrow">System Update</p>
                <h2>系统更新</h2>
            </div>
        </div>

        <div class="field-grid" style="margin-bottom:16px;">
            <div>
                <strong style="display:block;font-size:14px;color:var(--text-soft);margin-bottom:4px;">当前版本</strong>
                <span style="font-size:24px;font-weight:700;">v<?= e($currentVersion) ?></span>
            </div>
            <div>
                <strong style="display:block;font-size:14px;color:var(--text-soft);margin-bottom:4px;">更新方式</strong>
                <span style="font-size:14px;color:var(--text-muted);">已改为手动更新</span>
            </div>
        </div>

        <div style="background:var(--main-bg);border-radius:var(--radius-sm);padding:16px;line-height:1.8;color:var(--text-secondary);">
            <p>内置在线更新已移除，不再依赖外部授权或更新服务。</p>
            <p>如需升级，请手动替换程序文件，并保留现有的 <code>config.php</code> 与 <code>storage/</code> 数据。</p>
            <p>升级前建议先备份项目文件和数据库。</p>
        </div>
    </section>
</main>
<?php render_footer(); ?>
