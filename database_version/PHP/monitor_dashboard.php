<?php
declare(strict_types=1);

ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_secure', '1');

session_start();

if (!isset($_SESSION['monitor_logged_in']) || $_SESSION['monitor_logged_in'] !== true) {
    header('Location: monitor_login.php');
    exit;
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

function safeTimezone(string $timezone): string
{
    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Throwable $e) {
        return 'America/Los_Angeles';
    }
}

function timezoneOptions(): array
{
    $preferred = [
		'America/New_York',
        'America/Chicago',
        'America/Los_Angeles',
		'America/Denver',
        'America/Phoenix',
        'America/Anchorage',
        'Pacific/Honolulu',
        'America/Toronto',
        'Europe/London',
        'Europe/Berlin',
        'Europe/Paris',
        'UTC'
    ];

    $all = timezone_identifiers_list();
    $options = [];

    foreach ($preferred as $tz) {
        if (in_array($tz, $all, true)) {
            $options[] = $tz;
        }
    }

    foreach ($all as $tz) {
        if (
            str_starts_with($tz, 'America/') ||
            str_starts_with($tz, 'Europe/') ||
            str_starts_with($tz, 'Pacific/') ||
            $tz === 'UTC'
        ) {
            if (!in_array($tz, $options, true)) {
                $options[] = $tz;
            }
        }
    }

    return $options;
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

function normalizeRegionList(string $raw): array
{
    $parts = array_map('trim', explode(',', $raw));
    $parts = array_filter($parts, static fn(string $value): bool => $value !== '');
    $parts = array_values(array_unique($parts));

    return $parts;
}

function isValidRole(string $role): bool
{
    return in_array($role, ['user', 'moderator', 'superadmin'], true);
}

function getAssignedRegions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("\n        SELECT region_name\n        FROM monitor_user_regions\n        WHERE user_id = :user_id\n        ORDER BY region_name ASC\n    ");
    $stmt->execute([':user_id' => $userId]);

    return array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['region_name'] ?? '')),
        $stmt->fetchAll()
    )));
}

function getAllRegionOptions(PDO $pdo): array
{
    $stmt = $pdo->query("\n        SELECT region_name\n        FROM (\n            SELECT DISTINCT region_name\n            FROM avatar_sessions\n            WHERE region_name IS NOT NULL AND region_name <> ''\n\n            UNION\n\n            SELECT DISTINCT region_name\n            FROM monitor_user_regions\n            WHERE region_name IS NOT NULL AND region_name <> ''\n\n            UNION\n\n            SELECT DISTINCT region_name\n            FROM region_scanners\n            WHERE region_name IS NOT NULL AND region_name <> ''\n        ) region_options\n        ORDER BY region_name ASC\n    ");

    return array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['region_name'] ?? '')),
        $stmt->fetchAll()
    )));
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

