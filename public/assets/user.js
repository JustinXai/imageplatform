const form = document.querySelector("#generateForm");
const button = document.querySelector("#generateButton");
const message = document.querySelector("#generateMessage");
const historyList = document.querySelector("#historyList");
const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || "";
const modeInputs = document.querySelectorAll('input[name="mode"]');
const editUpload = document.querySelector("[data-edit-upload]");
const editImagesInput = document.querySelector('input[name="edit_images[]"]');
const editPreview = document.querySelector("[data-edit-preview]");
const editUploadHint = document.querySelector("[data-edit-upload-hint]");

let isGenerating = false;
let editSelectedFiles = [];
const activeRecordPollers = new Map();

const showMessage = (text, type = "success") => {
  try { window.showToast?.(text, type); } catch (_) {}
  if (message) { message.textContent = ""; message.className = "inline-message hidden"; }
};

const setGenerating = (enabled) => {
  isGenerating = enabled;
  if (button) {
    button.disabled = enabled;
    button.textContent = enabled ? "生成中..." : "生成图片";
  }
};

const escapeHtml = (value) =>
  String(value ?? "").replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c]);

const statusText = (status) =>
  ({ queued: "排队中", running: "生成中", succeeded: "已完成", failed: "失败", deleted: "已删除" }[status] || status || "-");

const updateCredits = (credits) => {
  if (credits === undefined || credits === null) return;
  document.querySelectorAll("[data-balance-display]").forEach((node) => {
    node.textContent = Number(credits).toLocaleString();
  });
};

const getSelectedModelMeta = () => {
  const modelSelect = document.getElementById("ai_model");
  if (!modelSelect) return null;
  const opt = modelSelect.selectedOptions[0];
  if (!opt) return null;
  // Read from data-image-models JSON first, fallback to data attributes
  const dataModelsRaw = modelSelect.dataset.imageModels || modelSelect.dataset.imageModels;
  let modelsData = null;
  try {
    if (dataModelsRaw) {
      modelsData = JSON.parse(dataModelsRaw);
    }
  } catch (e) { modelsData = null; }

  if (modelsData && Array.isArray(modelsData)) {
    const selectedId = parseInt(modelSelect.value);
    const found = modelsData.find(m => m.id === selectedId);
    if (found) return found;
  }
  // Fallback to data attributes on option
  return {
    id: parseInt(opt.value) || 0,
    name: opt.textContent || "",
    credits: parseInt(opt.dataset.credits || "0"),
    supports_edit: parseInt(opt.dataset.supportsEdit || "0"),
  };
};

const ASPECT_LABELS = {
  'auto': 'Auto', '1:1': '1:1', '3:2': '3:2', '2:3': '2:3',
  '4:3': '4:3', '3:4': '3:4', '5:4': '5:4', '4:5': '4:5',
  '16:9': '16:9', '9:16': '9:16', '2:1': '2:1', '1:2': '1:2',
  '21:9': '21:9', '9:21': '9:21',
};
const SIZE_LABELS = {
  'auto': 'Auto', '1024x1024': '1024×1024', '1536x1024': '1536×1024',
  '1024x1536': '1024×1536', '2048x2048': '2048×2048',
};

const updateAspectOptions = () => {
  const aspectSelect = document.getElementById('image_aspect');
  if (!aspectSelect) return;
  const meta = getSelectedModelMeta();
  if (!meta) return;

  let opts = [];
  try {
    const raw = meta.image_aspect_options;
    if (Array.isArray(raw) && raw.length > 0) opts = raw;
  } catch (_) {}
  if (!opts.length) {
    try { const a = JSON.parse(aspectSelect.closest('.model-chip').querySelector('option:checked')?.dataset.aspectOptions || '[]'); if (a.length) opts = a; } catch (_) {}
  }
  if (!opts.length) opts = Object.keys(ASPECT_LABELS);

  const defaultAspect = meta.image_default_aspect || 'auto';
  aspectSelect.innerHTML = '';
  opts.forEach(v => {
    const opt = document.createElement('option');
    opt.value = v;
    opt.textContent = ASPECT_LABELS[v] || v;
    if (v === defaultAspect) opt.selected = true;
    aspectSelect.appendChild(opt);
  });
};

