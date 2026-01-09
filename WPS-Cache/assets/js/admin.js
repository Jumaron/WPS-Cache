document.addEventListener("DOMContentLoaded", function () {
  initPreloader();
  initCopyTriggers();
  initPasswordToggles();
  initFormSubmissions();
  initPurgeButton();
  initDismissButtons();
  initSwitches();
});

function initDismissButtons() {
  document.querySelectorAll(".wpsc-dismiss-btn").forEach((btn) => {
    btn.addEventListener("click", function () {
      const notice = this.closest(".wpsc-notice");
      if (!notice) return;

      notice.style.transition = "opacity 0.3s ease, transform 0.3s ease";
      notice.style.opacity = "0";
      notice.style.transform = "translateY(-10px)";

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
    input.addEventListener("change", function () {
      this.setAttribute("aria-checked", this.checked ? "true" : "false");
    });
  });
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
        if (icon) {
          icon.classList.remove("dashicons-visibility");
          icon.classList.add("dashicons-hidden");
        }
      } else {
        input.type = "password";
        this.setAttribute("aria-label", wpsc_admin.strings.show_password);
        if (icon) {
          icon.classList.remove("dashicons-hidden");
          icon.classList.add("dashicons-visibility");
        }
      }
    });
  });
}

function initPurgeButton() {
  const triggers = document.querySelectorAll(
    "#wpsc-purge-all, .wpsc-purge-trigger a",
  );

  triggers.forEach((trigger) => {
    // Remove inline onclick to prevent double confirmation dialogs (Progressive Enhancement)
    if (trigger.hasAttribute("onclick")) {
      trigger.removeAttribute("onclick");
    }

    trigger.addEventListener("click", function (e) {
      if (!confirm(wpsc_admin.strings.purge_confirm)) {
        e.preventDefault();
      } else {
        // User confirmed, link will be followed.
        // Show loading state immediately.
        trigger.classList.add("disabled");
        trigger.innerHTML =
          '<span class="dashicons dashicons-update wpsc-spin" aria-hidden="true" style="vertical-align: middle;"></span> ' +
          wpsc_admin.strings.purging;
      }
    });
  });
}

function initFormSubmissions() {
  document.querySelectorAll("form.wpsc-form").forEach((form) => {
    form.addEventListener("submit", function () {
      const btn = form.querySelector('button[type="submit"]');
      if (!btn) return;

      // Prevent double submission visually
      if (btn.classList.contains("disabled")) {
        return;
      }

      // Lock width to prevent jumping if possible, or just let it reflow
      // btn.style.width = btn.offsetWidth + "px";

      btn.classList.add("disabled");
      const loadingText = btn.dataset.loadingText || wpsc_admin.strings.saving;
      btn.innerHTML =
        '<span class="dashicons dashicons-update wpsc-spin" aria-hidden="true" style="vertical-align: middle;"></span> ' +
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

  const CONCURRENCY_LIMIT = 3;
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

    // UI Reset
    preloadBtn.disabled = true;
    // Store original text
    if (!preloadBtn.dataset.originalText) {
      preloadBtn.dataset.originalText = preloadBtn.innerHTML;
    }
    // Set loading state
    preloadBtn.innerHTML =
      '<span class="dashicons dashicons-update wpsc-spin" aria-hidden="true"></span> ' +
      wpsc_admin.strings.preload_loading;

    progressDiv.style.display = "block";
    statusSpan.textContent = wpsc_admin.strings.preload_start;
    progressBar.value = 0;
    percentSpan.textContent = "0%";
    processed = 0;
    activeRequests = 0;

    // Step 1: Get List
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
    // If finished
    if (queue.length === 0 && activeRequests === 0) {
      finish(wpsc_admin.strings.preload_complete);
      return;
    }

    // Fill the active slots
    while (activeRequests < CONCURRENCY_LIMIT && queue.length > 0) {
      const url = queue.shift();
      activeRequests++;

      updateStatus();

      // Step 2: Warmup Request
      fetch(wpsc_admin.ajax_url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          action: "wpsc_process_preload_url",
          url: url,
          _ajax_nonce: wpsc_admin.nonce,
        }),
      })
        .then(() => {
          // Success
        })
        .catch(() => {
          // Error - ignore
        })
        .finally(() => {
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

    // Show success state
    preloadBtn.innerHTML =
      '<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' +
      wpsc_admin.strings.preload_done;

    setTimeout(() => {
      preloadBtn.disabled = false;
      // Restore original text
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
      '<span class="dashicons dashicons-yes" aria-hidden="true" style="vertical-align: middle;"></span> ' +
      wpsc_admin.strings.copied;
    btn.classList.remove("wpsc-btn-secondary");
    btn.classList.add("button-primary", "wpsc-btn-primary");

    announce(wpsc_admin.strings.copied_announcement);

    setTimeout(() => {
      btn.innerHTML = originalHtml;
      btn.classList.remove("button-primary", "wpsc-btn-primary");
      btn.classList.add("wpsc-btn-secondary");
      btn.style.width = "";
    }, 2000);
  }
}
