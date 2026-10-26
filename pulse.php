<?php

declare(strict_types = 1);

// Loaded before PHPUnit starts coverage.
// @codeCoverageIgnoreStart
require_once 'pulse.civix.php';
// Bundled packages, prefixed into Civi\Pulse\Vendor\ by `composer install`.
require_once __DIR__ . '/vendor/autoload.php';
// @codeCoverageIgnoreEnd

/**
 * Implements hook_civicrm_config().
 *
 * @codeCoverageIgnore Civix delegate exercised during fresh install.
 */
function pulse_civicrm_config(\CRM_Core_Config $config): void {
  _pulse_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @codeCoverageIgnore Civix delegate exercised during fresh install.
 */
function pulse_civicrm_install(): void {
  _pulse_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @codeCoverageIgnore Civix delegate exercised during fresh install.
 */
function pulse_civicrm_enable(): void {
  _pulse_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable(): core's extension update message shows
 * again while pulse is disabled.
 */
function pulse_civicrm_disable(): void {
  \CRM_Pulse_Check_Updates::releaseCoreMessage();
}

/**
 * Implements hook_civicrm_check(): pulse's maintenance checks as core status
 * messages. A pending update is not an outage, so none of them fails the endpoint.
 *
 * @param array<int, \CRM_Utils_Check_Message> $messages
 * @param array<mixed> $statusNames
 */
function pulse_civicrm_check(&$messages, array $statusNames = []): void {
  foreach (\CRM_Pulse_StatusCheck::messages($statusNames) as $message) {
    $messages[] = $message;
  }
}
