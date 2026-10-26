<?php

declare(strict_types = 1);

use Civi\Pulse\Vendor\Civico\Stdlib\Cast;

/**
 * Helpers the checks share: the log directory, guarded CMS calls, translation
 * and metadata-only field filtering.
 */
class CRM_Pulse_Util {

  /**
   * CiviCRM's ConfigAndLog directory; a check that needs it cannot check without it.
   *
   * Via `Civi::paths()`, not the `CRM_Core_Config::$configAndLogDir` magic
   * property: same path variable, without the property's mkdir side effects.
   */
  public static function logDir(): string {
    $dir = Cast::toString(Civi::paths()->getVariable('civicrm.log', 'path'));
    if ($dir === '' || !is_dir($dir)) {
      throw new RuntimeException(self::ts('the CiviCRM log directory is not usable'));
    }
    return rtrim($dir, '/');
  }

  /**
   * The part of a ConfigAndLog chunk that was written at or after $since.
   *
   * Entries start with CiviCRM's log timestamp (`Y-m-d H:i:sO`); lines
   * without one continue the entry above. Lines before the first timestamp
   * of a tail read carry no provable date and are dropped.
   */
  public static function logChunkSince(string $chunk, int $since): string {
    $kept = [];
    $inWindow = FALSE;
    foreach (explode("\n", $chunk) as $line) {
      if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{4})/', $line, $m) === 1) {
        $stamp = strtotime($m[1]);
        $inWindow = $stamp !== FALSE && $stamp >= $since;
      }
      if (!$inWindow) {
        continue;
      }

      $kept[] = $line;
    }
    return implode("\n", $kept);
  }

  /**
   * Call a host-CMS global function that exists only when CiviCRM runs inside
   * that CMS (Drupal's update.module API, WordPress's transient API).
   *
   * One guarded seam: unresolvable at analysis time, and a missing function
   * becomes a 'skipped' check instead of a fatal.
   *
   * @param list<mixed> $args
   * @return mixed NULL when the function does not exist here.
   */
  public static function cmsCall(string $function, array $args = []): mixed {
    if (!function_exists($function)) {
      return NULL;
    }
    return $function(...$args);
  }

  /**
   * ts() bound to this extension's translation domain. The endpoint's
   * machine-facing contract is the HTTP status code, not the detail text.
   *
   * @param array<int|string, mixed> $params
   */
  public static function ts(string $text, array $params = []): string {
    // @mago-expect lint:civikitchen/use-extension-ts -- this is the wrapper
    return ts($text, $params + ['domain' => 'pulse']);
  }

  /**
   * Keep only the named scalar fields of each array row — a whitelist for
   * publishing metadata without free-text or path-bearing keys.
   *
   * @param array<mixed> $rows
   * @param list<string> $fields
   * @return array<string, array<string, mixed>>
   */
  public static function scalarFields(array $rows, array $fields): array {
    $allowed = array_flip($fields);
    $out = [];
    foreach (array_filter($rows, 'is_array') as $name => $row) {
      $out[(string) $name] = array_filter(array_intersect_key($row, $allowed), 'is_scalar');
    }
    return $out;
  }

}
