<?php
/**
 * PlanManager — quota tracking and enforcement for member plans
 */

require_once __DIR__ . '/db.php';

class PlanManager {
    private static ?self $instance = null;

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ----------------------------------------------------------------
    // Plan helpers
    // ----------------------------------------------------------------

    public function getPlan(int $planId): ?array {
        return db()->fetchOne("SELECT * FROM plans WHERE id = ?", [$planId]);
    }

    public function getAllActivePlans(): array {
        return db()->fetchAll("SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order ASC");
    }

    // ----------------------------------------------------------------
    // Member subscription
    // ----------------------------------------------------------------

    /** Get the current active subscription for a member, or null */
    public function getMemberPlan(int $memberId): ?array {
        return db()->fetchOne(
            "SELECT mp.*, p.name AS plan_name, p.slug AS plan_slug,
                    p.articles_per_month, p.max_sites, p.max_keywords, p.max_tokens_per_month,
                    p.price, p.features, p.is_trial, p.trial_articles_total
             FROM member_plans mp
             JOIN plans p ON p.id = mp.plan_id
             WHERE mp.member_id = ? AND mp.status = 'active'
               AND mp.expires_at > NOW()
             ORDER BY mp.expires_at DESC
             LIMIT 1",
            [$memberId]
        );
    }

    /** Activate the Trial plan for a new member (lifetime, no expiry). */
    public function activateTrialPlan(int $memberId): bool {
        $trial = db()->fetchOne("SELECT id FROM plans WHERE slug = 'trial' AND is_active = 1 LIMIT 1");
        if (!$trial) {
            return false;
        }
        $planId = (int)$trial['id'];

        $existing = db()->fetchColumn(
            "SELECT COUNT(*) FROM member_plans WHERE member_id = ? AND status = 'active'",
            [$memberId]
        );
        if ($existing) {
            return true; // already has a plan
        }

        db()->query(
            "INSERT INTO member_plans (member_id, plan_id, started_at, expires_at, quota_reset_at, status)
             VALUES (?, ?, NOW(), '2099-12-31 23:59:59', CURDATE(), 'active')",
            [$memberId, $planId]
        );
        db()->query(
            "UPDATE members SET plan_id = ?, plan_expires_at = '2099-12-31 23:59:59' WHERE id = ?",
            [$planId, $memberId]
        );
        return true;
    }

    /** Returns true if member is on the Trial plan */
    public function isTrial(int $memberId): bool {
        $sub = $this->getMemberPlan($memberId);
        return $sub && (bool)($sub['is_trial'] ?? false);
    }

    /**
     * Activate or extend a member plan after slip approval.
     * Runs inside a transaction; caller should wrap if needed.
     */
    public function activateOrExtend(int $memberId, int $planId, int $months = 1): bool {
        try {
            db()->beginTransaction();

            $existing = db()->fetchOne(
                "SELECT mp.id, mp.plan_id, mp.expires_at, p.is_trial
                 FROM member_plans mp
                 JOIN plans p ON p.id = mp.plan_id
                 WHERE mp.member_id = ? AND mp.status = 'active' AND mp.expires_at > NOW()
                 LIMIT 1
                 FOR UPDATE",
                [$memberId]
            );

            if ($existing && !(bool)$existing['is_trial'] && (int)$existing['plan_id'] === $planId) {
                // Same paid plan — extend from current expiry
                db()->query(
                    "UPDATE member_plans
                     SET expires_at = DATE_ADD(expires_at, INTERVAL ? MONTH), updated_at = NOW()
                     WHERE id = ?",
                    [$months, $existing['id']]
                );
            } else {
                // New plan or upgrading from trial — deactivate existing, create fresh subscription
                if ($existing) {
                    db()->query(
                        "UPDATE member_plans SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
                        [$existing['id']]
                    );
                }
                db()->query(
                    "INSERT INTO member_plans
                     (member_id, plan_id, started_at, expires_at, quota_reset_at, status)
                     VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), CURDATE(), 'active')",
                    [$memberId, $planId, $months]
                );
            }

