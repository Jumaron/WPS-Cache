document.addEventListener("DOMContentLoaded", function () {
  initPreloader();
  initCopyTriggers();
  initPasswordToggles();
  initFormSubmissions();
  initConfirmButtons();
  initDismissButtons();
  initSwitches();
  initConditionalSettings();
  initTabsResponsive();
  initSidebar();
  initImageBulk();
  initLabTest();
});

function initTabsResponsive() {
  const nav = document.querySelector(".wpsc-nav");
  const active = document.querySelector(".wpsc-nav-item.active");
  if (nav && active) {
    active.scrollIntoView({ block: "nearest" });
  }
}

function initSidebar() {
  const sidebar = document.getElementById("wpsc-sidebar");
  const toggle = document.querySelector("[data-wpsc-sidebar-toggle]");
  const close = document.querySelector("[data-wpsc-sidebar-close]");
  if (!sidebar || !toggle) return;

  const setOpen = (open) => {
    document.body.classList.toggle("wpsc-sidebar-open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    toggle.setAttribute("aria-label", open ? "Close navigation" : "Open navigation");
  };

  toggle.addEventListener("click", () => {
    setOpen(!document.body.classList.contains("wpsc-sidebar-open"));
  });
  if (close) close.addEventListener("click", () => setOpen(false));
  sidebar.querySelectorAll(".wpsc-nav-item").forEach((item) => {
    item.addEventListener("click", () => setOpen(false));
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") setOpen(false);
  });
  window.addEventListener("resize", () => {
    if (window.innerWidth > 960) setOpen(false);
  });
}

function initDismissButtons() {
  document.querySelectorAll(".wpsc-dismiss-btn").forEach((btn) => {
    btn.addEventListener("click", function () {
      const notice = this.closest(".wpsc-notice");
      if (!notice) return;

      notice.style.transition = "all 0.3s ease";
      notice.style.opacity = "0";
      notice.style.transform = "translateY(-10px)";
      notice.style.marginBottom = "0";
      notice.style.padding = "0";
      notice.style.border = "none";

      setTimeout(() => {
        notice.remove();
        if (wpsc_admin.strings.notice_dismissed) {
          announce(wpsc_admin.strings.notice_dismissed);
        }
      }, 300);
    });
  });
}

function initSwitches() {
  document.querySelectorAll('input[role="switch"]').forEach((input) => {
    updateSwitchPresentation(input);
    input.addEventListener("change", function () {
      this.setAttribute("aria-checked", this.checked ? "true" : "false");
      updateSwitchPresentation(this);
    });
  });
}

function updateSwitchPresentation(input) {
  const row = input.closest(".wpsc-setting-row");
  if (!row) return;
  row.classList.toggle("is-enabled", input.checked);
  const state = row.querySelector(".wpsc-toggle-state");
  if (state) state.textContent = input.checked ? "On" : "Off";
}

function initConditionalSettings() {
  const groups = Array.from(
    document.querySelectorAll("[data-wpsc-conditional]"),
  );
  if (!groups.length) return;

  const getControlValue = (key) => {
    const controls = Array.from(
      document.getElementsByName(`wpsc_settings[${key}]`),
    );
    if (!controls.length) return null;
    const choice = controls.find(
      (control) =>
        (control.type === "checkbox" || control.type === "radio") &&
        control.checked,
    );
    if (choice) return choice.value;
    const checkbox = controls.find((control) => control.type === "checkbox");
    if (checkbox) return checkbox.checked ? "1" : "0";
    const radio = controls.find((control) => control.type === "radio");
    if (radio) return null;
    return controls[controls.length - 1].value;
  };

  const updateGroup = (group) => {
    let conditions = {};
    try {
      conditions = JSON.parse(group.dataset.wpscConditional || "{}");
    } catch (error) {
      return;
    }

    const matches = Object.entries(conditions)
      .map(([key, accepted]) => {
        const value = getControlValue(key);
        return value === null
          ? null
          : accepted.map(String).includes(String(value));
      })
      .filter((match) => match !== null);

    // A controller may live on another settings screen. In that case the
    // server-rendered state remains authoritative until the page reloads.
    if (!matches.length) return;
    const visible =
      group.dataset.wpscConditionOperator === "any"
        ? matches.some(Boolean)
        : matches.every(Boolean);

    group.hidden = !visible;
    group.classList.toggle("is-visible", visible);
    if ("inert" in group) group.inert = !visible;
  };

  const updateAll = () => groups.forEach(updateGroup);
  document.addEventListener("change", (event) => {
    if (event.target?.name?.startsWith("wpsc_settings[")) updateAll();
  });
  updateAll();
}

function initPasswordToggles() {
  const toggles = document.querySelectorAll(".wpsc-password-toggle");

  toggles.forEach((btn) => {
    btn.addEventListener("click", function () {
      const inputId = this.getAttribute("aria-controls");
      if (!inputId) return;

      const input = document.getElementById(inputId);
      if (!input) return;

      const icon = this.querySelector(".dashicons");
      const isPassword = input.type === "password";

      if (isPassword) {
        input.type = "text";
        this.setAttribute("aria-label", wpsc_admin.strings.hide_password);
        this.setAttribute("title", wpsc_admin.strings.hide_password);
        if (icon) {
          icon.classList.remove("dashicons-visibility");
          icon.classList.add("dashicons-hidden");
        }
      } else {
        input.type = "password";
        this.setAttribute("aria-label", wpsc_admin.strings.show_password);
        this.setAttribute("title", wpsc_admin.strings.show_password);
        if (icon) {
          icon.classList.remove("dashicons-hidden");
          icon.classList.add("dashicons-visibility");
        }
      }
    });
  });
}

function initConfirmButtons() {
  const triggers = document.querySelectorAll(
    "#wpsc-purge-all, .wpsc-purge-trigger a, .wpsc-confirm-trigger",
  );

  triggers.forEach((trigger) => {
    if (trigger.hasAttribute("onclick")) {
      trigger.removeAttribute("onclick");
    }

    trigger.addEventListener("click", function (e) {
      if (trigger.classList.contains("disabled")) return;

      const message =
        trigger.dataset.confirm || wpsc_admin.strings.purge_confirm;

      if (!confirm(message)) {
        e.preventDefault();
      } else {
        trigger.classList.add("disabled");
        const loadingText =
          trigger.dataset.loadingText || wpsc_admin.strings.purging;
        // Keep width to prevent layout jump
        trigger.style.width = trigger.offsetWidth + "px";
        trigger.innerHTML = `<span class="dashicons dashicons-update wpsc-spin" aria-hidden="true"></span>`;
      }
    });
  });
}

function initFormSubmissions() {
  document.querySelectorAll("form.wpsc-form").forEach((form) => {
    form.addEventListener("change", function () {
      form.classList.add("is-dirty");
    });
    form.addEventListener("submit", function () {
      const btn = form.querySelector('button[type="submit"]');
      if (!btn) return;

      if (btn.classList.contains("disabled")) {
        return;
      }

      btn.classList.add("disabled");
      const loadingText = btn.dataset.loadingText || wpsc_admin.strings.saving;
      btn.innerHTML =
        '<span class="dashicons dashicons-update wpsc-spin" aria-hidden="true" style="margin-right:6px"></span> ' +
        loadingText;
    });
  });
}

function initPreloader() {
  const preloadBtn = document.getElementById("wpsc-start-preload");
  if (!preloadBtn) return;

  const progressDiv = document.getElementById("wpsc-preload-progress");
  const statusSpan = document.getElementById("wpsc-preload-status");
  const percentSpan = document.getElementById("wpsc-preload-percent");
  const progressBar = document.getElementById("wpsc-preload-bar");

  const CONCURRENCY_LIMIT = Number(wpsc_admin.preload_concurrency || 2);
  let queue = [];
  let total = 0;
  let processed = 0;
  let activeRequests = 0;

  function confirmExit(e) {
    e.preventDefault();
    e.returnValue = "";
    return "";
  }

  preloadBtn.addEventListener("click", function () {
    window.addEventListener("beforeunload", confirmExit);

    preloadBtn.disabled = true;
    if (!preloadBtn.dataset.originalText) {
      preloadBtn.dataset.originalText = preloadBtn.innerHTML;
    }
    preloadBtn.innerHTML =
      '<span class="dashicons dashicons-update wpsc-spin" aria-hidden="true"></span> ' +
      wpsc_admin.strings.preload_loading;

    progressDiv.style.display = "block";
    statusSpan.textContent = wpsc_admin.strings.preload_start;
    progressBar.value = 0;
    percentSpan.textContent = "0%";
    processed = 0;
    activeRequests = 0;

    fetch(wpsc_admin.ajax_url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "wpsc_get_preload_urls",
        _ajax_nonce: wpsc_admin.nonce,
      }),
    })
      .then((res) => res.json())
      .then((res) => {
        if (res.success) {
          queue = res.data;
          total = queue.length;
          if (total === 0) {
            finish("No URLs found.");
            return;
          }
          statusSpan.textContent = "Processing 0/" + total;
          processQueue();
        } else {
          throw new Error("Failed to fetch URLs");
        }
      })
      .catch((err) => {
        console.error(err);
        finish("Error: " + err.message);
      });
  });

  function processQueue() {
    if (queue.length === 0 && activeRequests === 0) {
      finish(wpsc_admin.strings.preload_complete);
      return;
    }
    while (activeRequests < CONCURRENCY_LIMIT && queue.length > 0) {
      const url = queue.shift();
      activeRequests++;
      updateStatus();
      fetch(wpsc_admin.ajax_url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          action: "wpsc_process_preload_url",
          url: url,
          _ajax_nonce: wpsc_admin.nonce,
        }),
      }).finally(() => {
        activeRequests--;
        processed++;
        updateProgress();
        processQueue();
      });
    }
  }

  function updateStatus() {
    statusSpan.textContent = `Processing ${processed}/${total} (${activeRequests} active)`;
  }

  function updateProgress() {
    const percent = Math.round((processed / total) * 100);
    progressBar.value = percent;
    percentSpan.textContent = percent + "%";
    updateStatus();
  }

  function finish(msg) {
    window.removeEventListener("beforeunload", confirmExit);
    statusSpan.textContent = msg;
    progressBar.value = 100;
    percentSpan.textContent = "100%";
    preloadBtn.innerHTML =
      '<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' +
      wpsc_admin.strings.preload_done;

    setTimeout(() => {
      preloadBtn.disabled = false;
      if (preloadBtn.dataset.originalText) {
        preloadBtn.innerHTML = preloadBtn.dataset.originalText;
      }
    }, 2000);
  }
}

