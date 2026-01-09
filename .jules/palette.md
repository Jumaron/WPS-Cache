# Palette's Journal

## 2024-05-22 - Accessibility in Admin Menus
**Learning:** WordPress admin menus often have icon-only buttons or links that lack accessible names. Adding `aria-label` or `screen-reader-text` is crucial.
**Action:** Always check for `aria-label` on icon-only controls.

## 2024-05-23 - Visual Feedback
**Learning:** Users need immediate feedback for actions like "Purge Cache". Using generic "Loading..." is okay, but specific feedback like "Purging..." or "Saved!" is better.
**Action:** Implement state specific messages for async actions.

## 2024-05-24 - Reusable Loading States
**Learning:** Hardcoded loading text like "Saving..." in global JS handlers prevents reuse for other actions (e.g., "Refreshing...").
**Action:** Use `data-loading-text` attribute on buttons to allow context-specific loading messages while keeping the JS handler generic.

## 2024-05-25 - Preventative UX
**Learning:** Instead of allowing users to click a button and then showing an error message (like "Please select an item"), disable the button until the condition is met. This reduces cognitive load and frustration.
**Action:** Use `disabled` state on buttons that require user input, and update state via event listeners.

## 2024-05-26 - Radio Groups for Small Sets
**Learning:** Dropdowns hide options and require two clicks to change. For small option sets (2-4 items), Radio Groups are superior as they expose all options immediately and allow single-click changes.
**Action:** Use `renderRadioGroup` (or similar pattern) instead of `<select>` when there are 4 or fewer mutually exclusive options.

## 2024-05-27 - Bulk Actions in Lists
**Learning:** Requiring users to manually select 5+ checkboxes individually causes friction. A single "Select All" toggle drastically improves usability for batch operations.
**Action:** Always provide "Select All / Deselect All" controls for lists with multiple selectable items.

## 2024-05-28 - Protecting Client-Side Processes
**Learning:** Client-side processes (like preloading) stop immediately if the tab is closed. Users often close tabs habitually.
**Action:** Use `beforeunload` to warn users during active client-side batch operations.

## 2024-05-29 - Input Constraints & Keyboards
**Learning:** Generic text/number inputs allow invalid data and don't trigger specialized mobile keyboards. Using `type="url"` and `min`/`max` attributes provides immediate feedback and better mobile UX.
**Action:** Always apply `min`, `max`, and specific `type` attributes to input fields where applicable.

## 2024-05-30 - Technical Inputs & Spellchecking
**Learning:** Technical inputs like API keys, hostnames, and IDs often trigger browser spellchecking (red squiggles) and mobile autocorrect, causing frustration and potential data entry errors.
**Action:** Always add `spellcheck="false"`, `autocorrect="off"`, and `autocapitalize="none"` to technical input fields.

## 2024-05-31 - Readonly Technical Inputs
**Learning:** Readonly textareas containing technical data (logs, reports) can still trigger spellcheck in some browsers or contexts, distracting from the data.
**Action:** Apply `spellcheck="false"`, `autocorrect="off"`, and `autocapitalize="none"` even to readonly technical textareas.

## 2024-05-21 - Consistent Button Icons
**Learning:** When most primary actions in an interface have icons, a text-only primary button feels unpolished and less noticeable. Consistent iconography helps users quickly scan for actions.
**Action:** Ensure all primary action buttons have an accompanying icon that represents the action.

## 2024-05-23 - Server-Side Flash Message Accessibility
**Learning:** Flash messages rendered via PHP (like `WPSCache\Admin\UI\NoticeManager`) often appear silently to screen reader users after a page reload. Adding explicit `role="alert"` (for errors) and `role="status"` (for success/warnings) ensures these critical updates are announced immediately upon page load, matching the experience of sighted users.
**Action:** Always verify that server-rendered notifications include appropriate ARIA roles, not just client-side injected ones.

## 2024-05-22 - [Notice Dismissal UX]
**Learning:** Removing elements from the DOM on dismissal can cause focus to be lost, disorienting screen reader users.
**Action:** Always animate the removal (fade out) to give visual feedback, and use a live region announcement (e.g., "Notice dismissed") to confirm the action to non-visual users.

## 2024-05-22 - [Switch Toggle Accessibility]
**Learning:** `role="switch"` inputs are robust, but explicit `aria-checked` attributes are recommended by MDN for strict compliance, even if browsers often map the native checked state automatically.
**Action:** Add `aria-checked` to switch inputs and update it dynamically via JS when the state changes.

## 2024-06-01 - Radio Group Descriptions
**Learning:** When using radio groups to replace select dropdowns, the associated description text can be missed by screen readers if not explicitly linked.
**Action:** Always add `aria-describedby` to the `role="radiogroup"` container pointing to the description element.
