<?php

declare(strict_types = 1);

/**
 * Free disk space on the filesystem CiviCRM writes to (measured at the
 * ConfigAndLog directory). Fails below `pulse_min_disk_free_percent`
 * (default 10; 0 disables).
 */
class CRM_Pulse_Check_Disk {

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    $minPercent = Civi::settings()->get('pulse_min_disk_free_percent');
    $minPercent = is_numeric($minPercent) ? (float) $minPercent : 10.0;
    if ($minPercent <= 0) {
      return ['status' => 'skipped'];
    }
    $dir = CRM_Pulse_Util::logDir();
    // open_basedir/permission failures must surface as the FALSE below, not as a warning.
    // @mago-expect lint:no-error-control-operator
    $free = @disk_free_space($dir);
    // @mago-expect lint:no-error-control-operator
    $total = @disk_total_space($dir);
    if ($free === FALSE || $total === FALSE || $total <= 0) {
      return [
        'status' => 'fail',
        'severity' => \Psr\Log\LogLevel::ERROR,
        'detail' => CRM_Pulse_Util::ts('could not stat filesystem'),
      ];
    }
    $percent = ($free / $total) * 100;
    return (
      $percent < $minPercent
        ? [
          'status' => 'fail',
          'detail' => CRM_Pulse_Util::ts('%1%% free (%2 GB) — threshold %3%%', [
            1 => sprintf('%.1f', $percent),
            2 => sprintf('%.1f', $free / (1024 ** 3)),
            3 => sprintf('%.0f', $minPercent),
          ]),
        ]
        : ['status' => 'ok']
    );
  }

}