function announce(message) {
  let announcer = document.getElementById("wpsc-a11y-speak");
  if (!announcer) {
    announcer = document.createElement("div");
    announcer.id = "wpsc-a11y-speak";
    announcer.setAttribute("aria-live", "polite");
    announcer.setAttribute("aria-atomic", "true");
    announcer.style.position = "absolute";
    announcer.style.width = "1px";
    announcer.style.height = "1px";
    announcer.style.overflow = "hidden";
    announcer.style.clip = "rect(0, 0, 0, 0)";
    announcer.style.whiteSpace = "nowrap";
    announcer.style.border = "0";
    document.body.appendChild(announcer);
  }
  announcer.textContent = message;
}

function initCopyTriggers() {
  document.querySelectorAll(".wpsc-copy-trigger").forEach((btn) => {
    btn.addEventListener("click", function () {
      const targetId = this.dataset.copyTarget;
      const targetEl = document.getElementById(targetId);
      if (!targetEl) return;

      targetEl.select();
      targetEl.setSelectionRange(0, 99999);

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(targetEl.value).then(
          () => showSuccess(btn),
          () => fallbackCopy(targetEl, btn),
        );
      } else {
        fallbackCopy(targetEl, btn);
      }
    });
  });

  function fallbackCopy(targetEl, btn) {
    try {
      document.execCommand("copy");
      showSuccess(btn);
    } catch (err) {
      console.error("Copy failed", err);
    }
  }

  function showSuccess(btn) {
    const originalHtml = btn.innerHTML;
    const originalWidth = btn.offsetWidth;
    btn.style.width = originalWidth + "px";
    btn.innerHTML =
      '<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' +
      wpsc_admin.strings.copied;
    btn.classList.remove("wpsc-btn-secondary");
    btn.classList.add("wpsc-btn-primary");
    announce(wpsc_admin.strings.copied_announcement);
    setTimeout(() => {
      btn.innerHTML = originalHtml;
      btn.classList.remove("wpsc-btn-primary");
      btn.classList.add("wpsc-btn-secondary");
      btn.style.width = "";
    }, 2000);
  }
}

