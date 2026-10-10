<?php

declare(strict_types=1);

/*
 * Labels for the Executive Brief mail (S1-03). Presentation only: the brief's
 * own prose comes from the frozen document and is never translated here.
 */
return [
    'brand' => 'Executive Brief',
    'version' => 'Version :version',
    'generated_at' => 'Erstellt',
    'period' => 'Zeitraum',
    'items' => 'Punkte',
    'nothing_new_title' => 'Nichts Neues',
    'nothing_new_body' => 'Keine neuen Ereignisse in diesem Zeitraum. Das nächste Brief kommt planmäßig.',
    'due' => 'Fällig',
    'sources' => 'Quelle|Quellen',
    'uncertainty' => 'Unsicher',
    'confidence' => 'Konfidenz :percent %',
    'approval_required' => 'Erfordert Ihre Freigabe',
    'open_in_app' => 'Vollständiges Brief öffnen',
    'privacy_mode' => 'Datenschutzmodus',
    'brief_id' => 'Brief',
    'footer_note' => 'Gesendet von :app aus einem eingefrorenen Brief. Jeder Punkt zeigt seine Quelle oder eine ausdrückliche Unsicherheitsmarkierung.',
    'kinds' => [
        'action' => 'Aufgabe',
        'followup' => 'Nachfassen',
        'risk' => 'Risiko',
        'info' => 'Info',
        'commitment' => 'Zusage',
    ],
    'priorities' => [
        'critical' => 'Kritisch',
        'high' => 'Hoch',
        'normal' => 'Normal',
        'low' => 'Niedrig',
    ],
    'source_types' => [
        'email' => 'E-Mail',
        'crm' => 'CRM',
        'github' => 'GitHub',
        'calendar' => 'Kalender',
        'manual' => 'Manuell',
        'ai_inference' => 'KI-Schlussfolgerung',
    ],
    'privacy_modes' => [
        'LOCAL_ONLY' => 'Nur lokal',
        'EU_RESTRICTED' => 'EU-beschränkt',
        'CUSTOM' => 'Benutzerdefiniert',
    ],
];
