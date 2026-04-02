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
require_once '/PATH/TO/YOUR/SECURE/config.php';

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

function renderStatsGrid(PDO $pdo, string $regionName): string
{
    $windowEnd = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $windows = [
        'Last Hour' => '-1 hour',
        'Last 12 Hours' => '-12 hours',
        'Last 24 Hours' => '-1 day',
        'Last Week' => '-1 week'
    ];

    $stats = [];
    foreach ($windows as $label => $modifier) {
        $start = buildWindowStart($modifier);
        $stats[] = [
            'label' => $label,
            'unique' => getUniqueVisitors($pdo, $regionName, $start),
            'peak' => getPeakActiveVisitors($pdo, $regionName, $start, $windowEnd)
        ];
    }

    ob_start();
    ?>
    <div class="stats-selection-heading">Stats for <?= htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="stats-grid">
        <?php foreach ($stats as $stat): ?>
            <div class="stats-card">
                <div class="stats-card-title"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="stats-metric-row">
                    <span class="stats-metric-label">Unique Visitors</span>
                    <span class="stats-metric-value"><?= number_format((int)$stat['unique']) ?></span>
                </div>
                <div class="stats-metric-row">
                    <span class="stats-metric-label">Peak Visitors</span>
                    <span class="stats-metric-value"><?= number_format((int)$stat['peak']) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php

    return (string)ob_get_clean();
}

$monitorUserId = (int)($_SESSION['monitor_user_id'] ?? 0);
$canViewAll = !empty($_SESSION['monitor_can_view_all']);
$pdo = db();

$allowedRegions = $canViewAll ? [] : getAssignedRegions($pdo, $monitorUserId);
$selectedRegion = normalizeSelectedRegion((string)($_GET['region'] ?? ''), $canViewAll, $allowedRegions);

if (!$canViewAll && $selectedRegion === '' && count($allowedRegions) === 1) {
    $selectedRegion = $allowedRegions[0];
}

if (!$canViewAll && empty($allowedRegions)) {
    exit('<div class="empty">No assigned regions are available for your account.</div>');
}

if ($selectedRegion === '') {
    if ($canViewAll) {
        exit('<div class="empty">Choose a region to view stats.</div>');
    }

    exit('<div class="empty">Choose one of your assigned regions to view stats.</div>');
}

if ($canViewAll) {
    $allRegions = getAllRegionOptions($pdo);
    $regionExists = false;
    foreach ($allRegions as $regionName) {
        if (strcasecmp($regionName, $selectedRegion) === 0) {
            $selectedRegion = $regionName;
            $regionExists = true;
            break;
        }
    }

    if (!$regionExists) {
        exit('<div class="empty">That region could not be found. Please choose a region from the list.</div>');
    }
}

try {
    echo renderStatsGrid($pdo, $selectedRegion);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="empty">Unable to load region stats right now.</div>';
}
