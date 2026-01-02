<?php

declare(strict_types=1);

namespace WPSCache\Admin\UI;

class TabManager
{
    private array $tabs = [];

    public function __construct()
    {
        $this->initializeTabs();
    }

    private function initializeTabs(): void
    {
        $this->tabs = [
            "dashboard" => [
                "label" => __("Dashboard", "wps-cache"),
                "icon" => "dashicons-dashboard",
                "order" => 10,
            ],
            "cache" => [
                "label" => __("Cache Rules", "wps-cache"),
                "icon" => "dashicons-database",
                "order" => 20,
            ],
            "media" => [
                "label" => __("Media", "wps-cache"),
                "icon" => "dashicons-images-alt",
                "order" => 25,
            ],
            "cdn" => [
                // NEW
                "label" => __("CDN", "wps-cache"),
                "icon" => "dashicons-cloud",
                "order" => 28,
            ],
            "css_js" => [
                "label" => __("File Optimization", "wps-cache"),
                "icon" => "dashicons-editor-code",
                "order" => 30,
            ],
            "tweaks" => [
                "label" => __("Tweaks", "wps-cache"),
                "icon" => "dashicons-controls-volumeon",
                "order" => 32,
            ],
            "database" => [
                "label" => __("Database", "wps-cache"),
                "icon" => "dashicons-archive",
                "order" => 35,
            ],
            "analytics" => [
                "label" => __("Analytics", "wps-cache"),
                "icon" => "dashicons-chart-bar",
                "order" => 40,
            ],
            "tools" => [
                "label" => __("Tools", "wps-cache"),
                "icon" => "dashicons-admin-tools",
                "order" => 50,
            ],
            "advanced" => [
                "label" => __("Advanced", "wps-cache"),
                "icon" => "dashicons-admin-settings",
                "order" => 60,
            ],
        ];

        uasort($this->tabs, fn($a, $b) => $a["order"] <=> $b["order"]);
    }

    public function getCurrentTab(): string
    {
        return isset($_GET["tab"]) &&
            array_key_exists($_GET["tab"], $this->tabs)
            ? sanitize_key($_GET["tab"])
            : "dashboard";
    }

    public function renderSidebar(string $current): void
    {
        echo '<nav class="wpsc-nav">';
        foreach ($this->tabs as $key => $data) {

            $isActive = $current === $key;
            $activeClass = $isActive ? "active" : "";
            $ariaCurrent = $isActive ? ' aria-current="page"' : "";
            $url = add_query_arg(
                ["page" => "wps-cache", "tab" => $key],
                admin_url("admin.php"),
            );
            ?>
            <a href="<?php echo esc_url(
                $url,
            ); ?>" class="wpsc-nav-item <?php echo esc_attr(
    $activeClass,
); ?>"<?php echo $ariaCurrent; ?>>
                <span class="dashicons <?php echo esc_attr(
                    $data["icon"],
                ); ?>" aria-hidden="true"></span>
                <?php echo esc_html($data["label"]); ?>
            </a>
<?php
        }
        echo "</nav>";
    }
}