const updateSizeOptions = () => {
  const sizeSelect = document.getElementById('image_size');
  if (!sizeSelect) return;
  const meta = getSelectedModelMeta();
  if (!meta) return;

  let opts = [];
  try {
    const raw = meta.image_size_options;
    if (Array.isArray(raw) && raw.length > 0) opts = raw;
  } catch (_) {}
  if (!opts.length) {
    try { const a = JSON.parse(sizeSelect.closest('.model-chip').querySelector('option:checked')?.dataset.sizeOptions || '[]'); if (a.length) opts = a; } catch (_) {}
  }
  if (!opts.length) opts = Object.keys(SIZE_LABELS);

  const defaultSize = meta.image_default_size || 'auto';
  sizeSelect.innerHTML = '';
  opts.forEach(v => {
    const opt = document.createElement('option');
    opt.value = v;
    opt.textContent = SIZE_LABELS[v] || v;
    if (v === defaultSize) opt.selected = true;
    sizeSelect.appendChild(opt);
  });
};

const createRecordCard = (record) => {
  const article = document.createElement("article");
  article.className = "media-card";
  article.tabIndex = 0;
  article.dataset.recordId = record.id || "";
  article.dataset.status = record.status || "succeeded";
  article.dataset.mode = record.mode || "draw";
  article.dataset.prompt = record.prompt || "";
  article.dataset.size = record.size || "auto";
  article.dataset.quality = record.quality || "auto";
  article.dataset.format = record.format || "png";
  article.dataset.credits = record.credits_charged || 0;
  article.dataset.created = record.created_at || "";
  article.dataset.finished = record.finished_at || "-";
  article.dataset.error = record.error_message || "";
  article.dataset.inputCount = record.input_image_count || 0;
  article.dataset.videoSrc = record.video_src || record.download_url || "";
  article.dataset.imageSrc = record.image_src || "";
  article.dataset.selectedDuration = record.selected_duration || "";
  article.dataset.selectedVideoMode = record.selected_video_mode || "";
  article.dataset.selectedAspect = record.selected_aspect || "";
  article.style.cursor = "pointer";

  // Build display label for record mode
  const modeLabel = record.mode === "edit" ? "编辑" : (record.mode === "video" ? "视频" : "绘画");
  const videoModeLabels = {
    'text_to_video': '文生视频',
    'first_frame': '首帧',
    'first_last_frame': '首尾帧',
    'multi_reference': '多帧',
  };
  const recVideoMode = record.selected_video_mode || '';
  const recVideoModeLabel = videoModeLabels[recVideoMode] || recVideoMode || '';

  // Build meta string: mode / aspect / size / duration / credits
  const recAspect = record.selected_aspect || '—';
  const recSize = record.selected_size || record.size || 'auto';
  const recDuration = record.selected_duration ? record.selected_duration + '秒' : '';
  const recCredits = record.credits_charged ? record.credits_charged + '点' : '';
  const recModeStr = record.mode === 'video'
    ? (recVideoModeLabel ? recVideoModeLabel : modeLabel)
    : modeLabel;
  const metaParts = [recModeStr];
  if (recAspect && recAspect !== '—') metaParts.push(recAspect);
  metaParts.push(recSize);
  if (recDuration) metaParts.push(recDuration);
  if (recCredits) metaParts.push(recCredits);

  article.innerHTML = `
    ${record.video_src
      ? `<video src="${escapeHtml(record.video_src)}" controls></video>`
      : record.image_src
        ? `<img src="${escapeHtml(record.image_src)}" alt="生成图片">`
        : `<div style="width:100%;aspect-ratio:1;display:grid;place-items:center;background:var(--main-surface-soft);color:var(--text-muted);font-weight:700;font-size:13px;"><span class="status-badge ${escapeHtml(record.status)}">${escapeHtml(statusText(record.status))}</span></div>`
    }
    <div class="media-card-body">
      <div class="prompt">${escapeHtml(record.prompt)}</div>
      <div class="meta">
        <span class="status-badge ${escapeHtml(record.status)}">${escapeHtml(statusText(record.status))}</span>
        <span>${escapeHtml(metaParts.join(' / '))}</span>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:6px;">
        <time style="font-size:10px;color:var(--text-muted);">${escapeHtml(record.created_at)}</time>
        <form method="post" action="/delete_record" class="record-delete-form" onsubmit="return confirm('确认删除这条生成记录？')">
          <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
          <input type="hidden" name="record_id" value="${record.id}">
          <input type="hidden" name="redirect_to" value="/index">
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);">删除</button>
        </form>
      </div>
    </div>
  `;
  return article;
};

