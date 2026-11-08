<?php

declare(strict_types = 1);

use Civi\Api4\Queue;
use Civi\Api4\QueueItem;

/**
 * Stuck items in CiviCRM's persistent queues, reduced to ok/fail.
 *
 * Fails on (a) items in an ACTIVE queue older than `pulse_queue_max_age_hours`
 * (default 6; 0 disables this leg), deferred retries (future `release_time`)
 * excluded, and (b) any queue in status 'aborted'. Template queues never run
 * and are excluded; cores without `civicrm_queue` get 'skipped'.
 */
class CRM_Pulse_Check_Queues {

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    // The registry table only exists with the modern queue API.
    if (!CRM_Core_DAO::checkTableExists('civicrm_queue') || !CRM_Core_DAO::checkTableExists('civicrm_queue_item')) {
      return ['status' => 'skipped'];
    }

    $maxAge = Civi::settings()->get('pulse_queue_max_age_hours');
    $maxAgeHours = is_numeric($maxAge) ? (int) $maxAge : 6;

    $aborted = self::abortedQueues();
    // maxAgeHours <= 0 disables only the age leg; aborted queues still fail.
    $stale = $maxAgeHours > 0 ? self::staleItems($maxAgeHours) : [];

    return self::verdict($aborted, $stale, $maxAgeHours);
  }

  /**
   * Turn the raw findings into a status. Pure — test-seam.
   *
   * @param list<string> $aborted Names of queues in status 'aborted'.
   * @param array<string, array{count: int, oldest_hours: int}> $stale
   *   Per-queue stuck-item summary, keyed by queue name.
   * @return array<string, mixed>
   */
  public static function verdict(array $aborted, array $stale, int $maxAgeHours): array {
    $problems = [];
    foreach ($aborted as $name) {
      $problems[] = CRM_Pulse_Util::ts("queue '%1' aborted", [1 => $name]);
    }
    foreach ($stale as $name => $info) {
      $problems[] = CRM_Pulse_Util::ts(
        "queue '%1': %2 item(s) stuck, oldest %3h (max %4h)",
        [1 => $name, 2 => $info['count'], 3 => $info['oldest_hours'], 4 => $maxAgeHours],
      );
    }
    return (
      $problems
        ? ['status' => 'fail', 'detail' => implode('; ', $problems)]
        : ['status' => 'ok']
    );
  }

  /**
   * @return list<string>
   */
  private static function abortedQueues(): array {
    /** @var list<string> */
    return Queue::get(checkPermissions: FALSE)
      ->addSelect('name')
      ->addWhere('status', '=', 'aborted')
      ->addClause('OR', ['is_template', '=', FALSE], ['is_template', 'IS NULL'])
      ->execute()
      ->column('name');
  }

  /**
   * Items older than $maxAgeHours in active, non-template queues, whose
   * release_time is not in the future (deferred retries don't count).
   *
   * @return array<string, array{count: int, oldest_hours: int}>
   */
  private static function staleItems(int $maxAgeHours): array {
    $now = CRM_Utils_Time::time();
    $out = [];
    $cutoff = date('Y-m-d H:i:s', $now - ($maxAgeHours * 3600));
    /** @var list<array{queue_name: string, cnt: int, oldest: ?string}> $rows */
    $rows = QueueItem::get(checkPermissions: FALSE)
      ->addSelect('queue_name', 'COUNT(id) AS cnt', 'MIN(submit_time) AS oldest')
      ->addJoin('Queue AS queue', 'INNER', NULL, ['queue_name', '=', 'queue.name'])
      ->addWhere('queue.status', '=', 'active')
      ->addClause('OR', ['queue.is_template', '=', FALSE], ['queue.is_template', 'IS NULL'])
      ->addWhere('submit_time', '<', $cutoff)
      ->addClause('OR', ['release_time', 'IS NULL'], ['release_time', '<=', date('Y-m-d H:i:s', $now)])
      ->addGroupBy('queue_name')
      ->execute()
      ->getArrayCopy();
    foreach ($rows as $row) {
      $oldest = strtotime((string) $row['oldest']);
      $out[$row['queue_name']] = [
        'count' => (int) $row['cnt'],
        'oldest_hours' => $oldest ? (int) floor(($now - $oldest) / 3600) : 0,
      ];
    }
    return $out;
  }

}
