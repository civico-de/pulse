<?php

declare(strict_types = 1);

// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:kan-defect
/**
 * CiviRules health, reduced to ok/fail. Skipped when CiviRules isn't installed.
 *
 * Ein Konfig-Fehler (z.B. Bedingung ohne `condition_link`) bricht die GESAMTE
 * Regel-Auswertung still ab und landet nur als [error]-Zeile im Log, die
 * pulse_fatal_errors ignoriert. Daher zwei Beine:
 *  (a) CONFIG-LINT über alle aktiven Regeln: condition_link ab der zweiten
 *      Bedingung, Stammzeilen und Klassen vorhanden, Params deserialisierbar,
 *      keine Regel ohne aktive Action.
 *  (b) LOG-SCAN: "CiviRules"-Zeilen mit [error]/[critical] im Tail der
 *      ConfigAndLog-Dateien innerhalb von `pulse_error_window` Minuten.
 */
class CRM_Pulse_Check_Civirules {

  /**
   * How much of a log file's tail to scan (mirrors FatalErrors).
   */
  private const TAIL_BYTES = 262_144;

  /**
   * @return array<string, mixed>
   */
  public static function check(): array {
    if (!CRM_Core_DAO::checkTableExists('civirule_rule_condition')) {
      return ['status' => 'skipped'];
    }
    $problems = [];
    $broken = self::lintConditionLinks();
    if ($broken !== []) {
      $problems[] = CRM_Pulse_Util::ts(
        'condition_link invalid (rule evaluation aborts): rule_condition ids %1',
        [1 => implode(', ', $broken)],
      );
    }
    $problems = array_merge($problems, self::lintReferences());
    if ($problems !== []) {
      return ['status' => 'fail', 'detail' => implode('; ', array_slice($problems, 0, 10))];
    }
    $logErrors = self::countRecentLogErrors();
    if ($logErrors > 0) {
      return [
        'status' => 'fail',
        'detail' => CRM_Pulse_Util::ts('%1 CiviRules error line(s) in CiviCRM logs within the window', [
          1 => $logErrors,
        ]),
      ];
    }
    return ['status' => 'ok'];
  }

