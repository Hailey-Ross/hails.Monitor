<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

define('ALLOW_CONFIG_INCLUDE', true);
require_once '/usr/www/mtnbound/secure/config.php';

/**
 * SETTINGS
 */
const JOB_NAME = 'change_log_session_compress';
const GAP_SECONDS = 300;
const SAFETY_DELAY_SECONDS = 600;
const FETCH_LIMIT = 25000;
const DELETE_CHUNK_SIZE = 2000;
const MAX_BATCHES_PER_RUN = 10;
const BATCH_SLEEP_MICROSECONDS = 200000;

/**
 * LOG SETTINGS
 */
const LOG_FILE = __DIR__ . '/phpcron.txt';
const LOG_MAX_SIZE_BYTES = 10 * 1024 * 1024;
const LOG_RETENTION_DAYS = 20;

/**
 * Rotate log if needed
 */
function rotateLogIfNeeded(): void
{
    if (!file_exists(LOG_FILE)) {
        return;
    }

    clearstatcache(true, LOG_FILE);
    $size = filesize(LOG_FILE);

    if ($size === false || $size < LOG_MAX_SIZE_BYTES) {
        return;
    }

    $rotatedName = __DIR__ . '/phpcron_' . gmdate('Ymd_His') . '.txt';
    @rename(LOG_FILE, $rotatedName);
}

/**
 * Delete old rotated logs
 */
function cleanupOldLogs(): void
{
    $files = glob(__DIR__ . '/phpcron_*.txt');
    if ($files === false) {
        return;
    }

    $cutoff = time() - (LOG_RETENTION_DAYS * 86400);

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        $mtime = filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($file);
        }
    }
}

/**
 * Logging helpers
 */
function logMessage(string $message): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function logError(string $message): void
{
    logMessage('ERROR: ' . $message);
}

/**
 * Catch fatal errors
 */
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null) {
        logError('FATAL: ' . json_encode($error));
    }
});

/**
 * DB connection
 */
function db(): PDO
{
    return new PDO(
        'mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"
        ]
    );
}

/**
 * Ensure compression state exists
 */
function ensureCompressionState(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        INSERT INTO compression_state (job_name, last_processed_id)
        VALUES (:job_name, 0)
        ON DUPLICATE KEY UPDATE job_name = VALUES(job_name)
    ");
    $stmt->execute([':job_name' => JOB_NAME]);
}

/**
 * Get last processed ID
 */
function getLastProcessedId(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        SELECT last_processed_id
        FROM compression_state
        WHERE job_name = :job_name
        LIMIT 1
    ");
    $stmt->execute([':job_name' => JOB_NAME]);
    $row = $stmt->fetch();

    return $row ? (int)$row['last_processed_id'] : 0;
}

/**
 * Save progress
 */
function setLastProcessedId(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("
        UPDATE compression_state
        SET last_processed_id = :id
        WHERE job_name = :job_name
    ");
    $stmt->execute([
        ':id' => $id,
        ':job_name' => JOB_NAME
    ]);
}

/**
 * Fetch eligible rows
 */
function fetchRows(PDO $pdo, int $afterId): array
{
    $safetyDelaySeconds = (int) SAFETY_DELAY_SECONDS;
    $fetchLimit = (int) FETCH_LIMIT;

    $sql = "
        SELECT
            id,
            change_time,
            region_name_gc,
            avatar_key_gc
        FROM change_log
        WHERE table_name = 'avatar_visits'
          AND operation IN ('INSERT', 'UPDATE')
          AND id > :after_id
          AND change_time < (UTC_TIMESTAMP() - INTERVAL {$safetyDelaySeconds} SECOND)
          AND avatar_key_gc IS NOT NULL
          AND avatar_key_gc <> ''
          AND region_name_gc IS NOT NULL
          AND region_name_gc <> ''
        ORDER BY id ASC
        LIMIT {$fetchLimit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':after_id' => $afterId]);

    return $stmt->fetchAll();
}

/**
 * Insert one compressed session
 */