/**
 * Update or insert a record card.
 * For running/queued cards: update DOM in-place without reordering
 * to prevent visible position flickering during polling.
 * For succeeded/failed/new cards: prepend to top of list.
 */
const syncRecordCard = (record) => {
  if (!record || !record.id) return null;
  const existing = historyList?.querySelector(`[data-record-id="${record.id}"]`);
  const isTransient = record.status === 'queued' || record.status === 'running';

  if (existing && isTransient) {
    // In-place update: only update status badge, image/video src, error, credits
    // Do NOT remove/reinsert — that causes visible position swaps during polling
    const card = existing;
    card.dataset.status = record.status || 'succeeded';
    card.dataset.error = record.error_message || '';
    card.dataset.credits = record.credits_charged || 0;

    const badge = card.querySelector('.status-badge');
    if (badge) {
      badge.className = `status-badge ${record.status || 'succeeded'}`;
      badge.textContent = statusText(record.status);
    }

    const meta = card.querySelector('.meta');
    if (meta) {
      const metaSpan = meta.querySelector('span:last-child');
      if (metaSpan) {
        // Update only the params part, keep the badge label
        const modeLabel = record.mode === 'edit' ? '编辑' : (record.mode === 'video' ? '视频' : '绘画');
        const aspect = record.selected_aspect || '—';
        const size = record.size || 'auto';
        const parts = [modeLabel];
        if (aspect && aspect !== '—') parts.push(aspect);
        parts.push(size);
        metaSpan.textContent = parts.join(' / ');
      }
    }

    // Update image/video source
    if (record.video_src) {
      let videoEl = card.querySelector('video');
      if (!videoEl) {
        const placeholder = card.querySelector('div[style*="aspect-ratio"]');
        if (placeholder) {
          const vd = document.createElement('video');
          vd.src = record.video_src;
          vd.controls = true;
          placeholder.replaceWith(vd);
        }
      } else {
        videoEl.src = record.video_src;
      }
    } else if (record.image_src || record.output_url) {
      const src = record.image_src || record.output_url || '';
      let imgEl = card.querySelector('img');
      if (!imgEl) {
        const placeholder = card.querySelector('div[style*="aspect-ratio"]');
        if (placeholder) {
          const im = document.createElement('img');
          im.src = src;
          im.alt = '生成图片';
          placeholder.replaceWith(im);
        }
      } else {
        imgEl.src = src;
      }
    }

    // Update error display
    const errorDiv = card.querySelector('.error-hint');
    if (record.error_message) {
      if (!errorDiv) {
        const err = document.createElement('div');
        err.className = 'error-hint';
        err.style.cssText = 'font-size:10px;color:var(--danger);margin-top:4px;';
        err.textContent = (record.error_message || '').substring(0, 60);
        const body = card.querySelector('.media-card-body');
        if (body) body.appendChild(err);
      } else {
        errorDiv.textContent = (record.error_message || '').substring(0, 60);
      }
    } else if (errorDiv) {
      errorDiv.remove();
    }

    return card;
  }

  // For succeeded, failed, or new cards: remove existing and prepend
  if (existing) existing.remove();
  const empty = historyList?.querySelector('.history-empty-inline');
  if (empty) empty.remove();
  const card = createRecordCard(record);
  historyList?.prepend(card);
  return card;
};

const refreshOpenRecordDialog = (record) => {
  const dialog = document.querySelector("#recordDialog");
  if (!dialog || dialog.classList.contains("hidden")) return;
  const currentId = dialog.querySelector('.record-dialog-foot [name="record_id"]')?.value || "";
  if (String(currentId) !== String(record.id || "")) return;
  showResultDialog(record);
};

const stopPollingRecord = (recordId) => {
  const key = String(recordId || "");
  const timer = activeRecordPollers.get(key);
  if (timer) clearTimeout(timer);
  activeRecordPollers.delete(key);
};

