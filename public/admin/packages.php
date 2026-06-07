<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$admin = require_admin();
ensure_all_tables();

// 处理新增/编辑/删除
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $credits = max(1, (int) ($_POST['credits'] ?? 1));
        $price = max(0.01, (float) ($_POST['price'] ?? 0.01));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));

        if ($name === '') {
            flash('error', '套餐名称不能为空。');
            redirect('/admin/packages');
        }

        $stmt = db()->prepare(
            'INSERT INTO shop_packages (name, description, credits, price, sort_order) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $description, $credits, $price, $sortOrder]);
        flash('success', '套餐已创建。');
        redirect('/admin/packages');
    }

    if ($action === 'update') {
        $pkgId = (int) ($_POST['package_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $credits = max(1, (int) ($_POST['credits'] ?? 1));
        $price = max(0.01, (float) ($_POST['price'] ?? 0.01));
        $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
        $isActive = (int) ($_POST['is_active'] ?? 1);

        if ($name === '' || $pkgId < 1) {
            flash('error', '参数不合法。');
            redirect('/admin/packages');
        }

        $stmt = db()->prepare(
            'UPDATE shop_packages SET name = ?, description = ?, credits = ?, price = ?, sort_order = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$name, $description, $credits, $price, $sortOrder, $isActive, $pkgId]);
        flash('success', '套餐已更新。');
        redirect('/admin/packages');
    }

    if ($action === 'delete') {
        $pkgId = (int) ($_POST['package_id'] ?? 0);
        if ($pkgId < 1) {
            flash('error', '参数不合法。');
            redirect('/admin/packages');
        }

        $stmt = db()->prepare('DELETE FROM shop_packages WHERE id = ?');
        $stmt->execute([$pkgId]);
        flash('success', '套餐已删除。');
        redirect('/admin/packages');
    }

    flash('error', '未知操作。');
    redirect('/admin/packages');
}

// 查询所有套餐
$stmt = db()->query('SELECT * FROM shop_packages ORDER BY sort_order ASC, id ASC');
$packages = $stmt->fetchAll();

// 统计
$activeCount = 0;
foreach ($packages as $pkg) { if ((int)$pkg['is_active'] === 1) $activeCount++; }

render_header('套餐管理', 'admin');
render_admin_nav('packages');
?>
<style>
/* ── 套餐管理卡片布局 ── */
.pkg-stats {
    display: flex;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.pkg-stat-card {
    flex: 1;
    min-width: 150px;
    padding: 20px;
    border-radius: 16px;
    background: var(--card-bg);
    border: 1px solid var(--line);
    text-align: center;
}
.pkg-stat-card .num {
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
}
.pkg-stat-card .lbl {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 4px;
}
.pkg-stat-card.green .num { color: #10b981; }
.pkg-stat-card.blue  .num { color: var(--primary); }

.pkg-create-section {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
}
.pkg-create-section h2 {
    font-size: 17px;
    font-weight: 700;
    margin: 0 0 18px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.pkg-create-section h2::before {
    content: '+';
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    border-radius: 8px;
    background: var(--primary-glow), rgba(59,130,246,0.12));
    color: var(--primary);
    font-size: 16px;
    font-weight: 700;
}
.pkg-create-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 12px;
}
.pkg-create-grid .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.pkg-create-grid .field label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.pkg-create-grid input,
.pkg-create-grid textarea {
    padding: 10px 12px;
    font-size: 14px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--main-surface);
    color: var(--text);
    transition: border-color .2s, box-shadow .2s;
}
.pkg-create-grid input:focus,
.pkg-create-grid textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow));
}
.pkg-create-grid textarea {
    resize: vertical;
    min-height: 44px;
}
.pkg-create-full {
    grid-column: 1 / -1;
}
.pkg-create-submit {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
    margin-top: 4px;
}

