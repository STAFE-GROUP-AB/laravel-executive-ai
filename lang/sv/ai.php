<?php

declare(strict_types=1);

/*
 * Svensk AI-statustext för PWA:n (S1-11). "AI redo" finns på exakt en nyckel,
 * state.ready; ingen annan text får innehålla den frasen. Inget här får påstå att
 * AI körs någon annanstans än på Macen (§1.2).
 */
return [
    'state' => [
        'ready' => 'AI redo',
        'error' => 'AI-fel',
        'setup_required' => 'AI-konfiguration krävs',
    ],
    'badge_label' => 'AI-status: :label',
    'select' => 'Välj AI',
    'reconfigure' => 'Konfigurera om AI',
    'description' => [
        'error' => 'Den senaste AI-körningen misslyckades. Konfigurera om AI på din Mac; ditt CRM, tidigare briefer och admin fungerar under tiden.',
        'not_configured' => 'Ingen AI-leverantör är vald ännu. Ditt CRM, tidigare briefer och admin är tillgängliga nu. AI och schemaläggaren körs på din Mac — välj en leverantör där för att få din första riktiga brief.',
        'configured_untested' => 'En leverantör är vald, men ingen förfrågan har lyckats ännu, så AI är inte redo. Slutför konfigurationen på din Mac; allt annat fortsätter att fungera.',
    ],
    'settings' => [
        'title' => 'Välj AI',
        'lead' => 'Välj vad assistenten tänker med. Inget är förvalt, och inget här krävs för att använda CRM eller läsa briefer.',
        'current_heading' => 'Aktuellt val',
        'current' => ':provider är vald. Ingen förfrågan har lyckats ännu, så AI är inte redo.',
        'current_ready' => ':provider är vald och har slutfört en förfrågan.',
        'none_selected' => 'Ingen leverantör är vald. Appen fungerar utan AI; nya briefer börjar när en leverantör har slutfört en förfrågan.',
        'options_heading' => 'Alternativ som den här versionen stöder',
        'skip_title' => 'Fortsätt utan AI',
        'skip_detail' => 'Behåll CRM, tidigare briefer och admin. Konfigurationsbannern stannar tills en leverantör har slutfört en förfrågan.',
        'kinds' => [
            'local' => 'Körs på din Mac. Inget skickas till någon leverantör.',
            'remote' => 'Ditt eget konto och din egen API-nyckel. Nyckeln lagras i macOS nyckelring av bakgrundstjänsten, aldrig i en fil.',
        ],
        'none_available' => 'Ingen AI-adapter är inkopplad i den här versionen ännu, så ingen leverantör kan väljas. Listan fylls på när adaptrar landar; allt annat fortsätter att fungera.',
        'where' => 'Valet görs på din Mac: öppna menyradspanelen och välj Inställningar. Den här sidan visar bara vad kärnan stöder och förväljer aldrig något.',
    ],
    'providers' => [
        'ollama' => 'Ollama',
        'lmstudio' => 'LM Studio',
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'custom' => 'Annan OpenAI-kompatibel endpoint',
    ],
];
