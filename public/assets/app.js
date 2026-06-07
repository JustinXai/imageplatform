(() => {
  const titles = {
    success: "操作成功",
    error: "操作失败",
    warning: "请注意",
    info: "提示",
  };

  const ensureLayerRoot = () => {
    let root = document.querySelector("#jptLayerRoot");
    if (root) return root;

    root = document.createElement("div");
    root.id = "jptLayerRoot";
    root.className = "jpt-layer-root";
    root.innerHTML = '<div class="jpt-toast-stack" data-layer-toasts></div>';
    document.body.appendChild(root);
    return root;
  };

  const toast = (message, type = "info", duration = 2600) => {
    const root = ensureLayerRoot();
    const stack = root.querySelector("[data-layer-toasts]");
    const item = document.createElement("div");
    item.className = `jpt-toast ${type}`;
    item.setAttribute("role", type === "error" ? "alert" : "status");
    item.innerHTML = `
      <span class="jpt-toast-icon" aria-hidden="true"></span>
      <span>${escapeLayerHtml(message)}</span>
    `;
    stack.appendChild(item);

    const close = () => {
      item.classList.add("is-leaving");
      window.setTimeout(() => item.remove(), 180);
    };
    window.setTimeout(close, duration);
    item.addEventListener("click", close, { once: true });
  };

  const openModal = ({ title = "提示", message = "", type = "info", confirmText = "确定", cancelText = "", danger = false }) =>
    new Promise((resolve) => {
      const root = ensureLayerRoot();
      const overlay = document.createElement("div");
      overlay.className = "jpt-layer-overlay";

      // 模板渲染可能抛出异常，先添加到 DOM 再加 has-dialog
      try {
        overlay.innerHTML = `
          <div class="jpt-layer-panel ${type}" role="dialog" aria-modal="true" aria-labelledby="jptLayerTitle">
            <div class="jpt-layer-head">
              <span class="jpt-layer-icon" aria-hidden="true"></span>
              <div>
                <h2 id="jptLayerTitle">${escapeLayerHtml(title)}</h2>
                <p>${escapeLayerHtml(message)}</p>
              </div>
            </div>
            <div class="jpt-layer-actions">
              ${cancelText ? `<button type="button" class="button secondary" data-layer-cancel>${escapeLayerHtml(cancelText)}</button>` : ""}
              <button type="button" class="button ${danger ? "danger" : "primary"}" data-layer-confirm>${escapeLayerHtml(confirmText)}</button>
            </div>
          </div>
        `;
      } catch (e) {
        // 渲染失败时移除 has-dialog 并 resolve(false)
        document.body.classList.remove("has-dialog");
        console.error("JptLayer render error:", e);
        resolve(false);
        return;
      }
      root.appendChild(overlay);
      document.body.classList.add("has-dialog");

      let finished = false;
      const finish = (value) => {
        if (finished) return;
        finished = true;
        window.removeEventListener("keydown", onKeydown);
        overlay.classList.add("is-leaving");
        window.setTimeout(() => {
          overlay.remove();
          if (!document.querySelector(".redeem-dialog:not(.hidden), .record-dialog:not(.hidden), .generation-overlay:not(.hidden), .jpt-layer-overlay")) {
            document.body.classList.remove("has-dialog");
          }
          resolve(value);
        }, 180);
      };

      overlay.querySelector("[data-layer-confirm]")?.focus();
      overlay.addEventListener("click", (event) => {
        if (event.target === overlay || event.target.closest("[data-layer-cancel]")) {
          finish(false);
          return;
        }
        if (event.target.closest("[data-layer-confirm]")) {
          finish(true);
        }
      });

      const onKeydown = (event) => {
        if (event.key === "Escape") {
          finish(false);
        }
      };
      window.addEventListener("keydown", onKeydown);
    });

  const layer = {
    toast,
    alert: (message, options = {}) =>
      openModal({
        title: options.title || titles[options.type || "info"],
        message,
        type: options.type || "info",
        confirmText: options.confirmText || "知道了",
      }),
    confirm: (message, options = {}) =>
      openModal({
        title: options.title || "确认操作",
        message,
        type: options.type || "warning",
        confirmText: options.confirmText || "确认",
        cancelText: options.cancelText || "取消",
        danger: Boolean(options.danger),
      }),
  };

  window.JptLayer = layer;

  const emitServerFlashes = () => {
    document.querySelectorAll("[data-flash-message]").forEach((node) => {
      const type = normalizeFlashType(node.dataset.flashType);
      layer.toast(node.dataset.flashMessage || "", type, type === "error" ? 4200 : 3000);
      node.remove();
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", emitServerFlashes, { once: true });
  } else {
    emitServerFlashes();
  }

  const invalidForms = new WeakSet();
  document.addEventListener("invalid", (event) => {
    event.preventDefault();
    const field = event.target;
    const form = field.form;
    if (form && invalidForms.has(form)) return;
    if (form) {
      invalidForms.add(form);
      window.setTimeout(() => invalidForms.delete(form), 220);
    }

    const label =
      field.closest(".field")?.querySelector("span")?.textContent?.trim() ||
      field.getAttribute("aria-label") ||
      field.getAttribute("placeholder") ||
      "当前字段";
    layer.toast(validationMessage(field, label), "warning", 3200);
    field.focus({ preventScroll: false });
  }, true);

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("form[data-confirm]");
    if (!form || form.dataset.confirmAccepted === "1") return;

    event.preventDefault();
    const confirmed = await layer.confirm(form.dataset.confirm || "确认继续操作？", {
      title: form.dataset.confirmTitle || "确认操作",
      confirmText: form.dataset.confirmText || "确认",
      cancelText: form.dataset.cancelText || "取消",
      type: form.dataset.confirmType || "warning",
      danger: form.dataset.confirmDanger === "1",
    });
    if (!confirmed) return;

    form.dataset.confirmAccepted = "1";
    if (typeof form.requestSubmit === "function") {
      form.requestSubmit();
    } else {
      HTMLFormElement.prototype.submit.call(form);
    }
  });

  function escapeLayerHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function validationMessage(field, label) {
    const validity = field.validity;
    if (validity.valueMissing) return `请填写${label}。`;
    if (validity.typeMismatch && field.type === "email") return "请输入有效的邮箱地址。";
    if (validity.typeMismatch && field.type === "url") return "请输入有效的网址。";
    if (validity.tooShort) return `${label}至少需要 ${field.minLength} 个字符。`;
    if (validity.tooLong) return `${label}不能超过 ${field.maxLength} 个字符。`;
    if (validity.rangeUnderflow) return `${label}不能小于 ${field.min}。`;
    if (validity.rangeOverflow) return `${label}不能大于 ${field.max}。`;
    if (validity.stepMismatch) return `请按正确步进填写${label}。`;
    if (validity.patternMismatch) return field.title || `请按要求填写${label}。`;
    if (validity.badInput) return `请填写有效的${label}。`;
    return field.validationMessage || `请检查${label}。`;
  }

  function normalizeFlashType(type) {
    return ["success", "error", "warning", "info"].includes(type) ? type : "info";
  }

  /* ── Scroll Safety: 定期检查 has-dialog 状态是否异常 ── */
  window.setInterval(function() {
    if (!document.body.classList.contains("has-dialog")) return;
    var visibleDialogs = document.querySelectorAll(
      ".redeem-dialog:not(.hidden), .record-dialog:not(.hidden), " +
      ".generation-overlay:not(.hidden), .jpt-layer-overlay"
    );
    var hasVisible = false;
    for (var i = 0; i < visibleDialogs.length; i++) {
      if (visibleDialogs[i].offsetParent !== null) {
        hasVisible = true;
        break;
      }
    }
    if (!hasVisible) {
      document.body.classList.remove("has-dialog");
    }
  }, 1000);
})();

