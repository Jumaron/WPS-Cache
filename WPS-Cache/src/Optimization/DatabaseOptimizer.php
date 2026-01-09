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
        // Optimization: Split queries further to ensure MySQL uses "Index Only Scan" for the totals count.
        // The previous "Combined" query forced MySQL to read the 'option_value' (LONGTEXT) column for every transient
        // to evaluate the CASE statement, even for non-expired items. By splitting, we count totals using ONLY the index.
        $time = time();

        // 1. Regular Transients
        $local_expired = $wpdb->get_var(
            "SELECT COUNT(*) FROM $wpdb->options
             WHERE option_name LIKE '\_transient\_timeout\_%'
             AND option_value < '$time'"
        );

        $local_total = $wpdb->get_var(
            "SELECT COUNT(*) FROM $wpdb->options
             WHERE option_name LIKE '\_transient\_%'"
        );

        // 2. Site Transients
        $site_expired = $wpdb->get_var(
            "SELECT COUNT(*) FROM $wpdb->options
             WHERE option_name LIKE '\_site\_transient\_timeout\_%'
             AND option_value < '$time'"
        );

        $site_total = $wpdb->get_var(
            "SELECT COUNT(*) FROM $wpdb->options
             WHERE option_name LIKE '\_site\_transient\_%'"
        );

        $stats["expired_transients"] = (int) $local_expired + (int) $site_expired;
        $stats["all_transients"] = (int) $local_total + (int) $site_total;

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

        // Optimization: Convert to hash map for O(1) lookups
        $lookup = array_flip($items);

        // Batch 1: Posts (Revisions, Auto Drafts, Trash)
        // Optimization: Combine multiple DELETE queries into one to reduce DB round-trips and lock contention.
        $post_conditions = [];

        if (isset($lookup["revisions"])) {
            $post_conditions[] = "a.post_type = 'revision'";
            $count++;
        }

        if (isset($lookup["auto_drafts"])) {
            $post_conditions[] = "a.post_type = 'auto-draft'";
            $count++;
        }

        if (isset($lookup["trashed_posts"])) {
            $post_conditions[] = "a.post_status = 'trash'";
            $count++;
        }

        if (!empty($post_conditions)) {
            $where = implode(" OR ", $post_conditions);
            $wpdb->query("DELETE a,b,c FROM $wpdb->posts a
                          LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id)
                          LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id)
                          WHERE $where");
        }

        $clean_orphaned_commentmeta = false;

        // Batch 2: Comments (Spam, Trash)
        // Optimization: Use IN clause to delete multiple comment types in a single query.
        $comment_types = [];

        if (isset($lookup["spam_comments"])) {
            $comment_types[] = "'spam'";
            $clean_orphaned_commentmeta = true;
            $count++;
        }

        if (isset($lookup["trashed_comments"])) {
            $comment_types[] = "'trash'";
            $clean_orphaned_commentmeta = true;
            $count++;
        }

        if (!empty($comment_types)) {
            $in_clause = implode(", ", $comment_types);
            $wpdb->query(
                "DELETE FROM $wpdb->comments WHERE comment_approved IN ($in_clause)",
            );
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

        if (isset($lookup["expired_transients"])) {
            $time = time();

            // SOTA: Delete both the timeout key AND the data key using multi-table DELETE.
            // This prevents "un-expiring" the transient (where WP sees no timeout and assumes permanent validity)
            // and actually frees up the database space.

            // 1. Regular Transients
            // Matches _transient_timeout_KEY and joins to _transient_KEY
            // _transient_timeout_ is 19 chars long. SUBSTRING is 1-based, so start at 20.
            $wpdb->query(
                "DELETE a, b FROM $wpdb->options a
                 LEFT JOIN $wpdb->options b ON (
                    b.option_name = CONCAT('_transient_', SUBSTRING(a.option_name, 20))
                 )
                 WHERE a.option_name LIKE '\_transient\_timeout\_%'
                 AND a.option_value < '$time'"
            );

            // 2. Site Transients
            // Matches _site_transient_timeout_KEY and joins to _site_transient_KEY
            // _site_transient_timeout_ is 24 chars long. Start at 25.
            $wpdb->query(
                "DELETE a, b FROM $wpdb->options a
                 LEFT JOIN $wpdb->options b ON (
                    b.option_name = CONCAT('_site_transient_', SUBSTRING(a.option_name, 25))
                 )
                 WHERE a.option_name LIKE '\_site\_transient\_timeout\_%'
                 AND a.option_value < '$time'"
            );

            $count++;
        }

        if (isset($lookup["all_transients"])) {
            // Optimization: Split into 2 queries to ensure MySQL uses the index range scan
            // instead of a full table scan or inefficient index merge caused by OR.
            $wpdb->query(
                "DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_%'",
            );
            $wpdb->query(
                "DELETE FROM $wpdb->options WHERE option_name LIKE '\_site\_transient\_%'",
            );
            $count++;
        }

        if (isset($lookup["optimize_tables"])) {
            // Sentinel Fix: Limit optimization to this site's tables and escape table names
            // Prevents touching shared DB tables and mitigates potential injection risks
            $like = $wpdb->esc_like($wpdb->prefix) . "%";
            $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));

            // Optimization: Batch OPTIMIZE TABLE commands to reduce DB round-trips
            // Process in chunks of 20 to avoid query length limits
            if (!empty($tables)) {
                $chunks = array_chunk($tables, 20);
                foreach ($chunks as $chunk) {
                    $escaped = array_map(fn($t) => "`$t`", $chunk);
                    $wpdb->query("OPTIMIZE TABLE " . implode(", ", $escaped));
                }
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
