# Palette's Journal - Critical Learnings

## 2024-05-24 - Async Button Labels
**Learning:** Async buttons should preserve their original label text in `dataset.originalText` before entering loading or disabled states.
**Action:** Always check if `dataset.originalText` is set before overwriting it, and restore it when the action completes.

## 2024-05-24 - Icon-Only Accessibility
**Learning:** Icon-only buttons (like dismiss or copy) often lack text labels, making them inaccessible to screen readers.
**Action:** Ensure all icon-only buttons have an `aria-label` or screen-reader-only text.

## 2024-05-24 - Focus Visible
**Learning:** Custom components often miss the `:focus-visible` styles that browsers provide by default, leading to poor keyboard navigation.
**Action:** Add explicit `:focus-visible` styles using `box-shadow` rings (matching the brand color) to all interactive custom elements.

## 2024-05-24 - Placeholders in Technical Forms
**Learning:** Technical configuration forms (like Redis/CDN settings) often confuse users about the expected format (e.g., "127.0.0.1" vs "localhost").
**Action:** Always provide explicit examples in the `placeholder` attribute for technical inputs to reduce cognitive load and validation errors.

## 2024-05-24 - Manual Settings vs Renderer
**Learning:** Manually constructing setting rows in loops (like database cleanup items) often leads to missing accessibility attributes (like `aria-describedby`) that the standardized `SettingsRenderer` handles automatically.
**Action:** When manually looping to render inputs, explicitly replicate the accessibility attributes (ID generation and `aria-describedby`) that the `SettingsRenderer` would otherwise provide.

## 2024-05-24 - Async Link Feedback
**Learning:** Destructive actions triggered by links (like "Purge All") often rely on native `confirm()` blocking dialogs with no subsequent visual feedback during the network request, leaving users unsure if the action is processing.
**Action:** Convert such links to use `role="button"` and handle the click event in JavaScript to show a non-blocking "Loading..." state (e.g., changing text to "Purging..." and disabling interaction) immediately after confirmation, providing clear system status visibility.
