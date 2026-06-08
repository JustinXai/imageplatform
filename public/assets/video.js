// Simplified video generation page — clean mode/size/duration controls
(function () {
    var modelConfig = window.__videoModelConfig || {};
    var modeLabels = window.__videoModeLabels || {};

    var modelSelect    = document.getElementById('ai_model_id');
    var modeSelect     = document.getElementById('video_mode_select');
    var durationSelect = document.getElementById('video_duration_select');
    var aspectSelect   = document.getElementById('video_aspect_select');
    var refUploadField = document.getElementById('refUploadField');
    var videoUploadField = document.getElementById('videoUploadField');
    var audioUploadField = document.getElementById('audioUploadField');
    var refImageInput  = document.getElementById('refImageInput');
    var videoInput     = document.getElementById('refVideoInput');
    var audioInput     = document.getElementById('refAudioInput');
    var maxRefCountSpan    = document.querySelector('[data-max-ref-count]');
    var maxVideoCountSpan  = document.querySelector('[data-max-video-count]');
    var maxAudioCountSpan  = document.querySelector('[data-max-audio-count]');
    var uploadHint         = document.querySelector('[data-video-upload-hint]');
    var costValue          = document.getElementById('costValue');
    var costSecondsInfo     = document.getElementById('costSecondsInfo');
    var form               = document.getElementById('videoGenerateForm');

    var selectedImages = [];
    var selectedVideos = [];
    var selectedAudios = [];

    // ============================================================
    // HELPERS
    // ============================================================

    function getCfg() {
        var id = modelSelect ? parseInt(modelSelect.value, 10) : 0;
        return modelConfig[id] || {};
    }

    // Fixed whitelist for front-end display only
    var ASPECT_WHITELIST = ['auto', '16:9', '4:3', '1:1', '3:4', '9:16', '21:9'];
    var ASPECT_LABELS = {
        'auto': '自动',
        '16:9': '16:9',
        '4:3':  '4:3',
        '1:1':  '1:1',
        '3:4':  '3:4',
        '9:16': '9:16',
        '21:9': '21:9'
    };

    // Fixed whitelist for front-end mode display (Chinese business labels only)
    var MODE_WHITELIST = ['text_to_video', 'first_frame', 'first_last_frame', 'multi_reference', 'video_edit'];
    var MODE_ORDER = ['text_to_video', 'first_frame', 'first_last_frame', 'multi_reference', 'video_edit'];

    function escHtml(v) {
        return String(v || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }

    // ============================================================
    // RENDER MODE OPTIONS (Chinese labels only)
    // ============================================================

    function renderModeOptions(cfg) {
        if (!modeSelect) return;
        var rawModes = cfg.mode_options || [];
        // Intersect with whitelist
        var allowed = rawModes.filter(function (m) { return MODE_WHITELIST.indexOf(m) !== -1; });
        // Sort by fixed order
        allowed.sort(function (a, b) {
            return MODE_ORDER.indexOf(a) - MODE_ORDER.indexOf(b);
        });

        modeSelect.innerHTML = '';

        // Default priority: multi_reference > first_frame > video_default_mode
        var defaults = ['multi_reference', 'first_frame'];
        var defaultMode = cfg.default_mode || 'text_to_video';
        for (var i = 0; i < defaults.length; i++) {
            if (allowed.indexOf(defaults[i]) !== -1) {
                defaultMode = defaults[i];
                break;
            }
        }
        if (allowed.indexOf(defaultMode) === -1 && allowed.length > 0) {
            defaultMode = allowed[0];
        }

        allowed.forEach(function (mode) {
            var opt = document.createElement('option');
            opt.value = mode;
            opt.textContent = modeLabels[mode] || mode;
            if (mode === defaultMode) opt.selected = true;
            modeSelect.appendChild(opt);
        });

        if (allowed.length === 0) {
            var opt = document.createElement('option');
            opt.value = 'text_to_video';
            opt.textContent = '文生视频';
            opt.selected = true;
            modeSelect.appendChild(opt);
        }
    }

    // ============================================================
    // RENDER DURATION OPTIONS
    // ============================================================

    function renderDurationOptions(cfg) {
        if (!durationSelect) return;
        var durs = cfg.duration_options || [];
        durationSelect.innerHTML = '';
        var defaultDur = cfg.default_duration || 0;

        durs.forEach(function (d) {
            var opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d + 's';
            if (d === defaultDur) opt.selected = true;
            durationSelect.appendChild(opt);
        });

        // Always show duration select (even if 1 option)
        var field = document.getElementById('durationField');
        if (field) field.style.display = durs.length > 0 ? '' : 'none';
    }

    // ============================================================
    // RENDER ASPECT (SIZE) OPTIONS — simplified ratio-only display
    // ============================================================

    function renderAspectOptions(cfg) {
        if (!aspectSelect) return;
        var rawAspects = cfg.aspect_options || ['16:9'];
        var defaultAsp = cfg.default_aspect || '16:9';

        // Intersect with whitelist
        var allowed = rawAspects.filter(function (a) { return ASPECT_WHITELIST.indexOf(a) !== -1; });
        if (allowed.length === 0) allowed = ['16:9'];

        // Sort by fixed order
        allowed.sort(function (a, b) {
            return ASPECT_WHITELIST.indexOf(a) - ASPECT_WHITELIST.indexOf(b);
        });

        aspectSelect.innerHTML = '';
        allowed.forEach(function (a) {
            var opt = document.createElement('option');
            opt.value = a;
            opt.textContent = ASPECT_LABELS[a] || a;
            if (a === defaultAsp) opt.selected = true;
            aspectSelect.appendChild(opt);
        });

        // Hide size field — we only show aspect
        var field = document.getElementById('sizeField');
        if (field) field.style.display = 'none';
    }

    // ============================================================
    // UPLOAD VISIBILITY BY MODE
    // ============================================================

    function updateUploadVisibility(cfg) {
        var mode = getCurrentMode();
        var maxImg = cfg.max_ref_images || 0;
        var maxVid = cfg.max_ref_videos || 0;
        var maxAud = cfg.max_ref_audios || 0;

        // Image upload
        var needsImages = ['first_frame', 'first_last_frame', 'multi_reference'].indexOf(mode) !== -1;
        if (refUploadField) refUploadField.classList.toggle('hidden', !needsImages);
        if (needsImages) {
            var imgCount = mode === 'first_frame' ? 1 : (mode === 'first_last_frame' ? 2 : maxImg);
            if (maxRefCountSpan) maxRefCountSpan.textContent = imgCount;
            if (refImageInput) refImageInput.dataset.maxFiles = imgCount;
        }

        // Video upload
        var needsVideo = (mode === 'video_edit') && maxVid > 0;
        if (videoUploadField) videoUploadField.classList.toggle('hidden', !needsVideo);
        if (needsVideo && maxVideoCountSpan) maxVideoCountSpan.textContent = maxVid;
        if (videoInput) videoInput.dataset.maxFiles = maxVid;

        // Audio upload
        var needsAudio = (mode === 'audio_reference') && maxAud > 0;
        if (audioUploadField) audioUploadField.classList.toggle('hidden', !needsAudio);
        if (needsAudio && maxAudioCountSpan) maxAudioCountSpan.textContent = maxAud;
        if (audioInput) audioInput.dataset.maxFiles = maxAud;

        // Hint text
        if (uploadHint) {
            var hintText = '支持 PNG / JPG / WEBP';
            if (mode === 'first_last_frame') {
                hintText = '已选 ' + selectedImages.length + ' / 2（首帧 / 尾帧）';
            } else if (mode === 'first_frame') {
                hintText = '已选 ' + selectedImages.length + ' / 1 张';
            } else if (mode === 'video_edit') {
                hintText = '参考视频编辑，请上传视频文件';
            } else if (mode === 'audio_reference') {
                hintText = '音频参考，请上传音频文件';
            }
            uploadHint.textContent = hintText;
        }
    }

    // ============================================================
    // COST DISPLAY — credits × duration
    // ============================================================

    function updateCost() {
        var cfg = getCfg();
        var credits = cfg.credits || 0;
        var duration = durationSelect && durationSelect.value
            ? parseInt(durationSelect.value, 10)
            : (cfg.default_duration || 1);
        var cost = credits * duration;
        if (costValue) costValue.textContent = cost;
        if (costSecondsInfo) {
            costSecondsInfo.textContent = duration > 1
                ? '(' + credits + '点/秒 × ' + duration + '秒)'
                : '';
            costSecondsInfo.style.display = duration > 1 ? 'inline' : 'none';
        }
    }

    // ============================================================
    // MODE GETTER
    // ============================================================

    function getCurrentMode() {
        return modeSelect ? (modeSelect.value || 'text_to_video') : 'text_to_video';
    }

    // ============================================================
    // REFRESH ALL ON MODEL/MODE CHANGE
    // ============================================================

    function refreshAll() {
        var cfg = getCfg();
        renderModeOptions(cfg);
        renderDurationOptions(cfg);
        renderAspectOptions(cfg);
        updateUploadVisibility(cfg);
        updateCost();
    }

    function onModelChange() {
        refreshAll();
    }

    function onDurationChange() {
        updateCost();
    }

    // ============================================================
    // IMAGE FILE HANDLING
    // ============================================================

    function renderImagePreview() {
        var preview = document.getElementById('refPreview');
        if (!preview) return;
        preview.innerHTML = '';
        var mode = getCurrentMode();
        var maxImg = mode === 'first_frame' ? 1 : (mode === 'first_last_frame' ? 2 : (getCfg().max_ref_images || 0));
        selectedImages.slice(0, maxImg).forEach(function (file, idx) {
            var item = document.createElement('div');
            item.className = 'edit-preview-item';
            if (file.type && file.type.startsWith('image/')) {
                var img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.alt = '参考图 ' + (idx + 1);
                img.addEventListener('load', function () { URL.revokeObjectURL(img.src); }, { once: true });
                item.appendChild(img);
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'edit-preview-remove';
            btn.textContent = '删除';
            btn.addEventListener('click', function () {
                selectedImages.splice(idx, 1);
                syncImageFiles();
                renderImagePreview();
                updateUploadVisibility(getCfg());
            });
            item.appendChild(btn);
            preview.appendChild(item);
        });
    }

    function syncImageFiles() {
        if (!refImageInput) return;
        var dt = new DataTransfer();
        selectedImages.forEach(function (f) { dt.items.add(f); });
        refImageInput.files = dt.files;
    }

    if (refImageInput) {
        refImageInput.addEventListener('change', function (e) {
            var cfg = getCfg();
            var mode = getCurrentMode();
            var maxImg = mode === 'first_frame' ? 1 : (mode === 'first_last_frame' ? 2 : (cfg.max_ref_images || 0));
            var files = Array.prototype.slice.call(e.target.files || []);
            selectedImages = selectedImages.concat(files).slice(0, maxImg);
            renderImagePreview();
            updateUploadVisibility(cfg);
            e.target.value = '';
        });
    }

    // ============================================================
    // VIDEO FILE HANDLING
    // ============================================================

    function renderVideoPreview() {
        var preview = document.getElementById('videoPreview');
        if (!preview) return;
        preview.innerHTML = '';
        var maxVid = getCfg().max_ref_videos || 0;
        selectedVideos.slice(0, maxVid).forEach(function (file, idx) {
            var item = document.createElement('div');
            item.className = 'edit-preview-item';
            item.style.alignItems = 'center';
            item.style.display = 'flex';
            item.style.gap = '8px';
            var label = document.createElement('span');
            label.textContent = file.name;
            label.style.fontSize = '12px';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'edit-preview-remove';
            btn.textContent = '删除';
            btn.addEventListener('click', function () {
                selectedVideos.splice(idx, 1);
                syncVideoFiles();
                renderVideoPreview();
                updateUploadVisibility(getCfg());
            });
            item.appendChild(label);
            item.appendChild(btn);
            preview.appendChild(item);
        });
    }

    function syncVideoFiles() {
        if (!videoInput) return;
        var dt = new DataTransfer();
        selectedVideos.forEach(function (f) { dt.items.add(f); });
        videoInput.files = dt.files;
    }

    if (videoInput) {
        videoInput.addEventListener('change', function (e) {
            var cfg = getCfg();
            var maxVid = cfg.max_ref_videos || 1;
            var files = Array.prototype.slice.call(e.target.files || []);
            selectedVideos = selectedVideos.concat(files).slice(0, maxVid);
            renderVideoPreview();
            updateUploadVisibility(cfg);
            e.target.value = '';
        });
    }

    // ============================================================
    // AUDIO FILE HANDLING
    // ============================================================

    function renderAudioPreview() {
        var preview = document.getElementById('audioPreview');
        if (!preview) return;
        preview.innerHTML = '';
        var maxAud = getCfg().max_ref_audios || 0;
        selectedAudios.slice(0, maxAud).forEach(function (file, idx) {
            var item = document.createElement('div');
            item.className = 'edit-preview-item';
            item.style.alignItems = 'center';
            item.style.display = 'flex';
            item.style.gap = '8px';
            var label = document.createElement('span');
            label.textContent = file.name;
            label.style.fontSize = '12px';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'edit-preview-remove';
            btn.textContent = '删除';
            btn.addEventListener('click', function () {
                selectedAudios.splice(idx, 1);
                syncAudioFiles();
                renderAudioPreview();
                updateUploadVisibility(getCfg());
            });
            item.appendChild(label);
            item.appendChild(btn);
            preview.appendChild(item);
        });
    }

    function syncAudioFiles() {
        if (!audioInput) return;
        var dt = new DataTransfer();
        selectedAudios.forEach(function (f) { dt.items.add(f); });
        audioInput.files = dt.files;
    }

    if (audioInput) {
        audioInput.addEventListener('change', function (e) {
            var cfg = getCfg();
            var maxAud = cfg.max_ref_audios || 1;
            var files = Array.prototype.slice.call(e.target.files || []);
            selectedAudios = selectedAudios.concat(files).slice(0, maxAud);
            renderAudioPreview();
            updateUploadVisibility(cfg);
            e.target.value = '';
        });
    }

    // ============================================================
    // INIT EVENT LISTENERS
    // ============================================================

    if (modelSelect) modelSelect.addEventListener('change', onModelChange);
    if (durationSelect) durationSelect.addEventListener('change', onDurationChange);

    // ============================================================
    // FORM SUBMIT — sync all files
    // ============================================================

    if (form) {
        form.addEventListener('submit', function () {
            syncImageFiles();
            syncVideoFiles();
            syncAudioFiles();
        });
    }

    // ============================================================
    // PUBLIC HELPERS (used by generation handler)
    // ============================================================

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

    // ============================================================
    // INIT ON LOAD
    // ============================================================

    if (modelSelect) refreshAll();
})();