const pollRecordStatus = async (recordId, interval = 3000) => {
  const key = String(recordId || "");
  if (!key || activeRecordPollers.has(key)) return;

  const run = async () => {
    activeRecordPollers.delete(key);
    try {
      const response = await fetch(`/check_record?id=${encodeURIComponent(key)}`);
      const data = await response.json();
      if (!data?.ok || !data.record) return;

      if (data.credits !== undefined) updateCredits(data.credits);
      syncRecordCard(data.record);
      refreshOpenRecordDialog(data.record);

      if (data.status === "queued" || data.status === "running") {
        activeRecordPollers.set(key, setTimeout(run, interval));
        return;
      }

      if (data.status === "failed") {
        showResultDialog(data.record);
      }
    } catch (_) {
      activeRecordPollers.set(key, setTimeout(run, interval));
    }
  };

  activeRecordPollers.set(key, setTimeout(run, interval));
};

const resumePendingRecords = async () => {
  try {
    const response = await fetch("/check_running_records");
    const data = await response.json();
    if (!data?.ok || !Array.isArray(data.records)) return;

    if (data.credits !== undefined) updateCredits(data.credits);
    data.records.forEach((record) => {
      syncRecordCard(record);
      pollRecordStatus(record.id, 2000);
    });
  } catch (_) {}
};

const inspectRecentRecords = () => {
  const cards = Array.from(document.querySelectorAll(".media-card[data-record-id]")).slice(0, 5);
  cards.forEach((card) => {
    const status = card.dataset.status || "";
    if (status === "queued" || status === "running") {
      pollRecordStatus(card.dataset.recordId, 1500);
    }
  });
};

const syncEditInputFiles = () => {
  if (!editImagesInput || !editPreview) return;
  const dt = new DataTransfer();
  editSelectedFiles.forEach((f) => dt.items.add(f));
  editImagesInput.files = dt.files;
};

const updateEditPreview = () => {
  if (!editPreview) return;
  editPreview.innerHTML = "";
  const max = parseInt(editImagesInput?.dataset.maxFiles || "4", 10);
  editSelectedFiles.slice(0, max).forEach((file, i) => {
    const div = document.createElement("div");
    div.className = "edit-preview-item";
    const img = document.createElement("img");
    img.src = URL.createObjectURL(file);
    img.alt = `参考图片 ${i + 1}`;
    img.addEventListener("load", () => URL.revokeObjectURL(img.src), { once: true });
    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "edit-preview-remove";
    removeBtn.textContent = "删除";
    removeBtn.addEventListener("click", () => {
      editSelectedFiles.splice(i, 1);
      updateEditPreview();
      syncEditInputFiles();
      updateEditUploadHint();
    });
    div.appendChild(img);
    div.appendChild(removeBtn);
    editPreview.appendChild(div);
  });
  updateEditUploadHint();
};

const updateEditUploadHint = () => {
  if (!editUploadHint) return;
  const max = parseInt(editImagesInput?.dataset.maxFiles || "4", 10);
  editUploadHint.textContent = editSelectedFiles.length > 0
    ? `已选择 ${editSelectedFiles.length} / ${max} 张，可继续添加`
    : "支持 PNG / JPG / WEBP，可多次选择";
};

const syncModeFields = () => {
  const selected = document.querySelector('input[name="mode"]:checked')?.value || "draw";
  const isEdit = selected === "edit";
  if (editUpload) editUpload.classList.toggle("hidden", !isEdit);
  const costDisplay = document.querySelector("[data-cost-display]");
  if (costDisplay) {
    const drawCost = costDisplay.dataset.drawCost || "1";
    const editCost = costDisplay.dataset.editCost || "2";
    costDisplay.querySelector("[data-cost-value]").textContent = isEdit ? editCost : drawCost;
    const editSpan = costDisplay.querySelector("[data-cost-edit]");
    if (editSpan) editSpan.classList.toggle("hidden", !isEdit);
  }
};

// Mode toggle
modeInputs.forEach((input) => {
  input.addEventListener("change", () => {
    syncModeFields();
    if (editPreview) { editSelectedFiles = []; editPreview.innerHTML = ""; editImagesInput.value = ""; updateEditUploadHint(); }
  });
});
syncModeFields();

