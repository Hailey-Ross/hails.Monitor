<?php
declare(strict_types=1);

ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_secure', '1');

session_start();

if (!isset($_SESSION['monitor_logged_in']) || $_SESSION['monitor_logged_in'] !== true) {
    http_response_code(403);
    exit('<div class="empty">Your session has expired. Please log in again.</div>');
}

define('ALLOW_CONFIG_INCLUDE', true);
require_once '/usr/www/mtnbound/secure/config.php';

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

function getAssignedRegions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT region_name
        FROM monitor_user_regions
        WHERE user_id = :user_id
        ORDER BY region_name ASC
    ');
    $stmt->execute([':user_id' => $userId]);

    return array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['region_name'] ?? '')),
        $stmt->fetchAll()
    )));
}

function getAllRegionOptions(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT region_name
        FROM (
            SELECT DISTINCT region_name
            FROM avatar_sessions
            WHERE region_name IS NOT NULL AND region_name <> ""

            UNION

            SELECT DISTINCT region_name
            FROM monitor_user_regions
            WHERE region_name IS NOT NULL AND region_name <> ""

            UNION

            SELECT DISTINCT region_name
            FROM region_scanners
            WHERE region_name IS NOT NULL AND region_name <> ""
        ) region_options
        ORDER BY region_name ASC
    ');

    return array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['region_name'] ?? '')),
        $stmt->fetchAll()
    )));
}

function normalizeSelectedRegion(string $raw, bool $canViewAll, array $allowedRegions): string
{
    $selected = trim($raw);

    if ($selected === '') {
        return '';
    }

    if ($canViewAll) {
        return $selected;
    }

    foreach ($allowedRegions as $regionName) {
        if (strcasecmp($regionName, $selected) === 0) {
            return $regionName;
        }
    }

    return '';
}

function safeTimezone(string $timezone): string
{
    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Throwable $e) {
        return 'America/Denver';
    }
}


