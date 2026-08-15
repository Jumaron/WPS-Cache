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
                "label" => __("Cache Rules", "wps-cache"),
                "icon" => "dashicons-database",
                "order" => 20,
                "group" => "Caching",
            ],
            "delivery" => [
                "label" => __("Delivery Rules", "wps-cache"),
                "icon" => "dashicons-randomize",
                "order" => 25,
                "group" => "Caching",
            ],
            "css_js" => [
                "label" => __("File Optimization", "wps-cache"),
                "icon" => "dashicons-editor-code",
                "order" => 30,
                "group" => "Frontend",
            ],
            "experience" => [
                "label" => __("Web Experience", "wps-cache"),
                "icon" => "dashicons-layout",
                "order" => 35,
                "group" => "Frontend",
            ],
            "media" => [
                "label" => __("Media Delivery", "wps-cache"),
                "icon" => "dashicons-images-alt",
                "order" => 40,
                "group" => "Frontend",
            ],
            "images" => [
                "label" => __("Image Engine & Offload", "wps-cache"),
                "icon" => "dashicons-format-image",
                "order" => 45,
                "group" => "Frontend",
            ],
            "cdn" => [
                "label" => __("CDN & Edge", "wps-cache"),
                "icon" => "dashicons-cloud",
                "order" => 50,
                "group" => "Infrastructure",
            ],
            "database" => [
                "label" => __("Database", "wps-cache"),
                "icon" => "dashicons-archive",
                "order" => 60,
                "group" => "Operations",
            ],
            "monitoring" => [
                "label" => __("Monitoring", "wps-cache"),
                "icon" => "dashicons-chart-area",
                "order" => 65,
                "group" => "Operations",
            ],
            "tweaks" => [
                "label" => __("Tweaks", "wps-cache"),
                "icon" => "dashicons-controls-volumeon",
                "order" => 70,
                "group" => "Operations",
            ],
            "tools" => [
                "label" => __("Tools & Diagnostics", "wps-cache"),
                "icon" => "dashicons-admin-tools",
                "order" => 80,
                "group" => "Operations",
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
                <?php echo esc_html($data["label"]); ?>
            </a>
<?php
        }
        echo "</nav>";
    }
}
