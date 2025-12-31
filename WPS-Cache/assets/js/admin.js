document.addEventListener("DOMContentLoaded", function () {
  const preloadBtn = document.getElementById("wpsc-start-preload");
  const progressDiv = document.getElementById("wpsc-preload-progress");
  const statusSpan = document.getElementById("wpsc-preload-status");
  const percentSpan = document.getElementById("wpsc-preload-percent");
  const progressBar = document.getElementById("wpsc-preload-bar");

  if (!preloadBtn) return;

  let queue = [];
  let total = 0;
  let processed = 0;

  preloadBtn.addEventListener("click", function () {
    // UI Reset
    preloadBtn.disabled = true;
    progressDiv.style.display = "block";
    statusSpan.textContent = wpsc_admin.strings.preload_start;
    progressBar.value = 0;
    percentSpan.textContent = "0%";

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
          statusSpan.textContent = "Processing 1 of " + total;
          processNextUrl();
        } else {
          throw new Error("Failed to fetch URLs");
        }
      })
      .catch((err) => {
        console.error(err);
        finish("Error: " + err.message);
      });
  });

  function processNextUrl() {
    if (queue.length === 0) {
      finish(wpsc_admin.strings.preload_complete);
      return;
    }

    const url = queue.shift(); // Now safely callable
    processed++;

    // Update UI
    const percent = Math.round((processed / total) * 100);
    progressBar.value = percent;
    percentSpan.textContent = percent + "%";

    // Safety check for URL string
    const urlDisplay =
      typeof url === "string" ? url.split("/").pop() || "Home" : "Page";
    statusSpan.textContent = `Processing ${processed}/${total}: ${urlDisplay}`;

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
        // Continue regardless of individual page errors (404s etc)
        processNextUrl();
      })
      .catch(() => {
        processNextUrl();
      });
  }

  function finish(msg) {
    statusSpan.textContent = msg;
    progressBar.value = 100;
    percentSpan.textContent = "100%";
    preloadBtn.disabled = false;
  }
});
