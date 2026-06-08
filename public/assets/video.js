// Dynamic video generation page - model-aware options
(function () {
    var modelConfig = window.__videoModelConfig || {};
    var modeLabels = window.__videoModeLabels || {};

    var modelSelect = document.getElementById('ai_model_id');
    var modeContainer = document.getElementById('modeToggleContainer');
    var refUploadField = document.getElementById('refUploadField');
    var refImageInput = document.getElementById('refImageInput');
    var durationSelect = document.getElementById('video_duration_select');
    var aspectSelect = document.getElementById('video_aspect');
    var sizeSelect = document.getElementById('video_size');
    var durationField = document.getElementById('durationField');
    var sizeField = document.getElementById('sizeField');
    var costValue = document.getElementById('costValue');
    var costSecondsInfo = document.getElementById('costSecondsInfo');
    var videoModeField = document.getElementById('video_mode_field');
    var videoDurationField = document.getElementById('video_duration_field');
    var maxRefCountSpan = document.querySelector('[data-max-ref-count]');
    var uploadHint = document.querySelector('[data-video-upload-hint]');
    var form = document.getElementById('videoGenerateForm');

    var selectedFiles = [];

    function getCfg() {
        var id = modelSelect ? parseInt(modelSelect.value, 10) : 0;
        return modelConfig[id] || {};
    }

    function renderModeOptions(cfg) {
        if (!modeContainer) return;
        var modes = cfg.mode_options || ['text_to_video'];
        var defaultMode = cfg.default_mode || 'text_to_video';
        modeContainer.innerHTML = '';
        modes.forEach(function (mode) {
            var label = modeLabels[mode] || mode;
            var div = document.createElement('label');
            div.innerHTML = '<input type="radio" name="video_mode_switch" value="' + escHtml(mode) + '"><span>' + escHtml(label) + '</span>';
            modeContainer.appendChild(div);
        });
        modeContainer.querySelectorAll('input[name="video_mode_switch"]').forEach(function (inp) {
            inp.addEventListener('change', onModelOrModeChange);
        });
        updateUploadVisibility(cfg);
    }

    function renderDurationOptions(cfg) {
        if (!durationSelect) return;
        var durs = cfg.duration_options || [];
        durationSelect.innerHTML = '';
        if (durs.length <= 1) {
            if (durationField) durationField.style.display = 'none';
        } else {
            if (durationField) durationField.style.display = '';
            durs.forEach(function (d) {
                var opt = document.createElement('option');
                opt.value = d;
                opt.textContent = d + '秒';
                durationSelect.appendChild(opt);
            });
        }
    }

    function renderAspectOptions(cfg) {
        if (!aspectSelect) return;
        var aspects = cfg.aspect_options || ['16:9'];
        var defaultAsp = cfg.default_aspect || '16:9';
        aspectSelect.innerHTML = '';
        var labels = {
            '16:9': '16:9 横屏', '9:16': '9:16 竖屏', '1:1': '1:1 方形',
            '4:3': '4:3 标准横屏', '3:4': '3:4 标准竖屏',
            '21:9': '21:9 电影宽屏', '9:21': '9:21 超长竖屏', 'auto': '自动'
        };
        aspects.forEach(function (a) {
            var opt = document.createElement('option');
            opt.value = a;
            opt.textContent = labels[a] || a;
            if (a === defaultAsp) opt.selected = true;
            aspectSelect.appendChild(opt);
        });
    }

    function renderSizeOptions(cfg) {
        if (!sizeSelect) return;
        var sizes = cfg.size_options || ['auto'];
        var defaultSz = cfg.default_size || 'auto';
        sizeSelect.innerHTML = '';
        if (sizes.length <= 1 && (sizes.length === 0 || sizes[0] === 'auto')) {
            if (sizeField) sizeField.style.display = 'none';
        } else {
            if (sizeField) sizeField.style.display = '';
            sizes.forEach(function (s) {
                var opt = document.createElement('option');
                opt.value = s;
                opt.textContent = s === 'auto' ? '自动' : s;
                if (s === defaultSz) opt.selected = true;
                sizeSelect.appendChild(opt);
            });
        }
    }

    function updateUploadVisibility(cfg) {
        var checked = document.querySelector('input[name="video_mode_switch"]:checked');
        var mode = checked ? checked.value : 'text_to_video';
        var needsUpload = mode !== 'text_to_video';
        if (refUploadField) refUploadField.classList.toggle('hidden', !needsUpload);
        var maxRef = cfg.max_ref_images || 1;
        if (maxRefCountSpan) maxRefCountSpan.textContent = maxRef;
        if (refImageInput) refImageInput.dataset.maxFiles = maxRef;
        if (uploadHint) {
            if (mode === 'first_last_frame') {
                uploadHint.textContent = selectedFiles.length > 0
                    ? '已选择 ' + selectedFiles.length + ' / 2 张（首帧 / 尾帧）'
                    : '首帧参考 / 尾帧参考，请上传 2 张图片';
            } else {
                uploadHint.textContent = selectedFiles.length > 0
                    ? '已选择 ' + selectedFiles.length + ' / ' + maxRef + ' 张，可继续添加'
                    : '支持 PNG / JPG / WEBP，可多次选择';
            }
        }
    }

    function updateCost() {
        var cfg = getCfg();
        var credits = cfg.credits || 0;
        var duration = durationSelect && durationSelect.value ? parseInt(durationSelect.value, 10) : (cfg.default_duration || 1);
        var cost = credits * duration;
        if (costValue) costValue.textContent = cost;
        if (costSecondsInfo) {
            costSecondsInfo.textContent = duration > 1 ? '(' + credits + '点/秒 × ' + duration + '秒)' : '';
            costSecondsInfo.style.display = duration > 1 ? 'inline' : 'none';
        }
    }

    function syncHidden() {
        var checked = document.querySelector('input[name="video_mode_switch"]:checked');
        if (videoModeField && checked) videoModeField.value = checked.value;
        if (videoDurationField && durationSelect) videoDurationField.value = durationSelect.value;
    }

    function onModelOrModeChange() {
        var cfg = getCfg();
        renderModeOptions(cfg);
        renderDurationOptions(cfg);
        renderAspectOptions(cfg);
        renderSizeOptions(cfg);
        updateUploadVisibility(cfg);
        updateCost();
        syncHidden();
    }

    if (modelSelect) modelSelect.addEventListener('change', onModelOrModeChange);
    if (durationSelect) durationSelect.addEventListener('change', function () { updateCost(); syncHidden(); });
    if (refImageInput) {
        refImageInput.addEventListener('change', function (e) {
            var cfg = getCfg();
            var maxRef = cfg.max_ref_images || 1;
            var files = Array.prototype.slice.call(e.target.files || []);
            selectedFiles = selectedFiles.concat(files).slice(0, maxRef);
            renderUploadPreview(maxRef);
            updateUploadVisibility(cfg);
            e.target.value = '';
        });
    }

    function renderUploadPreview(maxRef) {
        var preview = document.getElementById('refPreview');
        if (!preview) return;
        preview.innerHTML = '';
        selectedFiles.slice(0, maxRef).forEach(function (file, idx) {
            var item = document.createElement('div');
            item.className = 'edit-preview-item';
            var img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.alt = '参考图 ' + (idx + 1);
            img.addEventListener('load', function () { URL.revokeObjectURL(img.src); }, { once: true });
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'edit-preview-remove';
            btn.textContent = '删除';
            btn.addEventListener('click', function () {
                selectedFiles.splice(idx, 1);
                syncFiles();
                renderUploadPreview(maxRef);
                updateUploadVisibility(getCfg());
            });
            item.appendChild(img);
            item.appendChild(btn);
            preview.appendChild(item);
        });
    }

    function syncFiles() {
        if (!refImageInput) return;
        var dt = new DataTransfer();
        selectedFiles.forEach(function (f) { dt.items.add(f); });
        refImageInput.files = dt.files;
    }

    function escHtml(v) {
        return String(v || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }

    // Init
    if (modelSelect) onModelOrModeChange();

    // Form submit hook
    if (form) {
        form.addEventListener('submit', function () {
            syncHidden();
            syncFiles();
        });
    }

    // Expose for external use
    window.showMessage = function (text, type) {
        try { window.showToast && window.showToast(text, type); } catch (_) {}
        var msg = document.getElementById('generateMessage');
        if (msg) { msg.textContent = text || ''; msg.className = 'inline-message ' + (type || 'success'); }
    };
    window.setGenerating = function (enabled) {
        var btn = document.getElementById('generateButton');
        if (btn) { btn.disabled = enabled; btn.textContent = enabled ? '生成中...' : '生成视频'; }
    };
    window.updateCredits = function (credits) {
        if (credits === undefined || credits === null) return;
        document.querySelectorAll('[data-balance-display]').forEach(function (n) {
            n.textContent = Number(credits).toLocaleString();
        });
    };
})();