// Edit image upload
if (editImagesInput) {
  // 点击上传区域 → 直接程序化触发文件选择器（绕过所有 CSS 层叠问题）
  const uploadBox = document.querySelector("[data-edit-upload-box]");
  if (uploadBox) {
    uploadBox.addEventListener("click", (e) => {
      // 如果点击的是移除按钮或预览区域，不触发文件选择
      if (e.target.closest("[data-edit-remove]")) return;
      editImagesInput.click();
    });
  }

  editImagesInput.addEventListener("change", () => {
    const max = parseInt(editImagesInput.dataset.maxFiles || "4", 10);
    const newFiles = Array.from(editImagesInput.files || []);
    editSelectedFiles = [...editSelectedFiles, ...newFiles].slice(0, max);
    updateEditPreview();
    updateEditUploadHint();
  });
}

// AI模型选择 → 联动消耗显示
const modelSelect = document.getElementById("ai_model");
if (modelSelect) {
  modelSelect.addEventListener("change", () => {
    const modelMeta = getSelectedModelMeta();
    const credits = modelMeta?.credits || 0;
    const supportsEdit = modelMeta?.supports_edit || 0;
    const costVal = document.querySelector("[data-cost-value]");
    if (costVal) {
      costVal.textContent = credits > 0 ? credits : "1";
    }
    // Update aspect and size options dynamically based on selected model
    updateAspectOptions();
    updateSizeOptions();
    // Update edit mode visibility based on model supports_edit
    const selectedMode = document.querySelector('input[name="mode"]:checked')?.value || "draw";
    const editUpload = document.querySelector("[data-edit-upload]");
    if (editUpload && selectedMode === "edit" && !supportsEdit) {
      const drawRadio = document.querySelector('input[name="mode"][value="draw"]');
      if (drawRadio) drawRadio.checked = true;
      syncModeFields();
      showErrorDialog("当前模型不支持图片编辑，已自动切换到绘画模式。");
    }
  });
  // 初始触发
  modelSelect.dispatchEvent(new Event("change"));
}

// Form submit — result dialog mode
form?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (isGenerating) return;

  // Validate edit mode is supported by selected model
  const selectedMode = document.querySelector('input[name="mode"]:checked')?.value || "draw";
  if (selectedMode === "edit") {
    const selectedModel = getSelectedModelMeta();
    if (!selectedModel || !selectedModel.supports_edit) {
      showErrorDialog("当前模型不支持图片编辑，请选择已开启编辑能力的模型，或切换到绘画模式。");
      return;
    }
  }

  // 先清空原生 input 避免 FormData 重复读取，只用手动追加的文件
  editImagesInput.value = "";
  const formData = new FormData(form);
  editSelectedFiles.forEach((f, i) => formData.append("edit_images[]", f));
  editSelectedFiles = [];

  setGenerating(true);
  showMessage("正在提交...", "info");

  try {
    const response = await fetch("/generate.php", { method: "POST", body: formData });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch (e) { data = null; }

    if (data?.ok && data.record_id) {
      if (data.credits !== undefined) updateCredits(data.credits);
      // Auto-popup the result dialog with generation details
      if (data.record) {
        syncRecordCard(data.record);
        showResultDialog(data.record);
        pollRecordStatus(data.record_id, 1500);
      } else {
        showMessage(data.message || "已提交生成！");
      }
    } else {
      // Show error in a modal dialog (manual close)
      const errMsg = data?.message || "提交失败，请重试。";
      showErrorDialog(errMsg);
    }
  } catch (err) {
    showErrorDialog(err.message || "网络请求失败，请检查连接后重试。");
  } finally {
    setGenerating(false);
  }
});

resumePendingRecords();
inspectRecentRecords();
setInterval(inspectRecentRecords, 10000);

// Beforeunload warning
let pendingSubmit = false;
form?.addEventListener("submit", () => { pendingSubmit = true; });
form?.addEventListener("input", () => { pendingSubmit = false; });
window.addEventListener("beforeunload", (event) => {
  if (isGenerating || pendingSubmit) event.returnValue = "请求仍在处理中，关闭页面可能导致当前提交中断。";
});

