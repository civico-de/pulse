<?php

declare(strict_types = 1);

use Civi\Api4\Managed;
use Civi\Api4\StatusPreference;
use Civi\Pulse\Vendor\Civico\Stdlib\Cast;
use Psr\Log\LogLevel;

/**
 * Pending extension updates as the core message `pulse_updates`, read from
 * core's extension mapper and browser (CRM_Pulse_Check_UpdateFeed). Single
 * updates can be ignored via `pulse_updates_ignore`:
 *
 *   ["org.civicoop.civirules"]         — ignore this extension entirely
 *   ["org.civicoop.civirules@2.14"]    — snooze exactly this target version;
 *                                        the NEXT release alerts again
 *   ["extensions"]                     — ignore ALL extension updates
 *
 * A failed feed read is reported as `pulse_updates_feed` while `pulse_updates`
 * keeps the versions of the last successful read.
 */
class CRM_Pulse_Check_Updates {

  public const SEVERITY_SETTING = 'pulse_updates_min_severity';

  /**
   * @return list<CRM_Utils_Check_Message>
   */
  public static function messages(): array {
    if (!self::isActive()) {
      return [];
    }
    $severity = strtolower(Cast::toString(Civi::settings()->get(self::SEVERITY_SETTING)));
    try {
      CRM_Utils_Check::severityMap($severity);
    }
    catch (CRM_Core_Exception $e) {
      return [CRM_Pulse_StatusCheck::message(
        'updates',
        htmlspecialchars(CRM_Pulse_Util::ts("Could not check: invalid pulse_updates_min_severity '%1'", [
          1 => $severity,
        ])),
        LogLevel::ERROR,
      )];
    }
    $ignore = Civi::settings()->get('pulse_updates_ignore');
    $ignore = is_array($ignore) ? array_values(array_filter($ignore, 'is_string')) : [];
    if (in_array('extensions', $ignore, TRUE)) {
      return [];
    }

    $read = CRM_Pulse_Check_UpdateFeed::remoteVersions();
    $messages = [];
    if ($read['error'] !== NULL) {
      $messages[] = CRM_Pulse_StatusCheck::message(
        'updates_feed',
        htmlspecialchars(CRM_Pulse_Util::ts(
          'Could not check: the extension feed could not be read (%1). Until it can, the update check reports the versions of the last successful read.',
          [1 => trim(strip_tags($read['error']))],
        )),
        LogLevel::ERROR,
      );
    }
    $updates = self::filterIgnored(CRM_Pulse_Check_UpdateFeed::updates($read['versions']), $ignore);
    if ($updates !== []) {
      $messages[] = CRM_Pulse_StatusCheck::message('updates', self::updatesHtml($updates), $severity);
    }
    return $messages;
  }

  /**
   * Whether pulse reports extension updates, and so hides core's own message
   * (managed/StatusPreference.mgd.php).
   */
  public static function isActive(): bool {
    return strtolower(Cast::toString(Civi::settings()->get(self::SEVERITY_SETTING))) !== 'off';
  }

  /**
   * post_change of the severity setting: add or remove the StatusPreference.
   */
  public static function reconcileCoreMessage(): void {
    Managed::reconcile(checkPermissions: FALSE)->addModule('pulse')->execute();
  }

  /**
   * hook_civicrm_disable: core only deactivates the managed StatusPreference,
   * and visibility ignores is_active, so lift the ignore level explicitly.
   */
  public static function releaseCoreMessage(): void {
    $ids = Managed::get(checkPermissions: FALSE)
      ->addSelect('entity_id')
      ->addWhere('module', '=', 'pulse')
      ->addWhere('entity_type', '=', 'StatusPreference')
      ->execute()
      ->column('entity_id');
    if ($ids === []) {
      return;
    }
    StatusPreference::update(checkPermissions: FALSE)
      ->addWhere('id', 'IN', $ids)
      ->addValue('ignore_severity', 0)
      ->addValue('hush_until', NULL)
      ->execute();
  }

  /**
   * Apply the ignore list. Pure — test-seam.
   *
   * @param array<string, array{from: string, to: string}> $updates
   * @param list<string> $ignore
   * @return array<string, array{from: string, to: string}>
   */
  public static function filterIgnored(array $updates, array $ignore): array {
    foreach ($updates as $key => $upd) {
      if (!(in_array($key, $ignore, TRUE) || in_array($key . '@' . $upd['to'], $ignore, TRUE))) {
        continue;
      }

      unset($updates[$key]);
    }
    return $updates;
  }

  /**
   * @param array<string, array{from: string, to: string}> $updates
   */
  private static function updatesHtml(array $updates): string {
    ksort($updates);
    $items = '';
    foreach ($updates as $key => $upd) {
      $items .= sprintf(
        '<li><code>%s</code> %s → %s</li>',
        htmlspecialchars($key),
        htmlspecialchars($upd['from']),
        htmlspecialchars($upd['to']),
      );
    }
    return (
      '<p>'
      . htmlspecialchars(CRM_Pulse_Util::ts('These installed extensions have a newer release:'))
      . "</p><ul>{$items}</ul>"
    );
  }

}
