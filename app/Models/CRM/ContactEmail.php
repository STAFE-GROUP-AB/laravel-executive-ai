<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\CRM\Contacts\ContactEmailLabel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address a contact is known by, lower-cased, unique per organization.
 *
 * @property string $contact_id
 * @property string $address
 * @property ContactEmailLabel $label
 * @property bool $is_primary
 * @property int $position
 */
class ContactEmail extends CrmModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'label' => ContactEmailLabel::class,
            'is_primary' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