function insertSession(PDO $pdo, array $session): void
{
    static $stmt = null;

    if ($stmt === null) {
        $stmt = $pdo->prepare("
            INSERT INTO avatar_sessions (
                avatar_key,
                region_name,
                visit_start,
                visit_end,
                heartbeat_count,
                duration_seconds,
                source_first_change_log_id,
                source_last_change_log_id
            ) VALUES (
                :avatar_key,
                :region_name,
                :visit_start,
                :visit_end,
                :heartbeat_count,
                :duration_seconds,
                :source_first_id,
                :source_last_id
            )
        ");
    }

    $stmt->execute([
        ':avatar_key' => $session['avatar_key'],
        ':region_name' => $session['region_name'],
        ':visit_start' => $session['visit_start'],
        ':visit_end' => $session['visit_end'],
        ':heartbeat_count' => $session['heartbeat_count'],
        ':duration_seconds' => max(0, strtotime($session['visit_end']) - strtotime($session['visit_start'])),
        ':source_first_id' => $session['source_first_change_log_id'],
        ':source_last_id' => $session['source_last_change_log_id'],
    ]);
}

/**
 * Delete processed raw rows
 */
function deleteIds(PDO $pdo, array $ids): void
{
    foreach (array_chunk($ids, DELETE_CHUNK_SIZE) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("DELETE FROM change_log WHERE id IN ($placeholders)");

        foreach ($chunk as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }

        $stmt->execute();
    }
}

/**
 * Main run
 */
function run(): void
{
    rotateLogIfNeeded();
    cleanupOldLogs();

    logMessage('--- START RUN ---');

    $pdo = db();
    ensureCompressionState($pdo);

    $lastProcessedId = getLastProcessedId($pdo);
    $openSessions = [];
    $deleteIds = [];
    $maxSeenId = $lastProcessedId;
    $batchCount = 0;

    while (true) {
        if ($batchCount >= MAX_BATCHES_PER_RUN) {
            logMessage('Reached max batch limit for this run. Stopping to rest.');
            break;
        }

        $rows = fetchRows($pdo, $lastProcessedId);

        if (!$rows) {
            logMessage('No eligible rows found.');
            break;
        }

        $batchCount++;
        $insertedSessions = 0;

        logMessage('Fetched ' . count($rows) . ' rows (batch ' . $batchCount . ' of ' . MAX_BATCHES_PER_RUN . ')');

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $avatarKey = (string)$row['avatar_key_gc'];
            $regionName = (string)$row['region_name_gc'];
            $changeTime = (string)$row['change_time'];

            $sessionKey = $avatarKey . '|' . $regionName;
            $maxSeenId = max($maxSeenId, $id);

            if (!isset($openSessions[$sessionKey])) {
                $openSessions[$sessionKey] = [
                    'avatar_key' => $avatarKey,
                    'region_name' => $regionName,
                    'visit_start' => $changeTime,
                    'visit_end' => $changeTime,
                    'heartbeat_count' => 1,
                    'source_first_change_log_id' => $id,
                    'source_last_change_log_id' => $id,
                    'row_ids' => [$id],
                ];
                continue;
            }

            $gap = strtotime($changeTime) - strtotime($openSessions[$sessionKey]['visit_end']);

            if ($gap <= GAP_SECONDS) {
                $openSessions[$sessionKey]['visit_end'] = $changeTime;
                $openSessions[$sessionKey]['heartbeat_count']++;
                $openSessions[$sessionKey]['source_last_change_log_id'] = $id;
                $openSessions[$sessionKey]['row_ids'][] = $id;
            } else {
                insertSession($pdo, $openSessions[$sessionKey]);
                $insertedSessions++;
                $deleteIds = array_merge($deleteIds, $openSessions[$sessionKey]['row_ids']);

                $openSessions[$sessionKey] = [
                    'avatar_key' => $avatarKey,
                    'region_name' => $regionName,
                    'visit_start' => $changeTime,
                    'visit_end' => $changeTime,
                    'heartbeat_count' => 1,
                    'source_first_change_log_id' => $id,
                    'source_last_change_log_id' => $id,
                    'row_ids' => [$id],
                ];
            }
        }

        $cutoff = time() - SAFETY_DELAY_SECONDS;

        foreach ($openSessions as $key => $session) {
            if (strtotime($session['visit_end']) <= ($cutoff - GAP_SECONDS)) {
                insertSession($pdo, $session);
                $insertedSessions++;
                $deleteIds = array_merge($deleteIds, $session['row_ids']);
                unset($openSessions[$key]);
            }
        }

        try {
            $pdo->beginTransaction();

            logMessage('Inserted ' . $insertedSessions . ' sessions');

            if ($deleteIds) {
                deleteIds($pdo, $deleteIds);
                logMessage('Deleted ' . count($deleteIds) . ' rows');
                $deleteIds = [];
            } else {
                logMessage('Deleted 0 rows');
            }

            setLastProcessedId($pdo, $maxSeenId);
            $pdo->commit();

            logMessage('Advanced to ID ' . $maxSeenId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            logError($e->getMessage());
            throw $e;
        }

        $lastProcessedId = $maxSeenId;

        if (BATCH_SLEEP_MICROSECONDS > 0) {
            usleep(BATCH_SLEEP_MICROSECONDS);
        }
    }

    logMessage('--- END RUN ---');
}

try {
    run();
} catch (Throwable $e) {
    logError('UNCAUGHT: ' . $e->getMessage());
    exit(1);
}