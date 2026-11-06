<?php

declare(strict_types=1);

/*
 * Svensk kopia för de fyra CRM-skärmarna i §1.2 (S2-09).
 *
 * Samma två regler som i den engelska filen: ingen sträng här säger att CRM
 * behöver AI, och ingen sträng påstår att AI eller schemaläggaren kör någon
 * annanstans än på Macen (§1.2, §4.2).
 */

return [
    'nav' => 'CRM',

    'customers' => [
        'title' => 'Kunder',
        'lead' => 'Organisationerna du arbetar med, deras juridiska identitet och vem som äger relationen.',
        'empty' => 'Inga kunder än',
        'empty_body' => 'Lägg till den första kunden, eller importera en arbetsyta från ett JSON-paket.',
        'empty_search' => 'Inget matchar sökningen',
        'empty_search_body' => 'Alla ord måste matcha. Testa ett ord, eller lägg till * sist för att matcha en början.',
        'new' => 'Ny kund',
        'fields' => [
            'display_name' => 'Namn',
            'legal_name' => 'Registrerat namn',
            'country' => 'Land',
            'registration_number' => 'Organisationsnummer',
            'website' => 'Webbplats',
            'email_domains' => 'E-postdomäner',
            'email_domains_hint' => 'Kommaseparerat. En ledtråd för matchning, aldrig en nyckel: två kunder kan dela domän.',
            'tags' => 'Etiketter',
            'tags_hint' => 'Kommaseparerat.',
            'owner' => 'Relationsägare',
        ],
    ],

    'contacts' => [
        'title' => 'Kontakter',
        'lead' => 'Personerna på organisationerna, med alla adresser de skriver från.',
        'empty' => 'Inga kontakter än',
        'empty_body' => 'Lägg till den första personen, eller importera en arbetsyta från ett JSON-paket.',
        'empty_search' => 'Inget matchar sökningen',
        'empty_search_body' => 'Namn och anteckningar söks; e-postadresser gör det inte. Testa ett namn.',
        'new' => 'Ny kontakt',
        'addresses' => 'E-postadresser',
        'addresses_hint' => 'En person kan skriva från flera. Den primära visas först.',
        'add_address' => 'Lägg till adress',
        'remove_address' => 'Ta bort adressen',
        'make_primary' => 'Gör primär',
        'primary' => 'Primär',
        'customers' => 'Tillhör',
        'customers_hint' => 'En person kan tillhöra mer än en kund.',
        'no_addresses' => 'Ingen adress registrerad',
        'fields' => [
            'given_name' => 'Förnamn',
            'family_name' => 'Efternamn',
            'display_name' => 'Visas som',
            'title' => 'Titel',
            'language' => 'Språk',
            'address' => 'E-postadress',
            'label' => 'Typ',
        ],
        'labels' => [
            'work' => 'Arbete',
            'personal' => 'Privat',
            'other' => 'Annat',
        ],
        'errors' => [
            'duplicate_address' => 'Adressen finns redan i listan.',
            'address_taken' => 'En annan kontakt i arbetsytan använder redan adressen.',
        ],
    ],

    'projects' => [
        'title' => 'Projekt',
        'lead' => 'Vad som pågår för varje kund, med status och deadline.',
        'empty' => 'Inga projekt än',
        'empty_body' => 'Ett projekt hör till en kund, så lägg till en kund först om det inte finns någon.',
        'empty_search' => 'Inget matchar sökningen',
        'empty_search_body' => 'Projekt söks via sin kund. Testa kundens namn.',
        'new' => 'Nytt projekt',
        'fields' => [
            'name' => 'Projekt',
            'starts_on' => 'Startar',
            'due_on' => 'Deadline',
        ],
    ],

    'tasks' => [
        'title' => 'Uppgifter',
        'lead' => 'Vad du är skyldig andra, inklusive uppföljningar du lovat.',
        'empty' => 'Inga uppgifter än',
        'empty_body' => 'Lägg till vad du lovat någon. En uppgift behöver ingen deadline om ingen är överenskommen.',
        'empty_search' => 'Inget matchar sökningen',
        'empty_search_body' => 'Uppgifter söks via sin kund. Testa kundens namn.',
        'new' => 'Ny uppgift',
        'no_due_date' => 'Ingen deadline',
        'fields' => [
            'title' => 'Uppgift',
            'priority' => 'Prioritet',
            'due_at' => 'Senast',
        ],
    ],

    'fields' => [
        'status' => 'Status',
        'notes' => 'Anteckningar',
        'description' => 'Beskrivning',
        'customer' => 'Kund',
        'project' => 'Projekt',
        'contact' => 'Kontakt',
        'updated' => 'Uppdaterad',
        'none' => 'Ingen',
    ],

    'status' => [
        'customer' => [
            'prospect' => 'Prospekt',
            'active' => 'Aktiv',
            'dormant' => 'Vilande',
            'churned' => 'Avslutad',
        ],
        'project' => [
            'planned' => 'Planerat',
            'active' => 'Aktivt',
            'on_hold' => 'Pausat',
            'done' => 'Klart',
            'cancelled' => 'Avbrutet',
        ],
        'task' => [
            'todo' => 'Att göra',
            'doing' => 'Pågår',
            'blocked' => 'Blockerad',
            'done' => 'Klar',
        ],
    ],

    'search' => [
        'label' => 'Sök',
        'placeholder' => 'Sök i arbetsytan',
        'hint' => 'Alla ord måste matcha. Lägg till * sist i ett ord för att matcha en början.',
    ],

    'filters' => [
        'any_status' => 'Alla statusar',
        'any_customer' => 'Alla kunder',
        'show_deleted' => 'Visa borttagna',
        'clear' => 'Rensa filter',
    ],

    'actions' => [
        'open' => 'Öppna',
        'edit' => 'Redigera',
        'save' => 'Spara',
        'saving' => 'Sparar…',
        'cancel' => 'Avbryt',
        'close' => 'Stäng',
        'delete' => 'Ta bort',
        'approve_delete' => 'Godkänn borttagning',
        'withdraw_delete' => 'Dra tillbaka',
        'restore' => 'Återställ',
    ],

    'deletion' => [
        'proposed' => 'Borttagning föreslagen. Posten tas inte bort förrän borttagningen godkänts.',
        'done' => 'Borttagen. Den kan återställas till dess gallringsregeln tar bort den.',
        'withdrawn' => 'Den föreslagna borttagningen drogs tillbaka. Inget togs bort.',
        'awaiting' => 'Väntar på godkännande',
        'awaiting_body' => 'En borttagning är föreslagen för posten. Inget tas bort förrän den godkänns — det är det som gör vem som tog bort vad granskningsbart efteråt.',
        'expires' => 'Förslaget förfaller :when.',
        'deleted_badge' => 'Borttagen',
    ],

    'saved' => [
        'created' => 'Skapad.',
        'updated' => 'Sparad.',
    ],

    'restored' => 'Återställd.',

    'pagination' => [
        'previous' => 'Föregående',
        'next' => 'Nästa',
        'summary' => 'Visar :from–:to av :total',
    ],

    'no_workspace' => [
        'title' => 'Ingen arbetsyta än',
        'body' => 'Kontot är inloggat men hör inte till någon arbetsyta, så det finns inget att visa. Inget är fel med posterna; det finns inga att läsa.',
    ],
];
