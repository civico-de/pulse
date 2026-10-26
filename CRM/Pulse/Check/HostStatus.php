<?php

declare(strict_types = 1);

use Civi\Pulse\Vendor\Civico\Stdlib\Cast;

// @mago-expect lint:cyclomatic-complexity
/**
 * Host-side operations health, read from a status file the hosting writes.
 *
 * A process on the host records each dump, backup or health run in a small
 * metadata-only JSON file, mounted read-only into this container
 * (`pulse_host_status_file`; empty disables). The format is documented in
 * handbook/host-status.md. This check fails when
 *
 *  - the file itself is stale (`updated` older than the freshness window), or
 *  - any recorded job's last run errored, or
 *  - any recorded job has not succeeded within its window.
 *
 * A host-wide file judges only `environments.<pulse_host_status_environment>.jobs`;
 * with that map present and the setting unset it fails rather than guess. A
 * file with only the top-level `jobs` map is judged as is. A job's window is
 * max(expected_interval_hours × 1.5, pulse_host_status_max_age) when the field is set.
 */
class CRM_Pulse_Check_HostStatus {

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    $path = Civi::settings()->get('pulse_host_status_file');
    if (!is_string($path) || trim($path) === '') {
      return ['status' => 'skipped'];
    }
    $maxAge = Civi::settings()->get('pulse_host_status_max_age');
    $maxAgeHours = is_numeric($maxAge) && (int) $maxAge > 0 ? (int) $maxAge : 26;
    $environment = trim(Cast::toString(Civi::settings()->get('pulse_host_status_environment')));

    if (!is_readable($path)) {
      return [
        'status' => 'fail',
        'detail' => CRM_Pulse_Util::ts('status file not readable — mount missing or never written'),
      ];
    }
    $data = json_decode((string) file_get_contents($path), TRUE);
    if (!is_array($data) || !is_array($data['jobs'] ?? NULL)) {
      return ['status' => 'fail', 'detail' => CRM_Pulse_Util::ts('status file is not valid host status JSON')];
    }
    /** @var array<string, mixed> $data */
    $env = $environment === '' ? NULL : $environment;
    $result = self::evaluate($data, CRM_Utils_Time::time(), $maxAgeHours, $env);
    // Job metadata (never dump contents) lets pollers show backup age.
    $jobs = self::jobsInScope($data, $env);
    if (is_array($jobs)) {
      $result['updated'] = Cast::toString($data['updated'] ?? '');
      $result['jobs'] = CRM_Pulse_Util::scalarFields($jobs, [
        'status',
        'finished',
        'duration_seconds',
        'expected_interval_hours',
      ]);
    }
    return $result;
  }

  /**
   * Pure evaluation. Test-seam.
   *
   * @param array<string, mixed> $data Decoded status file.
   * @param string|null $environment This instance's environment name.
   * @return array<string, mixed>
   */
  // @mago-expect lint:halstead
  public static function evaluate(array $data, int $now, int $maxAgeHours, ?string $environment = NULL): array {
    $problems = [];
    $jobs = self::jobsInScope($data, $environment);
    if (is_string($jobs)) {
      return ['status' => 'fail', 'detail' => $jobs];
    }

    // The file's freshness window is the FASTEST job's window (a setup with
    // only a weekly backup writes weekly), never tighter than the global one.
    $jobWindows = [];
    foreach (array_filter($jobs, 'is_array') as $state) {
      $expected = $state['expected_interval_hours'] ?? NULL;
      $jobWindows[] = is_numeric($expected) && (float) $expected > 0
        ? max((int) ceil((float) $expected * 1.5), $maxAgeHours)
        : $maxAgeHours;
    }
    $fileWindowHours = $jobWindows ? min($jobWindows) : $maxAgeHours;

    $updated = strtotime(Cast::toString($data['updated'] ?? ''));
    if ($updated === FALSE || ($now - $updated) > ($fileWindowHours * 3600)) {
      $problems[] = CRM_Pulse_Util::ts('status file stale (not updated in %1h)', [1 => $fileWindowHours]);
    }
    foreach ($jobs as $job => $state) {
      if (!is_array($state)) {
        continue;
      }
      if (($state['status'] ?? '') === 'error') {
        $detail = is_scalar($state['detail'] ?? NULL) ? ': ' . $state['detail'] : '';
        $problems[] = CRM_Pulse_Util::ts('%1 failed%2', [1 => $job, 2 => $detail]);
        continue;
      }
      $windowHours = $maxAgeHours;
      $expected = $state['expected_interval_hours'] ?? NULL;
      if (is_numeric($expected) && (float) $expected > 0) {
        // ×1.5 room for an infrequent job, never tighter than the global window.
        $windowHours = max((int) ceil((float) $expected * 1.5), $maxAgeHours);
      }
      $finished = strtotime(Cast::toString($state['finished'] ?? ''));
      if ($finished === FALSE || ($now - $finished) > ($windowHours * 3600)) {
        $problems[] = CRM_Pulse_Util::ts('%1: no success in %2h', [1 => $job, 2 => $windowHours]);
      }
    }
    return (
      $problems
        ? ['status' => 'fail', 'detail' => implode('; ', $problems)]
        : ['status' => 'ok']
    );
  }

  /**
   * The job map this instance is entitled to judge, or a failure message when
   * the file distinguishes environments and this instance cannot say which it is.
   *
   * @param array<string, mixed> $data
   * @return array<mixed>|string
   */
  private static function jobsInScope(array $data, ?string $environment): array|string {
    $environments = $data['environments'] ?? NULL;
    if (!is_array($environments)) {
      $hostWide = $data['jobs'] ?? NULL;
      return is_array($hostWide) ? $hostWide : [];
    }
    if ($environment === NULL || $environment === '') {
      return CRM_Pulse_Util::ts(
        'pulse_host_status_environment is not set, but the status file covers several environments — set it to this instance\'s environment name',
      );
    }
    $own = $environments[$environment] ?? NULL;
    $jobs = is_array($own) ? $own['jobs'] ?? NULL : NULL;
    if (!is_array($jobs) || $jobs === []) {
      return CRM_Pulse_Util::ts('no jobs recorded for environment "%1"', [1 => $environment]);
    }
    return $jobs;
  }

}
