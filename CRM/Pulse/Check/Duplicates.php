<?php

declare(strict_types = 1);

use Civi\Pulse\Vendor\Civico\Stdlib\Cast;

/**
 * Possible contact duplicates created recently, reduced to ok/fail.
 *
 * A spike of new contacts sharing an e-mail with an older one means a form or
 * import bypasses the matcher. Window-restricted to contacts CREATED within
 * `pulse_duplicates_window_days` (default 7): the rate of NEW duplicates,
 * not the backlog. Fails at `pulse_duplicates_factor` (default 3) times the
 * site's normal per window, never below `pulse_duplicates_threshold` (default
 * 5; 0 disables). The normal is the median of the previous twelve windows, or
 * `pulse_duplicates_baseline` when set. Details carry contact-ID pairs, never
 * addresses (PII).
 */
class CRM_Pulse_Check_Duplicates {

  private const MAX_REPORTED_PAIRS = 5;

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    $threshold = self::integer('pulse_duplicates_threshold', 5);
    if ($threshold <= 0) {
      return ['status' => 'skipped'];
    }
    $windowDays = self::integer('pulse_duplicates_window_days', 7);
    $windowDays = $windowDays > 0 ? $windowDays : 7;
    $factor = self::integer('pulse_duplicates_factor', 3);

    $pairs = self::duplicatePairs($windowDays);
    $normal = $factor > 0 ? CRM_Pulse_Check_DuplicateNormal::perWindow($windowDays) : NULL;

    $limit = $normal === NULL ? $threshold : max($threshold, (int) ceil($factor * $normal));
    $affected = count(array_unique(array_column($pairs, 0)));
    if ($affected < $limit) {
      return ['status' => 'ok'];
    }

    $sample = implode(', ', array_slice(
      array_map(static fn (array $pair): string => $pair[0] . '→' . $pair[1], $pairs),
      0,
      self::MAX_REPORTED_PAIRS,
    ));
    return [
      'status' => 'fail',
      'detail' => $normal === NULL
        ? CRM_Pulse_Util::ts(
          '%1 contact(s) created in the last %2 day(s) share an e-mail with an existing contact (threshold %3), e.g. %4',
          [1 => $affected, 2 => $windowDays, 3 => $threshold, 4 => $sample],
        )
        : CRM_Pulse_Util::ts(
          '%1 contact(s) created in the last %2 day(s) share an e-mail with an existing contact (normal %3, limit %4), e.g. %5',
          [1 => $affected, 2 => $windowDays, 3 => round($normal, 1), 4 => $limit, 5 => $sample],
        ),
    ];
  }

  private static function integer(string $setting, int $default): int {
    $value = Civi::settings()->get($setting);
    return is_numeric($value) ? (int) $value : $default;
  }

  /**
   * Pairs of [new contact id, oldest matching existing contact id] where a
   * contact created inside the window shares an e-mail with an older
   * non-deleted contact; two fresh sharers count once, for the later one.
   *
   * @return list<array{0: int, 1: int}>
   */
  private static function duplicatePairs(int $windowDays): array {
    // executeQuery() collapses to `object`; reassert the DAO type and read via toArray().
    /** @var CRM_Core_DAO $dao */
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT nc.id AS new_id, MIN(oc.id) AS existing_id
       FROM civicrm_contact nc
       JOIN civicrm_email ne ON ne.contact_id = nc.id AND ne.email IS NOT NULL AND ne.email != ""
       JOIN civicrm_email oe ON oe.email = ne.email AND oe.contact_id != nc.id
       JOIN civicrm_contact oc ON oc.id = oe.contact_id AND oc.id < nc.id AND oc.is_deleted = 0
       WHERE nc.is_deleted = 0
         AND nc.created_date >= NOW() - INTERVAL %1 DAY
       GROUP BY nc.id
       ORDER BY nc.id DESC',
      [1 => [$windowDays, 'Integer']],
    );
    $pairs = [];
    while ($dao->fetch()) {
      $row = $dao->toArray();
      $pairs[] = [
        Cast::toInt($row['new_id'] ?? NULL),
        Cast::toInt($row['existing_id'] ?? NULL),
      ];
    }
    return $pairs;
  }

}
