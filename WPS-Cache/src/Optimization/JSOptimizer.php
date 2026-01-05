<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

use DOMDocument;
use DOMElement;

/**
 * Handles JavaScript Delaying and Deferring (SOTA Web Vitals Optimization).
 * Uses DOMDocument for robust parsing.
 */
class JSOptimizer
{
    private array $settings;
    private string $exclusionRegex;

    private const CRITICAL_EXCLUSIONS = [
        "jquery.js",
        "jquery.min.js",
        "jquery-core",
        "wps-cache",
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;

        $exclusions = array_merge(
            self::CRITICAL_EXCLUSIONS,
            $this->settings["excluded_js"] ?? [],
        );
        $exclusions = array_unique($exclusions);

        $quoted = array_map(fn($s) => preg_quote($s, "/"), $exclusions);
        $this->exclusionRegex = "/" . implode("|", $quoted) . "/i";
    }

    // New shared method
    public function processDom(DOMDocument $dom): void
    {
        $scripts = $dom->getElementsByTagName("script");
        $modified = false;

        // Optimization: iterate via foreach on a snapshot array to avoid O(N^2) complexity of item($i)
        // and prevent skipping elements due to live DOM updates.
        foreach (iterator_to_array($scripts) as $script) {
            /** @var DOMElement $script */
            $this->processScriptNode($script);
            if (
                $script->hasAttribute("type") &&
                $script->getAttribute("type") === "wpsc-delayed"
            ) {
                $modified = true;
            }
        }

        if ($modified && !empty($this->settings["js_delay"])) {
            $this->injectBootloader($dom);
        }
    }

    // Kept for backward compat or standalone usage (wraps processDom)
    public function process(string $html): string
    {
        if (
            empty($this->settings["js_delay"]) &&
            empty($this->settings["js_defer"])
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

        $output = $dom->saveHTML();
        return str_replace('<?xml encoding="utf-8" ?>', "", $output);
    }

    private function processScriptNode(DOMElement $script): void
    {
        $type = $script->getAttribute("type");
        $src = $script->getAttribute("src");

        if (
            $type &&
            !preg_match(
                '/^(text\/javascript|application\/javascript|module)$/i',
                $type,
            )
        ) {
            return;
        }

        $checkStr = $src . ($script->nodeValue ?? "");
        if ($this->isExcluded($checkStr)) {
            return;
        }

        // Delay Strategy
        if (!empty($this->settings["js_delay"])) {
            $script->setAttribute("type", "wpsc-delayed");
            if ($src) {
                $script->setAttribute("data-wpsc-src", $src);
                $script->removeAttribute("src");
            }
            return;
        }

        // Defer Strategy
        if (!empty($this->settings["js_defer"])) {
            if (
                $src &&
                !$script->hasAttribute("defer") &&
                !$script->hasAttribute("async")
            ) {
                $script->setAttribute("defer", "defer");
            }
        }
    }

    private function injectBootloader(DOMDocument $dom): void
    {
        $body = $dom->getElementsByTagName("body")->item(0);
        if (!$body) {
            return;
        }

        $script = $dom->createElement("script");
        $script->setAttribute("id", "wpsc-bootloader");
        // SOTA Bootloader Code
        $code = <<<'JS'
        (function(){let t=!1;const e=["mousedown","mousemove","keydown","scroll","touchstart"];function n(){if(t)return;t=!0,e.forEach(t=>window.removeEventListener(t,n,{passive:!0}));const o=document.querySelectorAll('script[type="wpsc-delayed"]'),c=t=>{if(t>=o.length)return;"requestIdleCallback"in window?requestIdleCallback(()=>r(t),{timeout:1e3}):setTimeout(()=>r(t),10)},r=t=>{const e=o[t],n=()=>c(t+1),r=document.createElement("script");[...e.attributes].forEach(t=>{let e=t.name,o=t.value;"type"===e&&(e="type",o="text/javascript"),"data-wpsc-src"===e&&(e="src"),r.setAttribute(e,o)}),r.text=e.text,r.src?(r.onload=n,r.onerror=n):n(),e.parentNode.insertBefore(r,e),e.remove()};c(0)}e.forEach(t=>window.addEventListener(t,n,{passive:!0})),setTimeout(n,8e3)})();
        JS;
        $script->nodeValue = $code;
        $body->appendChild($script);
    }

    private function isExcluded(string $str): bool
    {
        return preg_match($this->exclusionRegex, $str) === 1;
    }
}
