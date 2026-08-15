<?php

declare(strict_types=1);

namespace WPSCache\Admin\UI;

class TabManager
{
    private array $tabs = [];

    public function __construct()
    {
    }

    private function getTabs(): array
    {
        if (empty($this->tabs)) {
            $this->tabs = [

            "dashboard" => [
                "label" => __("Dashboard", "wps-cache"),
                "icon" => "dashicons-dashboard",
                "order" => 10,
                "group" => "Overview",
            ],
            "cache" => [
                "label" => __("Cache", "wps-cache"),
                "icon" => "dashicons-database",
                "order" => 20,
                "group" => "Optimize",
            ],
            "delivery" => [
                "label" => __("Cache Delivery", "wps-cache"),
                "icon" => "dashicons-randomize",
                "order" => 25,
                "group" => "Optimize",
            ],
            "css_js" => [
                "label" => __("CSS & JavaScript", "wps-cache"),
                "icon" => "dashicons-editor-code",
                "order" => 30,
                "group" => "Optimize",
            ],
            "experience" => [
                "label" => __("Frontend", "wps-cache"),
                "icon" => "dashicons-layout",
                "order" => 35,
                "group" => "Optimize",
            ],
            "media" => [
                "label" => __("Media", "wps-cache"),
                "icon" => "dashicons-images-alt",
                "order" => 40,
                "group" => "Optimize",
            ],
            "images" => [
                "label" => __("Images", "wps-cache"),
                "icon" => "dashicons-format-image",
                "order" => 45,
                "group" => "Optimize",
            ],
            "cdn" => [
                "label" => __("CDN & Edge", "wps-cache"),
                "icon" => "dashicons-cloud",
                "order" => 50,
                "group" => "Connect",
            ],
            "database" => [
                "label" => __("Database", "wps-cache"),
                "icon" => "dashicons-archive",
                "order" => 60,
                "group" => "Manage",
            ],
            "monitoring" => [
                "label" => __("Monitoring", "wps-cache"),
                "icon" => "dashicons-chart-area",
                "order" => 65,
                "group" => "Manage",
            ],
            "tweaks" => [
                "label" => __("WordPress Tweaks", "wps-cache"),
                "icon" => "dashicons-controls-volumeon",
                "order" => 70,
                "group" => "Manage",
            ],
            "tools" => [
                "label" => __("Tools", "wps-cache"),
                "icon" => "dashicons-admin-tools",
                "order" => 80,
                "group" => "Manage",
            ],
            ];

            uasort($this->tabs, fn($a, $b) => $a["order"] <=> $b["order"]);
        }

        return $this->tabs;
    }

    public function getCurrentTab(): string
    {
        $tabs = $this->getTabs();

        return isset($_GET["tab"]) &&
            array_key_exists($_GET["tab"], $tabs)
            ? sanitize_key($_GET["tab"])
            : "dashboard";
    }

    public function renderSidebar(string $current): void
    {
        echo '<nav class="wpsc-nav">';
        $lastGroup = null;
        foreach ($this->getTabs() as $key => $data) {

            $group = (string) ($data['group'] ?? '');
            if ($group !== '' && $group !== $lastGroup) {
                echo '<span class="wpsc-nav-group">' . esc_html($group) . '</span>';
                $lastGroup = $group;
            }

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
                <span class="wpsc-nav-label"><?php echo esc_html($data["label"]); ?></span>
                <?php if ($isActive): ?><span class="wpsc-nav-indicator" aria-hidden="true"></span><?php endif; ?>
            </a>
<?php
        }
        echo "</nav>";
    }
}
