<?php
declare(strict_types=1);

session_start();

define('ALLOW_CONFIG_INCLUDE', true);
require_once '/usr/www/yoursitehere/secure/config.php';

function db(): PDO
{
    return new PDO(
        'mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM monitor_users
        WHERE username = :username
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, (string)$user['password_hash'])) {
        $_SESSION['monitor_logged_in'] = true;
        $_SESSION['monitor_user_id'] = (int)$user['id'];
        $_SESSION['monitor_username'] = (string)$user['username'];
        $_SESSION['monitor_display_name'] = (string)$user['display_name'];
        $_SESSION['monitor_timezone'] = (string)$user['timezone'];
        $_SESSION['monitor_can_view_all'] = (bool)$user['can_view_all'];
        $_SESSION['monitor_role'] = (string)$user['role'];

        header('Location: monitor_dashboard.php');
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>hails.Monitor Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #111418;
            color: #f0f0f0;
            margin: 0;
            padding: 20px;
        }

        .login-box {
            max-width: 420px;
            margin: 60px auto;
            background: #1b2128;
            border: 1px solid #2d3742;
            border-radius: 10px;
            padding: 24px;
            box-sizing: border-box;
        }

        h1 {
            margin-top: 0;
            font-size: 24px;
        }

        label {
            display: block;
            margin: 14px 0 6px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            box-sizing: border-box;
            border: 1px solid #3a4653;
            border-radius: 6px;
            background: #0f1318;
            color: #f0f0f0;
        }

        button {
            margin-top: 18px;
            padding: 10px 16px;
            border: 0;
            border-radius: 6px;
            background: #3d7eff;
            color: #fff;
            cursor: pointer;
        }

        .error {
            margin-top: 12px;
            color: #ff9d9d;
        }

        .note {
            color: #b5bcc4;
            font-size: 13px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>hails.Monitor Login</h1>

        <form method="post" action="">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>

            <button type="submit">Log in</button>
        </form>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="note">Access is restricted.</div>
    </div>
</body>
</html>
