<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use DOMDocument;
use DOMElement;
use WPSCache\Contracts\HtmlProcessor;

/**
 * JavaScript Optimization: Defer and Delay script execution.
 *
 * Defer: Adds `defer` attribute to external scripts (execute after parse, before DOMContentLoaded).
 * Delay: Replaces script type with placeholder, loads on first user interaction.
 *
 * The bootloader fires synthetic DOMContentLoaded + load events after all delayed
 * scripts have executed, ensuring compatibility with libraries that depend on those events.
 */
final class JavaScriptOptimizer implements HtmlProcessor
{
    private array $settings;
    private string $exclusionRegex;

    /** Scripts that must NEVER be deferred/delayed (framework dependencies) */
    private const CRITICAL_EXCLUSIONS = [
        'jquery.js',
        'jquery.min.js',
        'jquery-core',
        'jquery-migrate',
        'wps-cache',
        'wp-polyfill',
    ];

    /** Minimum inline script size to bother delaying (bytes) */
    private const MIN_DELAY_SIZE = 100;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $exclusions = array_unique(array_merge(
            self::CRITICAL_EXCLUSIONS,
            $this->settings['excluded_js_execution'] ?? [],
        ));
        $quoted = array_map(fn($s) => preg_quote($s, '/'), $exclusions);
        $this->exclusionRegex = '/' . implode('|', $quoted) . '/i';
    }

    public function processDom(DOMDocument $dom): void
    {
        $scripts = $dom->getElementsByTagName('script');
        $hasDelayed = false;

        foreach (iterator_to_array($scripts) as $script) {
            $result = $this->processScriptNode($script);
            if ($result === 'delayed') {
                $hasDelayed = true;
            }
        }

        // Inject bootloader only if we actually delayed scripts
        if ($hasDelayed && !empty($this->settings['js_delay'])) {
            $this->injectBootloader($dom);
        }
    }

    public function process(string $html): string
    {
        if (
            empty($this->settings['js_delay']) &&
            empty($this->settings['js_defer'])
        ) {
            return $html;
        }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        $this->processDom($dom);
        return str_replace('<?xml encoding="utf-8" ?>', '', $dom->saveHTML());
    }

    /**
     * Processes a single <script> node. Returns 'delayed', 'deferred', or null.
     */
    private function processScriptNode(DOMElement $script): ?string
    {
        $type = $script->getAttribute('type');
        $src = $script->getAttribute('src');

        // Skip non-JavaScript types (JSON-LD, templates, etc.)
        if (
            $type !== '' &&
            !preg_match('/^(text\/javascript|application\/javascript|module)$/i', $type)
        ) {
            return null;
        }

        // Check exclusions against src AND inline content
        if ($src !== '' && $this->isExcluded($src)) {
            return null;
        }
        $content = $script->nodeValue ?? '';
        if ($content !== '' && $this->isExcluded($content)) {
            return null;
        }

        // ─── DELAY MODE ─────────────────────────────────────────
        if (!empty($this->settings['js_delay'])) {
            // Don't delay tiny inline scripts — the overhead exceeds any benefit
            if ($src === '' && strlen($content) < self::MIN_DELAY_SIZE) {
                return null;
            }

            // Preserve original type for module scripts
            $originalType = ($type === 'module') ? 'module' : 'text/javascript';

            $script->setAttribute('type', 'wpsc-delayed');
            $script->setAttribute('data-wpsc-type', $originalType);

            if ($src !== '') {
                $script->setAttribute('data-wpsc-src', $src);
                $script->removeAttribute('src');
            }

            return 'delayed';
        }

        // ─── DEFER MODE ─────────────────────────────────────────
        if (!empty($this->settings['js_defer'])) {
            if (
                $src !== '' &&
                !$script->hasAttribute('defer') &&
                !$script->hasAttribute('async')
            ) {
                $script->setAttribute('defer', 'defer');

                // Module scripts are already deferred by default,
                // but adding fetchpriority=low helps the browser prioritization
                if ($type !== 'module') {
                    $script->setAttribute('fetchpriority', 'low');
                }

                return 'deferred';
            }
        }

        return null;
    }

    /**
     * Injects the script bootloader that loads delayed scripts on user interaction.
     *
     * Features:
     * - Triggers on first user interaction (mouse, keyboard, scroll, touch)
     * - Falls back to loading after 8 seconds if no interaction
     * - Uses requestIdleCallback for non-blocking sequential loading
     * - Preserves original script type (text/javascript vs module)
     * - Fires synthetic DOMContentLoaded + load events after all scripts loaded
     * - Handles both src and inline scripts
     */
    private function injectBootloader(DOMDocument $dom): void
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return;
        }

        $script = $dom->createElement('script');
        $script->setAttribute('id', 'wpsc-bootloader');

        // Readable bootloader — minified inline for production
        $code = <<<'JS'
(function() {
    var fired = false;
    var events = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart'];

    function boot() {
        if (fired) return;
        fired = true;

        // Remove event listeners
        events.forEach(function(e) {
            window.removeEventListener(e, boot, {passive: true});
        });

        var scripts = document.querySelectorAll('script[type="wpsc-delayed"]');
        var index = 0;

        function next() {
            if (index >= scripts.length) {
                // All delayed scripts loaded — fire synthetic events
                // so libraries listening for DOMContentLoaded/load still work
                try {
                    document.dispatchEvent(new Event('DOMContentLoaded', {bubbles: true}));
                    window.dispatchEvent(new Event('load'));
                } catch(e) {}
                return;
            }
            loadScript(index);
        }

        function loadScript(i) {
            var old = scripts[i];
            var el = document.createElement('script');

            // Copy all attributes
            Array.from(old.attributes).forEach(function(attr) {
                var name = attr.name;
                var val = attr.value;

                if (name === 'type') return; // Skip placeholder type
                if (name === 'data-wpsc-src') { name = 'src'; }
                if (name === 'data-wpsc-type') {
                    el.setAttribute('type', val);
                    return;
                }
                el.setAttribute(name, val);
            });

            // If no data-wpsc-type was set, default to text/javascript
            if (!el.hasAttribute('type')) {
                el.setAttribute('type', 'text/javascript');
            }

            // Copy inline content
            if (old.text) {
                el.text = old.text;
            }

            // Chain: next script loads after current completes
            var advance = function() { index++; next(); };
            if (el.src) {
                el.onload = advance;
                el.onerror = advance;
            }

            old.parentNode.insertBefore(el, old);
            old.remove();

            // Inline scripts execute synchronously — advance immediately
            if (!el.src) {
                advance();
            }
        }

        // Start loading with idle callback for non-blocking behavior
        if ('requestIdleCallback' in window) {
            requestIdleCallback(next, {timeout: 1000});
        } else {
            setTimeout(next, 10);
        }
    }

    // Listen for first user interaction
    events.forEach(function(e) {
        window.addEventListener(e, boot, {passive: true});
    });

    // Fallback: load after 8 seconds even without interaction
    setTimeout(boot, 8000);
})();
JS;

        $script->nodeValue = $code;
        $body->appendChild($script);
    }

    private function isExcluded(string $str): bool
    {
        return preg_match($this->exclusionRegex, $str) === 1;
    }
}
