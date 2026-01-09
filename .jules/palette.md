# Palette's Journal

## 2024-05-20 - Centralized JS Confirmation
**Learning:** Replacing inline `onclick` handlers with data-attribute-driven JS listeners (`data-confirm`) enables better progressive enhancement and simpler CSP compliance.
**Action:** Use `.wpsc-confirm-trigger` and `data-confirm` for all future destructive actions instead of inline JS.

## 2024-05-21 - Custom Control Focus Management
**Learning:** Custom form controls (like Radio Cards and Switches) that hide the native input must provide explicit, high-contrast focus indicators for keyboard users.
**Action:** Use `:focus-visible` on the container or adjacent sibling selector (e.g., `input:focus + .slider`) to replicate the native focus ring using `--wpsc-primary-soft`.
