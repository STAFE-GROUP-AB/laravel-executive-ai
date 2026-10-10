<?php

declare(strict_types=1);

/*
 * Labels for the Executive Brief mail (S1-03). Presentation only: the brief's
 * own prose comes from the frozen document and is never translated here.
 */
return [
    'brand' => 'Executive Brief',
    'version' => 'versione :version',
    'generated_at' => 'Generato',
    'period' => 'Periodo',
    'items' => 'Punti',
    'nothing_new_title' => 'Niente di nuovo',
    'nothing_new_body' => 'Nessun nuovo evento in questo periodo. Il prossimo brief arriverà come programmato.',
    'due' => 'Scadenza',
    'sources' => 'Fonte|Fonti',
    'uncertainty' => 'Incerto',
    'confidence' => 'confidenza :percent %',
    'approval_required' => 'Richiede la tua approvazione',
    'open_in_app' => 'Apri il brief completo',
    'privacy_mode' => 'Modalità privacy',
    'brief_id' => 'Brief',
    'footer_note' => 'Inviato da :app da un brief congelato. Ogni punto mostra la sua fonte o un’esplicita indicazione di incertezza.',
    'kinds' => [
        'action' => 'Azione',
        'followup' => 'Follow-up',
        'risk' => 'Rischio',
        'info' => 'Info',
        'commitment' => 'Impegno',
    ],
    'priorities' => [
        'critical' => 'Critica',
        'high' => 'Alta',
        'normal' => 'Normale',
        'low' => 'Bassa',
    ],
    'source_types' => [
        'email' => 'Email',
        'crm' => 'CRM',
        'github' => 'GitHub',
        'calendar' => 'Calendario',
        'manual' => 'Manuale',
        'ai_inference' => 'Inferenza AI',
    ],
    'privacy_modes' => [
        'LOCAL_ONLY' => 'Solo locale',
        'EU_RESTRICTED' => 'Limitato all’UE',
        'CUSTOM' => 'Personalizzata',
    ],
];
