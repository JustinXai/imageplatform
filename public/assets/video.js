const form = document.querySelector("#videoGenerateForm");
const button = document.querySelector("#generateButton");
const message = document.querySelector("#generateMessage");
const historyList = document.querySelector("#videoHistoryList");
const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || "";
const videoModeInputs = document.querySelectorAll('input[name="video_mode_switch"]');
const videoModeField = document.querySelector('input[name="video_mode"]');
const uploadWrap = document.querySelector("[data-video-upload]");
const uploadBox = document.querySelector("[data-video-upload-box]");
const uploadInput = document.querySelector('input[name="edit_images[]"]');
const uploadPreview = document.querySelector("[data-video-preview]");
const uploadHint = document.querySelector("[data-video-upload-hint]");

let isGenerating = false;
let selectedFiles = [];

const showMessage = (text, type = "success") => {
  try { window.showToast?.(text, type); } catch (_) {}
  if (message) {
    message.textContent = "";
    message.className = "inline-message hidden";
  }
};

const setGenerating = (enabled) => {
  isGenerating = enabled;
  if (button) {
    button.disabled = enabled;
    button.textContent = enabled ? "生成中..." : "生成视频";
  }
};

const escapeHtml = (value) =>
  String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[c]));

const statusText = (status) =>
  ({ queued: "排队中", running: "生成中", succeeded: "已完成", failed: "失败", deleted: "已删除" }[status] || status || "-");

const updateCredits = (credits) => {
  if (credits === undefined || credits === null) return;
  document.querySelectorAll("[data-balance-display]").forEach((node) => {
    node.textContent = Number(credits).toLocaleString();
  });
};

const activeVideoMode = () =>
  document.querySelector('input[name="video_mode_switch"]:checked')?.value || "text";

const syncModeFields = () => {
  const mode = activeVideoMode();
  if (videoModeField) videoModeField.value = mode;
  if (uploadWrap) uploadWrap.classList.toggle("hidden", mode !== "image");
};

const updateUploadHint = () => {
  if (!uploadHint || !uploadInput) return;
  const max = parseInt(uploadInput.dataset.maxFiles || "4", 10);
  uploadHint.textContent = selectedFiles.length > 0
    ? `已选择 ${selectedFiles.length} / ${max} 张，可继续添加`
    : "支持 PNG / JPG / WEBP，可多次选择";
};

const syncUploadInputFiles = () => {
  if (!uploadInput) return;
  const dt = new DataTransfer();
  selectedFiles.forEach((file) => dt.items.add(file));
  uploadInput.files = dt.files;
};

const updateUploadPreview = () => {
  if (!uploadPreview || !uploadInput) return;
  uploadPreview.innerHTML = "";
  const max = parseInt(uploadInput.dataset.maxFiles || "4", 10);

  selectedFiles.slice(0, max).forEach((file, index) => {
    const item = document.createElement("div");
    item.className = "edit-preview-item";

    const img = document.createElement("img");
    img.src = URL.createObjectURL(file);
    img.alt = `参考图 ${index + 1}`;
    img.addEventListener("load", () => URL.revokeObjectURL(img.src), { once: true });

    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "edit-preview-remove";
    removeBtn.textContent = "删除";
    removeBtn.addEventListener("click", () => {
      selectedFiles.splice(index, 1);
      syncUploadInputFiles();
      updateUploadPreview();
    });

    item.appendChild(img);
    item.appendChild(removeBtn);
    uploadPreview.appendChild(item);
  });

  updateUploadHint();
};

const clearUploadSelection = () => {
  selectedFiles = [];
  if (uploadInput) uploadInput.value = "";
  if (uploadPreview) uploadPreview.innerHTML = "";
  updateUploadHint();
};

const videoMetaText = (record) => {
  const parts = [record.input_image_count > 0 ? "图生视频" : "文生视频", record.size || "16:9"];
  if (record.quality && record.quality !== "auto") {
    parts.push(record.quality);
  }
  parts.push(record.format || "mp4");
  return parts.join(" / ");
};

