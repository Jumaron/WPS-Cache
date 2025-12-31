## 2024-05-23 - Copy to Clipboard Pattern
**Learning:** Adding a "Copy" button to readonly textareas significantly improves usability. The implementation should prefer `navigator.clipboard` but must fallback to `document.execCommand('copy')` for compatibility. Visual feedback (changing button text/icon) is crucial for "delight".
**Action:** When displaying large blocks of text (logs, lists, keys), always bundle a copy action. Use the `wpsc-copy-urls` ID pattern and `admin.js` handler as a template.
