<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\CRM\Commitments\CommitmentDirection;
use App\CRM\Commitments\CommitmentStatus;
use App\CRM\Commitments\ExtractedBy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promise made in a communication (§3.1 `commitments`). `brief_item_id` is the
 * brief document's item id (`brief_items.item_id`), not a row key — see the
 * migration — so it is a plain column, resolved through the latest brief version.
 *
 * @property string $communication_id
 * @property string $customer_id
 * @property ?string $contact_id
 * @property CommitmentDirection $direction
 * @property string $text
 * @property CarbonImmutable $promised_at
 * @property ?CarbonImmutable $due_at
 * @property CommitmentStatus $status
 * @property ?string $follow_up_task_id
 * @property ?string $brief_item_id
 * @property ExtractedBy $extracted_by
 */
class Commitment extends CrmModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'direction' => CommitmentDirection::class,
            'promised_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'status' => CommitmentStatus::class,
            'extracted_by' => ExtractedBy::class,
        ];
    }

    /** @return BelongsTo<Communication, $this> */
    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function followUpTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'follow_up_task_id');
    }
}
