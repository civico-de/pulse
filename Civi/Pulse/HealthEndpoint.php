<?php

declare(strict_types = 1);

namespace Civi\Pulse;

use Civi\Api4\System;
use Civi\Pulse\Vendor\Civico\Stdlib\Cast;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

// @mago-expect lint:cyclomatic-complexity
/**
 * GET civicrm/pulse?token=...
 *
 * Answers 200 {"status":"ok",...} when the site is healthy, 503 otherwise.
 * Reaching this code already proves web server, PHP and bootstrap are up.
 */
class HealthEndpoint {

  public static function handle(RequestInterface $request): ResponseInterface {
    parse_str($request->getUri()->getQuery(), $query);
    $given = $query['token'] ?? NULL;
    /** @var array<string, mixed> $server */
    $server = $_SERVER;
    [$httpStatus, $body] = self::evaluate(is_string($given) ? $given : NULL, $server);
    return new Response(
      $httpStatus,
      ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
      (string) json_encode($body, JSON_UNESCAPED_SLASHES),
    );
  }

  /**
   * The whole endpoint logic minus the HTTP layer, so headless tests can
   * exercise it without building a request.
   *
   * @param ?string $given The ?token= query param (kept for compatibility).
   * @param array<string, mixed> $server $_SERVER, for the Authorization header.
   * @return array{0: int, 1: array<string, mixed>}
   */
  public static function evaluate(?string $given, array $server = []): array {
    // The header wins over ?token=, which leaks into access logs.
    $presented = self::extractToken($given, $server);

    $token = \Civi::settings()->get('pulse_token');
    if (!is_string($token) || $token === '') {
      // Refuse instead of degrading to an open endpoint.
      return [503, ['status' => 'unconfigured', 'detail' => \CRM_Pulse_Util::ts('pulse_token setting is not set')]];
    }
    if (!hash_equals($token, $presented ?? '')) {
      return [403, ['status' => 'forbidden']];
    }

    $checks = [
      'database' => self::checkDatabase(),
      'cron' => self::checkCron(),
      'system' => self::checkSystem(),
    ];
    $ok = !in_array('fail', array_column($checks, 'status'), TRUE);
    // Informational only: a late backup is not an outage.
    $checks['host_status'] = \CRM_Pulse_Check_HostStatus::check();
    return [
      $ok ? 200 : 503,
      ['status' => $ok ? 'ok' : 'fail', 'checks' => $checks],
    ];
  }

  /**
   * `X-Pulse-Token` header over ?token=. Not `Authorization: Bearer`: authx
   * answers 401 for an unknown credential before the route runs. Pure — test-seam.
   *
   * @param array<string, mixed> $server
   */
  public static function extractToken(?string $given, array $server): ?string {
    $header = $server['HTTP_X_PULSE_TOKEN'] ?? '';
    if (is_string($header) && trim($header) !== '') {
      return trim($header);
    }
    return $given !== NULL && $given !== '' ? $given : NULL;
  }

  /**
   * @return array<string, mixed>
   */
  public static function checkDatabase(): array {
    try {
      \CRM_Core_DAO::singleValueQuery('SELECT 1');
      return ['status' => 'ok'];
    }
    catch (Throwable $e) {
      return ['status' => 'fail', 'detail' => \CRM_Pulse_Util::ts('query failed')];
    }
  }

  /**
   * @return array<string, mixed>
   */
  public static function checkCron(): array {
    $maxAge = \Civi::settings()->get('pulse_max_cron_age');
    $maxAgeMinutes = is_numeric($maxAge) ? (int) $maxAge : 0;
    if ($maxAgeMinutes <= 0) {
      return ['status' => 'skipped'];
    }
    // civicrm_job_log gets a row per `job.execute` tick even when no job is
    // due; civicrm_job.last_run would look dead all week with only a weekly job.
    $lastRun = \CRM_Core_DAO::singleValueQuery('SELECT MAX(run_time) FROM civicrm_job_log');
    if (!$lastRun) {
      return ['status' => 'fail', 'detail' => \CRM_Pulse_Util::ts('cron has never run')];
    }
    $ageMinutes = (int) floor((\CRM_Utils_Time::time() - strtotime($lastRun)) / 60);
    return [
      'status' => $ageMinutes <= $maxAgeMinutes ? 'ok' : 'fail',
      'last_run' => $lastRun,
      'age_minutes' => $ageMinutes,
      'max_age_minutes' => $maxAgeMinutes,
    ];
  }

  /**
   * System.check without pulse's own messages, reduced to pass/fail against a
   * severity threshold; cached 5 minutes because some checks are expensive.
   *
   * @return array<string, mixed>
   */
  public static function checkSystem(): array {
    $minSeverity = \Civi::settings()->get('pulse_min_severity');
    $minSeverity = is_string($minSeverity) ? $minSeverity : '';
    // 'off' is the explicit disable value: SettingsBag treats '' as "unset"
    // and silently falls back to the default, so '' can never arrive here.
    if ($minSeverity === '' || $minSeverity === 'off') {
      return ['status' => 'skipped'];
    }
    try {
      $minLevel = \CRM_Utils_Check::severityMap($minSeverity);
    }
    catch (Throwable $e) {
      return [
        'status' => 'fail',
        'detail' => \CRM_Pulse_Util::ts("invalid pulse_min_severity '%1'", [1 => $minSeverity]),
      ];
    }

    $cache = \Civi::cache('long');
    $result = $cache->get('pulse_system_check');
    if (!is_array($result)) {
      try {
        $failing = [];
        foreach (System::check(checkPermissions: FALSE)->execute() as $check) {
          if (!(is_array($check) && !empty($check['is_visible']) && $check['severity_id'] >= $minLevel)) {
            continue;
          }
          // pulse's own messages are maintenance findings, never an outage.
          if (str_starts_with(Cast::toString($check['name'] ?? ''), 'pulse_')) {
            continue;
          }

          $failing[] = $check['name'];
        }
        $result = ['status' => $failing ? 'fail' : 'ok', 'min_severity' => $minSeverity];
        if ($failing) {
          $result['failing'] = $failing;
        }
      }
      catch (Throwable $e) {
        // Token-geschützt, die Message darf raus; sonst ist ein transienter
        // Fehler nachträglich nicht diagnostizierbar.
        $result = [
          'status' => 'fail',
          'detail' => \CRM_Pulse_Util::ts('System.check threw: %1', [1 => get_class($e) . ': ' . $e->getMessage()]),
        ];
      }
      // Ein Fail nur kurz cachen, sonst hält ein transienter Fehler 5 Minuten rot.
      $cache->set('pulse_system_check', $result, $result['status'] === 'ok' ? 300 : 60);
    }
    /** @var array<string, mixed> $result */
    return $result;
  }

}
