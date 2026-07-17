<?php

declare(strict_types=1);

// Wired up as parameters.doctrine.objectManagerLoader in phpstan.neon.dist —
// a phpstan-doctrine feature (not phpstan-symfony), which uses the real
// EntityManager to type-check DQL/QueryBuilder result types instead of
// falling back to `mixed`. See https://github.com/phpstan/phpstan-doctrine#configuration

use AeroNuk\FlightSearch\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
