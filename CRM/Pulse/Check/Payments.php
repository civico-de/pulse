<?php

declare(strict_types = 1);

/**
 * Stuck / failed payment-processor webhooks, reduced to ok/fail.
 *
 * mjwshared queues inbound webhooks in `civicrm_paymentprocessor_webhook`
 * (status 'new' | 'processing' | 'success' | 'error'). Skipped without the
 * table; missing columns fail at severity error. Fails on rows in
 * 'new'/'processing' older than `pulse_payment_webhook_max_age_hours`
 * (default 2; 0 disables this leg) or any 'error' row within the last 24 h.
 */
class CRM_Pulse_Check_Payments {

  private const TABLE = 'civicrm_paymentprocessor_webhook';

  private const ERROR_WINDOW_HOURS = 24;

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    // No table: mjwshared not installed.
    if (!CRM_Core_DAO::checkTableExists(self::TABLE)) {
      return ['status' => 'skipped'];
    }
    // A renamed column would make the queries below explode; say so instead.
    $columns = self::columns();
    if (!in_array('status', $columns, TRUE) || !in_array('created_date', $columns, TRUE)) {
      return [
        'status' => 'fail',
        'severity' => \Psr\Log\LogLevel::ERROR,
        'detail' => CRM_Pulse_Util::ts('could not check: the webhook table has no status or created_date column'),
      ];
    }

    $maxAge = Civi::settings()->get('pulse_payment_webhook_max_age_hours');
    $maxAgeHours = is_numeric($maxAge) ? (int) $maxAge : 2;

    $stuck = $maxAgeHours > 0 ? self::stuck($maxAgeHours) : NULL;
    // Dated by processed_date: a days-old webhook failed today must count.
    $errorCount = self::recentErrors(self::ERROR_WINDOW_HOURS, self::errorDateColumn($columns));

    return self::verdict($stuck, $errorCount, $maxAgeHours);
  }

  /**
   * Turn the raw findings into a status. Pure — test-seam.
   *
   * @param array{count: int, oldest_hours: int}|null $stuck
   *   Summary of new/processing rows past the age threshold, or NULL when the
   *   age leg is disabled.
   * @return array<string, mixed>
   */
  public static function verdict(?array $stuck, int $errorCount, int $maxAgeHours): array {
    $problems = [];
    if ($stuck !== NULL && $stuck['count'] > 0) {
      $problems[] = CRM_Pulse_Util::ts(
        '%1 webhook(s) stuck in new/processing, oldest %2h (max %3h)',
        [1 => $stuck['count'], 2 => $stuck['oldest_hours'], 3 => $maxAgeHours],
      );
    }
    if ($errorCount > 0) {
      $problems[] = CRM_Pulse_Util::ts('%1 webhook(s) errored in the last %2h', [
        1 => $errorCount,
        2 => self::ERROR_WINDOW_HOURS,
      ]);
    }
    return (
      $problems
        ? ['status' => 'fail', 'detail' => implode('; ', $problems)]
        : ['status' => 'ok']
    );
  }

  /**
   * processed_date when the schema has it, else created_date. Pure — test-seam.
   *
   * @param list<string> $columns Lower-cased column names.
   */
  public static function errorDateColumn(array $columns): string {
    return in_array('processed_date', $columns, TRUE) ? 'processed_date' : 'created_date';
  }

  /**
   * Lower-cased column names of the webhook table.
   *
   * @return list<string>
   */
  private static function columns(): array {
    $cols = [];
    $dao = CRM_Core_DAO::executeQuery('SHOW COLUMNS FROM ' . self::TABLE);
    /** @var \CRM_Core_DAO&object{Field: string} $dao */
    while ($dao->fetch()) {
      $cols[] = strtolower((string) $dao->Field);
    }
    return $cols;
  }

  /**
   * @return array{count: int, oldest_hours: int}
   */
  private static function stuck(int $maxAgeHours): array {
    $now = CRM_Utils_Time::time();
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT COUNT(*) AS cnt, MIN(created_date) AS oldest
       FROM ' . self::TABLE . "
       WHERE status IN ('new', 'processing')
         AND created_date < DATE_SUB(FROM_UNIXTIME(%1), INTERVAL %2 HOUR)",
      [
        1 => [$now, 'Integer'],
        2 => [$maxAgeHours, 'Integer'],
      ],
    );
    /** @var \CRM_Core_DAO&object{cnt: string, oldest: ?string} $dao */
    $dao->fetch();
    $oldest = strtotime((string) $dao->oldest);
    return [
      'count' => (int) $dao->cnt,
      'oldest_hours' => $oldest ? (int) floor(($now - $oldest) / 3600) : 0,
    ];
  }

  /**
   * @param string $dateColumn 'processed_date' (preferred) or 'created_date'
   *   fallback — validated against SHOW COLUMNS by the caller, never
   *   user-supplied.
   */
  private static function recentErrors(int $windowHours, string $dateColumn = 'created_date'): int {
    $now = CRM_Utils_Time::time();
    return (int) CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM '
      . self::TABLE
      . "
       WHERE status = 'error'
         AND `{$dateColumn}` >= DATE_SUB(FROM_UNIXTIME(%1), INTERVAL %2 HOUR)",
      [
        1 => [$now, 'Integer'],
        2 => [$windowHours, 'Integer'],
      ],
    );
  }

}
