<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * Handles Speculative Loading (Prerendering/Prefetching).
 *
 * SOTA Implementation (2026):
 * 1. Uses native "Speculation Rules API" for Chrome/Edge (Prerender support).
 * 2. Uses lightweight IntersectionObserver/MouseOver fallback for Safari/Firefox.
 */
class SpeculativeLoader
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function initialize(): void
    {
        if (empty($this->settings["speculative_loading"])) {
            return;
        }

        add_action("wp_footer", [$this, "injectSpeculationRules"], 100);
    }

    public function injectSpeculationRules(): void
    {
        // Don't prefetch on admin pages or for logged-in users (optional, but safer)
        if (is_admin()) {
            return;
        }

        $mode = $this->settings["speculation_mode"] ?? "prerender"; // 'prefetch' or 'prerender'

        // 1. Modern API (Chrome 109+, Edge)
        // 'moderate' eagerness = Triggers on Hover (stays for >200ms) or Pointer Down.
        $rules = [
            $mode => [
                [
                    "source" => "document",
                    "where" => [
                        "and" => [
                            ["href_matches" => "/*"], // Match all local links
                            ["not" => ["href_matches" => "/wp-admin/*"]],
                            ["not" => ["href_matches" => "/wp-login.php*"]],
                            ["not" => ["href_matches" => "*logout*"]],
                        ],
                    ],
                    "eagerness" => "moderate",
                ],
            ],
        ];

        echo '<script type="speculationrules">' .
            json_encode($rules) .
            "</script>";

        // 2. Legacy Fallback (Safari, Firefox)
        // Uses standard <link rel="prefetch"> on hover
        $this->outputLegacyFallback();
    }

    private function outputLegacyFallback(): void
    {
        ?>
        <script id="wpsc-speculation-fallback">
            (function () {
                // Feature detect Speculation Rules. If supported, exit (let browser handle it).
                if (HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) return;

                const uniqueLinks = new Set();

                const prefetch = (url) => {
                    if (uniqueLinks.has(url)) return;
                    uniqueLinks.add(url);

                    const link = document.createElement('link');
                    link.rel = 'prefetch';
                    link.href = url;
                    document.head.appendChild(link);
                };

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const url = entry.target.href;
                            if (isSafeUrl(url)) prefetch(url);
                            observer.unobserve(entry.target);
                        }
                    });
                });

                const isSafeUrl = (url) => {
                    // Must be local, http/https, not admin
                    try {
                        const loc = window.location;
                        const dest = new URL(url);
                        if (dest.origin !== loc.origin) return false;
                        if (dest.protocol !== 'http:' && dest.protocol !== 'https:') return false;
                        if (dest.pathname.includes('wp-admin') || dest.pathname.includes('wp-login')) return false;
                        if (url.includes('logout') || url.includes('add-to-cart')) return false;
                        return true;
                    } catch (e) {
                        return false;
                    }
                };

                // Event Delegation for performance
                document.addEventListener('mouseover', (e) => {
                    const link = e.target.closest('a');
                    if (link && isSafeUrl(link.href)) {
                        prefetch(link.href);
                    }
                }, {
                    passive: true
                });

                document.addEventListener('touchstart', (e) => {
                    const link = e.target.closest('a');
                    if (link && isSafeUrl(link.href)) {
                        prefetch(link.href);
                    }
                }, {
                    passive: true
                });
            })();
        </script>
        <?php
    }
}
?>
