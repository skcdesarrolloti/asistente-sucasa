<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Repository.php';
require_once __DIR__ . '/src/MiniMaxClient.php';
require_once __DIR__ . '/src/MetaClient.php';

function app(): array
{
    $config = Config::load(__DIR__);
    $db = Config::pdo($config);
    return [$config, $db, new Repository($db, $config)];
}