            // Sync denormalized columns on members table
            db()->query(
                "UPDATE members SET plan_id = ?, plan_expires_at = (
                    SELECT expires_at FROM member_plans
                    WHERE member_id = ? AND status = 'active' AND expires_at > NOW()
                    ORDER BY expires_at DESC LIMIT 1
                 ) WHERE id = ?",
                [$planId, $memberId, $memberId]
            );

            db()->commit();
            return true;
        } catch (Exception $e) {
            db()->rollback();
            logEvent('error', 'plan_manager', 'activateOrExtend failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ----------------------------------------------------------------
    // Quota
    // ----------------------------------------------------------------

    /**
     * Returns quota info for a member.
     * [articles => [limit, used, remaining, unlimited], sites => [...], keywords => [...]]
     */
    public function getQuota(int $memberId): array {
        $sub = $this->getMemberPlan($memberId);

        if (!$sub) {
            return $this->noAccessQuota();
        }

        $isTrial = (bool)($sub['is_trial'] ?? false);

        if (!$isTrial) {
            $this->maybeResetMonthlyQuota($sub['id'], $sub['quota_reset_at']);
            $sub = $this->getMemberPlan($memberId); // refresh after possible reset
        }

        // Articles: trial uses lifetime total from articles table; regular uses monthly counter
        if ($isTrial) {
            $articlesLimit = (int)$sub['trial_articles_total'];
            $articlesUsed  = (int)db()->fetchColumn(
                "SELECT COUNT(*) FROM articles WHERE owner_type = 'member' AND owner_id = ?",
                [$memberId]
            );
        } else {
            $articlesLimit = (int)$sub['articles_per_month'];
            $articlesUsed  = (int)$sub['articles_used_this_month'];
        }

        $tokensLimit = (int)$sub['max_tokens_per_month'];
        $tokensUsed  = (int)$sub['tokens_used_this_month'];

        // Count real sites and keywords from DB for accuracy
        $sitesCount = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM sites WHERE owner_type = 'member' AND owner_id = ?",
            [$memberId]
        );
        $keywordsCount = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM keywords WHERE owner_type = 'member' AND owner_id = ? AND is_active = 1",
            [$memberId]
        );

        $sitesLimit    = (int)$sub['max_sites'];
        $keywordsLimit = (int)$sub['max_keywords'];

        return [
            'articles' => [
                'limit'     => $articlesLimit,
                'used'      => $articlesUsed,
                'remaining' => max(0, $articlesLimit - $articlesUsed),
                'unlimited' => false,
            ],
            'sites' => [
                'limit'     => $sitesLimit,
                'used'      => $sitesCount,
                'remaining' => max(0, $sitesLimit - $sitesCount),
                'unlimited' => false,
            ],
            'keywords' => [
                'limit'     => $keywordsLimit,
                'used'      => $keywordsCount,
                'remaining' => $keywordsLimit === 0 ? PHP_INT_MAX : max(0, $keywordsLimit - $keywordsCount),
                'unlimited' => $keywordsLimit === 0,
            ],
            'tokens' => [
                'limit'     => $tokensLimit,
                'used'      => $tokensUsed,
                'remaining' => $tokensLimit === 0 ? PHP_INT_MAX : max(0, $tokensLimit - $tokensUsed),
                'unlimited' => $tokensLimit === 0,
            ],
            'plan_name'    => $sub['plan_name'],
            'plan_slug'    => $sub['plan_slug'],
            'expires_at'   => $sub['expires_at'],
            'days_left'    => $isTrial ? null : (int)ceil((strtotime($sub['expires_at']) - time()) / 86400),
            'is_trial'     => $isTrial,
        ];
    }

    /**
     * Check if member can perform an action.
     * $type: 'articles' | 'sites' | 'keywords' | 'tokens'
     */
    public function canUse(int $memberId, string $type): bool {
        $quota = $this->getQuota($memberId);
        return ($quota[$type]['remaining'] ?? 0) > 0;
    }

    /**
     * Consume quota (articles or tokens).
     * Uses SELECT FOR UPDATE to prevent race conditions.
     */
    public function consume(int $memberId, string $type, int $amount = 1): bool {
        if (!in_array($type, ['articles', 'tokens'], true)) {
            return false;
        }

        $column = $type === 'articles' ? 'articles_used_this_month' : 'tokens_used_this_month';

        try {
            db()->beginTransaction();

            $sub = db()->fetchOne(
                "SELECT id, {$column}, plan_id FROM member_plans
                 WHERE member_id = ? AND status = 'active' AND expires_at > NOW()
                 ORDER BY expires_at DESC LIMIT 1
                 FOR UPDATE",
                [$memberId]
            );

            if (!$sub) {
                db()->rollback();
                return false;
            }

            // Check plan limit
            $plan = $this->getPlan((int)$sub['plan_id']);
            $isTrial = (bool)($plan['is_trial'] ?? false);

            if ($type === 'articles' && $isTrial) {
                // Trial: count total articles ever created, not monthly counter
                $used  = (int)db()->fetchColumn(
                    "SELECT COUNT(*) FROM articles WHERE owner_type = 'member' AND owner_id = ?",
                    [$memberId]
                );
                $limit = (int)$plan['trial_articles_total'];
                if ($limit > 0 && ($used + $amount) > $limit) {
                    db()->rollback();
                    return false;
                }
                // No counter to increment for trial articles — article count comes from articles table
                db()->commit();
                return true;
            }

            $limit = $type === 'articles' ? (int)$plan['articles_per_month'] : (int)$plan['max_tokens_per_month'];
            $used  = (int)$sub[$column];

            if ($limit > 0 && ($used + $amount) > $limit) {
                db()->rollback();
                return false;
            }

            db()->query(
                "UPDATE member_plans SET {$column} = {$column} + ? WHERE id = ?",
                [$amount, $sub['id']]
            );

            db()->commit();
            return true;
        } catch (Exception $e) {
            db()->rollback();
            logEvent('error', 'plan_manager', 'consume failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ----------------------------------------------------------------
    // Monthly quota reset
    // ----------------------------------------------------------------

    /** Reset if quota_reset_at is from a previous month */
    private function maybeResetMonthlyQuota(int $subId, ?string $resetAt): void {
        $now = new DateTime();
        if ($resetAt) {
            $resetDate = new DateTime($resetAt);
            if ($resetDate->format('Y-m') === $now->format('Y-m')) {
                return; // already reset this month
            }
        }

        db()->query(
            "UPDATE member_plans
             SET articles_used_this_month = 0,
                 tokens_used_this_month   = 0,
                 quota_reset_at           = CURDATE()
             WHERE id = ?",
            [$subId]
        );
    }

    /** Bulk reset for all active subscriptions (cron job) */
    public function bulkResetMonthlyQuota(): int {
        $result = db()->query(
            "UPDATE member_plans
             SET articles_used_this_month = 0,
                 tokens_used_this_month   = 0,
                 quota_reset_at           = CURDATE()
             WHERE status = 'active'
               AND (quota_reset_at IS NULL
                    OR (YEAR(quota_reset_at) != YEAR(NOW()) OR MONTH(quota_reset_at) != MONTH(NOW())))"
        );
        return $result->rowCount();
    }

    // ----------------------------------------------------------------
    // Admin stats
    // ----------------------------------------------------------------

    public function getSaasStats(): array {
        $stats = [];
        $stats['total_members']    = (int)db()->fetchColumn("SELECT COUNT(*) FROM members WHERE is_active = 1");
        $stats['active_subs']      = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM member_plans mp
             JOIN plans p ON p.id = mp.plan_id
             WHERE mp.status = 'active' AND mp.expires_at > NOW() AND p.is_trial = 0"
        );
        $stats['trial_members']    = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM member_plans mp
             JOIN plans p ON p.id = mp.plan_id
             WHERE mp.status = 'active' AND mp.expires_at > NOW() AND p.is_trial = 1"
        );
        $stats['pending_slips']    = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM payment_slips WHERE status = 'pending'"
        );
        $stats['revenue_this_month'] = (float)db()->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM payment_slips
             WHERE status = 'approved' AND YEAR(reviewed_at) = YEAR(NOW()) AND MONTH(reviewed_at) = MONTH(NOW())"
        );
        $stats['revenue_today']    = (float)db()->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM payment_slips
             WHERE status = 'approved' AND DATE(reviewed_at) = CURDATE()"
        );
        $stats['new_members_today'] = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM members WHERE DATE(created_at) = CURDATE()"
        );
        return $stats;
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    private function noAccessQuota(): array {
        $zero = ['limit' => 0, 'used' => 0, 'remaining' => 0, 'unlimited' => false];
        return [
            'articles'   => $zero,
            'sites'      => $zero,
            'keywords'   => $zero,
            'tokens'     => $zero,
            'plan_name'  => null,
            'plan_slug'  => null,
            'expires_at' => null,
            'days_left'  => 0,
            'is_trial'   => false,
        ];
    }
}

function planManager(): PlanManager {
    return PlanManager::getInstance();
}
