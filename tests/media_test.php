<?php

declare(strict_types=1);

ob_start();
putenv('APP_ENV=testing');
putenv('DB_PATH=storage/media-test.sqlite');
putenv('LOG_PATH=storage/media-test.log');
putenv('ERROR_LOG_PATH=storage/media-test-errors.log');
putenv('AUTO_MIGRATE=true');
putenv('MAIL_DRIVER=log');
putenv('WORKER_URL=https://worker.example.test');
putenv('WORKER_SECRET=test-worker-secret');
putenv('WORKER_TOKEN_TTL=180');

$root = dirname(__DIR__);
$databasePath = $root . '/storage/media-test.sqlite';
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

$app = require $root . '/app/bootstrap.php';
$auth = $app['auth'];
$db = $app['db'];
$student = $auth->register('media-student@example.com', 'a-very-strong-password', 'Media Student');
$auth->verifyToken($student['verification_token']);
$admin = $auth->register('media-admin@example.com', 'another-strong-password', 'Media Admin');
$auth->verifyToken($admin['verification_token']);
$db->prepare("UPDATE users SET role = 'admin' WHERE id = :id")->execute([':id' => $admin['user']['id']]);

function media_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$workerCalls = [];
$worker = new GermanPath\Media\WorkerMediaService(
    $app['config'],
    $app['logger'],
    static function (string $url, array $payload, array $headers) use (&$workerCalls): array {
        $workerCalls[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
        return [
            'status' => 200,
            'body' => json_encode([
                'video_url' => 'https://media.example.test/media?token=video-token',
                'subtitle_url' => 'https://media.example.test/media?token=subtitle-token',
                'expires_at' => '2026-09-15T13:30:00+00:00',
            ], JSON_THROW_ON_ERROR),
        ];
    }
);
$media = new GermanPath\Media\MediaAccessService($app['content'], $app['access'], $worker);

$denied = false;
try {
    $media->signForUser((int) $student['user']['id'], 'video-relativsaetze');
} catch (GermanPath\Media\MediaException $exception) {
    $denied = $exception->status() === 403;
}
media_check($denied, 'protected media requires active course access');

$app['access']->grantManual(
    (int) $admin['user']['id'],
    (int) $student['user']['id'],
    'course-b1-german',
    'offer-b1-lukas',
    null,
    'Media test access'
);
$signed = $media->signForUser((int) $student['user']['id'], 'video-relativsaetze');
media_check($signed['video_url'] === 'https://media.example.test/media?token=video-token', 'authorized media receives a signed video URL');
media_check($signed['subtitle_url'] === 'https://media.example.test/media?token=subtitle-token', 'authorized media receives a signed subtitle URL');
media_check($workerCalls[0]['payload']['storage'] === 'r2-secondary', 'per-media storage mapping reaches the Worker');
media_check($workerCalls[0]['payload']['video'] === 'b1/relativsaetze.mp4', 'MP4 key reaches the Worker');
media_check($workerCalls[0]['payload']['subtitle'] === 'b1/relativsaetze.srt', 'SRT key reaches the Worker');
media_check($workerCalls[0]['headers']['X-Worker-Secret'] === 'test-worker-secret', 'Worker secret is sent server-side only');

$free = $media->signForUser(null, 'video-kennenlernen');
media_check($free['video_url'] !== '', 'free media can be signed without an account');

$badWorker = new GermanPath\Media\WorkerMediaService(
    $app['config'],
    $app['logger'],
    static fn (): array => ['status' => 200, 'body' => '{"unexpected":true}']
);
$badResponse = false;
try {
    $badWorker->sign($app['content']->findById('videos', 'video-relativsaetze'), (int) $student['user']['id']);
} catch (GermanPath\Media\MediaException $exception) {
    $badResponse = $exception->status() === 502;
}
media_check($badResponse, 'invalid Worker responses fail safely');

echo "Media tests completed.\n";
ob_end_flush();