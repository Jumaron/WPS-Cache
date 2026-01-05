# Palette's Journal

## 2024-05-22 - Accessibility in Admin Menus
**Learning:** WordPress admin menus often have icon-only buttons or links that lack accessible names. Adding `aria-label` or `screen-reader-text` is crucial.
**Action:** Always check for `aria-label` on icon-only controls.

## 2024-05-23 - Visual Feedback
**Learning:** Users need immediate feedback for actions like "Purge Cache". Using generic "Loading..." is okay, but specific feedback like "Purging..." or "Saved!" is better.
**Action:** Implement specific state messages for async actions.

## 2024-05-24 - Reusable Loading States
**Learning:** Hardcoded loading text like "Saving..." in global JS handlers prevents reuse for other actions (e.g., "Refreshing...").
**Action:** Use `data-loading-text` attribute on buttons to allow context-specific loading messages while keeping the JS handler generic.
