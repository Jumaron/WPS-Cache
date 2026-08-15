<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

use WPSCache\Config\Settings;

final class OptimizationMode
{
    public static function allows(Settings|array $settings): bool
    {
        $safe = $settings instanceof Settings ? $settings->enabled('optimization_safe_mode') : !empty($settings['optimization_safe_mode']);
        if (!$safe) {
            return true;
        }
        $nonce = isset($_GET['wpsc_preview']) && is_string($_GET['wpsc_preview']) ? sanitize_text_field(wp_unslash($_GET['wpsc_preview'])) : '';
        return $nonce !== '' && function_exists('wp_verify_nonce') && wp_verify_nonce($nonce, 'wpsc_optimization_preview') !== false;
    }
}
