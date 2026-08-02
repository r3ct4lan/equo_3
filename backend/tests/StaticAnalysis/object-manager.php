<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$dotenvPath = dirname(__DIR__, 2).'/.env';

if (is_file($dotenvPath)) {
    (new Dotenv())->bootEnv($dotenvPath);
}

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
