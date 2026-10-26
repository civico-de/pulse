<?php

declare(strict_types = 1);

use Civi\Pulse\Vendor\Civico\Stdlib\Cast;

// @mago-expect lint:cyclomatic-complexity
/**
 * Recent fatal errors, reduced to ok/fail.
 *
 * Fails when PHP fatals occurred within the last `pulse_error_window`
 * minutes (default 60; 0 disables). Two sources, picked by host CMS:
 *
 * - Drupal with dblog: the watchdog table through Drupal's OWN connection
 *   (the CMS tables need not be in CiviCRM's database).
 * - Everything else: the tails of ConfigAndLog error logs touched in the window.
 */
class CRM_Pulse_Check_FatalErrors {

  /**
   * RFC 5424: emergency(0) … error(3).
   */
  private const DRUPAL_MAX_SEVERITY = 3;

  /**
   * How much of a log file's tail to scan.
   */
  private const TAIL_BYTES = 262_144;

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    $window = Civi::settings()->get('pulse_error_window');
    $windowMinutes = is_numeric($window) ? (int) $window : 60;
    if ($windowMinutes <= 0) {
      return ['status' => 'skipped'];
    }

    $uf = Cast::toString(defined('CIVICRM_UF') ? CIVICRM_UF : '');
    if (str_starts_with($uf, 'Drupal') && class_exists('\Drupal')) {
      $drupal = self::checkDrupalWatchdog($windowMinutes);
      if ($drupal !== NULL) {
        return $drupal;
      }

      // dblog disabled — fall through to the Civi log scan.
    }
    return self::checkCiviLogs($windowMinutes);
  }

  /**
   * @return array<string, mixed>|null NULL when dblog is not available.
   */
  private static function checkDrupalWatchdog(int $windowMinutes): ?array {
    // @phpstan-ignore-next-line class.notFound -- Drupal classes exist only at runtime on Drupal
    if (!\Drupal::moduleHandler()->moduleExists('dblog')) {
      return NULL;
    }
    // @phpstan-ignore-next-line class.notFound
    $db = \Drupal::database();
    // An exotic connection would fatal on ->query(); degrade to "no watchdog leg".
    if (!is_object($db) || !method_exists($db, 'query')) {
      return NULL;
    }
    $since = time() - ($windowMinutes * 60);
    $count = Cast::toInt(self::fetchField($db->query(
      "SELECT COUNT(*) FROM {watchdog} WHERE type = 'php' AND severity <= :sev AND timestamp >= :since",
      [':sev' => self::DRUPAL_MAX_SEVERITY, ':since' => $since],
    )));
    if ($count === 0) {
      return ['status' => 'ok'];
    }
    $latest = self::fetchField($db->query(
      "SELECT message FROM {watchdog} WHERE type = 'php' AND severity <= :sev AND timestamp >= :since ORDER BY wid DESC",
      [':sev' => self::DRUPAL_MAX_SEVERITY, ':since' => $since],
    ));
    return [
      'status' => 'fail',
      'detail' => CRM_Pulse_Util::ts(
        '%1 PHP error(s) in watchdog (last %2 min), latest: %3',
        [1 => $count, 2 => $windowMinutes, 3 => mb_substr(Cast::toString($latest), 0, 200)],
      ),
    ];
  }

  /**
   * First column of the first row of a Drupal statement object, or NULL.
   *
   * @param mixed $statement Whatever Connection::query() handed back.
   * @return mixed
   */
  private static function fetchField(mixed $statement): mixed {
    return is_object($statement) && method_exists($statement, 'fetchField')
      ? $statement->fetchField()
      : NULL;
  }

  /**
   * @return array<string, mixed>
   */
  private static function checkCiviLogs(int $windowMinutes): array {
    $dir = CRM_Pulse_Util::logDir();
    $since = time() - ($windowMinutes * 60);
    $total = 0;
    $globbed = glob($dir . '/CiviCRM*.log');
    $files = $globbed === FALSE ? [] : $globbed;
    foreach ($files as $file) {
      // Log files may vanish or be unreadable between glob and stat; the
      // FALSE checks below are the contract, warnings would only spam.
      // @mago-expect lint:no-error-control-operator
      $mtime = @filemtime($file);
      if ($mtime === FALSE || $mtime < $since) {
        continue;
      }
      // @mago-expect lint:no-error-control-operator
      $size = @filesize($file);
      // @mago-expect lint:no-error-control-operator
      $fh = @fopen($file, 'r');
      if ($fh === FALSE) {
        continue;
      }
      if ($size !== FALSE && $size > self::TAIL_BYTES) {
        fseek($fh, -self::TAIL_BYTES, SEEK_END);
      }
      // mtime only gets the file into scope; the window itself is decided per
      // log entry, otherwise old markers keep the check red forever.
      $total += self::countFatalMarkers(
        CRM_Pulse_Util::logChunkSince((string) stream_get_contents($fh), $since),
      );
      fclose($fh);
    }
    return (
      $total > 0
        ? [
          'status' => 'fail',
          'detail' => CRM_Pulse_Util::ts('%1 fatal-error marker(s) in CiviCRM logs touched within %2 min', [
            1 => $total,
            2 => $windowMinutes,
          ]),
        ]
        : ['status' => 'ok']
    );
  }

  /**
   * Count fatal markers in a log chunk. Pure — test-seam. PHP's fatal banners
   * and Civi's [critical]/[emergency]; plain [error] is routine noise.
   */
  public static function countFatalMarkers(string $chunk): int {
    $count = preg_match_all('/PHP Fatal error|Fatal error:|\[(?:critical|emergency)\]/i', $chunk);
    return $count === FALSE ? 0 : $count;
  }

}
