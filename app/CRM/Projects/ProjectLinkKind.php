<?php

declare(strict_types=1);

namespace App\CRM\Projects;

/**
 * `project_links.kind` — blueprint §3.2 "GitHub-länkar". Vocabulary from
 * packaging/fixtures/crm/crm.schema.json (`githubLink.kind`).
 */
enum ProjectLinkKind: string
{
    case Repository = 'repository';
    case Issue = 'issue';
    case PullRequest = 'pull_request';
}
