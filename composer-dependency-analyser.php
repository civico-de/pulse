<?php

declare(strict_types = 1);

/**
 * ckdeps config: civikitchen's CiviCRM-aware default plus the host CMS.
 *
 * `\Drupal` is the host site's service container, present only when CiviCRM
 * runs on Drupal; the CMS checks reach it behind `class_exists()`. It is not a
 * composer dependency of this extension, so composer cannot resolve it.
 */

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

$configuration = require '/opt/civikitchen/toolbelt/lib/composer-deps.php';

if (!$configuration instanceof Configuration) {
  throw new RuntimeException('civikitchen ckdeps config did not return a Configuration');
}

return $configuration->ignoreUnknownClasses(['Drupal']);
