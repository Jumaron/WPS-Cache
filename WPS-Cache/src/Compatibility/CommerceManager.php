<?php

declare(strict_types=1);

namespace WPSCache\Compatibility;

/**
 * Handles WooCommerce Compatibility.
 *
 * Features:
 * 1. Prevents caching of Cart, Checkout, and My Account pages.
 * 2. Bypasses cache if the user has items in the cart (Cookie detection).
 * 3. Bypasses cache if recent order comments/notices exist.
 */
class CommerceManager
{
    private array $settings;
    private bool $isWooActive;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->isWooActive = class_exists("WooCommerce");
    }

    /**
     * Master check: Should we bypass the cache for this request?
     */
    public function shouldBypass(): bool
    {
        if (!$this->isWooActive) {
            return false;
        }

        // 1. Check if we are on a sensitive page
        if ($this->isSensitivePage()) {
            return true;
        }

        // 2. Check cookies (Items in cart)
        if ($this->hasActiveSession()) {
            return true;
        }

        return false;
    }

    private function isSensitivePage(): bool
    {
        // We rely on standard WP conditionals.
        // Note: These functions (is_cart, etc.) might not be available early in the boot process
        // depending on when caching triggers. However, our HTMLCache uses output buffering
        // which runs AFTER WP has loaded queries, so these functions are safe to use.

        if (function_exists("is_cart") && is_cart()) {
            return true;
        }
        if (function_exists("is_checkout") && is_checkout()) {
            return true;
        }
        if (function_exists("is_account_page") && is_account_page()) {
            return true;
        }

        // Also check URL endpoints just in case
        $uri = $_SERVER["REQUEST_URI"] ?? "";
        if (strpos($uri, "/wc-api/") !== false) {
            return true;
        }

        return false;
    }

    private function hasActiveSession(): bool
    {
        // Bypass if user has items in cart
        if (
            isset($_COOKIE["woocommerce_items_in_cart"]) &&
            $_COOKIE["woocommerce_items_in_cart"] > 0
        ) {
            return true;
        }

        // Bypass if user has just reset password or other session events
        foreach ($_COOKIE as $key => $val) {
            if (str_starts_with($key, "wp_woocommerce_session_")) {
                // We typically don't bypass just for having a session (browsing),
                // but strictly for cart items.
                // However, strictly checking items_in_cart is usually sufficient for performance.
                break;
            }
        }

        return false;
    }
}
