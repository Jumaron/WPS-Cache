# Palette's Journal

## 2024-05-20 - Centralized JS Confirmation
**Learning:** Replacing inline `onclick` handlers with data-attribute-driven JS listeners (`data-confirm`) enables better progressive enhancement and simpler CSP compliance.
**Action:** Use `.wpsc-confirm-trigger` and `data-confirm` for all future destructive actions instead of inline JS.

## 2024-05-21 - Custom Control Focus Management
**Learning:** Custom form controls (like Radio Cards and Switches) that hide the native input must provide explicit, high-contrast focus indicators for keyboard users.
**Action:** Use `:focus-visible` on the container or adjacent sibling selector (e.g., `input:focus + .slider`) to replicate the native focus ring using `--wpsc-primary-soft`.

## 2024-05-22 - Password Input State
**Learning:** Empty password fields for saved credentials cause user anxiety ("Did it save?").
**Action:** Use `placeholder="••••••••"` to indicate a saved value while keeping the actual value hidden (empty) in the input.

## 2025-10-31 - ARIA Switch Initialization
**Learning:** The `switch` role requires an explicit `aria-checked` attribute on page load. Relying solely on JavaScript change listeners leaves the initial accessibility state undefined.
**Action:** Always render `aria-checked="<?php echo $checked ? 'true' : 'false'; ?>"` server-side for any input using `role="switch"`.
