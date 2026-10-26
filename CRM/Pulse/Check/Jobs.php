<?php

declare(strict_types = 1);

// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:kan-defect
/**
 * Per-job staleness: an ACTIVE job that has not run within frequency ×
 * `pulse_jobs_grace_factor` (default 3; 0 disables) is broken even while the
 * global cron check stays green. A second leg catches never-run active jobs:
 * `civicrm_job` has no `created` column, so `pulse_jobs_first_seen` records
 * when each was first observed and fails it after
 * `pulse_jobs_never_ran_grace_hours` (default 48; 0 disables).
 */
class CRM_Pulse_Check_Jobs {

  /**
   * Expected max minutes between runs, per run_frequency value.
   */
  private const FREQUENCY_MINUTES = [
    'Always' => 60,
    'Hourly' => 60,
    'Daily' => 1440,
    'Weekly' => 10_080,
    'Monthly' => 44_640,
    'Quarter' => 133_920,
    'Yearly' => 535_680,
  ];

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    $factor = Civi::settings()->get('pulse_jobs_grace_factor');
    $factor = is_numeric($factor) ? (int) $factor : 3;
    $graceHours = Civi::settings()->get('pulse_jobs_never_ran_grace_hours');
    $graceHours = is_numeric($graceHours) ? (int) $graceHours : 48;

    // Both legs disabled → nothing to do.
    if ($factor <= 0 && $graceHours <= 0) {
      return ['status' => 'skipped'];
    }

    $problems = [];
    if ($factor > 0) {
      foreach (self::staleJobs($factor) as $detail) {
        $problems[] = $detail;
      }
    }
    if ($graceHours > 0) {
      foreach (self::neverRanJobs($graceHours) as $detail) {
        $problems[] = $detail;
      }
    }

    return (
      $problems
        ? ['status' => 'fail', 'detail' => implode('; ', $problems)]
        : ['status' => 'ok']
    );
  }

  /**
   * Active jobs that ran, but not within their grace window.
   *
   * @return list<string>
   */
  private static function staleJobs(int $factor): array {
    $stale = [];
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT name, run_frequency, last_run FROM civicrm_job WHERE is_active = 1 AND last_run IS NOT NULL',
    );
    /** @var \CRM_Core_DAO&object{name: ?string, run_frequency: ?string, last_run: ?string} $dao */
    while ($dao->fetch()) {
      $interval = self::FREQUENCY_MINUTES[(string) $dao->run_frequency] ?? NULL;
      if ($interval === NULL) {
        continue;
      }
      $ageMinutes = (int) floor((CRM_Utils_Time::time() - strtotime((string) $dao->last_run)) / 60);
      if ($ageMinutes > ($interval * $factor)) {
        $stale[] = CRM_Pulse_Util::ts('%1 (%2, last run %3h ago)', [
          1 => $dao->name,
          2 => $dao->run_frequency,
          3 => intdiv($ageMinutes, 60),
        ]);
      }
    }
    return $stale;
  }

  /**
   * Active jobs that have never run and are past their grace period. Persists
   * the refreshed first-seen bookkeeping as a side effect.
   *
   * @return list<string>
   */
  private static function neverRanJobs(int $graceHours): array {
    $names = [];
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT name FROM civicrm_job WHERE is_active = 1 AND last_run IS NULL',
    );
    /** @var \CRM_Core_DAO&object{name: ?string} $dao */
    while ($dao->fetch()) {
      $names[] = (string) $dao->name;
    }

    $firstSeen = Civi::settings()->get('pulse_jobs_first_seen');
    /** @var array<string, mixed> $firstSeen */
    $firstSeen = is_array($firstSeen) ? $firstSeen : [];

    $result = self::evaluateNeverRan($names, $firstSeen, CRM_Utils_Time::time(), $graceHours);
    // Only write when the map actually changed, to avoid setting churn. Loose
    // on purpose: settings round-trip ints as strings and reorder keys.
    // @mago-expect lint:identity-comparison
    if ($result['firstSeen'] != $firstSeen) {
      Civi::settings()->set('pulse_jobs_first_seen', $result['firstSeen']);
    }

    $out = [];
    foreach ($result['failed'] as $name) {
      $out[] = CRM_Pulse_Util::ts('%1 never ran (active, first seen >%2h ago)', [1 => $name, 2 => $graceHours]);
    }
    return $out;
  }

  /**
   * Pure never-ran bookkeeping. Test-seam. Registers unknown jobs at $now,
   * forgets jobs not in the list, flags those past the grace window.
   *
   * @param list<string> $neverRanNames
   * @param array<string, mixed> $firstSeen job-name → unix timestamp
   * @return array{firstSeen: array<string, int>, failed: list<string>}
   */
  public static function evaluateNeverRan(array $neverRanNames, array $firstSeen, int $now, int $graceHours): array {
    $updated = [];
    $failed = [];
    foreach ($neverRanNames as $name) {
      $seen = isset($firstSeen[$name]) && is_numeric($firstSeen[$name]) ? (int) $firstSeen[$name] : $now;
      $updated[$name] = $seen;
      if ($graceHours > 0 && ($now - $seen) >= ($graceHours * 3600)) {
        $failed[] = $name;
      }
    }
    return ['firstSeen' => $updated, 'failed' => $failed];
  }

}
