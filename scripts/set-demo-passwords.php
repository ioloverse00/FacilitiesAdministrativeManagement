<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

$args = array_slice($argv, 1);

if ($args === [] || in_array('--help', $args, true)) {
    usage();
    exit($args === [] ? 1 : 0);
}

$all = false;
$username = null;

if ($args[0] === '--all') {
    $all = true;
    $password = $args[1] ?? promptPassword();
} else {
    $username = trim((string) $args[0]);
    $password = $args[1] ?? promptPassword();
}

if (!$all && ($username === null || $username === '')) {
    fwrite(STDERR, "Username is required.\n");
    exit(1);
}

if (!isStrongPassword($password)) {
    fwrite(STDERR, "Password must be at least 12 characters and include uppercase, lowercase, number, and symbol characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo = Database::connection();

if ($all) {
    $statement = $pdo->prepare(
        'UPDATE user_account
         SET password_hash = :password_hash, failed_login_count = 0, locked_until = NULL
         WHERE account_status = "ACTIVE" AND deleted_at IS NULL'
    );
    $statement->execute(['password_hash' => $hash]);
    printf("Updated %d active demo account(s).\n", $statement->rowCount());
    exit(0);
}

$statement = $pdo->prepare(
    'UPDATE user_account
     SET password_hash = :password_hash, failed_login_count = 0, locked_until = NULL
     WHERE username = :username AND deleted_at IS NULL'
);
$statement->execute([
    'password_hash' => $hash,
    'username' => $username,
]);

if ($statement->rowCount() < 1) {
    fwrite(STDERR, "No matching account was updated.\n");
    exit(1);
}

printf("Updated demo password for %s.\n", $username);
exit(0);

function usage(): void
{
    echo "Development-only demo password setup.\n\n";
    echo "Usage:\n";
    echo "  php scripts/set-demo-passwords.php gsms-super-admin \"NewPassword123!\"\n";
    echo "  php scripts/set-demo-passwords.php --all \"NewPassword123!\"\n";
}

function promptPassword(): string
{
    fwrite(STDOUT, 'Password: ');
    $line = fgets(STDIN);

    return $line === false ? '' : rtrim($line, "\r\n");
}

function isStrongPassword(string $password): bool
{
    return strlen($password) >= 12
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}
