<?php

declare(strict_types = 1);

/**
 * Upgrade steps for existing installs. Fresh installs start at the newest
 * revision and run none of them.
 */
class CRM_Pulse_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Retired setting name => its replacement.
   */
  public const RENAMED_HOST_STATUS_SETTINGS = [
    'pulse_civenv_status_file' => 'pulse_host_status_file',
    'pulse_civenv_environment' => 'pulse_host_status_environment',
    'pulse_civenv_max_age' => 'pulse_host_status_max_age',
  ];

  /**
   * The host status check's settings were renamed: carry each old value over,
   * unless the new name already has one, then drop the old rows.
   */
  public function upgrade_1000(): bool {
    $this->ctx->log->info('pulse: moving the host status settings to their new names');
    self::renameHostStatusSettings();
    return TRUE;
  }

  /**
   * SQL, because the settings API refuses names without metadata.
   */
  public static function renameHostStatusSettings(): void {
    $renamed = self::RENAMED_HOST_STATUS_SETTINGS;
    foreach ($renamed as $old => $new) {
      $rows = CRM_Utils_SQL_Select::from('civicrm_setting')
        ->select(['domain_id', 'value'])
        ->where('contact_id IS NULL')
        ->where('name = @name', ['name' => $old])
        ->execute();
      /** @var \CRM_Core_DAO&object{domain_id: ?string, value: ?string} $rows */
      while ($rows->fetch()) {
        $value = CRM_Utils_String::unserialize($rows->value);
        $settings = Civi::settings((int) $rows->domain_id);
        if ($value !== NULL && $value !== FALSE && !$settings->hasExplicit($new)) {
          $settings->set($new, $value);
        }
      }
    }
    CRM_Utils_SQL_Delete::from('civicrm_setting')
      ->where('name IN (@names)', ['names' => array_keys($renamed)])
      ->execute();
  }

}
