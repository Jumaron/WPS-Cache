## 2024-05-28 - Missing Function Level Authorization in Analytics
**Vulnerability:** The `AnalyticsManager::render` method processed POST requests to delete a transient (`wpsc_stats_cache`) without verifying user capabilities (`manage_options`). While the calling code (`AdminPanelManager`) performed a check, relying on the router for authorization is fragile (Defense in Depth violation). If the method were reused or the router changed, the vulnerability would be exposed.
**Learning:** Controller methods that mutate state (handling POST/PUT/DELETE) must *always* explicitly verify authorization, even if the routing layer also performs a check. Do not assume the context in which a method is called.
**Prevention:** Add `current_user_can('manage_options')` checks inside all POST handling logic within controller methods.

## 2024-05-23 - Missing Permissions-Policy Header
**Vulnerability:** The application was missing the `Permissions-Policy` header, which allows controlling access to sensitive browser features like camera, microphone, and payment API.
**Learning:** Default WordPress installations or plugins often neglect this header, leaving users vulnerable to compromised plugins or XSS using these APIs silently.
**Prevention:** Always include a strict `Permissions-Policy` header in the security headers configuration, defaulting to denying sensitive features unless explicitly required.
