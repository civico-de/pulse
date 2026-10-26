<?php

declare(strict_types = 1);

use Civi\Pulse\Vendor\Civico\Stdlib\Cast;

/**
 * The normal number of possible duplicates per window, against which
 * CRM_Pulse_Check_Duplicates judges a spike.
 */
class CRM_Pulse_Check_DuplicateNormal {

  private const HISTORY_WINDOWS = 12;

  /**
   * The fixed baseline, or the median count of the previous windows; windows
   * without a duplicate count as zero.
   */
  public static function perWindow(int $windowDays): float {
    $baseline = Civi::settings()->get('pulse_duplicates_baseline');
    if (is_numeric($baseline) && (int) $baseline > 0) {
      return (float) $baseline;
    }
    $counts = array_fill(1, self::HISTORY_WINDOWS, 0);
    /** @var CRM_Core_DAO $dao */
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT FLOOR(TIMESTAMPDIFF(SECOND, nc.created_date, NOW()) / %1) AS bucket,
         COUNT(DISTINCT nc.id) AS duplicates
       FROM civicrm_contact nc
       JOIN civicrm_email ne ON ne.contact_id = nc.id AND ne.email IS NOT NULL AND ne.email != ""
       JOIN civicrm_email oe ON oe.email = ne.email AND oe.contact_id != nc.id
       JOIN civicrm_contact oc ON oc.id = oe.contact_id AND oc.id < nc.id AND oc.is_deleted = 0
       WHERE nc.is_deleted = 0
         AND nc.created_date < NOW() - INTERVAL %2 DAY
         AND nc.created_date >= NOW() - INTERVAL %3 DAY
       GROUP BY bucket',
      [
        1 => [$windowDays * 86_400, 'Integer'],
        2 => [$windowDays, 'Integer'],
        3 => [$windowDays * (self::HISTORY_WINDOWS + 1), 'Integer'],
      ],
    );
    while ($dao->fetch()) {
      $row = $dao->toArray();
      $bucket = Cast::toInt($row['bucket'] ?? NULL);
      if (isset($counts[$bucket])) {
        $counts[$bucket] = Cast::toInt($row['duplicates'] ?? NULL);
      }
    }
    sort($counts);
    $middle = intdiv(self::HISTORY_WINDOWS, 2);
    return ($counts[$middle - 1] + $counts[$middle]) / 2;
  }

}
