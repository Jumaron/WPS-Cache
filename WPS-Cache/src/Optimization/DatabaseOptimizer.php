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
        "revisions" => "Post Revisions",
        "auto_drafts" => "Auto Drafts",
        "trashed_posts" => "Trashed Posts",
        "spam_comments" => "Spam Comments",
        "trashed_comments" => "Trashed Comments",
        "expired_transients" => "Expired Transients",
        "all_transients" => "All Transients",
        "optimize_tables" => "Optimize Tables",
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

        // Posts (Revisions, Auto Drafts, Trashed)
        // Optimization: Combine 3 queries into 1
        $posts_stats = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN post_type = 'revision' THEN 1 ELSE 0 END) as revisions,
                SUM(CASE WHEN post_type = 'auto-draft' THEN 1 ELSE 0 END) as auto_drafts,
                SUM(CASE WHEN post_status = 'trash' THEN 1 ELSE 0 END) as trashed_posts
             FROM $wpdb->posts
             WHERE post_type IN ('revision', 'auto-draft') OR post_status = 'trash'"
        );

        $stats["revisions"] = (int) ($posts_stats->revisions ?? 0);
        $stats["auto_drafts"] = (int) ($posts_stats->auto_drafts ?? 0);
        $stats["trashed_posts"] = (int) ($posts_stats->trashed_posts ?? 0);

        // Comments (Spam, Trash)
        // Optimization: Combine 2 queries into 1
        $comments_stats = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN comment_approved = 'spam' THEN 1 ELSE 0 END) as spam_comments,
                SUM(CASE WHEN comment_approved = 'trash' THEN 1 ELSE 0 END) as trashed_comments
             FROM $wpdb->comments
             WHERE comment_approved IN ('spam', 'trash')"
        );

        $stats["spam_comments"] = (int) ($comments_stats->spam_comments ?? 0);
        $stats["trashed_comments"] = (int) ($comments_stats->trashed_comments ?? 0);

        // Transients (Expired, All)
        // Optimization: Split into 2 queries (Regular + Site) to maximize index usage (range scan)
        // and avoid OR conditions which can be slow. Also escape underscores to prevent wildcard matching.
        $time = time();

        // 1. Regular Transients
        $transients_local = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN option_name LIKE '\_transient\_timeout\_%' AND option_value < '$time' THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN option_name LIKE '\_transient\_%' THEN 1 ELSE 0 END) as total
             FROM $wpdb->options
             WHERE option_name LIKE '\_transient\_%'"
        );

        // 2. Site Transients (often missed)
        $transients_site = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN option_name LIKE '\_site\_transient\_timeout\_%' AND option_value < '$time' THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN option_name LIKE '\_site\_transient\_%' THEN 1 ELSE 0 END) as total
             FROM $wpdb->options
             WHERE option_name LIKE '\_site\_transient\_%'"
        );

        $stats["expired_transients"] = (int) ($transients_local->expired ?? 0) + (int) ($transients_site->expired ?? 0);
        $stats["all_transients"] = (int) ($transients_local->total ?? 0) + (int) ($transients_site->total ?? 0);

        // Overhead
        $stats["optimize_tables"] = $this->getTableOverhead($wpdb);

        return $stats;
    }

    private function getTableOverhead($wpdb): string
    {
        // Optimization: Restrict status check to this site's tables only.
        // Prevents scanning thousands of tables in shared databases.
        $like = $wpdb->esc_like($wpdb->prefix) . "%";
        $sql = $wpdb->prepare("SHOW TABLE STATUS WHERE Name LIKE %s AND Data_free > 0", $like);

        $tables = $wpdb->get_results($sql);
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

        if (in_array("revisions", $items)) {
            // SOTA: Delete revisions and their meta in one go using multi-table DELETE if supported, or sequential
            $wpdb->query("DELETE a,b,c FROM $wpdb->posts a
                          LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id)
                          LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id)
                          WHERE a.post_type = 'revision'");
            $count++;
        }

        if (in_array("auto_drafts", $items)) {
            $wpdb->query("DELETE a,b,c FROM $wpdb->posts a
                          LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id)
                          LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id)
                          WHERE a.post_type = 'auto-draft'");
            $count++;
        }

        if (in_array("trashed_posts", $items)) {
            $wpdb->query("DELETE a,b,c FROM $wpdb->posts a
                          LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id)
                          LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id)
                          WHERE a.post_status = 'trash'");
            $count++;
        }

        $clean_orphaned_commentmeta = false;

        if (in_array("spam_comments", $items)) {
            $wpdb->query(
                "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'",
            );
            $clean_orphaned_commentmeta = true;
            $count++;
        }

        if (in_array("trashed_comments", $items)) {
            $wpdb->query(
                "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'",
            );
            $clean_orphaned_commentmeta = true;
            $count++;
        }

        if ($clean_orphaned_commentmeta) {
            // Optimization: Use LEFT JOIN ... IS NULL instead of expensive NOT IN (subquery)
            // Also batches the cleanup so it only runs once even if multiple comment types are selected.
            $wpdb->query(
                "DELETE meta FROM $wpdb->commentmeta meta
                 LEFT JOIN $wpdb->comments comments ON meta.comment_id = comments.comment_ID
                 WHERE comments.comment_ID IS NULL"
            );
        }

        if (in_array("expired_transients", $items)) {
            $time = time();
            $wpdb->query(
                "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_%' AND option_value < '$time'",
            );
            // Cleanup orphaned data keys is harder in SQL alone safely, WP does lazy clean.
            // But we can try to clean data keys that have no corresponding timeout or match expired logic.
            // Safe approach: Just clean the timeouts, WP cleans data on access.
            $count++;
        }

        if (in_array("all_transients", $items)) {
            // Optimization: Split into 2 queries to ensure MySQL uses the index range scan
            // instead of a full table scan or inefficient index merge caused by OR.
            $wpdb->query(
                "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_%'",
            );
            $wpdb->query(
                "DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_%'",
            );
            $count++;
        }

        if (in_array("optimize_tables", $items)) {
            // Sentinel Fix: Limit optimization to this site's tables and escape table names
            // Prevents touching shared DB tables and mitigates potential injection risks
            $like = $wpdb->esc_like($wpdb->prefix) . "%";
            $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));

            foreach ($tables as $table) {
                $wpdb->query("OPTIMIZE TABLE `$table`");
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
            if (!empty($this->settings["db_clean_" . $key])) {
                $to_clean[] = $key;
            }
        }

        if (!empty($to_clean)) {
            $this->processCleanup($to_clean);
        }
    }
}
