<?php

declare(strict_types = 1);

namespace Civi\Pulse\Demo;

use Civi\Api4\Contact;
use Civi\Api4\Email;

/**
 * Seeds one deterministic maintenance-health failure using ordinary contacts.
 */
final class ScenarioSeeder {

  public const EMAIL = 'doppelkontakt@dataops.demo.invalid';

  private const MARKERS = [
    'civico-demo-dataops-duplicate-a' => ['Dora', 'Doppelt'],
    'civico-demo-dataops-duplicate-b' => ['Daniel', 'Doppelt'],
  ];

  /**
   * @return array{contact_ids: list<int>, check: string, expected_severity: string}
   */
  public function seed(): array {
    $contactIds = [];
    foreach (self::MARKERS as $marker => [$first, $last]) {
      $contactId = $this->upsertContact($marker, $first, $last);
      $this->upsertEmail($contactId);
      $contactIds[] = $contactId;
    }

    \Civi::settings()->set('pulse_duplicates_threshold', 1);
    // Keep the fixture demonstrable in a long-lived local demo as well as a
    // nightly-reset showroom. The contacts are synthetic and marker-scoped;
    // no production data should ever be seeded through this script.
    \Civi::settings()->set('pulse_duplicates_window_days', 3_650);

    return [
      'contact_ids' => $contactIds,
      'check' => 'pulse_duplicates',
      'expected_severity' => \Psr\Log\LogLevel::WARNING,
    ];
  }

  private function upsertContact(string $marker, string $first, string $last): int {
    $existing = Contact::get(checkPermissions: FALSE)
      ->addSelect('id')
      ->addWhere('external_identifier', '=', $marker)
      ->setLimit(1)
      ->execute()
      ->first();
    if (is_array($existing) && is_numeric($existing['id'] ?? NULL)) {
      return (int) $existing['id'];
    }

    $created = Contact::create(checkPermissions: FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', $first)
      ->addValue('last_name', $last)
      ->addValue('external_identifier', $marker)
      ->addValue('source', 'Civico DataOps Demo')
      ->execute()
      ->first();
    if (!is_array($created) || !is_numeric($created['id'] ?? NULL)) {
      throw new \CRM_Core_Exception('pulse demo: could not create duplicate contact');
    }
    return (int) $created['id'];
  }

  private function upsertEmail(int $contactId): void {
    $existing = Email::get(checkPermissions: FALSE)
      ->addSelect('id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('email', '=', self::EMAIL)
      ->setLimit(1)
      ->execute()
      ->first();
    if (is_array($existing)) {
      return;
    }

    Email::create(checkPermissions: FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', self::EMAIL)
      ->addValue('is_primary', value: TRUE)
      ->addValue('location_type_id:name', 'Home')
      ->execute();
  }

}
