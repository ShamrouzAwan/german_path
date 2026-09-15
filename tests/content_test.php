<?php

declare(strict_types=1);

putenv('APP_ENV=testing');
putenv('DB_PATH=storage/content-test.sqlite');
putenv('LOG_PATH=storage/content-test.log');
putenv('ERROR_LOG_PATH=storage/content-test-errors.log');
putenv('AUTO_MIGRATE=true');

$root = dirname(__DIR__);
$app = require $root . '/app/bootstrap.php';
$loader = $app['content'];

function content_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$courses = $loader->loadCollection('courses');
$videos = $loader->loadCollection('videos');
$contentTypes = ['teachers', 'courses', 'playlists', 'videos', 'shorts', 'pages', 'site'];
foreach ($contentTypes as $collection) {
    content_check($loader->loadCollection($collection) !== [], "{$collection} collection loads");
}
content_check(count($courses) === 1, 'development course fixture loads');
content_check(count($videos) === 3, 'development video fixtures load');
content_check($loader->findBySlug('courses', 'b1-german')['teacher_ids'] === ['teacher-anna', 'teacher-lukas'], 'course supports multiple teachers');
content_check($loader->findById('videos', 'video-relativsaetze')['storage'] === 'r2-secondary', 'media item keeps its own storage mapping');
content_check($loader->findById('videos', 'video-infinitiv-zu')['storage'] !== $loader->findById('videos', 'video-relativsaetze')['storage'], 'one course can span multiple storage locations');

$report = $loader->validationReport();
content_check($report['courses']['valid'] === true && $report['videos']['valid'] === true, 'content validation report is healthy');
content_check($loader->integrityErrors() === [], 'cross-collection references are valid');

$integrityValidator = new GermanPath\Content\ContentIntegrityValidator();
$brokenReferences = $integrityValidator->validate([
    'teachers' => [['id' => 'teacher-one']],
    'courses' => [['id' => 'course-one', 'teacher_ids' => ['teacher-missing']]],
    'playlists' => [['id' => 'playlist-one', 'video_ids' => ['video-missing']]],
    'videos' => [[
        'id' => 'video-one',
        'teacher_id' => 'teacher-missing',
        'course_id' => 'course-missing',
        'playlist_id' => 'playlist-missing',
    ]],
    'shorts' => [['id' => 'short-one', 'teacher_id' => 'teacher-missing']],
]);
content_check(count($brokenReferences) === 6, 'broken cross-collection references are reported');

$tempRoot = sys_get_temp_dir() . '/germanpath-content-' . bin2hex(random_bytes(5));
mkdir($tempRoot . '/teachers', 0775, true);
file_put_contents($tempRoot . '/teachers/broken.json', '{"schema_version": 1,');
$brokenLoader = new GermanPath\Content\ContentLoader(
    $tempRoot,
    new GermanPath\Content\ContentValidator(),
    $app['logger']
);
$malformedRejected = false;
try {
    $brokenLoader->loadCollection('teachers');
} catch (GermanPath\Content\ContentException $exception) {
    $malformedRejected = count($exception->errors()) > 0;
}
content_check($malformedRejected, 'malformed JSON is rejected with validation errors');
unlink($tempRoot . '/teachers/broken.json');
rmdir($tempRoot . '/teachers');
rmdir($tempRoot);

$unknownRejected = false;
try {
    $loader->loadCollection('../secrets');
} catch (GermanPath\Content\ContentException) {
    $unknownRejected = true;
}
content_check($unknownRejected, 'unknown content collections are rejected');

echo "Content tests completed.\n";
