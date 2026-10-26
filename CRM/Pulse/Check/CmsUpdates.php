<?php

declare(strict_types = 1);

use Civi\Pulse\Vendor\Civico\Stdlib\Cast;

// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:kan-defect
/**
 * Pending CMS updates (Drupal, WordPress), reduced to ok/fail; a security
 * release fails at severity error.
 *
 * Runs inside CiviCRM but reads the HOST CMS's own update bookkeeping, so
 * it works wherever Civi is embedded:
 *
 * - Drupal: the `update` module's project data (update_calculate_project_data)
 * - WordPress: the update_core / update_plugins / update_themes transients
 * - Standalone or CMS data unavailable: 'skipped'
 *
 * `pulse_cms_updates_mode`: 'security' (default) fails only on security
 * releases, 'all' also on ordinary updates, 'off' disables. Cached 6 h.
 */
class CRM_Pulse_Check_CmsUpdates {

  private const CACHE_KEY = 'pulse_cms_updates_check';
  private const CACHE_TTL = 21_600;

  /**
   * Drupal's update-status constants, resolved at runtime. Pure — test-seam.
   *
   * @return array{security: list<int>, update: list<int>}
   */
  public static function drupalStatuses(): array {
    $c = static function (string $name, string $legacy): ?int {
      $iface = '\Drupal\update\UpdateManagerInterface';
      if (defined("{$iface}::{$name}")) {
        return Cast::toInt(constant("{$iface}::{$name}"));
      }
      return defined($legacy) ? Cast::toInt(constant($legacy)) : NULL;
    };
    $security = array_values(array_filter([
      $c('NOT_SECURE', 'UPDATE_NOT_SECURE'),
      $c('REVOKED', 'UPDATE_REVOKED'),
      $c('NOT_SUPPORTED', 'UPDATE_NOT_SUPPORTED'),
    ], 'is_int'));
    $update = array_values(array_filter([$c('NOT_CURRENT', 'UPDATE_NOT_CURRENT')], 'is_int'));
    return ['security' => $security, 'update' => $update];
  }

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    $mode = Civi::settings()->get('pulse_cms_updates_mode');
    $mode = is_string($mode) && $mode !== '' ? $mode : 'security';
    if ($mode === 'off') {
      return ['status' => 'skipped'];
    }

    $cache = Civi::cache('long');
    $result = $cache->get(self::CACHE_KEY);
    if (!is_array($result)) {
      $result = self::checkHost($mode);
      $cache->set(self::CACHE_KEY, $result, self::CACHE_TTL);
    }
    /** @var array<string, mixed> $result */
    return $result;
  }

  /**
   * @return array<string, mixed>
   */
  private static function checkHost(string $mode): array {
    $uf = Cast::toString(defined('CIVICRM_UF') ? CIVICRM_UF : '');
    if (str_starts_with($uf, 'Drupal') && class_exists('\Drupal')) {
      return self::checkDrupal($mode);
    }
    if ($uf === 'WordPress' && function_exists('get_site_transient')) {
      return self::checkWordpress($mode);
    }
    return ['status' => 'skipped'];
  }

  /**
   * @return array<string, mixed>
   */
  private static function checkDrupal(string $mode): array {
    // @phpstan-ignore-next-line class.notFound -- Drupal classes exist only at runtime on Drupal
    if (!\Drupal::moduleHandler()->moduleExists('update')) {
      return ['status' => 'skipped'];
    }
    // @phpstan-ignore-next-line class.notFound
    \Drupal::moduleHandler()->loadInclude('update', 'inc', 'update.compare');
    // Refresh=FALSE: never block on a round-trip to updates.drupal.org.
    $available = CRM_Pulse_Util::cmsCall('update_get_available', [FALSE]);
    if (!$available) {
      return ['status' => 'skipped'];
    }
    $projects = CRM_Pulse_Util::cmsCall('update_calculate_project_data', [$available]);
    if (!is_array($projects)) {
      return ['status' => 'skipped'];
    }
    $statuses = self::drupalStatuses();
    $security = [];
    $updates = [];
    foreach ($projects as $name => $project) {
      $status = Cast::toInt(is_array($project) ? $project['status'] ?? 0 : 0);
      if (in_array($status, $statuses['security'], TRUE)) {
        $security[] = (string) $name;
      }
      // Security wins: a project is classified into exactly one bucket.
      // @mago-expect lint:no-else-clause
      elseif (in_array($status, $statuses['update'], TRUE)) {
        $updates[] = (string) $name;
      }
    }
    return self::verdict($mode, $security, $updates);
  }

  /**
   * Reads the three WordPress update transients. Public as a test-seam.
   *
   * @return array<string, mixed>
   */
  public static function checkWordpress(string $mode): array {
    $security = [];
    $updates = [];
    $core = CRM_Pulse_Util::cmsCall('get_site_transient', ['update_core']);
    $coreUpdates = is_object($core) ? $core->updates ?? NULL : NULL;
    $newest = is_array($coreUpdates) ? $coreUpdates[0] ?? NULL : NULL;
    if (is_object($newest) && ($newest->response ?? '') === 'upgrade') {
      // WP does not flag security-ness in the transient; treat core as security.
      $security[] = 'wordpress-core ' . Cast::toString($newest->version ?? '');
    }
    foreach (['update_plugins' => 'plugin', 'update_themes' => 'theme'] as $transient => $kind) {
      $t = CRM_Pulse_Util::cmsCall('get_site_transient', [$transient]);
      if (is_object($t) && !empty($t->response) && is_array($t->response)) {
        foreach (array_keys($t->response) as $slug) {
          $updates[] = "{$kind}:{$slug}";
        }
      }
    }
    return self::verdict($mode, $security, $updates);
  }

  /**
   * Shared threshold logic. Pure — test-seam.
   *
   * @param list<string> $security
   * @param list<string> $updates
   * @return array<string, mixed>
   */
  public static function verdict(string $mode, array $security, array $updates): array {
    if ($security) {
      $detail = 'security: ' . implode(', ', $security);
      if ($updates) {
        $detail .= ' | updates: ' . implode(', ', $updates);
      }
      return ['status' => 'fail', 'severity' => \Psr\Log\LogLevel::ERROR, 'detail' => $detail];
    }
    if ($mode === 'all' && $updates) {
      return ['status' => 'fail', 'detail' => CRM_Pulse_Util::ts('updates: %1', [1 => implode(', ', $updates)])];
    }
    return ['status' => 'ok'];
  }

}