const createRecordCard = (record) => {
  const article = document.createElement("article");
  article.className = "media-card";
  article.tabIndex = 0;
  article.dataset.recordId = record.id || "";
  article.dataset.status = record.status || "succeeded";
  article.dataset.mode = "video";
  article.dataset.prompt = record.prompt || "";
  article.dataset.size = record.size || "16:9";
  article.dataset.quality = record.quality || "";
  article.dataset.format = record.format || "mp4";
  article.dataset.credits = record.credits_charged || 0;
  article.dataset.created = record.created_at || "";
  article.dataset.finished = record.finished_at || "-";
  article.dataset.error = record.error_message || "";
  article.dataset.inputCount = record.input_image_count || 0;

  const src = record.video_src;

  article.innerHTML = `
    ${src
      ? `<video src="${escapeHtml(src)}" controls></video>`
      : `<div style="width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:var(--main-surface-soft);color:var(--text-muted);font-size:13px;font-weight:700;"><span class="status-badge ${escapeHtml(record.status)}">${escapeHtml(statusText(record.status))}</span></div>`
    }
    <div class="media-card-body">
      <div class="prompt">${escapeHtml(record.prompt)}</div>
      <div class="meta">
        <span class="status-badge ${escapeHtml(record.status)}">${escapeHtml(statusText(record.status))}</span>
        <span>${escapeHtml(videoMetaText(record))}</span>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:6px;">
        <time style="font-size:10px;color:var(--text-muted);">${escapeHtml(record.created_at)}</time>
        <form method="post" action="/delete_record" class="record-delete-form" onsubmit="return confirm('确认删除这条生成记录？')">
          <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
          <input type="hidden" name="record_id" value="${record.id}">
          <input type="hidden" name="redirect_to" value="/user/video">
          <button type="submit" class="btn btn-ghost btn-sm">删除</button>
        </form>
      </div>
    </div>
  `;

  return article;
};

const prependRecordCard = (record) => {
  if (!historyList) return;
  const existing = historyList.querySelector(`[data-record-id="${record.id}"]`);
  if (existing) existing.remove();
  const empty = historyList.querySelector(".history-empty-inline");
  if (empty) empty.remove();
  historyList.prepend(createRecordCard(record));
};

// Model-specific video options: supports_reference, fixed_seconds, read-only resolution/aspect_ratio
const videoModelSelect = document.getElementById("ai_model_id");
const sizeSelect = document.getElementById("size");
const resolutionSelect = document.getElementById("resolution");
const maxRefCount = document.querySelector("[data-max-ref-count]");

const getSelectedModelData = () => {
  if (!videoModelSelect) return {};
  try {
    const models = JSON.parse(videoModelSelect.dataset.videoModels || "[]");
    return models.find(m => m.id === parseInt(videoModelSelect.value, 10)) || {};
  } catch (_) { return {}; }
};

const syncModelOptions = (modelData) => {
  const supportsRef = parseInt(modelData?.supports_reference || "0", 10);
  const refRequired = parseInt(modelData?.reference_required || "0", 10);
  const imageModeLabel = document.querySelector("[data-video-mode-with-image]");

  if (imageModeLabel) {
    imageModeLabel.style.display = supportsRef === 1 ? "" : "none";
  }

  if (maxRefCount) {
    const maxRef = parseInt(modelData?.max_reference_images || "1", 10);
    maxRefCount.textContent = maxRef;
    if (uploadInput) uploadInput.dataset.maxFiles = String(maxRef);
  }

  const fixedRes = modelData?.video_resolution || "auto";
  const fixedRatio = modelData?.video_aspect_ratio || "auto";

  if (sizeSelect) {
    if (fixedRatio !== "auto") {
      sizeSelect.value = fixedRatio;
      sizeSelect.disabled = true;
      sizeSelect.title = "由模型固定配置";
    } else {
      sizeSelect.disabled = false;
      sizeSelect.title = "";
    }
  }

  if (resolutionSelect) {
    if (fixedRes !== "auto") {
      resolutionSelect.value = fixedRes;
      resolutionSelect.disabled = true;
      resolutionSelect.title = "由模型固定配置";
    } else {
      resolutionSelect.disabled = false;
      resolutionSelect.title = "";
    }
  }

  const credits = parseInt(modelData?.credits || "0", 10);
  const fixedSeconds = parseInt(modelData?.fixed_seconds || "0", 10);
  const costVal = document.querySelector("[data-cost-value]");
  const costSecondsInfo = document.querySelector("[data-cost-seconds-info]");

  if (costVal && credits > 0) {
    costVal.textContent = credits + (fixedSeconds > 0 ? " × " + fixedSeconds + "s" : "");
  }

  if (costSecondsInfo) {
    costSecondsInfo.style.display = fixedSeconds > 0 ? "inline" : "none";
    costSecondsInfo.textContent = fixedSeconds > 0 ? "(固定时长" + fixedSeconds + "秒)" : "";
  }

  if (uploadHint) {
    uploadHint.dataset.refRequired = String(refRequired);
  }
};

