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
require_once '/path/to/your/hosted/config.php';

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

function safeTimezone(string $timezone): string
{
    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Throwable $e) {
        return 'America/Denver';
    }
}

function formatLocal(?string $utcValue, string $timezone): string
{
    if ($utcValue === null || $utcValue === '') {
        return '';
    }

    $dt = new DateTime($utcValue, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($timezone));

    return $dt->format('Y-m-d h:i:s A T');
}

function secondsSinceUtc(?string $utcValue): ?int
{
    if ($utcValue === null || $utcValue === '') {
        return null;
    }

    $then = new DateTime($utcValue, new DateTimeZone('UTC'));
    $now = new DateTime('now', new DateTimeZone('UTC'));

    return max(0, $now->getTimestamp() - $then->getTimestamp());
}

function formatAge(?string $utcValue): string
{
    $seconds = secondsSinceUtc($utcValue);

    if ($seconds === null) {
        return '';
    }

    if ($seconds < 60) {
        return $seconds . 's ago';
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm ago';
    }

    if ($seconds < 86400) {
        return floor($seconds / 3600) . 'h ago';
    }

    return floor($seconds / 86400) . 'd ago';
}

function getDashboardRows(PDO $pdo, int $monitorUserId, bool $canViewAll, string $regionFilterRaw): array
{
    if ($canViewAll) {
        $stmt = $pdo->query("
            SELECT
                v.avatar_name,
                v.region_name,
                v.last_seen,
                COUNT(s.id) AS visit_count
            FROM avatar_visits v
            LEFT JOIN avatar_sessions s
                ON s.avatar_key = v.avatar_key
               AND s.region_name = v.region_name
            GROUP BY
                v.avatar_key,
                v.avatar_name,
                v.region_name,
                v.last_seen
            ORDER BY v.last_seen DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT
                v.avatar_name,
                v.region_name,
                v.last_seen,
                COUNT(s.id) AS visit_count
            FROM avatar_visits v
            INNER JOIN monitor_user_regions mur
                ON mur.user_id = :user_id
               AND mur.region_name = v.region_name
            LEFT JOIN avatar_sessions s
                ON s.avatar_key = v.avatar_key
               AND s.region_name = v.region_name
            GROUP BY
                v.avatar_key,
                v.avatar_name,
                v.region_name,
                v.last_seen
            ORDER BY v.last_seen DESC
        ");
        $stmt->execute([':user_id' => $monitorUserId]);
    }

    $rows = $stmt->fetchAll();

    if ($regionFilterRaw !== '') {
        $regionFilters = array_values(array_filter(array_map('trim', explode(',', $regionFilterRaw))));

        if (!empty($regionFilters)) {
            $rows = array_values(array_filter($rows, function (array $row) use ($regionFilters): bool {
                $rowRegion = trim((string)($row['region_name'] ?? ''));

                foreach ($regionFilters as $filter) {
                    if ($filter !== '' && stripos($rowRegion, $filter) !== false) {
                        return true;
                    }
                }

                return false;
            }));
        }
    }

    return $rows;
}

$monitorUserId = (int)($_SESSION['monitor_user_id'] ?? 0);
$canViewAll = !empty($_SESSION['monitor_can_view_all']);
$timezone = safeTimezone((string)($_SESSION['monitor_timezone'] ?? 'America/Denver'));
$regionFilterRaw = trim((string)($_SESSION['monitor_region_filter'] ?? ''));

try {
    $pdo = db();
    $rows = getDashboardRows($pdo, $monitorUserId, $canViewAll, $regionFilterRaw);

    $totalAvatars = count($rows);
    $freshCount = 0;

    foreach ($rows as $row) {
        $ageSeconds = secondsSinceUtc($row['last_seen']);

        if ($ageSeconds !== null && $ageSeconds < 120) {
            $freshCount++;
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('<div class="empty">Unable to refresh dashboard data right now.</div>');
}
?>
<div class="summary">
    <div class="card">
        <div class="label">Visible Rows</div>
        <div class="value"><?= number_format($totalAvatars) ?></div>
    </div>

    <div class="card">
        <div class="label">Currently Active</div>
        <div class="value"><?= number_format($freshCount) ?></div>
    </div>

    <div class="card">
        <div class="label">Timezone display</div>
        <div class="value value-small"><?= htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</div>

<div class="table-wrap">
    <?php if (!$rows): ?>
        <div class="empty">No rows available for your assigned regions.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Avatar</th>
                    <th>Region</th>
                    <th>Visits</th>
                    <th>Last Seen</th>
                    <th>Age</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
						$ageSeconds = secondsSinceUtc($row['last_seen']);
						$statusClass = 'stale';
						$statusText = 'Stale';

						if ($ageSeconds !== null) {
							if ($ageSeconds < 120) {
								$statusClass = 'fresh';
								$statusText = 'Active';
							} elseif ($ageSeconds < 300) {
								$statusClass = 'idle';
								$statusText = 'Idle';
							}
						}
					?>
                <tr>
                    <td><?= htmlspecialchars((string)$row['avatar_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$row['region_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="visits"><?= number_format((int)$row['visit_count']) ?></td>
                    <td><?= htmlspecialchars(formatLocal($row['last_seen'], $timezone), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(formatAge($row['last_seen']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="<?= $statusClass ?>">
						<?= $statusText ?>
					</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
