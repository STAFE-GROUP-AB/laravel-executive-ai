Blade views: layouts, PWA pages, own TALL admin, HTML mail templates (§1.2).

`crm/` holds the four §1.2 CRM screens (S2-09): one entry view per record type, the shell they share
in `crm/partials/screen.blade.php`, and the per-record `rows-*` and `form-*` partials. They are
`@include`d rather than made Blade components so the Livewire component's own properties
(`$formOpen`, `$search`, `$notice`) are in scope with no prop list to keep in step across four
screens. `components/crm/field.blade.php` is the one labelled form control: the `for`/`id` pair, the
`aria-describedby`, `aria-invalid` and the 44 px minimum live there once. See
docs/architecture/crm.md §9.
