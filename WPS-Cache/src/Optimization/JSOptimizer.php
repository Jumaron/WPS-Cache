<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

class JSOptimizer
{
    private array $settings;

    // Scripts that should NEVER be delayed/deferred to prevent breaking dependencies
    private const CRITICAL_EXCLUSIONS = [
        'jquery.js',
        'jquery.min.js',
        'jquery-core',
        'jquery-migrate',
        'wp-includes/js', // Protects wp-embed, wp-emoji, wp-polyfill, etc.
        'wps-cache',      // Our own scripts

        // Astra Theme specific exclusions to prevent "flexibility is not defined"
        'flexibility.min.js',
        'flexibility.js',
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function process(string $html): string
    {
        // 1. Delay Strategy (SOTA)
        // If enabled, this processes scripts first.
        if (!empty($this->settings['js_delay'])) {
            $html = $this->applyDelay($html);
        }

        // 2. Defer Strategy (Fallback or Independent)
        // Only apply defer if delay didn't touch the script (avoid double handling)
        if (!empty($this->settings['js_defer'])) {
            $html = $this->applyDefer($html);
        }

        return $html;
    }

    /**
     * SOTA Defer: 
     * Adds 'defer' to external scripts.
     * Does NOT wrap inline scripts to preserve global variable scope (fixes 'astra is not defined').
     */
    private function applyDefer(string $html): string
    {
        return preg_replace_callback(
            '/<script\s+([^>]+)>(?:<\/script>)?/i',
            function ($matches) {
                $attrs = $matches[1];

                // Only defer valid JS with a src attribute
                if (stripos($attrs, 'src=') === false) {
                    return $matches[0];
                }

                // Safety checks
                if (!$this->isExecutableJS($attrs)) {
                    return $matches[0];
                }

                // If it's already delayed by previous step, skip
                if (str_contains($attrs, 'wpsc-delayed')) {
                    return $matches[0];
                }

                if (
                    stripos($attrs, 'defer') !== false ||
                    stripos($attrs, 'async') !== false ||
                    $this->isExcluded($attrs)
                ) {
                    return $matches[0];
                }

                // Add defer attribute
                return str_replace('<script ', '<script defer ', $matches[0]);
            },
            $html
        );
    }

    private function applyDelay(string $html): string
    {
        // 1. Rewrite script tags
        $html = preg_replace_callback(
            '/<script\s*(.*?)>(.*?)<\/script>/is',
            function ($matches) {
                $attrs = $matches[1];
                $content = $matches[2];

                // Safety checks
                if (!$this->isExecutableJS($attrs)) {
                    return $matches[0];
                }

                if ($this->isExcluded($attrs)) {
                    return $matches[0];
                }

                // Detect module
                $is_module = preg_match('/type=["\']module["\']/i', $attrs);

                // Remove existing type and src
                $new_attrs = preg_replace('/type=["\'][^"\']*["\']/', '', $attrs);
                $new_attrs = preg_replace('/src=["\']([^"\']*)["\']/', 'data-wpsc-src="$1"', $new_attrs);

                // Set new delay type
                $delay_type = $is_module ? 'wpsc-delayed-module' : 'wpsc-delayed';
                $new_attrs = trim($new_attrs) . ' type="' . $delay_type . '"';

                return "<script {$new_attrs}>{$content}</script>";
            },
            $html
        );

        // 2. Inject Bootloader if needed
        if (str_contains($html, 'type="wpsc-delayed')) {
            $bootloader = $this->getBootloaderScript();
            if (str_contains($html, '</body>')) {
                $html = str_replace('</body>', $bootloader . '</body>', $html);
            } else {
                $html .= $bootloader;
            }
        }

        return $html;
    }

    private function isExecutableJS(string $attrs): bool
    {
        // If no type is specified, it is JS
        if (!preg_match('/type=["\']([^"\']*)["\']/i', $attrs, $matches)) {
            return true;
        }

        $type = strtolower($matches[1]);
        $valid_types = [
            'text/javascript',
            'application/javascript',
            'application/ecmascript',
            'module'
        ];

        return in_array($type, $valid_types, true);
    }

    private function isExcluded(string $attrs): bool
    {
        $exclusions = array_merge(
            self::CRITICAL_EXCLUSIONS,
            $this->settings['excluded_js'] ?? []
        );

        foreach ($exclusions as $exclusion) {
            if (stripos($attrs, $exclusion) !== false) {
                return true;
            }
        }
        return false;
    }

    private function getBootloaderScript(): string
    {
        return <<<'JS'
<script>
(function() {
    const events = ["keydown", "mousemove", "touchmove", "touchstart", "wheel"];
    let triggered = false;

    function wpscLoad() {
        if (triggered) return;
        triggered = true;
        
        events.forEach(e => window.removeEventListener(e, wpscLoad, {passive: true}));
        
        const scripts = document.querySelectorAll('script[type^="wpsc-delayed"]');
        if (scripts.length === 0) return;

        const loadNext = (index) => {
            if (index >= scripts.length) {
                window.dispatchEvent(new Event('DOMContentLoaded'));
                window.dispatchEvent(new Event('load'));
                return;
            }

            const script = scripts[index];
            const newScript = document.createElement('script');
            
            [...script.attributes].forEach(attr => {
                let name = attr.name;
                let value = attr.value;
                
                if (name === 'type') {
                    value = (script.getAttribute('type') === 'wpsc-delayed-module') ? 'module' : 'text/javascript';
                } else if (name === 'data-wpsc-src') {
                    name = 'src';
                }
                newScript.setAttribute(name, value);
            });

            if (script.innerHTML) {
                newScript.innerHTML = script.innerHTML;
            }

            script.parentNode.replaceChild(newScript, script);

            if (newScript.hasAttribute('src')) {
                newScript.onload = () => loadNext(index + 1);
                newScript.onerror = () => loadNext(index + 1);
            } else {
                loadNext(index + 1);
            }
        };

        loadNext(0);
    }

    events.forEach(e => window.addEventListener(e, wpscLoad, {passive: true}));
    setTimeout(wpscLoad, 8000);
})();
</script>
JS;
    }
}
