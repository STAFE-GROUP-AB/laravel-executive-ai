Renders one frozen brief to Blade HTML mail and plaintext; semantic parity with the menu bar and the PWA is defined by the parity payload (§1.3, §16.3).

| Class | Role |
|---|---|
| `BriefMailRenderer` | Document → `RenderedBrief` (HTML + text + parity payload). Pure: no mailer, no transport, no recipient. Declares `SUPPORTED_SCHEMA_VERSION`. |
| `BriefParityPayload` | The semantic projection every surface exports (`docs/architecture/brief-parity.md`). |
| `BriefViewModel` | Payload → pre-formatted view data; the Blade views see nothing else. |
| `BriefLocale` | BCP-47 → sv/en/it/de, Carbon date formatting in the document's offset. |
| `BriefMailStyles` | The complete inline-style vocabulary: `MailTokens::styles()` plus layout roles composed from `MailTokens` constants only. |
| `PlaintextBriefRenderer` | `mail.brief-text` → normalised text part (LF, 76-column soft wrap, single trailing newline). |
| `BriefMailable` | The Mailable Sprint 2 will send: `htmlString` + text part from `RenderedBrief`, subject, deterministic `Message-ID`, no `List-Unsubscribe`. Not sent anywhere in Sprint 1. |
| `BriefMessageId` | `<idempotency_key.channel.recipient@domain>` (brief schema §2, data-model §2). |
| `RenderedBrief` | Value object with both parts, the payload and `parityJson()`. |

Views: `resources/views/mail/brief.blade.php`, `resources/views/mail/brief-text.blade.php`.
Labels: `lang/{sv,en,it,de}/brief.php`. Preview: `GET /briefs/{brief}/preview/{mail,text}` (auth +
`BriefPolicy`), `php artisan lea:brief:preview`. Tests: `tests/Feature/Briefs/Mail/`.
