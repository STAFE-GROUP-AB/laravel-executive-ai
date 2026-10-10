<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Security\Authorization\CreateOwner;
use App\Security\Authorization\OwnerAlreadyExistsException;
use App\Security\Authorization\OwnerDetails;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * `lea:owner:create` is how the installer's CREATE_OWNER screen (§12.3
 * screen 4) creates the owner: the Mac app collects the fields and calls
 * this through the Supervisor, so the user never sees a Terminal. Exit codes
 * are part of the contract with S1-05: 0 created, 2 invalid input, 3 an
 * owner already exists (never silently a second owner, T-72). `--json`
 * prints one machine-readable line; nothing printed is ever a credential.
 */
class OwnerCreateCommand extends Command
{
    public const int OWNER_EXISTS = 3;

    protected $signature = 'lea:owner:create
        {--name= : The owner\'s name}
        {--email= : The owner\'s email address; one-time sign-in codes go here}
        {--organization= : Organization (workspace) name}
        {--timezone=UTC : IANA time zone for the organization}
        {--language=en : BCP-47 language tag for the organization}
        {--json : Print the result as JSON}';

    protected $description = 'Create the single owner account, its organization and membership (§12.1 CREATE_OWNER)';

    public function handle(CreateOwner $createOwner): int
    {
        try {
            $details = OwnerDetails::fromInput([
                'name' => $this->option('name'),
                'email' => $this->option('email'),
                'organization' => $this->option('organization'),
                'timezone' => $this->option('timezone'),
                'language' => $this->option('language'),
            ]);
        } catch (ValidationException $exception) {
            return $this->reportFailure('invalid', 'Invalid owner details.', $exception->errors(), self::INVALID);
        }

        try {
            $created = $createOwner->create($details, 'job:OwnerCreateCommand');
        } catch (OwnerAlreadyExistsException $exception) {
            return $this->reportFailure('owner_exists', $exception->getMessage(), [], self::OWNER_EXISTS);
        }

        if ($this->option('json')) {
            $this->line(json_encode($created->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->comment("Owner account created for {$created->user->email} in organization `{$created->organization->name}`.");
        $this->line("Install state: {$created->installState->value}. Sign-in is by one-time code to that address.");

        return self::SUCCESS;
    }

    /** @param array<string, array<int, string>> $errors */
    private function reportFailure(string $status, string $message, array $errors, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['status' => $status, 'message' => $message, 'errors' => $errors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->error($message);

        foreach ($errors as $field => $messages) {
            foreach ($messages as $fieldMessage) {
                $this->line("  {$field}: {$fieldMessage}");
            }
        }

        return $exitCode;
    }
}
