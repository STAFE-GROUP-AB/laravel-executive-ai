<?php

declare(strict_types=1);

namespace App\CRM\Communications;

/**
 * `communications.source_type` — the morph target of `source_id` (ADR-002 D9):
 * an `email_messages` row (E3-2) today, a GitHub item or a note tomorrow.
 * Vocabulary from packaging/fixtures/crm/crm.schema.json; `activitySource()`
 * maps it onto the timeline origin the WorkspaceRepository contract exposes.
 */
enum CommunicationSourceType: string
{
    case EmailMessage = 'email_message';
    case GithubIssue = 'github_issue';
    case GithubPullRequest = 'github_pull_request';
    case CallNote = 'call_note';
    case MeetingNote = 'meeting_note';

    public function activitySource(): ActivitySource
    {
        return match ($this) {
            self::EmailMessage => ActivitySource::Email,
            self::GithubIssue, self::GithubPullRequest => ActivitySource::GitHub,
            self::CallNote => ActivitySource::Call,
            self::MeetingNote => ActivitySource::Meeting,
        };
    }

    /**
     * Notes typed in locally are their own original: `source_id` is null and
     * `source_uri` is the row's own lxai:// URI.
     */
    public function isLocalNote(): bool
    {
        return $this === self::CallNote || $this === self::MeetingNote;
    }
}
