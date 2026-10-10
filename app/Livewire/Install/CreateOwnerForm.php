<?php

declare(strict_types=1);

namespace App\Livewire\Install;

use App\Security\Audit\AuditLog;
use App\Security\Authorization\CreateOwner;
use App\Security\Authorization\OwnerAlreadyExistsException;
use App\Security\Authorization\OwnerDetails;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /install/owner — §12.3 screen 4 ("Create account") rendered in the PWA for
 * the case where the Mac installer hands the browser over before an owner
 * exists. Reachable only while no owner exists (EnsureNoOwnerExists). The
 * person at this loopback page is the installer, so a successful creation
 * signs them in directly; later sign-ins use the emailed code.
 */
#[Layout('layouts.auth')]
#[Title('Create the owner account')]
final class CreateOwnerForm extends Component
{
    public string $name = '';

    public string $email = '';

    public string $organization = '';

    public string $timezone = 'UTC';

    public string $language = 'en';

    public function mount(): void
    {
        $this->timezone = (string) config('app.timezone', 'UTC');
    }

    public function create(CreateOwner $createOwner, AuditLog $audit): void
    {
        $validated = $this->validate(OwnerDetails::rules());

        try {
            $created = $createOwner->create(OwnerDetails::fromInput($validated), 'job:CreateOwnerForm');
        } catch (OwnerAlreadyExistsException) {
            $this->redirectRoute('login');

            return;
        }

        Auth::guard('web')->login($created->user);
        session()->regenerate();

        $audit->record('auth.logged_in', "user:{$created->user->id}", AuditLog::OUTCOME_OK, 'user', $created->user->id, [
            'method' => 'owner_creation',
            'ip_address' => request()->ip(),
        ], $created->organization->id);

        $this->redirectRoute('home');
    }

    public function render(): View
    {
        return view('livewire.install.create-owner-form', [
            'timezones' => DateTimeZone::listIdentifiers(),
            'languages' => ['en' => 'English', 'sv' => 'Svenska', 'de' => 'Deutsch', 'it' => 'Italiano'],
        ]);
    }
}
