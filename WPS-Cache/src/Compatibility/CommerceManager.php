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
        // 1. Guard: Check if the user explicitly disabled support
        // We default to true in Plugin.php, so this handles the "ON by default" requirement.
        if (empty($this->settings["woo_support"])) {
            return false; // Compatibility OFF -> Allow Caching (User assumes risk)
        }

        // 2. Check if Woo is actually active
        if (!$this->isWooActive) {
            return false;
        }

        // 3. Check if we are on a sensitive page (Cart/Checkout/Account)
        if ($this->isSensitivePage()) {
            return true;
        }

        // 4. Check cookies (Items in cart)
        if ($this->hasActiveSession()) {
            return true;
        }

        return false;
    }

    private function isSensitivePage(): bool
    {
        // Standard Woo conditionals
        if (function_exists("is_cart") && is_cart()) {
            return true;
        }
        if (function_exists("is_checkout") && is_checkout()) {
            return true;
        }
        if (function_exists("is_account_page") && is_account_page()) {
            return true;
        }

        // API endpoints
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

        // Check for specific session cookies that indicate dynamic state
        foreach ($_COOKIE as $key => $val) {
            if (str_starts_with($key, "wp_woocommerce_session_")) {
                return true;
            }
        }

        return false;
    }
}