/* ── 套餐卡片列表 ── */
.pkg-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}
.pkg-card {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 20px;
    transition: border-color .2s, box-shadow .2s;
}
.pkg-card:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}
.pkg-card.inactive {
    opacity: 0.55;
}
.pkg-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
.pkg-card-header .name {
    font-size: 16px;
    font-weight: 700;
    color: var(--text);
}
.pkg-card-header .sort-tag {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    background: var(--main-surface-soft);
    padding: 3px 10px;
    border-radius: 20px;
}
.pkg-card-meta {
    display: flex;
    gap: 20px;
    margin-bottom: 12px;
    padding: 12px 0;
    border-top: 1px solid var(--line);
    border-bottom: 1px solid var(--line);
}
.pkg-meta-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.pkg-meta-item .val {
    font-size: 17px;
    font-weight: 700;
    color: var(--text);
}
.pkg-meta-item .lbl {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.pkg-card-editor {
    display: grid;
    grid-template-columns: 3fr 2fr 2fr;
    gap: 8px;
    align-items: center;
    margin-bottom: 10px;
}
.pkg-card-editor input,
.pkg-card-editor select {
    width: 100%;
    padding: 7px 10px;
    font-size: 13px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--main-surface);
    color: var(--text);
    box-sizing: border-box;
    transition: border-color .15s;
}
.pkg-card-editor input:focus,
.pkg-card-editor select:focus {
    outline: none;
    border-color: var(--primary);
}
.pkg-card-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}
.pkg-card-actions .btn {
    padding: 7px 16px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    border: none;
    transition: all .15s;
}
.pkg-card-actions .btn-save {
    background: var(--primary);
    color: #fff;
}
.pkg-card-actions .btn-save:hover {
    opacity: 0.85;
}
.pkg-card-actions .btn-save:disabled {
    opacity: 0.5;
    cursor: default;
}
.pkg-card-actions .btn-danger {
    background: none;
    color: #ef4444;
    border: 1px solid #fecaca;
}
.pkg-card-actions .btn-danger:hover {
    background: #fef2f2;
}

.pkg-name-input {
    width: 100%;
    padding: 5px 8px;
    font-size: 14px;
    font-weight: 700;
    border: 1px solid transparent;
    border-radius: 8px;
    background: transparent;
    color: var(--text);
    box-sizing: border-box;
    transition: border-color .15s, background .15s;
}
.pkg-name-input:hover { border-color: var(--line); background: var(--main-surface); }
.pkg-name-input:focus { outline: none; border-color: var(--primary); background: var(--main-surface); }

.pkg-description-input {
    width: 100%;
    padding: 5px 8px;
    font-size: 13px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: transparent;
    color: var(--text-muted);
    box-sizing: border-box;
    resize: none;
    transition: border-color .15s, background .15s;
}
.pkg-description-input:hover { border-color: var(--line); background: var(--main-surface); }
.pkg-description-input:focus { outline: none; border-color: var(--primary); background: var(--main-surface); }

/* ── 上下架开关 ── */
.pkg-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
}
.pkg-toggle input {
    position: relative;
    width: 42px;
    height: 24px;
    appearance: none;
    -webkit-appearance: none;
    border-radius: 12px;
    background: #d1d5db;
    cursor: pointer;
    transition: background .25s;
    flex-shrink: 0;
}
.pkg-toggle input::after {
    content: '';
    position: absolute;
    top: 2px; left: 2px;
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.15);
    transition: transform .25s;
}
.pkg-toggle input:checked { background: #10b981; }
.pkg-toggle input:checked::after { transform: translateX(18px); }
.pkg-toggle-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    min-width: 48px;
}

/* 页面头部 */
.pkg-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.pkg-header h1 {
    font-size: 22px;
    font-weight: 700;
    color: var(--text);
}
</style>

