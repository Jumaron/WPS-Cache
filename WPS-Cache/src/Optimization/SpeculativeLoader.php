<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

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
        add_action("wp_footer", [$this, "injectSmartSpeculation"], 100);
    }

    public function injectSmartSpeculation(): void
    {
        if (is_admin()) {
            return;
        }

        $rules = [
            "prerender" => [
                [
                    "source" => "document",
                    "where" => [
                        "and" => [
                            ["href_matches" => "/*"],
                            ["not" => ["href_matches" => "/wp-admin/*"]],
                            ["not" => ["href_matches" => "*wp-login*"]],
                            ["not" => ["href_matches" => "*logout*"]],
                            ["not" => ["href_matches" => "*add-to-cart*"]],
                        ],
                    ],
                    "eagerness" => "moderate",
                ],
            ],
        ];

        echo '<script type="speculationrules">' .
            json_encode($rules) .
            "</script>";// Fallback for Safari/Firefox
        ?>
        <script id="wpsc-smart-speculation">
        (function(){
            if(HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) return;
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
                try {
                    const loc = window.location;
                    const dest = new URL(url);
                    if (dest.origin !== loc.origin) return false;
                    if (dest.protocol !== 'http:' && dest.protocol !== 'https:') return false;
                    if (dest.pathname.includes('wp-admin') || dest.pathname.includes('wp-login')) return false;
                    if (url.includes('logout') || url.includes('add-to-cart')) return false;
                    return true;
                } catch (e) { return false; }
            };
            document.addEventListener('mouseover', (e) => {
                const link = e.target.closest('a');
                if (link && isSafeUrl(link.href)) prefetch(link.href);
            }, {passive: true});
        })();
        </script>
        <?php
    }
}
