<?php

declare(strict_types = 1);

use Civi\Pulse\Demo\ScenarioSeeder;

$result = (new ScenarioSeeder())->seed();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