// Record dialog (from media card click)
const ensureRecordDialog = () => {
  let dialog = document.querySelector("#recordDialog");
  if (dialog) return dialog;
  dialog = document.createElement("div");
  dialog.id = "recordDialog";
  dialog.className = "record-dialog hidden";
  dialog.innerHTML = `
    <div class="record-dialog-panel" role="dialog" aria-modal="true">
      <div class="record-dialog-head">
        <h2>生成记录详情</h2>
        <button type="button" data-close-dialog class="record-dialog-close" aria-label="关闭">关闭</button>
      </div>
      <div class="record-dialog-body">
        <div class="record-full-prompt"><span>完整提示词</span><p data-dialog-prompt></p></div>
        <div class="record-dialog-image" data-dialog-images></div>
        <div class="record-detail-grid">
          <div class="record-detail-status"><span>状态</span><strong class="status" data-dialog-status>-</strong></div>
          <div><span>参数</span><strong data-dialog-params>-</strong></div>
          <div><span>消耗</span><strong data-dialog-credits>-</strong></div>
          <div><span>时间</span><strong data-dialog-time>-</strong></div>
        </div>
        <div class="record-error hidden" data-dialog-error></div>
      </div>
      <div class="record-dialog-foot">
        <form method="post" action="/delete_record" onsubmit="return confirm('确认删除？')">
          <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
          <input type="hidden" name="record_id" value="">
          <button type="submit" class="btn btn-secondary btn-sm">删除记录</button>
        </form>
        <button type="button" data-share-gallery class="btn btn-secondary btn-sm" style="display:none;">📤 分享到广场</button>
        <button type="button" data-close-dialog class="btn btn-primary btn-sm">关闭</button>
      </div>
    </div>`;
  document.body.appendChild(dialog);
  dialog.addEventListener("click", (e) => {
    if (e.target.closest("[data-close-dialog]") || e.target === dialog) {
      dialog.classList.add("hidden");
      document.body.classList.remove("has-dialog");
      const shouldRefresh = dialog.dataset.refreshOnClose === "1";
      delete dialog.dataset.refreshOnClose;
      if (shouldRefresh) { location.reload(); }
    }
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !dialog.classList.contains("hidden")) {
      dialog.classList.add("hidden");
      document.body.classList.remove("has-dialog");
      const shouldRefresh = dialog.dataset.refreshOnClose === "1";
      delete dialog.dataset.refreshOnClose;
      if (shouldRefresh) { location.reload(); }
    }
  });
  return dialog;
};

