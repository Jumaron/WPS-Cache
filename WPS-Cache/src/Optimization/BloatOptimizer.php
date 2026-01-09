<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * Handles removal of WordPress bloat and Heartbeat API control.
 *
 * SOTA Strategy:
 * 1. Uses specific hooks to de-register scripts/styles before they load.
 * 2. Modifies Heartbeat API settings via JSON injection (no extra JS).
 * 3. Removes unnecessary HTTP headers and meta tags.
 */
class BloatOptimizer
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function initialize(): void
    {
        add_action("init", [$this, "runTweaks"]);
        add_action("init", [$this, "configureHeartbeat"]);

        // Remove Query Strings (runs on tag filter)
        if (!empty($this->settings["bloat_remove_query_strings"])) {
            add_filter("style_loader_src", [$this, "removeQueryString"]);
            add_filter("script_loader_src", [$this, "removeQueryString"]);
        }
    }

    public function runTweaks(): void
    {
        // 1. Disable Emojis (Adds ~6KB JS/CSS to every page)
        if (!empty($this->settings["bloat_disable_emojis"])) {
            remove_action("wp_head", "print_emoji_detection_script", 7);
            remove_action(
                "admin_print_scripts",
                "print_emoji_detection_script",
            );
            remove_action("wp_print_styles", "print_emoji_styles");
            remove_action("admin_print_styles", "print_emoji_styles");
            remove_filter("the_content_feed", "wp_staticize_emoji");
            remove_filter("comment_text_rss", "wp_staticize_emoji");
            remove_filter("wp_mail", "wp_staticize_emoji_for_email");
        }

        // 2. Disable Embeds (Prevents others from embedding your site + removes JS)
        if (!empty($this->settings["bloat_disable_embeds"])) {
            // Remove oEmbed-specific JavaScript from the front-end and back-end.
            remove_action("wp_head", "wp_oembed_add_host_js");
            // Remove the discovery links.
            remove_action("wp_head", "wp_oembed_add_discovery_links");
            // Remove oEmbed-specific JavaScript from the front-end and back-end.
            add_filter("tiny_mce_plugins", function ($plugins) {
                return array_diff($plugins, ["wpembed"]);
            });
            // Remove all embeds rewrite rules.
            add_filter("rewrite_rules_array", function ($rules) {
                foreach ($rules as $rule => $rewrite) {
                    if (false !== strpos($rewrite, "embed=true")) {
                        unset($rules[$rule]);
                    }
                }
                return $rules;
            });
        }

        // 3. Disable XML-RPC (Security + Performance)
        if (!empty($this->settings["bloat_disable_xmlrpc"])) {
            add_filter("xmlrpc_enabled", "__return_false");
            // Remove X-Pingback header
            add_filter("wp_headers", function ($headers) {
                unset($headers["X-Pingback"]);
                return $headers;
            });
        }

        // 3.5. Disable User Enumeration (Security)
        if (!empty($this->settings["bloat_disable_user_enumeration"])) {
            // Block Author Archives (/?author=N)
            add_action("template_redirect", function () {
                if (is_author() && isset($_GET["author"])) {
                    wp_redirect(home_url(), 301);
                    exit();
                }
            });

            // Block REST API Users Endpoint
            add_filter("rest_endpoints", function ($endpoints) {
                if (!is_user_logged_in() && isset($endpoints["/wp/v2/users"])) {
                    unset($endpoints["/wp/v2/users"]);
                    unset($endpoints["/wp/v2/users/(?P<id>[\d]+)"]);
                }
                return $endpoints;
            });
        }

        // 4. Hide WP Version (Security)
        if (!empty($this->settings["bloat_hide_wp_version"])) {
            remove_action("wp_head", "wp_generator");
            add_filter("the_generator", "__return_empty_string");
        }

        // 5. Remove wlwmanifest & RSD (Blogging tools from 2010)
        if (!empty($this->settings["bloat_remove_wlw_rsd"])) {
            remove_action("wp_head", "rsd_link");
            remove_action("wp_head", "wlwmanifest_link");
        }

        // 6. Remove Shortlink
        if (!empty($this->settings["bloat_remove_shortlink"])) {
            remove_action("wp_head", "wp_shortlink_wp_head");
        }

        // 7. Remove RSS Feeds (Optional, rarely used on corp sites)
        if (!empty($this->settings["bloat_disable_rss"])) {
            remove_action("wp_head", "feed_links", 2);
            remove_action("wp_head", "feed_links_extra", 3);
        }

        // 8. Self Pingbacks
        if (!empty($this->settings["bloat_disable_self_pingbacks"])) {
            add_action("pre_ping", function (&$links) {
                $home = get_option("home");
                foreach ($links as $l => $link) {
                    if (strpos($link, $home) === 0) {
                        unset($links[$l]);
                    }
                }
            });
        }

        // 9. Dequeue Scripts (Frontend Only)
        if (!is_admin()) {
            add_action(
                "wp_enqueue_scripts",
                function () {
                    // Remove jQuery Migrate
                    if (
                        !empty($this->settings["bloat_remove_jquery_migrate"])
                    ) {
                        global $wp_scripts;
                        if (isset($wp_scripts->registered["jquery"])) {
                            $script = $wp_scripts->registered["jquery"];
                            if ($script->deps) {
                                $script->deps = array_diff($script->deps, [
                                    "jquery-migrate",
                                ]);
                            }
                        }
                    }

                    // Remove Dashicons (Except for logged-in users who need Admin Bar)
                    if (
                        !empty($this->settings["bloat_remove_dashicons"]) &&
                        !is_user_logged_in()
                    ) {
                        wp_dequeue_style("dashicons");
                    }
                },
                99,
            );
        }
    }

    /**
     * SOTA Heartbeat Control.
     * Hooks into the heartbeat settings filter to adjust frequency/disable.
     */
    public function configureHeartbeat(): void
    {
        $freq = (int) ($this->settings["heartbeat_frequency"] ?? 15);
        // Ensure valid range (15-120) if not 0 (disabled in logic below)
        if ($freq < 15) {
            $freq = 15;
        }

        if ($freq > 120) {
            $freq = 120;
        }

        add_filter("heartbeat_settings", function ($settings) use ($freq) {
            $settings["interval"] = $freq;
            return $settings;
        });

        // Disable logic
        if (!empty($this->settings["heartbeat_disable_admin"])) {
            if (is_admin() && !defined("DOING_AJAX")) {
                wp_deregister_script("heartbeat");
            }
        }

        if (!empty($this->settings["heartbeat_disable_dashboard"])) {
            // Dashboard is technically admin, but we check specific screen if needed
            // Usually "Admin" covers dashboard. Competitors split "Backend" vs "Post Editor".
            // Let's stick to strict locations.
            global $pagenow;
            if ($pagenow === "index.php" && is_admin()) {
                wp_deregister_script("heartbeat");
            }
        }

        if (!empty($this->settings["heartbeat_disable_frontend"])) {
            if (!is_admin()) {
                wp_deregister_script("heartbeat");
            }
        }

        // Editor check (Post Edit Screen)
        if (!empty($this->settings["heartbeat_disable_editor"])) {
            global $pagenow;
            if (
                ($pagenow === "post.php" || $pagenow === "post-new.php") &&
                is_admin()
            ) {
                wp_deregister_script("heartbeat");
            }
        }
    }

    public function removeQueryString(string $src): string
    {
        if (
            strpos($src, "?ver=") !== false ||
            strpos($src, "&ver=") !== false
        ) {
            $src = remove_query_arg("ver", $src);
        }
        return $src;
    }
}
