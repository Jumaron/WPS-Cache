<?php

declare(strict_types=1);

namespace WPSCache\Admin\UI;

/**
 * Manages admin interface navigation (Sidebar Style)
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
        // Using "FlyingPress" style logical grouping
        $this->tabs = [
            'settings' => [
                'label'      => __('Dashboard', 'wps-cache'),
                'capability' => 'manage_options',
                'icon'       => 'dashicons-dashboard',
                'order'      => 10
            ],
            'cache' => [ // Split settings
                'label'      => __('Cache', 'wps-cache'),
                'capability' => 'manage_options',
                'icon'       => 'dashicons-database',
                'order'      => 20
            ],
            'css_js' => [ // New distinct tab
                'label'      => __('CSS & JS', 'wps-cache'),
                'capability' => 'manage_options',
                'icon'       => 'dashicons-editor-code',
                'order'      => 30
            ],
            'analytics' => [
                'label'      => __('Analytics', 'wps-cache'),
                'capability' => 'manage_options',
                'icon'       => 'dashicons-chart-bar',
                'order'      => 40
            ],
            'tools' => [
                'label'      => __('Tools', 'wps-cache'),
                'capability' => 'manage_options',
                'icon'       => 'dashicons-admin-tools',
                'order'      => 50
            ]
        ];
    }

    /**
     * Renders tab navigation
     *
     * @param string $current_tab Currently active tab
     */
    public function renderSidebar(string $current_tab = 'settings'): void
    {
        $tabs = $this->getAccessibleTabs();
?>
        <div class="wpsc-sidebar">
            <nav class="wpsc-nav">
                <?php foreach ($tabs as $tab_id => $tab): ?>
                    <?php
                    $url = add_query_arg(['page' => 'wps-cache', 'tab' => $tab_id], admin_url('admin.php'));
                    $active = $current_tab === $tab_id ? 'active' : '';
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="wpsc-nav-item <?php echo esc_attr($active); ?>">
                        <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Quick Action in Sidebar -->
            <div style="margin-top: 2rem; padding: 1rem; background: #f3f4f6; border-radius: 8px; text-align: center;">
                <p style="margin-bottom: 0.5rem; font-size: 0.8rem; color: #666;">Need a boost?</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wpsc_clear_cache'); ?>
                    <input type="hidden" name="action" value="wpsc_clear_cache">
                    <button type="submit" class="button wpsc-btn-secondary" style="width: 100%;">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle; font-size: 16px;"></span> Purge All
                    </button>
                </form>
            </div>
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
        return isset($this->tabs[$current_tab]) ? $current_tab : 'settings';
    }
}
