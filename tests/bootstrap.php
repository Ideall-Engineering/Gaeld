<?php

// Pin the Laravel application base path so plugin autoloaders cannot
// pollute Application::inferBasePath() during the test run. Without this,
// once plugins/gaeld-ee/vendor/autoload.php registers a ClassLoader,
// inferBasePath() may resolve to the plugin directory and cause
// "Failed opening required '.../plugins/gaeld-ee/bootstrap/app.php'".
$basePath = dirname(__DIR__);
$_ENV['APP_BASE_PATH'] = $basePath;
$_SERVER['APP_BASE_PATH'] = $basePath;
putenv('APP_BASE_PATH='.$basePath);

// Abort before a cached config can point the suite at the wrong database.
//
// With bootstrap/cache/config.php present, Laravel skips loading .env, so
// .env.testing never applies: DB_DATABASE stays on the development database
// and RefreshDatabase drops its tables. This has happened. Refuse to start
// rather than destroy data — the guard in AppServiceProvider stops the cache
// from being written, this one catches a file that is already there.
if (file_exists($basePath.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, PHP_EOL.'  Refusing to run the test suite: bootstrap/cache/config.php exists.'.PHP_EOL
        .'  A cached config overrides .env.testing, so the suite would run against'.PHP_EOL
        .'  the development database and wipe it.'.PHP_EOL.PHP_EOL
        .'  Fix it with:  php artisan config:clear'.PHP_EOL.PHP_EOL);

    exit(1);
}

// The complete suite exceeds the PHP CLI timeout on slower CI runners.
set_time_limit(0);
ini_set('max_execution_time', '0');

require __DIR__.'/../vendor/autoload.php';
