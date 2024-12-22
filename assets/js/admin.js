document.addEventListener("DOMContentLoaded", function () {
  const preloadButton = document.getElementById("wpsc-preload-cache");
  const progressBar = document.getElementById("wpsc-preload-progress");
  const progressText = progressBar.querySelector(".progress-text");
  const progressElement = progressBar.querySelector("progress");

  if (!preloadButton) return;

  async function startPreloading() {
    try {
      preloadButton.disabled = true;
      progressBar.style.display = "block";
      progressElement.value = 0;
      progressText.textContent = "0%";

      const response = await fetch(wpsc_admin.ajax_url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "wpsc_preload_cache",
          _ajax_nonce: wpsc_admin.nonce,
        }),
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const jsonResponse = await response.json();

      if (!jsonResponse.success) {
        throw new Error(jsonResponse.data || wpsc_admin.strings.preload_error);
      }

      const data = jsonResponse.data;

      // Update final progress
      progressElement.value = 100;
      progressText.textContent = `100% (${data.total}/${data.total} URLs)`;

      // Show completion message
      alert(wpsc_admin.strings.preload_complete);
    } catch (error) {
      console.error("Preload error:", error);
      alert(wpsc_admin.strings.preload_error);
    } finally {
      preloadButton.disabled = false;
      progressBar.style.display = "none";
    }
  }

  preloadButton.addEventListener("click", startPreloading);
});