function initImageBulk() {
  const button = document.getElementById("wpsc-start-image-bulk");
  if (!button) return;
  const container = document.getElementById("wpsc-image-progress");
  const bar = document.getElementById("wpsc-image-bar");
  const status = document.getElementById("wpsc-image-status");
  const restoreButton = document.getElementById("wpsc-restore-image");
  const restoreId = document.getElementById("wpsc-restore-image-id");

  if (restoreButton && restoreId) {
    restoreButton.addEventListener("click", async () => {
      if (!restoreId.value) return;
      restoreButton.disabled = true;
      try {
        await wpscAjax("wpsc_image_restore", { id: restoreId.value });
        status.textContent = "Original restored.";
        container.hidden = false;
      } catch (error) {
        status.textContent = `Restore error: ${error.message}`;
        container.hidden = false;
      } finally {
        restoreButton.disabled = false;
      }
    });
  }

  button.addEventListener("click", async () => {
    button.disabled = true;
    container.hidden = false;
    bar.value = 0;
    let page = 1;
    let processed = 0;
    let total = 0;
    try {
      do {
        const batch = await wpscAjax("wpsc_image_batch", { page });
        const data = batch.data;
        total = data.total;
        for (const id of data.ids) {
          await wpscAjax("wpsc_image_optimize", { id }).catch(() => null);
          processed++;
          bar.value = total ? Math.round((processed / total) * 100) : 100;
          status.textContent = `${processed}/${total}`;
        }
        if (page >= data.pages) break;
        page++;
      } while (true);
      status.textContent = wpsc_admin.strings.image_complete;
      bar.value = 100;
    } catch (error) {
      status.textContent = `Error: ${error.message}`;
    } finally {
      button.disabled = false;
    }
  });
}

function initLabTest() {
  const button = document.getElementById("wpsc-run-lab");
  const result = document.getElementById("wpsc-lab-result");
  if (!button || !result) return;
  button.addEventListener("click", async () => {
    button.disabled = true;
    result.textContent = "Running…";
    try {
      const response = await fetch(wpsc_admin.rest_url, {
        headers: { "X-WP-Nonce": wpsc_admin.rest_nonce },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
      result.textContent = JSON.stringify(data, null, 2);
    } catch (error) {
      result.textContent = `Error: ${error.message}`;
    } finally {
      button.disabled = false;
    }
  });
}

async function wpscAjax(action, values = {}) {
  const response = await fetch(wpsc_admin.ajax_url, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      action,
      _ajax_nonce: wpsc_admin.nonce,
      ...values,
    }),
  });
  const data = await response.json();
  if (!response.ok || !data.success) {
    throw new Error(
      typeof data.data === "string"
        ? data.data
        : data.data?.message || `HTTP ${response.status}`,
    );
  }
  return data;
}
