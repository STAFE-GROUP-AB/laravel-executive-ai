<?php

declare(strict_types=1);

/*
 * Labels for the Executive Brief mail (S1-03). Presentation only: the brief's
 * own prose comes from the frozen document and is never translated here.
 */
return [
    'brand' => 'Executive Brief',
    'version' => 'version :version',
    'generated_at' => 'Genererad',
    'period' => 'Period',
    'items' => 'Punkter',
    'nothing_new_title' => 'Inget nytt',
    'nothing_new_body' => 'Inga nya händelser under perioden. Nästa brief kommer enligt schema.',
    'due' => 'Deadline',
    'sources' => 'Källa|Källor',
    'uncertainty' => 'Osäkert',
    'confidence' => 'konfidens :percent %',
    'approval_required' => 'Kräver ditt godkännande',
    'open_in_app' => 'Öppna hela briefen',
    'privacy_mode' => 'Sekretessläge',
    'brief_id' => 'Brief',
    'footer_note' => 'Skickat av :app från en fryst brief. Varje punkt visar sin källa eller en uttrycklig osäkerhetsmarkering.',
    'kinds' => [
        'action' => 'Åtgärd',
        'followup' => 'Uppföljning',
        'risk' => 'Risk',
        'info' => 'Info',
        'commitment' => 'Åtagande',
    ],
    'priorities' => [
        'critical' => 'Kritisk',
        'high' => 'Hög',
        'normal' => 'Normal',
        'low' => 'Låg',
    ],
    'source_types' => [
        'email' => 'E-post',
        'crm' => 'CRM',
        'github' => 'GitHub',
        'calendar' => 'Kalender',
        'manual' => 'Manuell',
        'ai_inference' => 'AI-slutsats',
    ],
    'privacy_modes' => [
        'LOCAL_ONLY' => 'Endast lokalt',
        'EU_RESTRICTED' => 'EU-begränsat',
        'CUSTOM' => 'Anpassat',
    ],
];
