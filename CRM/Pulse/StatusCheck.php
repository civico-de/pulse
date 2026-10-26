<?php

declare(strict_types = 1);

use Civi\Pulse\Vendor\Civico\Stdlib\Cast;
use Psr\Log\LogLevel;

/**
 * pulse's checks as core status messages named `pulse_<check>`. A healthy or
 * disabled check adds nothing; a check that throws reports an error.
 */
class CRM_Pulse_StatusCheck {

  /**
   * Check => [callback, severity of a finding]. A result may name its own
   * `severity`, e.g. error for a check that could not check.
   */
  private const CHECKS = [
    'database' => [[\Civi\Pulse\HealthEndpoint::class, 'checkDatabase'], LogLevel::CRITICAL],
    'cms_updates' => [['CRM_Pulse_Check_CmsUpdates', 'check'], LogLevel::WARNING],
    'fatal_errors' => [['CRM_Pulse_Check_FatalErrors', 'check'], LogLevel::ERROR],
    'jobs' => [['CRM_Pulse_Check_Jobs', 'check'], LogLevel::WARNING],
    'queues' => [['CRM_Pulse_Check_Queues', 'check'], LogLevel::WARNING],
    'payments' => [['CRM_Pulse_Check_Payments', 'check'], LogLevel::ERROR],
    'disk' => [['CRM_Pulse_Check_Disk', 'check'], LogLevel::WARNING],
    'host_status' => [['CRM_Pulse_Check_HostStatus', 'check'], LogLevel::ERROR],
    'civirules' => [['CRM_Pulse_Check_Civirules', 'check'], LogLevel::ERROR],
    'duplicates' => [['CRM_Pulse_Check_Duplicates', 'check'], LogLevel::WARNING],
  ];

  /**
   * @param array<mixed> $statusNames Names System.check asked for; empty asks for all.
   * @return list<CRM_Utils_Check_Message>
   */
  public static function messages(array $statusNames = []): array {
    $wanted = static function (string $check) use ($statusNames): bool {
      foreach ($statusNames as $name) {
        if (str_starts_with(Cast::toString($name), 'pulse_' . $check)) {
          return TRUE;
        }
      }
      return $statusNames === [];
    };

    $messages = $wanted('updates') ? self::guarded('updates', CRM_Pulse_Check_Updates::messages(...)) : [];
    foreach (self::CHECKS as $check => [$callback, $severity]) {
      if (!$wanted($check)) {
        continue;
      }
      $messages = array_merge($messages, self::guarded(
        $check,
        static fn (): array => self::fromResult($check, $callback(), $severity),
      ));
    }
    return $messages;
  }

  /**
   * One message under the check's name. $html is markup: escape what it quotes.
   */
  public static function message(string $check, string $html, string $severity): CRM_Utils_Check_Message {
    return new CRM_Utils_Check_Message('pulse_' . $check, $html, self::title($check), $severity, 'fa-heartbeat');
  }

  /**
   * @param callable(): list<CRM_Utils_Check_Message> $produce
   * @return list<CRM_Utils_Check_Message>
   */
  private static function guarded(string $check, callable $produce): array {
    try {
      return $produce();
    }
    catch (Throwable $e) {
      return [self::message(
        $check,
        htmlspecialchars(CRM_Pulse_Util::ts('Could not check: %1', [1 => get_class($e) . ': ' . $e->getMessage()])),
        LogLevel::ERROR,
      )];
    }
  }

  /**
   * @return list<CRM_Utils_Check_Message>
   */
  private static function fromResult(string $check, mixed $result, string $severity): array {
    if (!is_array($result) || ($result['status'] ?? NULL) !== 'fail') {
      return [];
    }
    $level = $result['severity'] ?? NULL;
    return [self::message(
      $check,
      htmlspecialchars(Cast::toString($result['detail'] ?? '')),
      is_string($level) ? $level : $severity,
    )];
  }

  private static function title(string $check): string {
    return match ($check) {
      'database' => CRM_Pulse_Util::ts('Database query failed'),
      'updates' => CRM_Pulse_Util::ts('Extension updates available'),
      'updates_feed' => CRM_Pulse_Util::ts('Extension feed not readable'),
      'cms_updates' => CRM_Pulse_Util::ts('CMS updates pending'),
      'fatal_errors' => CRM_Pulse_Util::ts('Recent PHP fatal errors'),
      'jobs' => CRM_Pulse_Util::ts('Scheduled jobs late'),
      'queues' => CRM_Pulse_Util::ts('Queue items stuck'),
      'payments' => CRM_Pulse_Util::ts('Payment webhooks stuck or failed'),
      'disk' => CRM_Pulse_Util::ts('Low disk space'),
      'host_status' => CRM_Pulse_Util::ts('Host jobs failing'),
      'civirules' => CRM_Pulse_Util::ts('CiviRules problems'),
      'duplicates' => CRM_Pulse_Util::ts('Spike of possible duplicate contacts'),
      default => throw new InvalidArgumentException("unknown pulse check '{$check}'"),
    };
  }

}
