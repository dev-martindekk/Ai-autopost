<?php
/**
 * AI AutoPost SEO System - Queue Manager
 * =======================================
 * ระบบจัดการ Queue สำหรับงานต่าง ๆ
 * รองรับ Priority, Retry, Rate Limiting
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class QueueManager {
    private $maxRetries = 3;
    private $retryDelay = 300; // 5 minutes

    /**
     * เพิ่มงานเข้า Queue
     */
    public function push(string $jobType, array $payload, array $options = []): int {
        $priority = $options['priority'] ?? 5; // 1=highest, 10=lowest
        $scheduledAt = $options['scheduled_at'] ?? date('Y-m-d H:i:s');
        $siteId = $options['site_id'] ?? null;

        return db()->insert('job_queue', [
            'job_type' => $jobType,
            'payload' => json_encode($payload),
            'priority' => $priority,
            'site_id' => $siteId,
            'status' => 'pending',
            'scheduled_at' => $scheduledAt,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * ดึงงานถัดไปที่พร้อมทำ
     */
    public function pop(?string $jobType = null, string|int|null $workerId = null): ?array {
        $where = "status = 'pending' AND scheduled_at <= NOW() AND (locked_until IS NULL OR locked_until < NOW())";
        $params = [];

        if ($jobType) {
            $where .= " AND job_type = ?";
            $params[] = $jobType;
        }

        $lockUntil = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        try {
            db()->beginTransaction();

            $job = db()->fetchOne("
                SELECT * FROM job_queue
                WHERE {$where}
                ORDER BY priority ASC, scheduled_at ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            ", $params);

            if (!$job) {
                db()->rollback();
                return null;
            }

            // Mark as processing atomically within the same transaction
            db()->update('job_queue', [
                'status'     => 'processing',
                'locked_until' => $lockUntil,
                'worker_id'  => $workerId,
                'started_at' => date('Y-m-d H:i:s'),
                'attempts'   => $job['attempts'] + 1,
            ], 'id = ?', [$job['id']]);

            db()->commit();
        } catch (Exception $e) {
            db()->rollback();
            return null;
        }

        $job['payload'] = json_decode($job['payload'], true);
        return $job;
    }

    /**
     * ทำเครื่องหมายว่างานเสร็จสิ้น
     */
    public function complete(int $jobId, array $result = []): void {
        db()->update('job_queue', [
            'status' => 'completed',
            'result' => json_encode($result),
            'completed_at' => date('Y-m-d H:i:s'),
            'locked_until' => null
        ], 'id = ?', [$jobId]);
    }

    /**
     * ทำเครื่องหมายว่างานล้มเหลว
     */
    public function fail(int $jobId, string $error, bool $canRetry = true): void {
        $job = db()->fetchOne("SELECT * FROM job_queue WHERE id = ?", [$jobId]);

        if (!$job) return;

        $shouldRetry = $canRetry && $job['attempts'] < $this->maxRetries;

        if ($shouldRetry) {
            // Schedule retry
            $retryAt = date('Y-m-d H:i:s', strtotime("+{$this->retryDelay} seconds"));
            db()->update('job_queue', [
                'status' => 'pending',
                'error_message' => $error,
                'scheduled_at' => $retryAt,
                'locked_until' => null
            ], 'id = ?', [$jobId]);
        } else {
            // Mark as failed permanently
            db()->update('job_queue', [
                'status' => 'failed',
                'error_message' => $error,
                'completed_at' => date('Y-m-d H:i:s'),
                'locked_until' => null
            ], 'id = ?', [$jobId]);
        }
    }

    /**
     * ดึงสถิติ Queue
     */
    public function getStats(): array {
        $stats = db()->fetchOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM job_queue
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        return $stats ?: [
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];
    }

    /**
     * ดึงงานที่รอดำเนินการ
     */
    public function getPendingJobs(int $limit = 50): array {
        return db()->fetchAll("
            SELECT jq.*, s.name as site_name
            FROM job_queue jq
            LEFT JOIN sites s ON jq.site_id = s.id
            WHERE jq.status IN ('pending', 'processing')
            ORDER BY jq.priority ASC, jq.scheduled_at ASC
            LIMIT ?
        ", [$limit]);
    }

    /**
     * ดึงงานที่ล้มเหลว
     */
    public function getFailedJobs(int $limit = 50): array {
        return db()->fetchAll("
            SELECT jq.*, s.name as site_name
            FROM job_queue jq
            LEFT JOIN sites s ON jq.site_id = s.id
            WHERE jq.status = 'failed'
            ORDER BY jq.completed_at DESC
            LIMIT ?
        ", [$limit]);
    }

    /**
     * Retry งานที่ล้มเหลว
     */
    public function retry(int $jobId): bool {
        $job = db()->fetchOne("SELECT * FROM job_queue WHERE id = ? AND status = 'failed'", [$jobId]);

        if (!$job) return false;

        db()->update('job_queue', [
            'status' => 'pending',
            'attempts' => 0,
            'error_message' => null,
            'scheduled_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$jobId]);

        return true;
    }

    /**
     * ยกเลิกงาน
     */
    public function cancel(int $jobId): bool {
        return db()->update('job_queue', [
            'status' => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s')
        ], 'id = ? AND status IN ("pending", "processing")', [$jobId]) > 0;
    }

    /**
     * ล้าง Queue เก่า
     */
    public function cleanup(int $daysOld = 7): int {
        return db()->delete(
            'job_queue',
            "status IN ('completed', 'cancelled', 'failed') AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$daysOld]
        );
    }

    /**
     * ปลดล็อคงานที่ค้าง (timeout)
     */
    public function releaseStuckJobs(): int {
        $stmt = db()->query("
            UPDATE job_queue
            SET status = 'pending',
                locked_until = NULL,
                worker_id = NULL
            WHERE status = 'processing'
            AND locked_until < NOW()
        ");
        return $stmt->rowCount();
    }

    /**
     * จัดลำดับความสำคัญใหม่
     */
    public function reprioritize(int $jobId, int $newPriority): bool {
        return db()->update('job_queue', [
            'priority' => max(1, min(10, $newPriority))
        ], 'id = ? AND status = "pending"', [$jobId]) > 0;
    }

    /**
     * นับจำนวนงานแต่ละประเภท
     */
    public function countByType(): array {
        return db()->fetchAll("
            SELECT
                job_type,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing
            FROM job_queue
            WHERE status IN ('pending', 'processing')
            GROUP BY job_type
        ");
    }

    /**
     * ดึงข้อมูล Job ตาม ID
     */
    public function getJob(int $jobId): ?array {
        $job = db()->fetchOne("SELECT * FROM job_queue WHERE id = ?", [$jobId]);
        if ($job) {
            $job['payload'] = json_decode($job['payload'] ?? '{}', true);
            $job['result'] = json_decode($job['result'] ?? '{}', true);
        }
        return $job;
    }

    /**
     * ดึง Job ล่าสุดของ Site
     */
    public function getLatestJobBySite(int $siteId, string $jobType = 'generate_article'): ?array {
        $job = db()->fetchOne("
            SELECT * FROM job_queue
            WHERE site_id = ? AND job_type = ?
            ORDER BY created_at DESC
            LIMIT 1
        ", [$siteId, $jobType]);
        if ($job) {
            $job['payload'] = json_decode($job['payload'] ?? '{}', true);
            $job['result'] = json_decode($job['result'] ?? '{}', true);
        }
        return $job;
    }
}

/**
 * Helper function
 */
function queue(): QueueManager {
    static $instance = null;
    if ($instance === null) {
        $instance = new QueueManager();
    }
    return $instance;
}
