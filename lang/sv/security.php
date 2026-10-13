<?php

declare(strict_types=1);

/*
 * Svensk text för nycklar, enheter och omautentisering (S2-05,
 * docs/architecture/auth.md §5, §7). Inget här får antyda att det finns ett
 * lösenord: de två inloggningssätten är en passkey och engångskoden via
 * e-post, och koden är alltid reservvägen.
 */
return [
    'settings' => [
        'title' => 'Inloggning och enheter',
        'nav' => 'Inloggning och enheter',
        'lead' => 'Lägg till en passkey för snabbare inloggning och se varje enhet som är inloggad. Engångskoden via e-post fungerar alltid, så en borttappad enhet låser dig aldrig ute.',
    ],

    'passkeys' => [
        'heading' => 'Passkeys',
        'lead' => 'En passkey loggar in dig med Touch ID, Face ID eller enhetens upplåsning i stället för att du öppnar mejlet. Den lagras på enheten, aldrig på den här Macen, och den ersätter inte engångskoden utan kompletterar den.',
        'sign_in' => 'Logga in med passkey',
        'sign_in_hint' => 'Enheten frågar efter Touch ID, Face ID eller din upplåsning. Vill du använda koden i stället? Den finns ovan.',
        'add' => 'Lägg till en passkey',
        'adding' => 'Väntar på din enhet…',
        'name_label' => 'Namn på den här passkeyn (frivilligt)',
        'default_name' => 'Passkey tillagd :date',
        'none' => 'Ingen passkey är registrerad ännu. Inloggning sker med engångskoden via e-post.',
        'registered' => 'Passkeyn är registrerad. Du kan använda den nästa gång du loggar in.',
        'removed' => 'Passkeyn ”:name” togs bort.',
        'remove' => 'Ta bort',
        'remove_confirm' => 'Ta bort passkeyn ”:name”? Du kan alltid logga in med engångskoden via e-post.',
        'bound_to' => 'Fungerar på :rp',
        'synced' => 'synkad av din lösenordshanterare',
        'last_used' => 'Senast använd :when',
        'never_used' => 'Inte använd ännu',
        'fallback_note' => 'En passkey fungerar bara på den adress den skapades för, så lägg till en per adress du använder. Engångskoden via e-post fungerar överallt och är återställningsvägen.',
        'status' => [
            'available' => 'Passkeys fungerar på den här adressen (:rp).',
            'insecure' => 'Passkeys kräver en HTTPS-adress eller localhost; den här sidan ligger på :origin.',
            'ip_literal' => 'Passkeys kräver ett värdnamn i stället för en IP-adress; den här sidan ligger på :origin. Appen öppnas på localhost, där passkeys fungerar — öppna den från menyraden, eller använd din privata fjärradress.',
        ],
        'errors' => [
            'unavailable' => 'Passkeys är inte tillgängliga på den här adressen. Använd engångskoden via e-post.',
            'not_verified' => 'Den passkeyn godtogs inte. Använd engångskoden via e-post i stället.',
            'no_pending_ceremony' => 'Den passkey-förfrågan är inte öppen längre. Börja om.',
            'ceremony_expired' => 'Den passkey-förfrågan tog för lång tid. Börja om.',
            'origin_mismatch' => 'Den passkey-förfrågan startades på en annan adress. Börja om här.',
            'malformed_credential' => 'Webbläsaren skickade något vi inte kunde läsa. Börja om.',
            'verification_failed' => 'Passkeyn kunde inte verifieras. Börja om, eller använd engångskoden via e-post.',
            'already_registered' => 'Den passkeyn är redan registrerad.',
            'browser' => 'Webbläsaren avbröt passkey-förfrågan (:reason). Börja om, eller använd engångskoden via e-post.',
        ],
    ],

    'devices' => [
        'heading' => 'Inloggade enheter',
        'lead' => 'Varje webbläsare som är inloggad just nu. Att logga ut en enhet avslutar dess session på servern, så den är utloggad nästa gång den hämtar en sida — oavsett vad den har kvar i kakburken.',
        'this_device' => 'Den här enheten',
        'revoke' => 'Logga ut',
        'revoke_others' => 'Logga ut alla andra enheter',
        'revoke_others_confirm' => 'Logga ut alla enheter utom den här?',
        'revoked_one' => 'Enheten är utloggad.',
        'revoked_others' => 'Loggade ut :count andra enhet(er).',
        'already_gone' => 'Enheten var redan utloggad.',
        'cannot_revoke_current' => 'Det är den enhet du använder. Använd ”Logga ut” i stället.',
        'signed_in' => 'Inloggad :when',
        'last_active' => 'senast aktiv :when',
        'unknown_device' => 'Okänd webbläsare',
        'mac_app' => 'Mac-appen',
        'methods' => [
            'otp' => 'Inloggad med engångskod via e-post',
            'passkey' => 'Inloggad med passkey',
            'owner_creation' => 'Inloggad när kontot skapades',
        ],
    ],

    'reauth' => [
        'heading' => 'Bekräfta att det är du',
        'lead' => 'Det här steget kräver nytt bevis på att du sitter vid tangentbordet innan :action.',
        'satisfied' => 'Bekräftat. Du kan slutföra åtgärden nu.',
        'use_passkey' => 'Bekräfta med passkey',
        'email_code' => 'Mejla mig en kod',
        'sending' => 'Skickar…',
        'code_label' => 'Engångskod',
        'code_sent' => 'En engångskod är på väg. Den går ut om :minutes minuter.',
        'code_reused' => 'En kod skickades nyss; använd den. Den är fortfarande giltig.',
        'verify' => 'Bekräfta',
        'back' => 'Välj ett annat sätt',
        'actions' => [
            'approvals.approve' => 'en utgående åtgärd godkänns',
            'remote-access.enable' => 'privat fjärråtkomst slås på',
            'keys.rotate' => 'en nyckel roteras',
            'privacy.mode_change' => 'sekretessläget utvidgas',
        ],
    ],
];
