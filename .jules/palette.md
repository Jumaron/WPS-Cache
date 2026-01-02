## 2024-05-23 - Dynamic Content Accessibility
**Learning:** Dynamically injected content via JavaScript (like loading spinners or success icons) often bypasses static HTML checks. Decorative icons injected this way must explicitly include `aria-hidden="true"` to prevent screen readers from announcing them as unpronounceable characters or "image".
**Action:** Always include `aria-hidden="true"` in the HTML string when injecting icon-only or decorative elements via `innerHTML`.
