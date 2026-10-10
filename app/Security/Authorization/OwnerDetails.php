<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What CREATE_OWNER (§12.1, §12.3 screen 4) collects: the person, their
 * address, the organization and the locale the first brief is generated in.
 * Validated once here so the artisan command and the Livewire form agree;
 * the address is always stored lower-cased and trimmed.
 */
final readonly class OwnerDetails
{
    public string $name;

    public string $email;

    public string $organizationName;

    public string $timezone;

    public string $language;

    public function __construct(string $name, string $email, string $organizationName, string $timezone = 'UTC', string $language = 'en')
    {
        $this->name = trim($name);
        $this->email = Str::lower(trim($email));
        $this->organizationName = trim($organizationName);
        $this->timezone = trim($timezone);
        $this->language = Str::lower(trim($language));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $validated = Validator::make($input, self::rules())->validate();

        return new self(
            name: (string) $validated['name'],
            email: (string) $validated['email'],
            organizationName: (string) $validated['organization'],
            timezone: (string) ($validated['timezone'] ?? 'UTC'),
            language: (string) ($validated['language'] ?? 'en'),
        );
    }

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'organization' => ['required', 'string', 'max:200'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'language' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ];
    }
}
