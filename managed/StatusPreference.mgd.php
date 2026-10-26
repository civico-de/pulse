<?php

declare(strict_types = 1);

// Core's checkExtensionsUpdates is hidden exactly while pulse_updates replaces it.
// Its severity is always warning, so ignore_severity warning keeps it silent.
if (!CRM_Pulse_Check_Updates::isActive()) {
  return [];
}

return [
  [
    'name' => 'StatusPreference_checkExtensionsUpdates',
    'entity' => 'StatusPreference',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'match' => ['domain_id', 'name'],
      'values' => [
        'domain_id' => 'current_domain',
        'name' => 'checkExtensionsUpdates',
        'ignore_severity:name' => 'warning',
        'hush_until' => NULL,
        'is_active' => TRUE,
      ],
    ],
  ],
];
