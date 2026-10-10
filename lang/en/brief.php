<?php

declare(strict_types=1);

/*
 * Labels for the Executive Brief mail (S1-03). Presentation only: the brief's
 * own prose comes from the frozen document and is never translated here.
 */
return [
    'brand' => 'Executive Brief',
    'version' => 'version :version',
    'generated_at' => 'Generated',
    'period' => 'Period',
    'items' => 'Items',
    'nothing_new_title' => 'Nothing new',
    'nothing_new_body' => 'No new events in this period. The next brief arrives on schedule.',
    'due' => 'Due',
    'sources' => 'Source|Sources',
    'uncertainty' => 'Uncertain',
    'confidence' => 'confidence :percent%',
    'approval_required' => 'Needs your approval',
    'open_in_app' => 'Open the full brief',
    'privacy_mode' => 'Privacy mode',
    'brief_id' => 'Brief',
    'footer_note' => 'Sent by :app from a frozen brief. Every item shows its source or an explicit uncertainty marker.',
    'kinds' => [
        'action' => 'Action',
        'followup' => 'Follow-up',
        'risk' => 'Risk',
        'info' => 'Info',
        'commitment' => 'Commitment',
    ],
    'priorities' => [
        'critical' => 'Critical',
        'high' => 'High',
        'normal' => 'Normal',
        'low' => 'Low',
    ],
    'source_types' => [
        'email' => 'Email',
        'crm' => 'CRM',
        'github' => 'GitHub',
        'calendar' => 'Calendar',
        'manual' => 'Manual',
        'ai_inference' => 'AI inference',
    ],
    'privacy_modes' => [
        'LOCAL_ONLY' => 'Local only',
        'EU_RESTRICTED' => 'EU restricted',
        'CUSTOM' => 'Custom',
    ],
];
