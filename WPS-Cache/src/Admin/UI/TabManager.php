<?php

declare(strict_types=1);

namespace WPSCache\Admin\UI;

/**
 * Manages admin interface navigation tabs
 */
class TabManager
{
    /**
     * Available admin tabs
     *
     * @var array<string, array>
     */
    private array $tabs;

    public function __construct()
    {
        $this->initializeTabs();
    }

    /**
     * Initializes available tabs and their properties
     */
    private function initializeTabs(): void
    {
        $this->tabs = [
            'settings' => [
                'label'      => __('Settings', 'wps-cache'),
                'capability' => 'manage_options',
                'icon'       => 'dashicons-admin-generic',
                'order'      => 10
            ],
            'analytics' => [
                'label'      => __('Analytics', 'wps-cache'),
                'capability' => 'manage_options',
                'icon'       => 'dashicons-chart-bar',
                'order'      => 20
            ],
            'tools' => [
                'label'      => __('Tools', 'wps-cache'),
                'capability' => 'manage_options',
                'icon'       => 'dashicons-admin-tools',
                'order'      => 30
            ]
        ];
    }

    /**
     * Renders tab navigation
     *
     * @param string $current_tab Currently active tab
     */
    public function renderTabs(string $current_tab = 'settings'): void
    {
        $tabs = $this->getAccessibleTabs();

        if (empty($tabs)) {
            return;
        }
?>
        <div class="nav-tab-wrapper wp-clearfix">
            <?php foreach ($tabs as $tab_id => $tab): ?>
                <?php $this->renderTab($tab_id, $tab, $current_tab === $tab_id); ?>
            <?php endforeach; ?>
        </div>
    <?php
    }

    /**
     * Gets all tabs accessible to current user
     *
     * @return array Accessible tabs
     */
    public function getAccessibleTabs(): array
    {
        $accessible_tabs = array_filter($this->tabs, function ($tab) {
            return current_user_can($tab['capability']);
        });

        // Sort tabs by order
        uasort($accessible_tabs, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return $accessible_tabs;
    }

    /**
     * Renders individual tab
     *
     * @param string $tab_id Tab identifier
     * @param array $tab Tab configuration
     * @param bool $is_active Whether tab is currently active
     */
    private function renderTab(string $tab_id, array $tab, bool $is_active): void
    {
        $classes = ['nav-tab'];
        if ($is_active) {
            $classes[] = 'nav-tab-active';
        }

        $url = add_query_arg([
            'page'     => 'wps-cache',
            'tab'      => $tab_id
        ], admin_url('admin.php'));
    ?>
        <a href="<?php echo esc_url($url); ?>"
            class="<?php echo esc_attr(implode(' ', $classes)); ?>"
            id="wpsc-tab-<?php echo esc_attr($tab_id); ?>">
            <?php if (!empty($tab['icon'])): ?>
                <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
            <?php endif; ?>
            <?php echo esc_html($tab['label']); ?>
        </a>
<?php
    }

    /**
     * Gets tab configuration
     *
     * @param string $tab_id Tab identifier
     * @return array|null Tab configuration or null if not found
     */
    public function getTab(string $tab_id): ?array
    {
        return $this->tabs[$tab_id] ?? null;
    }

    /**
     * Gets all registered tabs
     *
     * @return array All registered tabs
     */
    public function getAllTabs(): array
    {
        return $this->tabs;
    }

    /**
     * Checks if a tab exists
     *
     * @param string $tab_id Tab identifier
     * @return bool Whether tab exists
     */
    public function hasTab(string $tab_id): bool
    {
        return isset($this->tabs[$tab_id]);
    }

    /**
     * Checks if a tab is accessible to current user
     *
     * @param string $tab_id Tab identifier
     * @return bool Whether tab is accessible
     */
    public function isTabAccessible(string $tab_id): bool
    {
        if (!$this->hasTab($tab_id)) {
            return false;
        }

        return current_user_can($this->tabs[$tab_id]['capability']);
    }

    /**
     * Registers a new tab
     *
     * @param string $tab_id Tab identifier
     * @param string $label Tab label
     * @param string $capability Required capability
     * @param string $icon Tab icon class
     * @param int $order Tab order
     * @return bool Whether tab was registered
     */
    public function registerTab(
        string $tab_id,
        string $label,
        string $capability = 'manage_options',
        string $icon = '',
        int $order = 50
    ): bool {
        if ($this->hasTab($tab_id)) {
            return false;
        }

        $this->tabs[$tab_id] = [
            'label'      => $label,
            'capability' => $capability,
            'icon'       => $icon,
            'order'      => $order
        ];

        return true;
    }

    /**
     * Unregisters a tab
     *
     * @param string $tab_id Tab identifier
     * @return bool Whether tab was unregistered
     */
    public function unregisterTab(string $tab_id): bool
    {
        if (!$this->hasTab($tab_id)) {
            return false;
        }

        unset($this->tabs[$tab_id]);
        return true;
    }

    /**
     * Gets URL for a specific tab
     *
     * @param string $tab_id Tab identifier
     * @param array $args Additional query arguments
     * @return string Tab URL
     */
    public function getTabUrl(string $tab_id, array $args = []): string
    {
        $default_args = [
            'page' => 'wps-cache',
            'tab'  => $tab_id
        ];

        return add_query_arg(
            array_merge($default_args, $args),
            admin_url('admin.php')
        );
    }

    /**
     * Gets current active tab
     *
     * @return string Active tab ID
     */
    public function getCurrentTab(): string
    {
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';

        if (!$this->isTabAccessible($current_tab)) {
            return 'settings';
        }

        return $current_tab;
    }
}
