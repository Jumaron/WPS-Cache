<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * High-Performance Database Cleaner.
 * 
 * SOTA Strategy:
 * 1. Uses Direct SQL (Atomic Deletes) instead of slow WP Loops.
 * 2. Optimized table maintenance using native MySQL commands.
 * 3. Handles metadata cleanup automatically.
 */
class DatabaseOptimizer
{
    private array $settings;

    // Mapping of cleanup keys to human labels and logic
    public const ITEMS = [
        'revisions' => 'Post Revisions',
        'auto_drafts' => 'Auto Drafts',
        'trashed_posts' => 'Trashed Posts',
        'spam_comments' => 'Spam Comments',
        'trashed_comments' => 'Trashed Comments',
        'expired_transients' => 'Expired Transients',
        'all_transients' => 'All Transients',
        'optimize_tables' => 'Optimize Tables',
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Returns current counts for all cleanup items.
     * Efficiently queries DB without loading full objects.
     */
    public function getStats(): array
    {
        global $wpdb;
        $stats = [];

        // Posts
        $stats['revisions'] = (int) $wpdb->get_var("SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = 'revision'");
        $stats['auto_drafts'] = (int) $wpdb->get_var("SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = 'auto-draft'");
        $stats['trashed_posts'] = (int) $wpdb->get_var("SELECT COUNT(ID) FROM $wpdb->posts WHERE post_status = 'trash'");

        // Comments
        $stats['spam_comments'] = (int) $wpdb->get_var("SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE comment_approved = 'spam'");
        $stats['trashed_comments'] = (int) $wpdb->get_var("SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE comment_approved = 'trash'");

        // Transients
        $time = time();
        $stats['expired_transients'] = (int) $wpdb->get_var(
            "SELECT COUNT(option_name) FROM $wpdb->options 
             WHERE option_name LIKE '_transient_timeout_%' 
             AND option_value < '$time'"
        );

        // Count all transients (approximate, based on timeout keys)
        $stats['all_transients'] = (int) $wpdb->get_var(
            "SELECT COUNT(option_name) FROM $wpdb->options 
             WHERE option_name LIKE '_transient_%'"
        );

        // Overhead
        $stats['optimize_tables'] = $this->getTableOverhead($wpdb);

        return $stats;
    }

    private function getTableOverhead($wpdb): string
    {
        $tables = $wpdb->get_results("SHOW TABLE STATUS WHERE Data_free > 0");
        $overhead = 0;
        foreach ($tables as $table) {
            $overhead += (float) $table->Data_free;
        }
        return size_format($overhead);
    }

    /**
     * Executes the cleanup based on provided list of keys.
     */
    public function processCleanup(array $items): int
    {
        global $wpdb;
        $count = 0;

        if (in_array('revisions', $items)) {
            // SOTA: Delete revisions and their meta in one go using multi-table DELETE if supported, or sequential
            $wpdb->query("DELETE a,b,c FROM $wpdb->posts a 
                          LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id) 
                          LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id) 
                          WHERE a.post_type = 'revision'");
            $count++;
        }

        if (in_array('auto_drafts', $items)) {
            $wpdb->query("DELETE a,b,c FROM $wpdb->posts a 
                          LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id) 
                          LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id) 
                          WHERE a.post_type = 'auto-draft'");
            $count++;
        }

        if (in_array('trashed_posts', $items)) {
            $wpdb->query("DELETE a,b,c FROM $wpdb->posts a 
                          LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id) 
                          LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id) 
                          WHERE a.post_status = 'trash'");
            $count++;
        }

        if (in_array('spam_comments', $items)) {
            $wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'");
            $wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments)");
            $count++;
        }

        if (in_array('trashed_comments', $items)) {
            $wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'");
            $wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments)");
            $count++;
        }

        if (in_array('expired_transients', $items)) {
            $time = time();
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_%' AND option_value < '$time'");
            // Cleanup orphaned data keys is harder in SQL alone safely, WP does lazy clean. 
            // But we can try to clean data keys that have no corresponding timeout or match expired logic.
            // Safe approach: Just clean the timeouts, WP cleans data on access.
            $count++;
        }

        if (in_array('all_transients', $items)) {
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
            $count++;
        }

        if (in_array('optimize_tables', $items)) {
            $tables = $wpdb->get_col("SHOW TABLES");
            foreach ($tables as $table) {
                $wpdb->query("OPTIMIZE TABLE $table");
            }
            $count++;
        }

        return $count;
    }

    /**
     * Runs via Cron. Reads settings to decide what to clean.
     */
    public function runScheduledCleanup(): void
    {
        $to_clean = [];
        foreach (self::ITEMS as $key => $label) {
            if (!empty($this->settings['db_clean_' . $key])) {
                $to_clean[] = $key;
            }
        }

        if (!empty($to_clean)) {
            $this->processCleanup($to_clean);
        }
    }
}
