// Dynamic video generation page - model-aware options with video/audio reference support
(function () {
    var modelConfig = window.__videoModelConfig || {};
    var modeLabels = window.__videoModeLabels || {};

    var modelSelect = document.getElementById('ai_model_id');
    var modeContainer = document.getElementById('modeToggleContainer');
    var refUploadField = document.getElementById('refUploadField');
    var refImageInput = document.getElementById('refImageInput');
    var videoUploadField = document.getElementById('videoUploadField');
    var videoInput = document.getElementById('refVideoInput');
    var audioUploadField = document.getElementById('audioUploadField');
    var audioInput = document.getElementById('refAudioInput');
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
    var maxVideoCountSpan = document.querySelector('[data-max-video-count]');
    var maxAudioCountSpan = document.querySelector('[data-max-audio-count]');
    var uploadHint = document.querySelector('[data-video-upload-hint]');
    var form = document.getElementById('videoGenerateForm');

    var selectedImages = [];
    var selectedVideos = [];
    var selectedAudios = [];

    function getCfg() {
        var id = modelSelect ? parseInt(modelSelect.value, 10) : 0;
        return modelConfig[id] || {};
    }

    function escHtml(v) {
        return String(v || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
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
        // Select default
        var radios = modeContainer.querySelectorAll('input[name="video_mode_switch"]');
        radios.forEach(function (r) {
            if (r.value === defaultMode) r.checked = true;
        });
        if (radios.length > 0 && !modeContainer.querySelector('input[name="video_mode_switch"]:checked')) {
            radios[0].checked = true;
        }
        modeContainer.querySelectorAll('input[name="video_mode_switch"]').forEach(function (inp) {
            inp.addEventListener('change', onModelOrModeChange);
        });
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

    function getCurrentMode() {
        var checked = document.querySelector('input[name="video_mode_switch"]:checked');
        return checked ? checked.value : 'text_to_video';
    }

    function updateUploadVisibility(cfg) {
        var mode = getCurrentMode();
        var maxImg = cfg.max_ref_images || 1;
        var maxVid = cfg.max_ref_videos || 0;
        var maxAud = cfg.max_ref_audios || 0;

        // Image upload: first_frame, first_last_frame, multi_reference, video_edit, video_reference
        var needsImages = ['first_frame', 'first_last_frame', 'multi_reference', 'video_edit', 'video_reference'].indexOf(mode) !== -1;
        if (refUploadField) refUploadField.classList.toggle('hidden', !needsImages);
        if (maxRefCountSpan) maxRefCountSpan.textContent = maxImg;
        if (refImageInput) refImageInput.dataset.maxFiles = maxImg;

        // Video upload: video_edit, video_reference
        var needsVideo = ['video_edit', 'video_reference'].indexOf(mode) !== -1 && maxVid > 0;
        if (videoUploadField) {
            videoUploadField.classList.toggle('hidden', !needsVideo);
            if (needsVideo && maxVideoCountSpan) maxVideoCountSpan.textContent = maxVid;
            if (videoInput) videoInput.dataset.maxFiles = maxVid;
        }

        // Audio upload: audio_reference
        var needsAudio = mode === 'audio_reference' && maxAud > 0;
        if (audioUploadField) {
            audioUploadField.classList.toggle('hidden', !needsAudio);
            if (needsAudio && maxAudioCountSpan) maxAudioCountSpan.textContent = maxAud;
            if (audioInput) audioInput.dataset.maxFiles = maxAud;
        }

        // Upload hint
        if (uploadHint) {
            var hintText = '支持 PNG / JPG / WEBP';
            if (mode === 'first_last_frame') {
                hintText = selectedImages.length > 0
                    ? '已选 ' + selectedImages.length + ' / 2 张（首帧 / 尾帧）'
                    : '首帧参考 / 尾帧参考，请上传 2 张图片';
            } else if (mode === 'first_frame') {
                hintText = selectedImages.length > 0
                    ? '已选 ' + selectedImages.length + ' / 1 张'
                    : '首帧参考，请上传 1 张图片';
            } else if (mode === 'video_edit' || mode === 'video_reference') {
                hintText = '参考视频编辑，请上传视频文件';
            } else if (mode === 'audio_reference') {
                hintText = '音频参考，请上传音频文件';
            }
            uploadHint.textContent = hintText;
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

    // Image input
    if (refImageInput) {
        refImageInput.addEventListener('change', function (e) {
            var cfg = getCfg();
            var maxRef = cfg.max_ref_images || 1;
            var files = Array.prototype.slice.call(e.target.files || []);
            selectedImages = selectedImages.concat(files).slice(0, maxRef);
            renderImagePreview(maxRef);
            updateUploadVisibility(cfg);
            e.target.value = '';
        });
    }

    // Video input
    if (videoInput) {
        videoInput.addEventListener('change', function (e) {
            var cfg = getCfg();
            var maxVid = cfg.max_ref_videos || 1;
            var files = Array.prototype.slice.call(e.target.files || []);
            selectedVideos = selectedVideos.concat(files).slice(0, maxVid);
            renderVideoPreview(maxVid);
            updateUploadVisibility(cfg);
            e.target.value = '';
        });
    }

    // Audio input
    if (audioInput) {
        audioInput.addEventListener('change', function (e) {
            var cfg = getCfg();
            var maxAud = cfg.max_ref_audios || 1;
            var files = Array.prototype.slice.call(e.target.files || []);
            selectedAudios = selectedAudios.concat(files).slice(0, maxAud);
            renderAudioPreview(maxAud);
            updateUploadVisibility(cfg);
            e.target.value = '';
        });
    }

    function renderImagePreview(maxRef) {
        var preview = document.getElementById('refPreview');
        if (!preview) return;
        preview.innerHTML = '';
        selectedImages.slice(0, maxRef).forEach(function (file, idx) {
            var item = document.createElement('div');
            item.className = 'edit-preview-item';
            if (file.type.startsWith('image/')) {
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
                renderImagePreview(maxRef);
                updateUploadVisibility(getCfg());
            });
            item.appendChild(btn);
            preview.appendChild(item);
        });
    }

    function renderVideoPreview(maxRef) {
        var preview = document.getElementById('videoPreview');
        if (!preview) return;
        preview.innerHTML = '';
        selectedVideos.slice(0, maxRef).forEach(function (file, idx) {
            var item = document.createElement('div');
            item.className = 'edit-preview-item';
            item.style.alignItems = 'center';
            item.style.display = 'flex';
            item.style.gap = '8px';
            var label = document.createElement('span');
            label.textContent = '📹 ' + file.name;
            label.style.fontSize = '12px';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'edit-preview-remove';
            btn.textContent = '删除';
            btn.addEventListener('click', function () {
                selectedVideos.splice(idx, 1);
                syncVideoFiles();
                renderVideoPreview(maxRef);
                updateUploadVisibility(getCfg());
            });
            item.appendChild(label);
            item.appendChild(btn);
            preview.appendChild(item);
        });
    }

    function renderAudioPreview(maxRef) {
        var preview = document.getElementById('audioPreview');
        if (!preview) return;
        preview.innerHTML = '';
        selectedAudios.slice(0, maxRef).forEach(function (file, idx) {
            var item = document.createElement('div');
            item.className = 'edit-preview-item';
            item.style.alignItems = 'center';
            item.style.display = 'flex';
            item.style.gap = '8px';
            var label = document.createElement('span');
            label.textContent = '🎵 ' + file.name;
            label.style.fontSize = '12px';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'edit-preview-remove';
            btn.textContent = '删除';
            btn.addEventListener('click', function () {
                selectedAudios.splice(idx, 1);
                syncAudioFiles();
                renderAudioPreview(maxRef);
                updateUploadVisibility(getCfg());
            });
            item.appendChild(label);
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

    function syncVideoFiles() {
        if (!videoInput) return;
        var dt = new DataTransfer();
        selectedVideos.forEach(function (f) { dt.items.add(f); });
        videoInput.files = dt.files;
    }

    function syncAudioFiles() {
        if (!audioInput) return;
        var dt = new DataTransfer();
        selectedAudios.forEach(function (f) { dt.items.add(f); });
        audioInput.files = dt.files;
    }

    // Init
    if (modelSelect) {
        modelSelect.addEventListener('change', onModelOrModeChange);
    }
    if (durationSelect) {
        durationSelect.addEventListener('change', function () { updateCost(); syncHidden(); });
    }

    // Form submit hook
    if (form) {
        form.addEventListener('submit', function () {
            syncHidden();
            syncImageFiles();
            syncVideoFiles();
            syncAudioFiles();
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

    // Init on load
    if (modelSelect) onModelOrModeChange();
})();