  /**
   * Ids aktiver Bedingungszeilen, die einen condition_link bräuchten, aber
   * keinen gültigen (AND/OR) tragen.
   *
   * @return list<int>
   */
  private static function lintConditionLinks(): array {
    // Erste Bedingung je Regel = kleinstes weight, Tie-Break kleinste id (CiviRules-Reihenfolge).
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT rc.id
         FROM civirule_rule_condition rc
         JOIN civirule_rule r ON r.id = rc.rule_id AND r.is_active = 1
        WHERE rc.is_active = 1
          AND (rc.condition_link IS NULL OR UPPER(rc.condition_link) NOT IN ('AND', 'OR'))
          AND rc.id <> (
            SELECT rc2.id FROM civirule_rule_condition rc2
             WHERE rc2.rule_id = rc.rule_id AND rc2.is_active = 1
             ORDER BY rc2.weight ASC, rc2.id ASC LIMIT 1
          )",
    );
    /** @var \CRM_Core_DAO&object{id: string} $dao */
    $ids = [];
    while ($dao->fetch()) {
      $ids[] = (int) $dao->id;
    }
    return $ids;
  }

  /**
   * Struktur-Lint über alle aktiven Regeln; jedes Problem eine Zeile.
   *
   * @return list<string>
   */
  // @mago-expect lint:halstead
  private static function lintReferences(): array {
    $problems = [];

    // Trigger: Stammzeile vorhanden, aktiv, Klasse ladbar.
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT r.id, r.trigger_id, t.id AS tid, t.class_name, t.is_active AS tactive
         FROM civirule_rule r LEFT JOIN civirule_trigger t ON t.id = r.trigger_id
        WHERE r.is_active = 1',
    );
    /** @var \CRM_Core_DAO&object{id: string, trigger_id: ?string, tid: ?string, class_name: ?string, tactive: ?string} $dao */
    while ($dao->fetch()) {
      // Mutually exclusive diagnoses: only the first applicable one is reported.
      if (!$dao->tid) {
        $problems[] = CRM_Pulse_Util::ts('rule %1: trigger %2 missing', [1 => $dao->id, 2 => $dao->trigger_id]);
      }
      // @mago-expect lint:no-else-clause
      elseif (!(int) $dao->tactive) {
        $problems[] = CRM_Pulse_Util::ts('rule %1: trigger inactive', [1 => $dao->id]);
      }
      // @mago-expect lint:no-else-clause
      elseif (!empty($dao->class_name) && !class_exists($dao->class_name)) {
        $problems[] = CRM_Pulse_Util::ts('rule %1: trigger class %2 not loadable', [
          1 => $dao->id,
          2 => $dao->class_name,
        ]);
      }
    }

    // Bedingungen: Stammzeile + Klasse + Parameter.
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT rc.id, rc.rule_id, rc.condition_params, c.class_name
         FROM civirule_rule_condition rc
         JOIN civirule_rule r ON r.id = rc.rule_id AND r.is_active = 1
         LEFT JOIN civirule_condition c ON c.id = rc.condition_id
        WHERE rc.is_active = 1',
    );
    /** @var \CRM_Core_DAO&object{id: string, rule_id: string, condition_params: ?string, class_name: ?string} $dao */
    while ($dao->fetch()) {
      if (empty($dao->class_name)) {
        $problems[] = CRM_Pulse_Util::ts('rule_condition %1 (rule %2): condition definition missing', [
          1 => $dao->id,
          2 => $dao->rule_id,
        ]);
      }
      // No early continue: the params check below must run either way.
      // @mago-expect lint:no-else-clause
      elseif (!class_exists($dao->class_name)) {
        $problems[] = CRM_Pulse_Util::ts('rule_condition %1: class %2 not loadable', [
          1 => $dao->id,
          2 => $dao->class_name,
        ]);
      }
      if (!self::paramsDecodable($dao->condition_params)) {
        $problems[] = CRM_Pulse_Util::ts('rule_condition %1 (rule %2): params not unserializable', [
          1 => $dao->id,
          2 => $dao->rule_id,
        ]);
      }
    }

    // Actions: Stammzeile + Klasse + Parameter; Regel ohne Action läuft leer.
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT ra.id, ra.rule_id, ra.action_params, a.class_name
         FROM civirule_rule_action ra
         JOIN civirule_rule r ON r.id = ra.rule_id AND r.is_active = 1
         LEFT JOIN civirule_action a ON a.id = ra.action_id
        WHERE ra.is_active = 1',
    );
    /** @var \CRM_Core_DAO&object{id: string, rule_id: string, action_params: ?string, class_name: ?string} $dao */
    while ($dao->fetch()) {
      if (empty($dao->class_name)) {
        $problems[] = CRM_Pulse_Util::ts('rule_action %1 (rule %2): action definition missing', [
          1 => $dao->id,
          2 => $dao->rule_id,
        ]);
      }
      // No early continue: the params check below must run either way.
      // @mago-expect lint:no-else-clause
      elseif (!class_exists($dao->class_name)) {
        $problems[] = CRM_Pulse_Util::ts('rule_action %1: class %2 not loadable', [
          1 => $dao->id,
          2 => $dao->class_name,
        ]);
      }
      if (!self::paramsDecodable($dao->action_params)) {
        $problems[] = CRM_Pulse_Util::ts('rule_action %1 (rule %2): params not unserializable', [
          1 => $dao->id,
          2 => $dao->rule_id,
        ]);
      }
    }

    $dao = CRM_Core_DAO::executeQuery(
      'SELECT r.id FROM civirule_rule r
        WHERE r.is_active = 1
          AND NOT EXISTS (SELECT 1 FROM civirule_rule_action ra WHERE ra.rule_id = r.id AND ra.is_active = 1)',
    );
    /** @var \CRM_Core_DAO&object{id: string} $dao */
    while ($dao->fetch()) {
      $problems[] = CRM_Pulse_Util::ts('rule %1: active but has no active action', [1 => $dao->id]);
    }

    return $problems;
  }

  /**
   * Sind gespeicherte Params deserialisierbar? Pure — test-seam.
   * NULL/leer ist ok (Bedingungen ohne Parameter sind legitim).
   */
  public static function paramsDecodable(?string $params): bool {
    if ($params === NULL || $params === '') {
      return TRUE;
    }
    // allowed_classes=FALSE: ein Health-Check darf keine fremden DB-Inhalte
    // instanziieren; ein Objekt-Payload zählt als __PHP_Incomplete_Class weiter als lesbar.
    // @mago-expect lint:no-error-control-operator
    return @unserialize($params, ['allowed_classes' => FALSE]) !== FALSE || $params === serialize(value: FALSE);
  }

  private static function countRecentLogErrors(): int {
    $window = Civi::settings()->get('pulse_error_window');
    $windowMinutes = is_numeric($window) ? (int) $window : 60;
    if ($windowMinutes <= 0) {
      return 0;
    }
    $dir = CRM_Pulse_Util::logDir();
    $since = time() - ($windowMinutes * 60);
    $total = 0;
    $globbed = glob($dir . '/CiviCRM*.log');
    $files = $globbed === FALSE ? [] : $globbed;
    foreach ($files as $file) {
      // Log files may vanish or be unreadable between glob and stat; the
      // FALSE checks below are the contract, warnings would only spam.
      // @mago-expect lint:no-error-control-operator
      $mtime = @filemtime($file);
      if ($mtime === FALSE || $mtime < $since) {
        continue;
      }
      // @mago-expect lint:no-error-control-operator
      $size = @filesize($file);
      // @mago-expect lint:no-error-control-operator
      $fh = @fopen($file, 'r');
      if ($fh === FALSE) {
        continue;
      }
      if ($size !== FALSE && $size > self::TAIL_BYTES) {
        fseek($fh, -self::TAIL_BYTES, SEEK_END);
      }
      // mtime only gets the file into scope; the window itself is decided per
      // log entry, otherwise old markers keep the check red forever.
      $total += self::countErrorMarkers(
        CRM_Pulse_Util::logChunkSince((string) stream_get_contents($fh), $since),
      );
      fclose($fh);
    }
    return $total;
  }

  /**
   * Count CiviRules error lines in a log chunk. Pure — test-seam. Nur
   * [error]/[critical]-Zeilen, die CiviRules selbst betreffen.
   */
  public static function countErrorMarkers(string $chunk): int {
    $count = preg_match_all('/\[(?:error|critical)\][^\n]*CiviRules?\b/i', $chunk);
    return $count === FALSE ? 0 : $count;
  }

}
