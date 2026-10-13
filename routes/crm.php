<?php

declare(strict_types=1);

use App\Livewire\Crm\Contacts;
use App\Livewire\Crm\Customers;
use App\Livewire\Crm\Projects;
use App\Livewire\Crm\Tasks;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Local CRM (S2-09, blueprint §1.2, §3.2, §13.1 "Local CRM CRUD")
|--------------------------------------------------------------------------
|
| Required from routes/web.php after routes/pwa.php, so everything here runs
| inside the `web` group (session, CSRF, the canonical-host redirect and the
| A16 security headers) behind `auth`.
|
| §1.2 fixes exactly four paths, and there are exactly four routes: each is
| one full-page Livewire component that lists, creates, reads, updates and
| deletes its record type. The form is a panel on the same page rather than a
| route of its own — a create and an edit are the same form over the same
| fields, and a second URL for them would be a second place for the ability
| check and the empty state to drift.
|
| Three things these routes deliberately do not have:
|
| - **No `{record}` route.** A detail panel opens in place, so no ULID appears
|   in a URL here. The IDOR surface is then exactly the Livewire action, which
|   `CrmRepository::find()` scopes and the CRM policies decide.
| - **No destroy route.** §11 makes CRM deletion approval-driven: the screens
|   freeze an `action_proposals` row and soft-delete only once an approval is
|   recorded (`App\CRM\Deletions\CrmDeletions`).
| - **No cache.** `/crm` is on the service worker's deny list already
|   (pwa.md §4), so every page here is network-only like /home and /briefs.
|
| The page ability is `viewCrm` for all four, and it answers "is this a
| signed-in user of this install" — not "is this a member of an
| organization". A session without a membership gets a 200 and the empty
| states (pwa.md §2); the tenant decision belongs to the row.
|
*/

Route::middleware('auth')->prefix('crm')->name('crm.')->group(function (): void {
    Route::get('/customers', Customers::class)->name('customers');
    Route::get('/contacts', Contacts::class)->name('contacts');
    Route::get('/projects', Projects::class)->name('projects');
    Route::get('/tasks', Tasks::class)->name('tasks');
});
