<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$dotenvPath = dirname(__DIR__).'/.env';

if (is_file($dotenvPath)) {
    (new Dotenv())->bootEnv($dotenvPath);
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
