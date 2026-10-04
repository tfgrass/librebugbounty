<?php

// Never inherit a live DDEV database or artifact path, even with APP_ENV=dev set.
require dirname(__DIR__).'/vendor/autoload.php';

// Install once before PHPUnit snapshots per-test exception handlers.
Symfony\Component\ErrorHandler\ErrorHandler::register(null, false);

define('APP_TEST_ROOT', sys_get_temp_dir().'/librebugbounty-tests-'.bin2hex(random_bytes(10)));
mkdir(APP_TEST_ROOT, 0700, true);
foreach ([
    'APP_ENV' => 'test',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-phpunit-secret',
    'DATABASE_URL' => 'sqlite:///'.APP_TEST_ROOT.'/database.sqlite',
    'EVIDENCE_STORAGE_DIR' => APP_TEST_ROOT.'/artifacts',
    'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}
register_shutdown_function(static function (): void {
    (new Symfony\Component\Filesystem\Filesystem())->remove(APP_TEST_ROOT);
});

require dirname(__DIR__).'/config/bootstrap.php';