function normalizeRequestedRegions(string $raw): array
{
    $parts = array_map('trim', explode(',', $raw));
    $parts = array_filter($parts, static fn(string $value): bool => $value !== '');

    $normalized = [];
    $seen = [];

    foreach ($parts as $part) {
        $key = mb_strtolower($part, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $normalized[] = $part;
    }

    return $normalized;
}

function resolveAllowedRegions(array $requestedRegions, bool $canViewAll, array $allowedRegions, array $allRegions): array
{
    $resolved = [];
    $hadSkipped = false;

    $allowedLookup = [];
    foreach ($allowedRegions as $regionName) {
        $allowedLookup[mb_strtolower($regionName, 'UTF-8')] = $regionName;
    }

    $allLookup = [];
    foreach ($allRegions as $regionName) {
        $allLookup[mb_strtolower($regionName, 'UTF-8')] = $regionName;
    }

    foreach ($requestedRegions as $requestedRegion) {
        $lookupKey = mb_strtolower($requestedRegion, 'UTF-8');

        if ($canViewAll) {
            if (!isset($allLookup[$lookupKey])) {
                $hadSkipped = true;
                continue;
            }

            $resolved[] = $allLookup[$lookupKey];
            continue;
        }

        if (!isset($allowedLookup[$lookupKey])) {
            $hadSkipped = true;
            continue;
        }

        $resolved[] = $allowedLookup[$lookupKey];
    }

    return [
        'regions' => $resolved,
        'had_skipped' => $hadSkipped
    ];
}

function buildWindowStart(string $modifier): string
{
    $dt = new DateTime('now', new DateTimeZone('UTC'));
    $dt->modify($modifier);
    return $dt->format('Y-m-d H:i:s');
}

function getUniqueVisitors(PDO $pdo, string $regionName, string $windowStart): int
{
    $stmt = $pdo->prepare('
        SELECT COUNT(DISTINCT avatar_key) AS visitor_count
        FROM avatar_sessions
        WHERE region_name = :region_name
          AND visit_end >= :window_start
    ');
    $stmt->execute([
        ':region_name' => $regionName,
        ':window_start' => $windowStart
    ]);

    return (int)($stmt->fetchColumn() ?: 0);
}

function getPeakActiveVisitors(PDO $pdo, string $regionName, string $windowStart, string $windowEnd): int
{
    $stmt = $pdo->prepare('
        SELECT visit_start, visit_end
        FROM avatar_sessions
        WHERE region_name = :region_name
          AND visit_start <= :window_end
          AND visit_end >= :window_start
        ORDER BY visit_start ASC, visit_end ASC
    ');
    $stmt->execute([
        ':region_name' => $regionName,
        ':window_start' => $windowStart,
        ':window_end' => $windowEnd
    ]);

    $events = [];

    foreach ($stmt->fetchAll() as $row) {
        $start = max((string)$row['visit_start'], $windowStart);
        $end = min((string)$row['visit_end'], $windowEnd);

        if ($end < $windowStart || $start > $windowEnd || $start > $end) {
            continue;
        }

        $events[] = ['time' => $start, 'delta' => 1];
        $events[] = ['time' => $end, 'delta' => -1];
    }

    if (!$events) {
        return 0;
    }

    usort($events, static function (array $a, array $b): int {
        if ($a['time'] === $b['time']) {
            return $b['delta'] <=> $a['delta'];
        }

        return strcmp($a['time'], $b['time']);
    });

    $current = 0;
    $peak = 0;

    foreach ($events as $event) {
        $current += (int)$event['delta'];
        if ($current > $peak) {
            $peak = $current;
        }
    }

    return $peak;
}

function getAverageVisitDurationSeconds(PDO $pdo, string $regionName, string $windowStart): float
{
    $stmt = $pdo->prepare('
        SELECT AVG(duration_seconds) AS avg_duration_seconds
        FROM avatar_sessions
        WHERE region_name = :region_name
          AND visit_end >= :window_start
          AND duration_seconds > 0
    ');
    $stmt->execute([
        ':region_name' => $regionName,
        ':window_start' => $windowStart
    ]);

    $value = $stmt->fetchColumn();

    return $value !== false && $value !== null ? (float)$value : 0.0;
}

function formatDuration(float $seconds): string
{
    $seconds = (int)round($seconds);

    if ($seconds <= 0) {
        return '0s';
    }

    $days = intdiv($seconds, 86400);
    $seconds %= 86400;

    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;

    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];

    if ($days > 0) {
        $parts[] = $days . 'd';
    }

    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }

    if ($minutes > 0) {
        $parts[] = $minutes . 'm';
    }

    if ($seconds > 0 || empty($parts)) {
        $parts[] = $seconds . 's';
    }

    return implode(' ', $parts);
}

function formatHourBucketLabel(int $hour, string $timezone): string
{
    $dt = new DateTime(sprintf('2000-01-01 %02d:00:00', $hour), new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($timezone));

    return $dt->format('g:00 A T');
}

function getActivityHourStats(PDO $pdo, string $regionName, string $windowStart, string $windowEnd, string $timezone): array
{
    $stmt = $pdo->prepare('
        SELECT visit_start, visit_end
        FROM avatar_sessions
        WHERE region_name = :region_name
          AND visit_start <= :window_end
          AND visit_end >= :window_start
        ORDER BY visit_start ASC, visit_end ASC
    ');
    $stmt->execute([
        ':region_name' => $regionName,
        ':window_start' => $windowStart,
        ':window_end' => $windowEnd
    ]);

    $hourCounts = array_fill(0, 24, 0);
    $tz = new DateTimeZone($timezone);
    $utc = new DateTimeZone('UTC');

    foreach ($stmt->fetchAll() as $row) {
        $startUtc = new DateTime((string)$row['visit_start'], $utc);
        $endUtc = new DateTime((string)$row['visit_end'], $utc);
        $windowStartUtc = new DateTime($windowStart, $utc);
        $windowEndUtc = new DateTime($windowEnd, $utc);

        if ($startUtc < $windowStartUtc) {
            $startUtc = clone $windowStartUtc;
        }

        if ($endUtc > $windowEndUtc) {
            $endUtc = clone $windowEndUtc;
        }

        if ($startUtc > $endUtc) {
            continue;
        }

        $cursor = clone $startUtc;
        $cursor->setTimezone($tz);
        $cursor->setTime((int)$cursor->format('H'), 0, 0);

        $localEnd = clone $endUtc;
        $localEnd->setTimezone($tz);

        while ($cursor <= $localEnd) {
            $hourIndex = (int)$cursor->format('G');
            $hourCounts[$hourIndex]++;

            $cursor->modify('+1 hour');
        }
    }

    $maxCount = max($hourCounts);
    $minCount = min($hourCounts);

    $mostActiveHour = array_search($maxCount, $hourCounts, true);
    $leastActiveHour = array_search($minCount, $hourCounts, true);

    return [
        'most_active_hour' => formatHourBucketLabel((int)$mostActiveHour, $timezone),
        'least_active_hour' => formatHourBucketLabel((int)$leastActiveHour, $timezone)
    ];
}

function renderStatsGrid(PDO $pdo, string $regionName, string $timezone): string
{
    $windowEnd = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $windows = [
        'Last Hour' => '-1 hour',
        'Last 12 Hours' => '-12 hours',
        'Last 24 Hours' => '-1 day',
        'Last Week' => '-1 week',
        'Last Month' => '-1 month',
        'Last Year' => '-1 year'
    ];

    $stats = [];
    foreach ($windows as $label => $modifier) {
        $start = buildWindowStart($modifier);

        $stat = [
            'label' => $label,
            'unique' => getUniqueVisitors($pdo, $regionName, $start),
            'peak' => getPeakActiveVisitors($pdo, $regionName, $start, $windowEnd),
            'average_duration' => null,
            'most_active_hour' => null,
            'least_active_hour' => null
        ];

		if (
            $label === 'Last 12 Hours' ||
            $label === 'Last 24 Hours' ||
            $label === 'Last Week' ||
            $label === 'Last Month' ||
            $label === 'Last Year'
		) {
            $stat['average_duration'] = formatDuration(
                getAverageVisitDurationSeconds($pdo, $regionName, $start)
            );

            $hourStats = getActivityHourStats($pdo, $regionName, $start, $windowEnd, $timezone);
            $stat['most_active_hour'] = $hourStats['most_active_hour'];
            $stat['least_active_hour'] = $hourStats['least_active_hour'];
        }

        $stats[] = $stat;
    }

    ob_start();
    ?>
    <div class="stats-selection-heading">Stats for <?= htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="stats-grid">
        <?php foreach ($stats as $stat): ?>
            <div class="stats-card<?= $stat['label'] === 'Last Hour' ? ' stats-card-compact' : '' ?>">
                <div class="stats-card-title"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="stats-metric-row">
                    <span class="stats-metric-label">Unique Visitors</span>
                    <span class="stats-metric-value"><?= number_format((int)$stat['unique']) ?></span>
                </div>
                <div class="stats-metric-row">
                    <span class="stats-metric-label">Peak Visitors</span>
                    <span class="stats-metric-value"><?= number_format((int)$stat['peak']) ?></span>
                </div>
                <?php if ($stat['average_duration'] !== null): ?>
                    <div class="stats-metric-row">
                        <span class="stats-metric-label">Average Visit Duration</span>
                        <span class="stats-metric-value"><?= htmlspecialchars($stat['average_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="stats-metric-row">
                        <span class="stats-metric-label">Most Active Hour</span>
                        <span class="stats-metric-value"><?= htmlspecialchars($stat['most_active_hour'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="stats-metric-row">
                        <span class="stats-metric-label">Least Active Hour</span>
                        <span class="stats-metric-value"><?= htmlspecialchars($stat['least_active_hour'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php

    return (string)ob_get_clean();
}

$monitorUserId = (int)($_SESSION['monitor_user_id'] ?? 0);
$canViewAll = !empty($_SESSION['monitor_can_view_all']);
$timezone = safeTimezone((string)($_SESSION['monitor_timezone'] ?? 'America/Denver'));
$pdo = db();

$allowedRegions = $canViewAll ? [] : getAssignedRegions($pdo, $monitorUserId);
$requestedRegions = normalizeRequestedRegions((string)($_GET['region'] ?? ''));

if (!$canViewAll && empty($allowedRegions)) {
    exit('<div class="empty">No assigned regions are available for your account.</div>');
}

if (empty($requestedRegions)) {
    if ($canViewAll) {
        exit('<div class="empty">Choose a region to view stats.</div>');
    }

    exit('<div class="empty">Choose one or more of your assigned regions to view stats.</div>');
}

$allRegions = getAllRegionOptions($pdo);
$resolvedRegionResult = resolveAllowedRegions($requestedRegions, $canViewAll, $allowedRegions, $allRegions);
$resolvedRegions = $resolvedRegionResult['regions'];
$hadSkippedRegions = $resolvedRegionResult['had_skipped'];

if (empty($resolvedRegions) && !$hadSkippedRegions) {
    if ($canViewAll) {
        exit('<div class="empty">That region could not be found. Please choose a region from the list.</div>');
    }

    exit('<div class="empty">Choose one or more of your assigned regions to view stats.</div>');
}

try {
    $output = '';

    if ($hadSkippedRegions) {
        $output .= '<div class="error-message">One or more regions could not be shown because they were invalid or you do not have permission to view them.</div>';
    }

    foreach ($resolvedRegions as $resolvedRegion) {
        $output .= renderStatsGrid($pdo, $resolvedRegion, $timezone);
    }

    if ($output === '') {
        $output = '<div class="error-message">One or more regions could not be shown because they were invalid or you do not have permission to view them.</div>';
    }

    echo $output;
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="empty">Unable to load region stats right now.</div>';
}