const openRecordDialog = (card) => {
  const d = ensureRecordDialog();
  if (!d) return;
  d.classList.remove("hidden");
  document.body.classList.add("has-dialog");
  const st = (sel, val) => { const el = d.querySelector(sel); if (el) el.textContent = val; };
  st("[data-dialog-prompt]", card.dataset.prompt || "");
  st("[data-dialog-status]", statusText(card.dataset.status) || "-");
  // Build params label
  const recMode = card.dataset.mode || "draw";
  const recDuration = card.dataset.selectedDuration || "";
  const recVideoMode = card.dataset.selectedVideoMode || "";
  const videoModeLabels = { 'text_to_video': '文生视频', 'first_frame': '首帧', 'first_last_frame': '首尾帧', 'multi_reference': '多帧', 'video_edit': '视频编辑' };
  const recVideoModeLabel = videoModeLabels[recVideoMode] || "";
  let paramsLabel;
  if (recMode === "video") {
    const parts = [recVideoModeLabel || "视频"];
    const sz = card.dataset.size || "auto";
    if (sz && sz !== "auto") parts.push(sz);
    if (recDuration) parts.push(recDuration + "秒");
    paramsLabel = parts.join(" / ");
  } else {
    paramsLabel = (recMode === "edit" ? "编辑" : "绘画") + " / " + (card.dataset.size || "auto");
  }
  st("[data-dialog-params]", paramsLabel);
  st("[data-dialog-credits]", card.dataset.credits || "0");
  st("[data-dialog-time]", `创建 ${card.dataset.created}`);
  const errEl = d.querySelector("[data-dialog-error]");
  if (errEl) {
    const msg = card.dataset.error || "";
    if (msg) { errEl.textContent = msg; errEl.classList.remove("hidden"); }
    else { errEl.classList.add("hidden"); }
  }
  const imgSec = d.querySelector("[data-dialog-images]");
  if (imgSec) {
    imgSec.innerHTML = "";
    const recMode = card.dataset.mode || "draw";
    const videoSrc = card.dataset.videoSrc || card.querySelector("video")?.src || "";
    const imageSrc = card.dataset.imageSrc || card.querySelector("img")?.src || "";
    const isSucceeded = card.dataset.status === "succeeded";

    if (recMode === "video" && videoSrc) {
      imgSec.innerHTML = `
        <div class="record-media record-media-video">
          <video controls preload="metadata" playsinline style="width:100%;max-height:420px;border-radius:12px;background:#000;">
            <source src="${escapeHtml(videoSrc)}" type="video/mp4">
            当前浏览器不支持视频播放。
          </video>
        </div>`;
      if (isSucceeded && (card.dataset.videoSrc || card.querySelector("video"))) {
        const vidUrl = card.dataset.videoSrc || card.querySelector("video")?.src || "";
        if (vidUrl) {
          const foot = d.querySelector(".record-dialog-foot");
          if (foot) {
            const dl = document.createElement("a");
            dl.className = "btn btn-secondary btn-sm";
            dl.href = vidUrl;
            dl.download = "";
            dl.textContent = "下载视频";
            dl.style.cssText = "display:inline-flex;align-items:center;";
            const openBtn = document.createElement("a");
            openBtn.className = "btn btn-secondary btn-sm";
            openBtn.href = vidUrl;
            openBtn.target = "_blank";
            openBtn.rel = "noopener noreferrer";
            openBtn.textContent = "新窗口打开";
            openBtn.style.cssText = "display:inline-flex;align-items:center;";
            foot.insertBefore(dl, foot.querySelector("[data-share-gallery]"));
            foot.insertBefore(openBtn, foot.querySelector("[data-share-gallery]"));
          }
        }
      }
    } else if (imageSrc) {
      const img = card.querySelector("img");
      if (img) { const c = img.cloneNode(); c.style.cssText = "max-width:100%;max-height:300px;cursor:pointer;"; imgSec.appendChild(c); }
      else { const c = document.createElement("img"); c.src = imageSrc; c.style.cssText = "max-width:100%;max-height:300px;cursor:pointer;"; imgSec.appendChild(c); }
    }
  }
  const delForm = d.querySelector(".record-dialog-foot form");
  if (delForm) { delForm.querySelector('[name="record_id"]').value = card.dataset.recordId; }

  // 分享到广场按钮（切换模式）
  const shareBtn = d.querySelector("[data-share-gallery]");
  if (shareBtn) {
    const isSucceeded = card.dataset.status === "succeeded";
    shareBtn.style.display = isSucceeded ? "" : "none";
    shareBtn.disabled = false;
    const recordId = parseInt(card.dataset.recordId);

    // 检查是否已分享
    const checkShare = async () => {
      try {
        const r = await fetch("/api/gallery", {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "check_share", record_id: recordId })
        });
        const d = await r.json();
        shareBtn.textContent = d.data?.shared ? "✅ 已分享（点击取消）" : "📤 分享到广场";
        shareBtn.dataset.shared = d.data?.shared ? "1" : "";
      } catch(e) {}
    };
    checkShare();

    shareBtn.onclick = async () => {
      shareBtn.disabled = true;
      const isShared = shareBtn.dataset.shared === "1";
      const action = isShared ? "unshare" : "share";
      shareBtn.textContent = isShared ? "取消中..." : "分享中...";
      try {
        const res = await fetch("/api/gallery", {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action, record_id: recordId })
        });
        const data = await res.json();
        if (data.ok) {
          shareBtn.dataset.shared = isShared ? "" : "1";
          shareBtn.textContent = isShared ? "📤 分享到广场" : "✅ 已分享（点击取消）";
        } else {
          shareBtn.textContent = "❌ " + (data.message || "失败");
        }
      } catch(e) { shareBtn.textContent = "❌ 网络错误"; }
      shareBtn.disabled = false;
    };
  }
};

// Show result dialog from a generated record
const showResultDialog = (record) => {
  // Create a temporary card-like object to reuse openRecordDialog
  const proxyCard = {
    dataset: {
      recordId: record.id || "",
      status: record.status || "succeeded",
      mode: record.mode || "draw",
      prompt: record.prompt || "",
      size: record.size || "auto",
      quality: record.quality || "auto",
      format: record.format || "png",
      credits: record.credits_charged || 0,
      created: record.created_at || "",
      finished: record.finished_at || "-",
      error: record.error_message || "",
      inputCount: record.input_image_count || 0,
      videoSrc: record.video_src || record.download_url || "",
      imageSrc: record.image_src || "",
      selectedDuration: record.selected_duration || "",
      selectedVideoMode: record.selected_video_mode || "",
      selectedAspect: record.selected_aspect || "",
    },
    querySelector: () => null // no image/video element in the proxy
  };

  openRecordDialog(proxyCard);

  // Set refresh-on-close flag
  const dialog = document.querySelector("#recordDialog");
  if (dialog) {
    dialog.dataset.refreshOnClose = "1";
  }
};

