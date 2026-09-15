<?php

declare(strict_types=1);

use GermanPath\Config\Config;
use GermanPath\Content\ContentLoader;
use GermanPath\Content\ContentValidator;
use GermanPath\Database\Database;
use GermanPath\Database\Migrator;
use GermanPath\ErrorHandler;
use GermanPath\Support\Logger;
use GermanPath\Support\Session;

require_once __DIR__ . '/Config/Config.php';
require_once __DIR__ . '/Support/Logger.php';
require_once __DIR__ . '/Support/Session.php';
require_once __DIR__ . '/Database/Database.php';
require_once __DIR__ . '/Database/Migrator.php';
require_once __DIR__ . '/Http/Request.php';
require_once __DIR__ . '/Http/Response.php';
require_once __DIR__ . '/Http/Router.php';
require_once __DIR__ . '/Support/View.php';
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/Audit/AuditService.php';
require_once __DIR__ . '/Commerce/PaymentException.php';
require_once __DIR__ . '/Commerce/CourseOfferService.php';
require_once __DIR__ . '/Commerce/AccessService.php';
require_once __DIR__ . '/Commerce/PaymentService.php';
require_once __DIR__ . '/Media/MediaException.php';
require_once __DIR__ . '/Media/WorkerMediaService.php';
require_once __DIR__ . '/Media/MediaAccessService.php';
require_once __DIR__ . '/Mail/EmailService.php';
require_once __DIR__ . '/Auth/AuthException.php';
require_once __DIR__ . '/Auth/AuthService.php';
require_once __DIR__ . '/Content/ContentException.php';
require_once __DIR__ . '/Content/ContentValidator.php';
require_once __DIR__ . '/Content/ContentIntegrityValidator.php';
require_once __DIR__ . '/Content/ContentLoader.php';

$rootPath = dirname(__DIR__);
$config = Config::load($rootPath);
$logger = new Logger($config);
ErrorHandler::register($config, $logger);
Session::start($config);

$database = Database::connect($config);
$migrator = new Migrator($database, $rootPath . '/database/migrations', $logger);
$appliedMigrations = $config->get('auto_migrate', true) ? $migrator->migrate() : [];
$content = new ContentLoader($rootPath . '/content', new ContentValidator(), $logger);
$email = new GermanPath\Mail\EmailService($config, $logger);
$audit = new GermanPath\Audit\AuditService($database, $logger);
$auth = new GermanPath\Auth\AuthService($database, $config, $logger, $email, $audit);
$offers = new GermanPath\Commerce\CourseOfferService($content);
$access = new GermanPath\Commerce\AccessService($database, $audit);
$payments = new GermanPath\Commerce\PaymentService($database, $offers, $access, $audit);
$workerMedia = new GermanPath\Media\WorkerMediaService($config, $logger);
$media = new GermanPath\Media\MediaAccessService($content, $access, $workerMedia);

return [
    'root' => $rootPath,
    'config' => $config,
    'logger' => $logger,
    'db' => $database,
    'content' => $content,
    'auth' => $auth,
    'audit' => $audit,
    'offers' => $offers,
    'access' => $access,
    'payments' => $payments,
    'worker_media' => $workerMedia,
    'media' => $media,
    'applied_migrations' => $appliedMigrations,
];
