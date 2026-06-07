<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $socialLoginEnabledPost = (string) ($_POST['social_login_enabled'] ?? 'off');
    set_app_setting('social_login_enabled', $socialLoginEnabledPost === 'on' ? 'on' : 'off');
    set_app_setting('social_login_api_url', trim((string) ($_POST['social_login_api_url'] ?? '')));
    set_app_setting('social_login_appid', trim((string) ($_POST['social_login_appid'] ?? '')));
    set_app_setting('social_login_appkey', trim((string) ($_POST['social_login_appkey'] ?? '')));
    set_app_setting('social_login_types', implode(',', (array) ($_POST['social_login_types'] ?? [])));

    flash('success', '聚合登录配置已保存。');
    redirect('/admin/social_login');
}

$socialLoginEnabled = app_setting('social_login_enabled', 'off') === 'on';
$socialLoginApiUrl = trim((string) app_setting('social_login_api_url', ''));
$socialLoginAppid = trim((string) app_setting('social_login_appid', ''));
$socialLoginAppkey = trim((string) app_setting('social_login_appkey', ''));
$socialLoginTypes = explode(',', (string) app_setting('social_login_types', ''));

render_header('聚合登录配置', 'admin');
render_admin_nav('social_login');
?>
<style>
.admin-form { margin-top: 14px; }
</style>
<main>
    <div class="page-hd">
        <div>
            <h1>聚合登录</h1>
            <p>第三方 OAuth2.0 登录配置</p>
        </div>
    </div>

    <section class="card-v3">
        <div class="card-v3-body">
            <form method="post" class="admin-form">
                <?= csrf_field() ?>
                <label class="field">
                    <span>启用聚合登录</span>
                    <select name="social_login_enabled">
                        <option value="off" <?= !$socialLoginEnabled ? 'selected' : '' ?>>关闭</option>
                        <option value="on" <?= $socialLoginEnabled ? 'selected' : '' ?>>开启</option>
                    </select>
                    <span class="field-hint">开启后登录页会显示第三方登录按钮，用户中心可绑定/解绑。</span>
                </label>
                <label class="field">
                    <span>接口地址</span>
                    <input name="social_login_api_url" value="<?= e($socialLoginApiUrl) ?>" placeholder="接口地址">
                    <span class="field-hint">仅填写域名</span>
                </label>
                <label class="field">
                    <span>APPID</span>
                    <input name="social_login_appid" value="<?= e($socialLoginAppid) ?>" placeholder="你的 APPID">
                </label>
                <label class="field">
                    <span>APPKEY</span>
                    <input name="social_login_appkey" value="<?= e($socialLoginAppkey) ?>" placeholder="你的 APPKEY" type="password">
                </label>
                <label class="field">
                    <span>允许的登录方式（可多选）</span>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;">
                        <?php foreach (social_login_types() as $key => $label): ?>
                        <label style="display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--line);border-radius:8px;cursor:pointer;font-size:13px;user-select:none;">
                            <input type="checkbox" name="social_login_types[]" value="<?= e($key) ?>" <?= in_array($key, $socialLoginTypes) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="field-hint">勾选的登录方式将显示在登录页面。</span>
                </label>

                <button class="button primary" type="submit">保存配置</button>
            </form>
        </div>
    </section>
</main>
<?php render_footer(); ?>
