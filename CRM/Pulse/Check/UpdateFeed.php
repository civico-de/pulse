<?php

declare(strict_types = 1);

use Civi\Pulse\Vendor\Civico\Stdlib\Cast;

/**
 * Installed extensions compared with core's extension browser. A read is
 * cached 6 h, a failed one 1 h; the last successful read is kept.
 */
class CRM_Pulse_Check_UpdateFeed {

  public const LAST_READ_SETTING = 'pulse_updates_remote_versions';

  public const CACHE_KEY = 'pulse_updates_feed';

  private const CACHE_TTL = 21_600;

  private const FAILED_READ_TTL = 3_600;

  /**
   * Versions of the last successful read, with the error of a failed one.
   *
   * @return array{versions: array<string, string>, error: ?string}
   */
  public static function remoteVersions(): array {
    $cache = Civi::cache('long');
    $error = $cache->get(self::CACHE_KEY);
    if (!is_string($error)) {
      $error = self::read();
      $cache->set(self::CACHE_KEY, $error, $error === '' ? self::CACHE_TTL : self::FAILED_READ_TTL);
    }
    $versions = Civi::settings()->get(self::LAST_READ_SETTING);
    return [
      'versions' => array_map(Cast::toString(...), Cast::namedOnly(is_array($versions) ? $versions : [])),
      'error' => $error === '' ? NULL : $error,
    ];
  }

  /**
   * Installed extensions whose remote version is newer than the local one.
   *
   * @param array<string, string> $remoteVersions extension key => remote version
   * @return array<string, array{from: string, to: string}>
   */
  public static function updates(array $remoteVersions): array {
    $mapper = CRM_Extension_System::singleton()->getMapper();
    $updates = [];
    foreach (self::installedKeys() as $key) {
      $remote = $remoteVersions[$key] ?? '';
      if ($remote === '') {
        continue;
      }
      try {
        $local = (string) $mapper->keyToInfo($key)->version;
      }
      catch (CRM_Extension_Exception $e) {
        // Core's checkExtensionsError reports extensions without readable files.
        continue;
      }
      if ($local !== '' && version_compare($local, $remote, '<')) {
        $updates[$key] = ['from' => $local, 'to' => $remote];
      }
    }
    return $updates;
  }

  /**
   * @return list<string>
   */
  private static function installedKeys(): array {
    $statuses = CRM_Extension_System::singleton()->getManager()->getStatuses();
    return array_map(
      'strval',
      array_keys(array_filter(
        $statuses,
        static fn (mixed $status): bool => $status === CRM_Extension_Manager::STATUS_INSTALLED,
      )),
    );
  }

  /**
   * One read of the extension browser, stored as the last successful read.
   *
   * @return string the error of a failed read, '' on success
   */
  private static function read(): string {
    try {
      $remotes = CRM_Extension_System::singleton()->getBrowser()->getExtensions();
    }
    catch (CRM_Extension_Exception $e) {
      return $e->getMessage();
    }
    $versions = [];
    foreach (self::installedKeys() as $key) {
      if (!isset($remotes[$key])) {
        continue;
      }
      $versions[$key] = (string) $remotes[$key]->version;
    }
    Civi::settings()->set(self::LAST_READ_SETTING, $versions);
    return '';
  }

}
