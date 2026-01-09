# Sentinel Journal

## 2024-05-22 - Server Configuration Tight Coupling
**Vulnerability:** Security features (XML-RPC blocking) were implicitly dependent on Performance features (HTML Cache) due to `.htaccess` management logic.
**Learning:** Configuration managers should handle features independently or check the aggregate of all relevant settings before removal.
**Prevention:** Use explicit "needs_configuration" logic that aggregates all dependencies (Cache, Security, CDN) before deciding to wipe config files.
