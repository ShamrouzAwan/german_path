<?php

declare(strict_types=1);

putenv('APP_ENV=testing');
putenv('DB_PATH=storage/foundation-test.sqlite');
putenv('LOG_PATH=storage/foundation-test.log');
putenv('ERROR_LOG_PATH=storage/foundation-test-errors.log');
putenv('AUTO_MIGRATE=true');

$root = dirname(__DIR__);
$databasePath = $root . '/storage/foundation-test.sqlite';
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

$app = require $root . '/app/bootstrap.php';
$db = $app['db'];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('schema_migrations', $tables, true), 'migration table exists');
check(in_array('users', $tables, true), 'foundation users table exists');
check(in_array('audit_logs', $tables, true), 'foundation audit log table exists');
check(count($app['applied_migrations']) === 4, 'foundation, auth, rate-limit, and payment migrations were applied exactly once');

$secondRun = (new GermanPath\Database\Migrator(
    $db,
    $root . '/database/migrations',
    $app['logger']
))->migrate();
check($secondRun === [], 'migration runner is idempotent');

$router = new GermanPath\Http\Router();
$router->get('/course/{slug}', static fn (
    GermanPath\Http\Request $request,
    array $params
): GermanPath\Http\Response => GermanPath\Http\Response::text((string) $params['slug']));
$response = $router->dispatch(new GermanPath\Http\Request('GET', '/course/b1-grammar'));
check($response->status() === 200 && $response->body() === 'b1-grammar', 'parameterized route dispatches');

$notFound = $router->dispatch(new GermanPath\Http\Request('GET', '/missing'));
check($notFound->status() === 404, 'unknown route returns 404');

foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
echo "Foundation tests completed.\n";
