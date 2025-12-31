document.addEventListener("DOMContentLoaded", function () {
  const preloadBtn = document.getElementById("wpsc-start-preload");
  const progressDiv = document.getElementById("wpsc-preload-progress");
  const statusSpan = document.getElementById("wpsc-preload-status");
  const percentSpan = document.getElementById("wpsc-preload-percent");
  const progressBar = document.getElementById("wpsc-preload-bar");

  if (!preloadBtn) return;

  const CONCURRENCY_LIMIT = 3;
  let queue = [];
  let total = 0;
  let processed = 0;
  let activeRequests = 0;

  preloadBtn.addEventListener("click", function () {
    // UI Reset
    preloadBtn.disabled = true;
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
          // CRITICAL FIX: Ensure we have an Array, even if PHP sent an Object
          queue = Array.isArray(res.data) ? res.data : Object.values(res.data);

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
    statusSpan.textContent = msg;
    progressBar.value = 100;
    percentSpan.textContent = "100%";
    preloadBtn.disabled = false;
  }
});
