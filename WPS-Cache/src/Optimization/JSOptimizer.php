<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * Handles JavaScript Delaying and Deferring (SOTA Web Vitals Optimization).
 * Can delay execution until user interaction (scroll/click).
 */
class JSOptimizer
{
    private array $settings;
    private string $exclusionRegex;

    // Critical scripts that should usually be excluded from delay
    private const CRITICAL_EXCLUSIONS = [
        "jquery.js",
        "jquery.min.js",
        "jquery-core",
        "wps-cache", // exclude self
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;

        // Compile exclusions into a single regex for O(1) matching
        $exclusions = array_merge(
            self::CRITICAL_EXCLUSIONS,
            $this->settings["excluded_js"] ?? [],
        );
        $exclusions = array_unique($exclusions); // Deduplicate

        // Escape for regex and join
        $quoted = array_map(fn($s) => preg_quote($s, "/"), $exclusions);
        $this->exclusionRegex = "/" . implode("|", $quoted) . "/i";
    }

    public function process(string $html): string
    {
        // 1. Check if feature is enabled
        if (
            empty($this->settings["js_delay"]) &&
            empty($this->settings["js_defer"])
        ) {
            return $html;
        }

        // 2. Parse Scripts
        $html = preg_replace_callback(
            "/<script\s*(.*?)>(.*?)<\/script>/is",
            [$this, "processScriptTag"],
            $html,
        );

        // 3. Inject Bootloader if delaying is active
        if (!empty($this->settings["js_delay"])) {
            $html = str_replace(
                "</body>",
                $this->getBootloader() . "</body>",
                $html,
            );
        }

        return $html;
    }

    private function processScriptTag(array $matches): string
    {
        $attrs = $matches[1];
        $content = $matches[2];
        $fullTag = $matches[0];

        // Skip non-JS (e.g., JSON-LD, template)
        if (
            preg_match(
                '/type=["\'](?!text\/javascript|application\/javascript|module)[^"\']*["\']/i',
                $attrs,
            )
        ) {
            return $fullTag;
        }

        // Skip Excluded Scripts
        if ($this->isExcluded($attrs)) {
            return $fullTag;
        }

        // Strategy A: DELAY (User Interaction)
        if (!empty($this->settings["js_delay"])) {
            // Change type to prevent execution
            $newAttrs = preg_replace('/type=["\'][^"\']*["\']/', "", $attrs);

            // Handle src -> data-src
            if (stripos($newAttrs, "src=") !== false) {
                $newAttrs = str_replace("src=", "data-wpsc-src=", $newAttrs);
            }

            return sprintf(
                '<script %s type="wpsc-delayed">%s</script>',
                trim($newAttrs),
                $content,
            );
        }

        // Strategy B: DEFER (Standard)
        if (!empty($this->settings["js_defer"])) {
            // Only defer external scripts that aren't already deferred/async
            if (
                stripos($attrs, "src=") !== false &&
                stripos($attrs, "defer") === false &&
                stripos($attrs, "async") === false
            ) {
                return str_replace("<script ", "<script defer ", $fullTag);
            }
        }

        return $fullTag;
    }

    private function isExcluded(string $attributes): bool
    {
        // Optimized: Single regex match instead of loop + array_merge
        return preg_match($this->exclusionRegex, $attributes) === 1;
    }

    /**
     * SOTA Bootloader:
     * Waits for user interaction, then loads delayed scripts sequentially.
     * Maintains execution order.
     */
    private function getBootloader(): string
    {
        return <<<'JS'
        <script id="wpsc-bootloader">
        (function() {
            let triggered = false;
            const events = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart'];

            function wakeUp() {
                if (triggered) return;
                triggered = true;

                events.forEach(e => window.removeEventListener(e, wakeUp, {passive: true}));

                // Load Scripts
                const scripts = document.querySelectorAll('script[type="wpsc-delayed"]');
                const loadScript = (i) => {
                    if (i >= scripts.length) return;

                    const original = scripts[i];
                    const next = () => loadScript(i + 1);

                    const clone = document.createElement('script');
                    [...original.attributes].forEach(attr => {
                        let name = attr.name;
                        let val = attr.value;
                        if (name === 'type') {
                            name = 'type'; val = 'text/javascript';
                        }
                        if (name === 'data-wpsc-src') {
                            name = 'src';
                        }
                        clone.setAttribute(name, val);
                    });

                    clone.text = original.text;

                    if (clone.src) {
                        clone.onload = next;
                        clone.onerror = next;
                    } else {
                        // Inline scripts run immediately
                        setTimeout(next, 0);
                    }

                    original.parentNode.insertBefore(clone, original);
                    original.remove();
                };

                loadScript(0);
            }

            events.forEach(e => window.addEventListener(e, wakeUp, {passive: true}));

            // Fallback: Wake up after 8 seconds anyway
            setTimeout(wakeUp, 8000);
        })();
        </script>
        JS;
    }
}