function renderDashboardData(array $rows, string $timezone): string
{
    ob_start();

    $totalAvatars = count($rows);
    $freshCount = 0;

    foreach ($rows as $row) {
        $ageSeconds = secondsSinceUtc($row['last_seen']);

        if ($ageSeconds !== null && $ageSeconds < 120) {
            $freshCount++;
        }
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
                        <td class="<?= $statusClass ?>"><?= $statusText ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php

    return (string)ob_get_clean();
}

$monitorUserId = (int)($_SESSION['monitor_user_id'] ?? 0);
$monitorUsername = (string)($_SESSION['monitor_username'] ?? '');
$displayName = (string)($_SESSION['monitor_display_name'] ?? $_SESSION['monitor_username'] ?? 'User');
$timezone = safeTimezone((string)($_SESSION['monitor_timezone'] ?? 'America/Los_Angeles'));
$canViewAll = !empty($_SESSION['monitor_can_view_all']);
$role = (string)($_SESSION['monitor_role'] ?? 'user');
$regionFilterRaw = trim((string)($_SESSION['monitor_region_filter'] ?? ''));

$isSuperAdmin = ($role === 'superadmin');
$isModerator = ($role === 'moderator');
$protectedSuperAdminUsername = defined('MONITOR_SUPERADMIN') ? (string)MONITOR_SUPERADMIN : '';

$pdo = db();

$settingsMessage = '';
$settingsError = '';
$userCreateMessage = '';
$userCreateError = '';
$userUpdateMessage = '';
$userUpdateError = '';
$userDeleteMessage = '';
$userDeleteError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_display_settings') {
        $requestedTimezone = safeTimezone((string)($_POST['timezone'] ?? 'America/Los_Angeles'));
        $requestedRegionFilter = trim((string)($_POST['region_filter'] ?? ''));

        try {
            $stmt = $pdo->prepare("
                UPDATE monitor_users
                SET timezone = :timezone
                WHERE id = :user_id
                LIMIT 1
            ");
            $stmt->execute([
                ':timezone' => $requestedTimezone,
                ':user_id' => $monitorUserId
            ]);

            $_SESSION['monitor_timezone'] = $requestedTimezone;
            $_SESSION['monitor_region_filter'] = $requestedRegionFilter;

            $timezone = $requestedTimezone;
            $regionFilterRaw = $requestedRegionFilter;

            $settingsMessage = 'Display settings updated successfully.';
        } catch (Throwable $e) {
            $settingsError = 'Unable to update display settings right now.';
        }
    }

    if ($action === 'create_user' && ($isSuperAdmin || $isModerator)) {
        $newUsername = trim((string)($_POST['new_username'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newDisplayName = trim((string)($_POST['new_display_name'] ?? ''));
        $newTimezone = safeTimezone((string)($_POST['new_timezone'] ?? 'America/Los_Angeles'));
        $newCanViewAll = isset($_POST['new_can_view_all']) ? 1 : 0;
        $newIsActive = isset($_POST['new_is_active']) ? 1 : 0;
        $newRegionsRaw = trim((string)($_POST['new_regions'] ?? ''));
        $newRegions = normalizeRegionList($newRegionsRaw);
        $newRole = $isSuperAdmin ? (string)($_POST['new_role'] ?? 'user') : 'user';

        if (!isValidRole($newRole)) {
            $newRole = 'user';
        }

        if ($isModerator && $newRole !== 'user') {
            $newRole = 'user';
        }

        if ($newUsername === '') {
            $userCreateError = 'Username is required.';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $newUsername)) {
            $userCreateError = 'Username must be 3 to 100 characters and only contain letters, numbers, underscores, periods, or hyphens.';
        } elseif ($newPassword === '') {
            $userCreateError = 'Password is required.';
        } elseif (strlen($newPassword) < 8) {
            $userCreateError = 'Password must be at least 8 characters long.';
        } elseif (!$newCanViewAll && empty($newRegions)) {
            $userCreateError = 'Please provide at least one region unless the user can view all regions.';
        } else {
            try {
                $pdo->beginTransaction();

                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM monitor_users
                    WHERE username = :username
                    LIMIT 1
                ");
                $checkStmt->execute([':username' => $newUsername]);

                if ($checkStmt->fetch()) {
                    throw new RuntimeException('That username already exists.');
                }

                $insertUserStmt = $pdo->prepare("
                    INSERT INTO monitor_users (
                        username,
                        password_hash,
                        display_name,
                        timezone,
                        can_view_all,
                        is_active,
                        role
                    ) VALUES (
                        :username,
                        :password_hash,
                        :display_name,
                        :timezone,
                        :can_view_all,
                        :is_active,
                        :role
                    )
                ");
                $insertUserStmt->execute([
                    ':username' => $newUsername,
                    ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':display_name' => ($newDisplayName !== '' ? $newDisplayName : $newUsername),
                    ':timezone' => $newTimezone,
                    ':can_view_all' => $newCanViewAll,
                    ':is_active' => $newIsActive,
                    ':role' => $newRole
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                if (!$newCanViewAll && !empty($newRegions)) {
                    $insertRegionStmt = $pdo->prepare("
                        INSERT INTO monitor_user_regions (user_id, region_name)
                        VALUES (:user_id, :region_name)
                    ");

                    foreach ($newRegions as $regionName) {
                        $insertRegionStmt->execute([
                            ':user_id' => $newUserId,
                            ':region_name' => $regionName
                        ]);
                    }
                }

                $pdo->commit();
                $userCreateMessage = 'User created successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof RuntimeException) {
                    $userCreateError = $e->getMessage();
                } else {
                    $userCreateError = 'Unable to create user right now.';
                }
            }
        }
    }

    if ($action === 'update_user' && ($isSuperAdmin || $isModerator)) {
        $editUserId = (int)($_POST['edit_user_id'] ?? 0);
        $editUsername = trim((string)($_POST['edit_username'] ?? ''));
        $editDisplayName = trim((string)($_POST['edit_display_name'] ?? ''));
        $editTimezone = safeTimezone((string)($_POST['edit_timezone'] ?? 'America/Los_Angeles'));
        $editCanViewAll = isset($_POST['edit_can_view_all']) ? 1 : 0;
        $editIsActive = isset($_POST['edit_is_active']) ? 1 : 0;
        $editRegionsRaw = trim((string)($_POST['edit_regions'] ?? ''));
        $editRegions = normalizeRegionList($editRegionsRaw);
        $editPassword = (string)($_POST['edit_password'] ?? '');

        try {
            $targetStmt = $pdo->prepare("
                SELECT id, username, role
                FROM monitor_users
                WHERE id = :id
                LIMIT 1
            ");
            $targetStmt->execute([':id' => $editUserId]);
            $targetUser = $targetStmt->fetch();

            if (!$targetUser) {
                throw new RuntimeException('That user no longer exists.');
            }

            $targetUsername = (string)$targetUser['username'];
            $targetRole = (string)$targetUser['role'];

            if ($isModerator) {
                if ($editUserId === $monitorUserId) {
                    throw new RuntimeException('Moderators cannot modify their own profile here.');
                }

                if ($targetRole !== 'user') {
                    throw new RuntimeException('Moderators cannot modify moderators or the superadmin.');
                }
            }

            if (!$isSuperAdmin && $targetRole === 'superadmin') {
                throw new RuntimeException('Only the superadmin can modify this account.');
            }

            $editRole = $targetRole;
            if ($isSuperAdmin) {
                $requestedRole = (string)($_POST['edit_role'] ?? $targetRole);
                if (isValidRole($requestedRole)) {
                    $editRole = $requestedRole;
                }
            }

            if ($editUserId <= 0) {
                throw new RuntimeException('Invalid user selected.');
            }

            if ($editUsername === '') {
                throw new RuntimeException('Username is required.');
            }

            if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $editUsername)) {
                throw new RuntimeException('Username must be 3 to 100 characters and only contain letters, numbers, underscores, periods, or hyphens.');
            }

            if ($editPassword !== '' && strlen($editPassword) < 8) {
                throw new RuntimeException('New password must be at least 8 characters long.');
            }

            if (!$editCanViewAll && empty($editRegions)) {
                throw new RuntimeException('Please provide at least one region unless the user can view all regions.');
            }

            if ($editUserId === $monitorUserId && $editIsActive !== 1) {
                throw new RuntimeException('You cannot deactivate the account you are currently logged in with.');
            }

            $duplicateStmt = $pdo->prepare("
                SELECT id
                FROM monitor_users
                WHERE username = :username
                  AND id <> :id
                LIMIT 1
            ");
            $duplicateStmt->execute([
                ':username' => $editUsername,
                ':id' => $editUserId
            ]);

            if ($duplicateStmt->fetch()) {
                throw new RuntimeException('That username is already in use by another account.');
            }

            $pdo->beginTransaction();

            if ($editPassword !== '') {
                $updateUserStmt = $pdo->prepare("
                    UPDATE monitor_users
                    SET
                        username = :username,
                        password_hash = :password_hash,
                        display_name = :display_name,
                        timezone = :timezone,
                        can_view_all = :can_view_all,
                        is_active = :is_active,
                        role = :role
                    WHERE id = :id
                    LIMIT 1
                ");
                $updateUserStmt->execute([
                    ':username' => $editUsername,
                    ':password_hash' => password_hash($editPassword, PASSWORD_DEFAULT),
                    ':display_name' => ($editDisplayName !== '' ? $editDisplayName : $editUsername),
                    ':timezone' => $editTimezone,
                    ':can_view_all' => $editCanViewAll,
                    ':is_active' => $editIsActive,
                    ':role' => $editRole,
                    ':id' => $editUserId
                ]);
            } else {
                $updateUserStmt = $pdo->prepare("
                    UPDATE monitor_users
                    SET
                        username = :username,
                        display_name = :display_name,
                        timezone = :timezone,
                        can_view_all = :can_view_all,
                        is_active = :is_active,
                        role = :role
                    WHERE id = :id
                    LIMIT 1
                ");
                $updateUserStmt->execute([
                    ':username' => $editUsername,
                    ':display_name' => ($editDisplayName !== '' ? $editDisplayName : $editUsername),
                    ':timezone' => $editTimezone,
                    ':can_view_all' => $editCanViewAll,
                    ':is_active' => $editIsActive,
                    ':role' => $editRole,
                    ':id' => $editUserId
                ]);
            }

            $deleteRegionsStmt = $pdo->prepare("
                DELETE FROM monitor_user_regions
                WHERE user_id = :user_id
            ");
            $deleteRegionsStmt->execute([':user_id' => $editUserId]);

            if (!$editCanViewAll && !empty($editRegions)) {
                $insertRegionStmt = $pdo->prepare("
                    INSERT INTO monitor_user_regions (user_id, region_name)
                    VALUES (:user_id, :region_name)
                ");

                foreach ($editRegions as $regionName) {
                    $insertRegionStmt->execute([
                        ':user_id' => $editUserId,
                        ':region_name' => $regionName
                    ]);
                }
            }

            $pdo->commit();

            if ($editUserId === $monitorUserId) {
                $_SESSION['monitor_username'] = $editUsername;
                $_SESSION['monitor_display_name'] = ($editDisplayName !== '' ? $editDisplayName : $editUsername);
                $_SESSION['monitor_timezone'] = $editTimezone;
                $_SESSION['monitor_can_view_all'] = ($editCanViewAll === 1);
                $_SESSION['monitor_role'] = $editRole;
            }

            $userUpdateMessage = 'User updated successfully.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $userUpdateError = $e->getMessage();
            } else {
                $userUpdateError = 'Unable to update user right now.';
            }
        }
    }

    if ($action === 'delete_user' && $isSuperAdmin) {
        $deleteUserId = (int)($_POST['delete_user_id'] ?? 0);

        if ($deleteUserId <= 0) {
            $userDeleteError = 'Invalid user selected.';
        } elseif ($deleteUserId === $monitorUserId) {
            $userDeleteError = 'You cannot delete the account you are currently logged in with.';
        } else {
            try {
                $checkDeleteStmt = $pdo->prepare("
                    SELECT id, username, role
                    FROM monitor_users
                    WHERE id = :id
                    LIMIT 1
                ");
                $checkDeleteStmt->execute([':id' => $deleteUserId]);
                $deleteTarget = $checkDeleteStmt->fetch();

                if (!$deleteTarget) {
                    throw new RuntimeException('That user no longer exists.');
                }

                if (
                    $protectedSuperAdminUsername !== '' &&
                    strcasecmp((string)$deleteTarget['username'], $protectedSuperAdminUsername) === 0
                ) {
                    throw new RuntimeException($protectedSuperAdminUsername . ' cannot be deleted from the UI.');
                }

                $deleteStmt = $pdo->prepare("
                    DELETE FROM monitor_users
                    WHERE id = :id
                    LIMIT 1
                ");
                $deleteStmt->execute([':id' => $deleteUserId]);

                if ($deleteStmt->rowCount() < 1) {
                    throw new RuntimeException('Unable to delete that user right now.');
                }

                $userDeleteMessage = 'User deleted successfully.';
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException) {
                    $userDeleteError = $e->getMessage();
                } else {
                    $userDeleteError = 'Unable to delete user right now.';
                }
            }
        }
    }
}

$timezoneOptions = timezoneOptions();

$managedUsers = [];
if ($isSuperAdmin || $isModerator) {
    $usersStmt = $pdo->query("
        SELECT
            u.id,
            u.username,
            u.display_name,
            u.timezone,
            u.can_view_all,
            u.is_active,
            u.role,
            u.created_at,
            GROUP_CONCAT(mur.region_name ORDER BY mur.region_name SEPARATOR ', ') AS regions
        FROM monitor_users u
        LEFT JOIN monitor_user_regions mur
            ON mur.user_id = u.id
        GROUP BY
            u.id,
            u.username,
            u.display_name,
            u.timezone,
            u.can_view_all,
            u.is_active,
            u.role,
            u.created_at
        ORDER BY u.username ASC
    ");
    $managedUsers = $usersStmt->fetchAll();
}

$rows = getDashboardRows($pdo, $monitorUserId, $canViewAll, $regionFilterRaw);
$dashboardDataHtml = renderDashboardData($rows, $timezone);
$assignedRegions = getAssignedRegions($pdo, $monitorUserId);
$regionOptions = $canViewAll ? getAllRegionOptions($pdo) : $assignedRegions;
$defaultStatsRegion = '';
if ($canViewAll) {
    $defaultStatsRegion = '';
} elseif (count($assignedRegions) === 1) {
    $defaultStatsRegion = $assignedRegions[0];
}

$openPanel = '';
if ($settingsMessage !== '' || $settingsError !== '') {
    $openPanel = 'settings';
} elseif ($userCreateMessage !== '' || $userCreateError !== '') {
    $openPanel = 'create-user';
} elseif ($userUpdateMessage !== '' || $userUpdateError !== '' || $userDeleteMessage !== '' || $userDeleteError !== '') {
    $openPanel = 'manage-users';
} elseif ($defaultStatsRegion !== '') {
    $openPanel = 'stats';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>hails.Monitor Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #101418;
            color: #eef2f5;
            margin: 0;
            padding: 16px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        h1 {
            margin: 0;
            font-size: 24px;
        }

        .top-links {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 14px;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #1a2128;
            border: 1px solid #2d3742;
            color: #d8e6ff;
            font-size: 12px;
            font-weight: bold;
        }

        .status-chip.paused {
            color: #ffd39a;
            border-color: rgba(255, 180, 80, 0.35);
            background: rgba(255, 180, 80, 0.08);
        }
		
		#last-updated {
			color: #b5bcc4;
			font-weight: normal;
		}

        .top-links a {
            color: #9fc6ff;
            text-decoration: none;
        }

        .mini-action {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid #2d3742;
            background: #1a2128;
            color: #eef2f5;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .mini-action:hover {
            background: #202933;
        }

        .mini-action.active {
            background: #2b436f;
            border-color: #4b76be;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .tooltip-wrap {
            position: relative;
            display: inline-flex;
        }

        .icon-toggle {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            border: 1px solid #2d3742;
            background: #1a2128;
            color: #eef2f5;
            cursor: pointer;
            font-size: 22px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .icon-toggle:hover {
            background: #202933;
        }

        .icon-toggle.active {
            background: #2b436f;
            border-color: #4b76be;
        }

        .tooltip-text {
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: #0f1318;
            color: #eef2f5;
            border: 1px solid #2d3742;
            border-radius: 6px;
            padding: 6px 8px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease;
            z-index: 20;
        }
		
		.top-links .tooltip-text {
			top: calc(100% + 8px);
			bottom: auto;
		}

        .tooltip-wrap:hover .tooltip-text {
            opacity: 1;
        }

        .panel {
            display: none;
            background: #1a2128;
            border: 1px solid #2d3742;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 16px;
        }

        .panel.active {
            display: block;
        }

        .panel-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .summary {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .card {
            background: #1a2128;
            border: 1px solid #2d3742;
            border-radius: 8px;
            padding: 12px 14px;
            min-width: 150px;
        }

        .card .label {
            font-size: 12px;
            color: #a9b3bc;
            margin-bottom: 4px;
        }

        .card .value {
            font-size: 22px;
            font-weight: bold;
        }

        .card .value-small {
            font-size: 16px;
            font-weight: bold;
            word-break: break-word;
        }

        .settings-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 520px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-weight: bold;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .checkbox-row input[type="checkbox"] {
            transform: translateY(1px);
        }

        .settings-form select,
        .settings-form input[type="text"],
        .settings-form input[type="password"] {
            width: 100%;
            max-width: 100%;
            padding: 10px 12px;
            border: 1px solid #3a4653;
            border-radius: 6px;
            background: #0f1318;
            color: #f0f0f0;
            box-sizing: border-box;
        }

        .settings-form button,
        .user-edit-form button,
        .user-delete-form button {
            width: fit-content;
            padding: 10px 16px;
            border: 0;
            border-radius: 6px;
            background: #3d7eff;
            color: #fff;
            cursor: pointer;
        }

        .danger-button {
            background: #b74a4a !important;
        }

        .settings-note,
        .restricted-note {
            margin-top: 10px;
            font-size: 13px;
            color: #b5bcc4;
        }

        .success-message {
            margin-top: 10px;
            color: #9ee7a1;
            font-weight: bold;
        }

        .error-message,
        .ajax-error {
            margin-top: 10px;
            color: #ff9d9d;
            font-weight: bold;
        }

        .user-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 12px;
        }

        .user-card,
        .user-card-static {
            background: #151b21;
            border: 1px solid #2d3742;
            border-radius: 8px;
            padding: 12px;
        }

        .user-card summary,
        .user-card-static .user-summary-static {
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-weight: bold;
        }

        .user-card summary::-webkit-details-marker {
            display: none;
        }

        .user-card summary::after {
            content: '\25BE';
            font-size: 13px;
            color: #9fc6ff;
            transition: transform 0.18s ease;
        }

        .user-card[open] summary::after {
            transform: rotate(180deg);
        }

        .user-summary-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            background: #26303a;
            color: #d8e6ff;
        }

        .pill.active {
            background: rgba(40, 140, 70, 0.25);
            color: #9ee7a1;
        }

        .pill.inactive {
            background: rgba(160, 60, 60, 0.25);
            color: #ffb0b0;
        }

        .pill.role {
            background: rgba(61, 126, 255, 0.18);
            color: #bdd6ff;
        }

        .pill.restricted {
            background: rgba(255, 180, 80, 0.18);
            color: #ffd39a;
        }

        .user-edit-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 12px;
            max-width: 520px;
        }

        .user-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .user-delete-form {
            margin-top: 4px;
        }

        .table-wrap {
            overflow: auto;
            max-height: calc(100vh - 220px);
            background: #1a2128;
            border: 1px solid #2d3742;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 680px;
        }

        th, td {
            padding: 10px 12px;
            border-bottom: 1px solid #2d3742;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #212a33;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        tr:hover td {
            background: #202933;
        }

        .visits {
            font-weight: bold;
            color: #d8e6ff;
        }

        .fresh {
            color: #9ee7a1;
            font-weight: bold;
        }
		
		.idle {
			color: #ffd39a;
			font-weight: bold;
		}

        .stale {
            color: #ffb0b0;
            font-weight: bold;
        }
		
		.row-just-active td {
			animation: rowJustActiveFlash 2.2s ease;
		}

		@keyframes rowJustActiveFlash {
			0% {
				background: rgba(40, 140, 70, 0.35);
			}
			100% {
				background: transparent;
			}
		}



        .stats-controls {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 620px;
        }

        .stats-picker-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: end;
        }

        .stats-picker-row .form-group {
            flex: 1 1 280px;
        }

        .stats-picker-row button {
            width: fit-content;
            padding: 10px 16px;
            border: 0;
            border-radius: 6px;
            background: #3d7eff;
            color: #fff;
            cursor: pointer;
        }

        .stats-selection-heading {
            margin-bottom: 12px;
            font-size: 18px;
            font-weight: bold;
            color: #d8e6ff;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 12px;
        }

        .stats-card {
            background: #151b21;
            border: 1px solid #2d3742;
            border-radius: 8px;
            padding: 14px;
        }

        .stats-card-title {
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .stats-metric-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }

        .stats-metric-row:last-child {
            margin-bottom: 0;
        }

        .stats-metric-label {
            color: #b5bcc4;
        }

        .stats-metric-value {
            font-weight: bold;
            color: #eef2f5;
        }

        .stats-results {
            margin-top: 16px;
        }

        .empty {
            padding: 20px;
            color: #c8d0d7;
        }
    </style>
</head>
<body>
    <div class="topbar">
        <h1>hails.Monitor Dashboard</h1>
        <div class="top-links">
            <span id="refresh-status" class="status-chip">Live</span>
			<span id="last-updated" class="status-chip">Updated just now</span>

            <span class="tooltip-wrap">
                <button type="button" id="manual-refresh" class="mini-action" aria-label="Refresh now">🔄</button>
                <span class="tooltip-text">Refresh now</span>
            </span>

            <span class="tooltip-wrap">
                <button type="button" id="toggle-refresh" class="mini-action" aria-label="Pause auto-refresh">⏸️</button>
                <span class="tooltip-text" id="toggle-refresh-tooltip">Pause auto-refresh</span>
            </span>

            <span>Logged in as <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
            <a href="monitor_logout.php">Log out</a>
        </div>
    </div>

    <div class="quick-actions">
        <span class="tooltip-wrap">
            <button type="button" class="icon-toggle" data-panel="stats" aria-label="Region Stats">📊</button>
            <span class="tooltip-text">Region Stats</span>
        </span>

        <span class="tooltip-wrap">
            <button type="button" class="icon-toggle" data-panel="settings" aria-label="Display Settings">⚙️</button>
            <span class="tooltip-text">Display Settings</span>
        </span>

        <?php if ($isSuperAdmin || $isModerator): ?>
            <span class="tooltip-wrap">
                <button type="button" class="icon-toggle" data-panel="create-user" aria-label="Create User">➕</button>
                <span class="tooltip-text">Create User</span>
            </span>

            <span class="tooltip-wrap">
                <button type="button" class="icon-toggle" data-panel="manage-users" aria-label="Manage Users">👥</button>
                <span class="tooltip-text">Manage Users</span>
            </span>
        <?php endif; ?>
    </div>

    <div id="panel-stats" class="panel">
        <div class="panel-title">Region Stats</div>

        <div class="stats-controls">
            <?php if ($canViewAll): ?>
                <div class="form-group">
                    <label for="stats-region-search">Region Picker</label>
                    <div class="stats-picker-row">
                        <div class="form-group">
                            <input
                                type="text"
                                id="stats-region-search"
                                list="stats-region-options"
                                placeholder="Start typing a region name"
                                value=""
                                autocomplete="off"
                            >
                            <datalist id="stats-region-options">
                                <?php foreach ($regionOptions as $regionOption): ?>
                                    <option value="<?= htmlspecialchars($regionOption, ENT_QUOTES, 'UTF-8') ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <button type="button" id="stats-load-button">Load Stats</button>
                    </div>
                </div>
                <div class="settings-note">Start typing and choose a region from the filtered list.</div>
            <?php else: ?>
                <div class="form-group">
                    <label for="stats-region-select">Assigned Region<?= count($assignedRegions) > 1 ? 's' : '' ?></label>
                    <?php if (count($assignedRegions) > 1): ?>
                        <select id="stats-region-select">
                            <option value="">Choose a region</option>
                            <?php foreach ($assignedRegions as $assignedRegion): ?>
                                <option value="<?= htmlspecialchars($assignedRegion, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($assignedRegion, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" id="stats-region-select" value="<?= htmlspecialchars($defaultStatsRegion, ENT_QUOTES, 'UTF-8') ?>" readonly>
                    <?php endif; ?>
                </div>
                <div class="settings-note">Stats access is limited to the region assignments on your account.</div>
            <?php endif; ?>
        </div>

        <div id="stats-results" class="stats-results">
            <?php if (!$canViewAll && $defaultStatsRegion !== ''): ?>
                <div class="empty">Loading stats for <?= htmlspecialchars($defaultStatsRegion, ENT_QUOTES, 'UTF-8') ?>...</div>
            <?php else: ?>
                <div class="empty"><?= $canViewAll ? 'Choose a region to view stats.' : 'Choose one of your assigned regions to view stats.' ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div id="panel-settings" class="panel">
        <div class="panel-title">Display Settings</div>
        <form method="post" class="settings-form">
            <input type="hidden" name="action" value="update_display_settings">

            <div class="form-group">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone">
                    <?php foreach ($timezoneOptions as $timezoneOption): ?>
                        <option value="<?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>" <?= $timezoneOption === $timezone ? 'selected' : '' ?>>
                            <?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="region_filter">Region Filter</label>
                <input
                    type="text"
                    id="region_filter"
                    name="region_filter"
                    placeholder="New York, Sandbox, Test"
                    value="<?= htmlspecialchars($regionFilterRaw, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <button type="submit">Save</button>
        </form>

        <div class="settings-note">Timezone only changes how times are displayed for your account.</div>
        <div class="settings-note">Region Filter is optional. Enter one or more partial region names separated by commas to limit the table.</div>
        <div class="settings-note">Leave Region Filter blank and save to show all available regions again.</div>

        <?php if ($settingsMessage !== ''): ?>
            <div class="success-message"><?= htmlspecialchars($settingsMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($settingsError !== ''): ?>
            <div class="error-message"><?= htmlspecialchars($settingsError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div>

    <?php if ($isSuperAdmin || $isModerator): ?>
        <div id="panel-create-user" class="panel">
            <div class="panel-title">Create User</div>
            <form method="post" class="settings-form">
                <input type="hidden" name="action" value="create_user">

                <div class="form-group">
                    <label for="new_username">Username</label>
                    <input type="text" id="new_username" name="new_username" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label for="new_password">Password</label>
                    <input type="password" id="new_password" name="new_password" minlength="8" required>
                </div>

                <div class="form-group">
                    <label for="new_display_name">Display Name</label>
                    <input type="text" id="new_display_name" name="new_display_name" maxlength="150" placeholder="Optional, defaults to username">
                </div>

                <div class="form-group">
                    <label for="new_timezone">Timezone</label>
                    <select id="new_timezone" name="new_timezone">
                        <?php foreach ($timezoneOptions as $timezoneOption): ?>
                            <option value="<?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>" <?= $timezoneOption === 'America/Los_Angeles' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="new_regions">Assigned Regions</label>
                    <input type="text" id="new_regions" name="new_regions" placeholder="Region One, Region Two">
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="new_can_view_all" value="1">
                    <span>Can view all regions</span>
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="new_is_active" value="1" checked>
                    <span>Active user</span>
                </label>

                <?php if ($isSuperAdmin): ?>
                    <div class="form-group">
                        <label for="new_role">Role</label>
                        <select id="new_role" name="new_role">
                            <option value="user">User</option>
                            <option value="moderator">Moderator</option>
                        </select>
                    </div>
                <?php endif; ?>

                <button type="submit">Create User</button>
            </form>

            <div class="settings-note">If Can view all regions is unchecked, enter one or more region names separated by commas.</div>
            <?php if ($isModerator): ?>
                <div class="settings-note">Moderators can only create standard users.</div>
            <?php endif; ?>

            <?php if ($userCreateMessage !== ''): ?>
                <div class="success-message"><?= htmlspecialchars($userCreateMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($userCreateError !== ''): ?>
                <div class="error-message"><?= htmlspecialchars($userCreateError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <div id="panel-manage-users" class="panel">
            <div class="panel-title">Manage Existing Users</div>
            <div class="settings-note">Open a user to edit their account details, region access, and password.</div>

            <?php if ($userUpdateMessage !== ''): ?>
                <div class="success-message"><?= htmlspecialchars($userUpdateMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($userUpdateError !== ''): ?>
                <div class="error-message"><?= htmlspecialchars($userUpdateError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($userDeleteMessage !== ''): ?>
                <div class="success-message"><?= htmlspecialchars($userDeleteMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($userDeleteError !== ''): ?>
                <div class="error-message"><?= htmlspecialchars($userDeleteError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="user-list">
                <?php foreach ($managedUsers as $managedUser): ?>
                    <?php
                        $managedUserId = (int)$managedUser['id'];
                        $managedUserUsername = (string)$managedUser['username'];
                        $managedUserRegions = (string)($managedUser['regions'] ?? '');
                        $managedUserTimezone = safeTimezone((string)($managedUser['timezone'] ?? 'America/Los_Angeles'));
                        $managedUserDisplayName = (string)($managedUser['display_name'] ?? $managedUserUsername);
                        $managedUserCanViewAll = ((int)$managedUser['can_view_all'] === 1);
                        $managedUserIsActive = ((int)$managedUser['is_active'] === 1);
                        $managedUserRole = (string)($managedUser['role'] ?? 'user');

                        $isProtectedSuperAdminUser = (
                            $protectedSuperAdminUsername !== '' &&
                            strcasecmp($managedUserUsername, $protectedSuperAdminUsername) === 0
                        );

                        $moderatorRestrictedCard = (
                            $isModerator &&
                            (
                                $managedUserId === $monitorUserId ||
                                $managedUserRole !== 'user'
                            )
                        );
                    ?>

                    <?php if ($moderatorRestrictedCard): ?>
                        <div class="user-card-static">
                            <div class="user-summary-static">
                                <span class="user-summary-left">
                                    <span><?= htmlspecialchars($managedUserDisplayName, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span style="color:#b5bcc4;">(<?= htmlspecialchars($managedUserUsername, ENT_QUOTES, 'UTF-8') ?>)</span>
                                    <span class="pill <?= $managedUserIsActive ? 'active' : 'inactive' ?>">
                                        <?= $managedUserIsActive ? 'Active' : 'Inactive' ?>
                                    </span>
                                    <span class="pill">
                                        <?= $managedUserCanViewAll ? 'View All' : 'Restricted' ?>
                                    </span>
                                    <span class="pill role"><?= htmlspecialchars(ucfirst($managedUserRole), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="pill restricted">Read Only</span>
                                </span>
                            </div>
                            <div class="restricted-note">
                                <?php if ($managedUserId === $monitorUserId): ?>
                                    Moderators cannot modify their own profile here.
                                <?php else: ?>
                                    Moderators cannot open or modify moderators or the superadmin.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <details class="user-card">
                            <summary>
                                <span class="user-summary-left">
                                    <span><?= htmlspecialchars($managedUserDisplayName, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span style="color:#b5bcc4;">(<?= htmlspecialchars($managedUserUsername, ENT_QUOTES, 'UTF-8') ?>)</span>
                                    <span class="pill <?= $managedUserIsActive ? 'active' : 'inactive' ?>">
                                        <?= $managedUserIsActive ? 'Active' : 'Inactive' ?>
                                    </span>
                                    <span class="pill">
                                        <?= $managedUserCanViewAll ? 'View All' : 'Restricted' ?>
                                    </span>
                                    <span class="pill role"><?= htmlspecialchars(ucfirst($managedUserRole), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($isProtectedSuperAdminUser): ?>
                                        <span class="pill restricted">Protected</span>
                                    <?php endif; ?>
                                </span>
                            </summary>

                            <form method="post" class="user-edit-form">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="edit_user_id" value="<?= $managedUserId ?>">

                                <div class="form-group">
                                    <label for="edit_username_<?= $managedUserId ?>">Username</label>
                                    <input
                                        type="text"
                                        id="edit_username_<?= $managedUserId ?>"
                                        name="edit_username"
                                        maxlength="100"
                                        value="<?= htmlspecialchars($managedUserUsername, ENT_QUOTES, 'UTF-8') ?>"
                                        required
                                    >
                                </div>

                                <div class="form-group">
                                    <label for="edit_display_name_<?= $managedUserId ?>">Display Name</label>
                                    <input
                                        type="text"
                                        id="edit_display_name_<?= $managedUserId ?>"
                                        name="edit_display_name"
                                        maxlength="150"
                                        value="<?= htmlspecialchars($managedUserDisplayName, ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>

                                <div class="form-group">
                                    <label for="edit_password_<?= $managedUserId ?>">New Password</label>
                                    <input
                                        type="password"
                                        id="edit_password_<?= $managedUserId ?>"
                                        name="edit_password"
                                        minlength="8"
                                        placeholder="Leave blank to keep current password"
                                    >
                                </div>

                                <div class="form-group">
                                    <label for="edit_timezone_<?= $managedUserId ?>">Timezone</label>
                                    <select id="edit_timezone_<?= $managedUserId ?>" name="edit_timezone">
                                        <?php foreach ($timezoneOptions as $timezoneOption): ?>
                                            <option value="<?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>" <?= $timezoneOption === $managedUserTimezone ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="edit_regions_<?= $managedUserId ?>">Assigned Regions</label>
                                    <input
                                        type="text"
                                        id="edit_regions_<?= $managedUserId ?>"
                                        name="edit_regions"
                                        placeholder="Region One, Region Two"
                                        value="<?= htmlspecialchars($managedUserRegions, ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>

                                <label class="checkbox-row">
                                    <input type="checkbox" name="edit_can_view_all" value="1" <?= $managedUserCanViewAll ? 'checked' : '' ?>>
                                    <span>Can view all regions</span>
                                </label>

                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="edit_is_active"
                                        value="1"
                                        <?= $managedUserIsActive ? 'checked' : '' ?>
                                        <?= $managedUserId === $monitorUserId ? 'disabled' : '' ?>
                                    >
                                    <span>
                                        Active user
                                        <?= $managedUserId === $monitorUserId ? '(cannot deactivate current logged-in account here)' : '' ?>
                                    </span>
                                </label>

                                <?php if ($managedUserId === $monitorUserId): ?>
                                    <input type="hidden" name="edit_is_active" value="1">
                                <?php endif; ?>

                                <?php if ($isSuperAdmin): ?>
                                    <div class="form-group">
                                        <label for="edit_role_<?= $managedUserId ?>">Role</label>
                                        <select id="edit_role_<?= $managedUserId ?>" name="edit_role">
                                            <option value="user" <?= $managedUserRole === 'user' ? 'selected' : '' ?>>User</option>
                                            <option value="moderator" <?= $managedUserRole === 'moderator' ? 'selected' : '' ?>>Moderator</option>
                                            <option value="superadmin" <?= $managedUserRole === 'superadmin' ? 'selected' : '' ?>>Superadmin</option>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <div class="user-actions">
                                    <button type="submit">Save Changes</button>
                                </div>
                            </form>

                            <?php if ($isSuperAdmin && !$isProtectedSuperAdminUser && $managedUserId !== $monitorUserId): ?>
                                <form
                                    method="post"
                                    class="user-delete-form"
                                    onsubmit="return confirm('Are you sure you want to delete the user <?= htmlspecialchars($managedUserUsername, ENT_QUOTES, 'UTF-8') ?>? This cannot be undone.');"
                                >
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="delete_user_id" value="<?= $managedUserId ?>">
                                    <button type="submit" class="danger-button">Delete User</button>
                                </form>
                            <?php elseif ($isProtectedSuperAdminUser): ?>
                                <div class="settings-note"><?= htmlspecialchars($protectedSuperAdminUsername, ENT_QUOTES, 'UTF-8') ?> is protected and cannot be deleted from the UI.</div>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div id="dashboard-live-region">
        <?= $dashboardDataHtml ?>
    </div>

    <div id="dashboard-ajax-error" class="ajax-error" style="display:none;"></div>

    <script>
        (function () {
            const liveRegion = document.getElementById('dashboard-live-region');
            const ajaxError = document.getElementById('dashboard-ajax-error');
            const manualRefreshButton = document.getElementById('manual-refresh');
            const toggleRefreshButton = document.getElementById('toggle-refresh');
            const toggleRefreshTooltip = document.getElementById('toggle-refresh-tooltip');
            const refreshStatus = document.getElementById('refresh-status');
            const toggleButtons = Array.from(document.querySelectorAll('.icon-toggle'));
			const lastUpdatedIndicator = document.getElementById('last-updated');

            const statsResults = document.getElementById('stats-results');
            const statsLoadButton = document.getElementById('stats-load-button');
            const statsRegionSearch = document.getElementById('stats-region-search');
            const statsRegionSelect = document.getElementById('stats-region-select');
            const statsDefaultRegion = <?= json_encode($defaultStatsRegion, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            let statsRequestInFlight = false;
            const panels = {
                stats: document.getElementById('panel-stats'),
                settings: document.getElementById('panel-settings'),
                'create-user': document.getElementById('panel-create-user'),
                'manage-users': document.getElementById('panel-manage-users')
            };

            const refreshStorageKey = 'monitor_refresh_paused_' + <?= json_encode($monitorUsername, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
			const panelStorageKey = 'monitor_open_panel_' + <?= json_encode($monitorUsername, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

            let refreshIntervalId = null;
            let isRefreshing = false;
            let activePanel = <?= json_encode($openPanel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            let lastFreshCount = null;
            let glowLevel = '';
            let isRefreshPaused = false;
			let lastUpdatedAt = Date.now();
			let lastUpdatedTimerId = null;



            function getSelectedStatsRegion() {
                if (statsRegionSearch) {
                    return statsRegionSearch.value.trim();
                }

                if (statsRegionSelect) {
                    return statsRegionSelect.value.trim();
                }

                return '';
            }

            async function loadStats(regionName, showError = true) {
                if (!statsResults || statsRequestInFlight) {
                    return;
                }

                const trimmedRegion = (regionName || '').trim();

                if (!trimmedRegion) {
                    statsResults.innerHTML = '<div class="empty">Choose a region to view stats.</div>';
                    return;
                }

                statsRequestInFlight = true;
                statsResults.innerHTML = '<div class="empty">Loading stats...</div>';

                try {
                    const response = await fetch('monitor_stats.php?region=' + encodeURIComponent(trimmedRegion) + '&_=' + Date.now(), {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        cache: 'no-store'
                    });

                    if (!response.ok) {
                        throw new Error('Stats request failed.');
                    }

                    statsResults.innerHTML = await response.text();
                } catch (error) {
                    if (showError) {
                        statsResults.innerHTML = '<div class="empty">Unable to load region stats right now.</div>';
                    }
                } finally {
                    statsRequestInFlight = false;
                }
            }

            function applyPanelState() {
                toggleButtons.forEach((button) => {
                    const panelName = button.getAttribute('data-panel');
                    const isActive = panelName === activePanel;
                    button.classList.toggle('active', isActive);
                });

                Object.keys(panels).forEach((panelName) => {
                    if (!panels[panelName]) {
                        return;
                    }
                    panels[panelName].classList.toggle('active', panelName === activePanel);
                });
            }

            function getFreshCountFromDOM() {
                const cards = document.querySelectorAll('.summary .card');
                if (cards.length < 2) return null;

                const valueEl = cards[1].querySelector('.value');
                if (!valueEl) return null;

                const value = parseInt(valueEl.textContent.replace(/,/g, ''), 10);
                return isNaN(value) ? null : value;
            }

            function setMonitorGlow(level) {
                if (window.parent === window) {
                    return;
                }

                const parentDoc = window.parent.document;
                const toggle = parentDoc.getElementById('monitorToggle');
                const panel = parentDoc.getElementById('monitorPanel');

                if (!toggle) {
                    return;
                }

                toggle.classList.remove('activity-glow-slow', 'activity-glow-fast');

                if (!level) {
                    glowLevel = '';
                    return;
                }

                if (panel && panel.classList.contains('open')) {
                    glowLevel = level;
                    return;
                }

                if (level === 'fast') {
                    toggle.classList.add('activity-glow-fast');
                } else {
                    toggle.classList.add('activity-glow-slow');
                }

                glowLevel = level;
            }

            function clearMonitorGlow() {
                setMonitorGlow('');
            }

            function reapplyMonitorGlowIfNeeded() {
                if (glowLevel) {
                    setMonitorGlow(glowLevel);
                }
            }
			
			
			function getRowStatusMap(root) {
			const map = new Map();
				if (!root) {
					return map;
				}

				const rows = root.querySelectorAll('tbody tr');

				rows.forEach(function (row) {
					const cells = row.querySelectorAll('td');
					if (cells.length < 6) {
						return;
					}

					const avatar = cells[0].textContent.trim();
					const region = cells[1].textContent.trim();
					const status = cells[5].textContent.trim();
					const key = avatar + '||' + region;

					map.set(key, status);
				});

				return map;
			}

			function highlightNewlyActiveRows(oldStatusMap, root) {
				if (!root) {
					return;
				}

				const rows = root.querySelectorAll('tbody tr');

				rows.forEach(function (row) {
					const cells = row.querySelectorAll('td');
					if (cells.length < 6) {
						return;
					}

					const avatar = cells[0].textContent.trim();
					const region = cells[1].textContent.trim();
					const status = cells[5].textContent.trim();
					const key = avatar + '||' + region;
					const previousStatus = oldStatusMap.get(key);

					if (status === 'Active' && previousStatus && previousStatus !== 'Active') {
						row.classList.add('row-just-active');

						setTimeout(function () {
							row.classList.remove('row-just-active');
						}, 2200);
					}
				});
			}
			

            function loadPausePreference() {
                try {
                    isRefreshPaused = localStorage.getItem(refreshStorageKey) === '1';
                } catch (error) {
                    isRefreshPaused = false;
                }
            }

            function savePausePreference() {
                try {
                    localStorage.setItem(refreshStorageKey, isRefreshPaused ? '1' : '0');
                } catch (error) {
                    // Ignore storage issues
                }
            }
			
			function loadPanelPreference() {
				try {
					const savedPanel = localStorage.getItem(panelStorageKey) || '';
					if (savedPanel && Object.prototype.hasOwnProperty.call(panels, savedPanel)) {
						activePanel = savedPanel;
					}
				} catch (error) {
					// Ignore storage issues
				}
			}

			function savePanelPreference() {
				try {
					localStorage.setItem(panelStorageKey, activePanel || '');
				} catch (error) {
					// Ignore storage issues
				}
			}

            function updateRefreshUI() {
                if (toggleRefreshButton) {
                    toggleRefreshButton.textContent = isRefreshPaused ? '▶️' : '⏸️';
                    toggleRefreshButton.setAttribute('aria-label', isRefreshPaused ? 'Resume auto-refresh' : 'Pause auto-refresh');
                    toggleRefreshButton.classList.toggle('active', isRefreshPaused);
                }

                if (toggleRefreshTooltip) {
                    toggleRefreshTooltip.textContent = isRefreshPaused ? 'Resume auto-refresh' : 'Pause auto-refresh';
                }

                if (refreshStatus) {
                    refreshStatus.textContent = isRefreshPaused ? 'Paused' : 'Live';
                    refreshStatus.classList.toggle('paused', isRefreshPaused);
                }
            }
			
			function updateLastUpdatedText() {
				if (!lastUpdatedIndicator) {
					return;
				}

				const seconds = Math.max(0, Math.floor((Date.now() - lastUpdatedAt) / 1000));

				if (seconds <= 1) {
					lastUpdatedIndicator.textContent = 'Updated just now';
				} else {
					lastUpdatedIndicator.textContent = 'Updated ' + seconds + 's ago';
				}
			}

			function markUpdatedNow() {
				lastUpdatedAt = Date.now();
				updateLastUpdatedText();
			}

			function startLastUpdatedTimer() {
				if (lastUpdatedTimerId !== null) {
					clearInterval(lastUpdatedTimerId);
				}

				lastUpdatedTimerId = setInterval(function () {
					updateLastUpdatedText();
				}, 1000);
			}

            function stopAutoRefresh() {
                if (refreshIntervalId !== null) {
                    clearInterval(refreshIntervalId);
                    refreshIntervalId = null;
                }
            }

            function startAutoRefresh() {
                stopAutoRefresh();

                if (isRefreshPaused) {
                    return;
                }

                refreshIntervalId = setInterval(function () {
                    if (!document.hidden) {
                        refreshDashboardData(false);
                    }
                }, 30000);
            }

            function toggleAutoRefresh() {
                isRefreshPaused = !isRefreshPaused;
                savePausePreference();
                updateRefreshUI();

                if (isRefreshPaused) {
                    stopAutoRefresh();
                } else {
                    startAutoRefresh();
                }
            }

            toggleButtons.forEach((button) => {
				button.addEventListener('click', function () {
					const panelName = button.getAttribute('data-panel');
					activePanel = (activePanel === panelName) ? '' : panelName;
					savePanelPreference();
					applyPanelState();
				});
			});

            async function refreshDashboardData(showError = true) {
                if (isRefreshing) {
                    return;
                }

                isRefreshing = true;

                try {
                    const response = await fetch('monitor_data.php?_=' + Date.now(), {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        cache: 'no-store'
                    });

                    if (!response.ok) {
                        throw new Error('Refresh request failed.');
                    }

                    const html = await response.text();
					const oldStatusMap = getRowStatusMap(liveRegion);

					if (liveRegion) {
						liveRegion.innerHTML = html;
						highlightNewlyActiveRows(oldStatusMap, liveRegion);
						
						const newFreshCount = getFreshCountFromDOM();

                        if (newFreshCount !== null) {
                            if (lastFreshCount !== null && newFreshCount > lastFreshCount) {
                                const increase = newFreshCount - lastFreshCount;

                                if (increase >= 3) {
                                    setMonitorGlow('fast');
                                } else {
                                    setMonitorGlow('slow');
                                }
                            }

                            lastFreshCount = newFreshCount;
                        }
                    }

                    if (ajaxError) {
                        ajaxError.style.display = 'none';
                        ajaxError.textContent = '';
                    }
					markUpdatedNow();
                } catch (error) {
                    if (showError && ajaxError) {
                        ajaxError.textContent = 'Live refresh failed. The page is still usable. You can click refresh or reload the page.';
                        ajaxError.style.display = 'block';
                    }
                } finally {
                    isRefreshing = false;
                }
            }

            if (manualRefreshButton) {
                manualRefreshButton.addEventListener('click', function () {
                    refreshDashboardData(true);
                });
            }

            if (toggleRefreshButton) {
                toggleRefreshButton.addEventListener('click', function () {
                    toggleAutoRefresh();
                });
            }


            if (statsLoadButton) {
                statsLoadButton.addEventListener('click', function () {
                    loadStats(getSelectedStatsRegion(), true);
                });
            }

            if (statsRegionSearch) {
                statsRegionSearch.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        loadStats(getSelectedStatsRegion(), true);
                    }
                });

                statsRegionSearch.addEventListener('change', function () {
                    if (statsRegionSearch.value.trim() !== '') {
                        loadStats(getSelectedStatsRegion(), false);
                    }
                });
            }

            if (statsRegionSelect && statsRegionSelect.tagName === 'SELECT') {
                statsRegionSelect.addEventListener('change', function () {
                    if (statsRegionSelect.value.trim() !== '') {
                        loadStats(getSelectedStatsRegion(), true);
                    } else if (statsResults) {
                        statsResults.innerHTML = '<div class="empty">Choose one of your assigned regions to view stats.</div>';
                    }
                });
            }

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden && !isRefreshPaused) {
                    refreshDashboardData(false);
                }
            });

			loadPausePreference();
			loadPanelPreference();
			applyPanelState();
			lastFreshCount = getFreshCountFromDOM();
			updateRefreshUI();
			markUpdatedNow();
			startLastUpdatedTimer();

            if (statsDefaultRegion) {
                if (statsRegionSelect && statsRegionSelect.tagName === 'INPUT') {
                    statsRegionSelect.value = statsDefaultRegion;
                }
                loadStats(statsDefaultRegion, false);
            }

            try {
                if (window.parent && window.parent !== window) {
                    const parentDoc = window.parent.document;
                    const parentPanel = parentDoc.getElementById('monitorPanel');
                    const parentToggle = parentDoc.getElementById('monitorToggle');

                    if (parentToggle) {
                        parentToggle.addEventListener('click', function () {
                            setTimeout(function () {
                                if (parentPanel && parentPanel.classList.contains('open')) {
                                    clearMonitorGlow();
                                } else {
                                    reapplyMonitorGlowIfNeeded();
                                }
                            }, 10);
                        });
                    }
                }
            } catch (error) {
                // Ignore cross-frame issues if they ever happen
		}
            startAutoRefresh();
        })();
    </script>
</body>
</html>
