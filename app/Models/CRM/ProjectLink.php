<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\CRM\Projects\ProjectLinkKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GitHub repository, issue or pull request tied to a project (§3.2).
 *
 * @property string $project_id
 * @property ProjectLinkKind $kind
 * @property string $uri
 * @property ?string $label
 * @property int $position
 */
class ProjectLink extends CrmModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'kind' => ProjectLinkKind::class,
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