// Show error dialog (manual close, no refresh)
const showErrorDialog = (message) => {
  let dialog = document.querySelector("#errorDialog");
  if (!dialog) {
    dialog = document.createElement("div");
    dialog.id = "errorDialog";
    dialog.className = "record-dialog hidden";
    dialog.innerHTML = `
      <div class="error-dialog-panel" role="dialog" aria-modal="true">
        <div class="error-dialog-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          <h2>生成失败</h2>
        </div>
        <div class="error-dialog-body" id="errorDialogBody">${escapeHtml(message)}</div>
        <div class="error-dialog-foot">
          <button type="button" data-close-dialog class="btn btn-primary">知道了</button>
        </div>
      </div>`;
    document.body.appendChild(dialog);
    dialog.addEventListener("click", (e) => {
      if (e.target.closest("[data-close-dialog]") || e.target === dialog) {
        dialog.classList.add("hidden");
        document.body.classList.remove("has-dialog");
      }
    });
  } else {
    const body = dialog.querySelector("#errorDialogBody");
    if (body) body.textContent = message;
  }
  dialog.classList.remove("hidden");
  document.body.classList.add("has-dialog");
};

// Click on media card opens detail
document.addEventListener("click", (event) => {
  const card = event.target.closest(".media-card");
  if (!card || event.target.closest(".record-delete-form, button")) return;
  event.preventDefault();
  openRecordDialog(card);
});

// Close helpers
const closeImageViewer = () => { document.querySelector("#imageViewer")?.classList.add("hidden"); };
const closeRecordDialog = () => { document.querySelector("#recordDialog")?.classList.add("hidden"); document.body.classList.remove("has-dialog"); };
window.addEventListener("keydown", (e) => { if (e.key === "Escape") { closeImageViewer(); closeRecordDialog(); } });

// Prompt Optimize
const optimizeBtn = document.querySelector("#optimizePromptBtn");
const promptTextarea = document.querySelector('textarea[name="prompt"]');
const optimizeStatus = document.querySelector("#optimizePromptStatus");
        if (optimizeBtn && promptTextarea) {
  optimizeBtn.addEventListener("click", async () => {
    const raw = promptTextarea.value.trim();
    if (!raw) { if (optimizeStatus) { optimizeStatus.textContent = "请先输入提示词"; optimizeStatus.className = "field-hint is-error"; } promptTextarea.focus(); return; }
    optimizeBtn.classList.add("is-loading"); optimizeBtn.textContent = "优化中...";
    if (optimizeStatus) { optimizeStatus.textContent = ""; optimizeStatus.className = "field-hint hidden"; }
    try {
      const fd = new FormData(); fd.append("prompt", raw); fd.append("csrf_token", csrfToken);
      const res = await fetch("/prompt_optimize.php", { method: "POST", body: fd });
      const data = await res.json();
      if (data.ok && data.prompt) {
        promptTextarea.value = data.prompt;
        if (optimizeStatus) { optimizeStatus.textContent = "优化完成"; optimizeStatus.className = "field-hint"; }
        promptTextarea.style.height = "auto"; promptTextarea.style.height = promptTextarea.scrollHeight + "px";
      } else throw new Error(data.message || "优化失败");
    } catch (err) {
      if (optimizeStatus) { optimizeStatus.textContent = err.message || "优化请求失败"; optimizeStatus.className = "field-hint is-error"; }
    } finally { optimizeBtn.classList.remove("is-loading"); optimizeBtn.textContent = "+ 优化提示词"; }
  });
}

/* ── Mode Toggle (fallback for :has() selector) ── */
document.querySelectorAll('.mode-toggle').forEach(function(group) {
    var radios = group.querySelectorAll('input[type="radio"]');
    radios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            radios.forEach(function(r) {
                var label = r.closest('label');
                if (label) label.classList.toggle('active', r.checked);
            });
        });
        if (radio.checked) {
            var label = radio.closest('label');
            if (label) label.classList.add('active');
        }
    });
});