<main>
    <!-- 统计卡片 -->
    <div class="pkg-stats">
        <div class="pkg-stat-card blue">
            <div class="num"><?= count($packages) ?></div>
            <div class="lbl">套餐总数</div>
        </div>
        <div class="pkg-stat-card green">
            <div class="num"><?= $activeCount ?></div>
            <div class="lbl">已上架</div>
        </div>
        <div class="pkg-stat-card">
            <div class="num"><?= count($packages) - $activeCount ?></div>
            <div class="lbl">已下架</div>
        </div>
    </div>

    <!-- 新增套餐表单 -->
    <section class="pkg-create-section">
        <h2>新增套餐</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="pkg-create-grid">
                <div class="field">
                    <label>套餐名称</label>
                    <input name="name" placeholder="例如：入门套餐" required>
                </div>
                <div class="field">
                    <label>排序权重</label>
                    <input name="sort_order" type="number" min="0" value="0">
                </div>
                <div class="pkg-create-full field">
                    <label>描述（选填）</label>
                    <textarea name="description" placeholder="简要描述套餐特点" rows="2"></textarea>
                </div>
                <div class="field">
                    <label><?= e(balance_label()) ?>数量</label>
                    <input name="credits" type="number" min="1" value="10" required>
                </div>
                <div class="field">
                    <label>价格（元）</label>
                    <input name="price" type="number" step="0.01" min="0.01" value="1.00" required>
                </div>
                <div class="pkg-create-submit">
                    <button type="submit" style="padding:10px 28px;font-size:14px;font-weight:600;background:var(--primary);color:#fff;border:none;border-radius:10px;cursor:pointer;">创建套餐</button>
                </div>
            </div>
        </form>
    </section>

    <!-- 套餐列表 -->
    <div class="pkg-grid">
        <?php foreach ($packages as $pkg): ?>
        <?php $pkgId = (int) $pkg['id']; $isActive = (int)$pkg['is_active'] === 1; ?>
        <div class="pkg-card<?= !$isActive ? ' inactive' : '' ?>" data-pkg-id="<?= $pkgId ?>">
            <form method="post" onsubmit="return pkgSubmit(this)" data-pkg-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="package_id" value="<?= $pkgId ?>">

                <!-- 头部 -->
                <div class="pkg-card-header">
                    <input class="pkg-name-input" name="name" value="<?= e($pkg['name']) ?>" required>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="sort-tag">排序 #<input type="number" name="sort_order" min="0" value="<?= (int)$pkg['sort_order'] ?>" style="width:40px;padding:1px 4px;font-size:11px;border:1px solid var(--line);border-radius:4px;background:transparent;text-align:center;color:var(--text-muted);"></span>
                        <label class="pkg-toggle" data-pkg-toggle>
                            <input type="checkbox" name="is_active_check" value="1" <?= $isActive ? 'checked' : '' ?> onchange="this.nextElementSibling.textContent=this.checked?'已上架':'已下架';this.closest('.pkg-card').classList.toggle('inactive',!this.checked)">
                            <span class="pkg-toggle-label"><?= $isActive ? '已上架' : '已下架' ?></span>
                        </label>
                        <input type="hidden" name="is_active" value="<?= $isActive ? '1' : '0' ?>">
                    </div>
                </div>

                <!-- 描述 -->
                <textarea class="pkg-description-input" name="description" rows="2" placeholder="描述（选填）"><?= e($pkg['description'] ?: '') ?></textarea>

                <!-- 核心指标 -->
                <div class="pkg-card-meta">
                    <div class="pkg-meta-item">
                        <span class="lbl"><?= e(balance_label()) ?></span>
                        <input type="number" name="credits" min="1" value="<?= (int)$pkg['credits'] ?>" style="font-size:17px;font-weight:700;border:none;background:transparent;color:inherit;width:80px;padding:0;">
                    </div>
                    <div class="pkg-meta-item">
                        <span class="lbl">价格 (元)</span>
                        <input type="number" name="price" step="0.01" min="0.01" value="<?= number_format((float)$pkg['price'], 2, '.', '') ?>" style="font-size:17px;font-weight:700;border:none;background:transparent;color:inherit;width:90px;padding:0;">
                    </div>
                </div>

                <!-- 操作 -->
                <div class="pkg-card-actions">
                    <button type="submit" class="btn btn-save">保存</button>
            </form>
                    <form method="post" onsubmit="return confirm('确定删除「<?= e($pkg['name']) ?>」套餐？')" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="package_id" value="<?= $pkgId ?>">
                        <button type="submit" class="btn btn-danger">删除</button>
                    </form>
                </div>
        </div>
        <?php endforeach; ?>

        <?php if (!$packages): ?>
        <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text-muted);font-size:15px;">
            <div style="font-size:40px;margin-bottom:12px;">📦</div>
            还没有套餐，请在上方创建第一个
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
function pkgSubmit(form) {
    var btn = form.querySelector('.btn-save');
    if (btn) { btn.disabled = true; btn.textContent = '保存中...'; }
    // 同步开关到隐藏域
    var cb = form.querySelector('[name="is_active_check"]');
    var hidden = form.querySelector('[name="is_active"]');
    if (cb && hidden) hidden.value = cb.checked ? '1' : '0';
    return true;
}
</script>

<?php render_footer(); ?>
