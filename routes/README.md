web.php, api.php and console.php (§2.1). `briefs.php` (required from web.php) holds the `/briefs/…`
routes: the mail preview surfaces from S1-03 behind `auth` + `BriefPolicy`; S1-04 adds the PWA pages.
`crm.php` holds the four §1.2 CRM screens (S2-09, docs/architecture/crm.md): one full-page Livewire
component each, behind `auth` + the `viewCrm` ability, with the record decisions on the CRM policies.