if (videoModelSelect) {
  videoModelSelect.addEventListener("change", () => {
    syncModelOptions(getSelectedModelData());
  });
  syncModelOptions(getSelectedModelData());
}

videoModeInputs.forEach((input) => {
  input.addEventListener("change", () => {
    syncModeFields();
    clearUploadSelection();
  });
});
syncModeFields();
updateUploadHint();

if (uploadBox && uploadInput) {
  uploadBox.addEventListener("click", (event) => {
    if (event.target.closest(".edit-preview-remove")) return;
    uploadInput.click();
  });

  uploadInput.addEventListener("change", () => {
    const max = parseInt(uploadInput.dataset.maxFiles || "4", 10);
    const newFiles = Array.from(uploadInput.files || []);
    selectedFiles = [...selectedFiles, ...newFiles].slice(0, max);
    syncUploadInputFiles();
    updateUploadPreview();
  });
}

form?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (isGenerating) return;

  const mode = activeVideoMode();
  const refRequired = uploadHint?.dataset.refRequired === "1";

  if (mode === "image" && selectedFiles.length === 0) {
    if (refRequired) {
      showErrorDialog("当前模型要求必须上传参考图片。");
      return;
    }
  }

  if (uploadInput) uploadInput.value = "";
  const formData = new FormData(form);
  if (mode === "image") {
    selectedFiles.forEach((file) => formData.append("edit_images[]", file));
  }

  setGenerating(true);
  showMessage("正在提交...", "info");

  try {
    const response = await fetch("/generate.php", { method: "POST", body: formData });
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (_) {
      data = null;
    }

    if (data?.ok && data.record_id) {
      if (data.credits !== undefined) updateCredits(data.credits);
      if (data.record) {
        prependRecordCard(data.record);
        showResultDialog(data.record);
      } else {
        showMessage(data.message || "已提交生成。");
      }
      if (mode === "image") clearUploadSelection();
    } else {
      showErrorDialog(data?.message || "提交失败，请重试。");
    }
  } catch (err) {
    showErrorDialog(err.message || "网络请求失败，请检查连接后重试。");
  } finally {
    setGenerating(false);
  }
});

let pendingSubmit = false;
form?.addEventListener("submit", () => { pendingSubmit = true; });
form?.addEventListener("input", () => { pendingSubmit = false; });
window.addEventListener("beforeunload", (event) => {
  if (isGenerating || pendingSubmit) {
    event.returnValue = "请求仍在处理中，关闭页面可能导致当前提交中断。";
  }
});

const showResultDialog = (record) => {
  const proxyCard = {
    dataset: {
      recordId: record.id || "",
      status: record.status || "succeeded",
      mode: "video",
      prompt: record.prompt || "",
      size: record.size || "16:9",
      quality: record.quality || "",
      format: record.format || "mp4",
      credits: record.credits_charged || 0,
      created: record.created_at || "",
      finished: record.finished_at || "-",
      error: record.error_message || "",
      inputCount: String(record.input_image_count || 0),
    },
    querySelector: () => null,
  };

  if (typeof window.openRecordDialog === "function") {
    window.openRecordDialog(proxyCard);
  } else {
    showMessage("生成完成。", "success");
  }

  const dialog = document.querySelector("#recordDialog");
  if (dialog) dialog.dataset.refreshOnClose = "1";
};

const showErrorDialog = (text) => {
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
        <div class="error-dialog-body" id="errorDialogBody">${escapeHtml(text)}</div>
        <div class="error-dialog-foot">
          <button type="button" data-close-dialog class="btn btn-primary">知道了</button>
        </div>
      </div>`;
    document.body.appendChild(dialog);
    dialog.addEventListener("click", (event) => {
      if (event.target.closest("[data-close-dialog]") || event.target === dialog) {
        dialog.classList.add("hidden");
        document.body.classList.remove("has-dialog");
      }
    });
  } else {
    const body = dialog.querySelector("#errorDialogBody");
    if (body) body.textContent = text;
  }

  dialog.classList.remove("hidden");
  document.body.classList.add("has-dialog");
};
