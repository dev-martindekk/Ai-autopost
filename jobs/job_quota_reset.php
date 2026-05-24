<?php
/**
 * Monthly quota reset job
 * Cron: 0 0 1 * * php /var/www/html/jobs/job_quota_reset.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/plan_manager.php';

// Expire plans past expiry date
$expired = db()->query(
    "UPDATE member_plans SET status='expired'
     WHERE status='active' AND expires_at < NOW()"
)->rowCount();

if ($expired > 0) {
    // Sync members.plan_expires_at
    db()->query(
        "UPDATE members m
         LEFT JOIN member_plans mp ON mp.member_id=m.id AND mp.status='active' AND mp.expires_at > NOW()
         SET m.plan_id = mp.plan_id, m.plan_expires_at = mp.expires_at
         WHERE m.plan_expires_at < NOW()"
    );
}

// Reset monthly quota
$reset = planManager()->bulkResetMonthlyQuota();

$msg = "[job_quota_reset] " . date('Y-m-d H:i:s') . " — Reset: {$reset} subscriptions, Expired: {$expired} plans";
echo $msg . PHP_EOL;

logEvent('info', 'cron', 'Monthly quota reset', ['reset' => $reset, 'expired' => $expired]);
