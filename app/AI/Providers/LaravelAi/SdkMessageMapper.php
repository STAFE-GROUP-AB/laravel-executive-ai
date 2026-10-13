<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use App\AI\Providers\Dto\AiAttachment;
use App\AI\Providers\Dto\AiMessage;
use App\AI\Providers\Dto\AiMessageRole;
use App\AI\Providers\Dto\AiRequest;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Our app-owned conversation (`AiRequest::$messages`) → the SDK's message
 * objects, settled against the 1.2.0 source:
 *
 * - **The last user turn is the prompt.** `Promptable::prompt($prompt,
 *   $attachments, ...)` appends a `UserMessage` built from those two arguments
 *   *after* the history it was handed, so the history must stop short of the
 *   turn being sent or the model would see it twice.
 * - **Attachments ride on their own message.** `UserMessage` carries a
 *   `Collection` of `Laravel\Ai\Files\*`, and `Message::tryFrom()` returns a
 *   `UserMessage` unchanged, so an attachment on an *earlier* turn survives
 *   `withMessages()`. `AiAttachment` holds bytes in memory, so each becomes a
 *   base64 file and never touches disk (ADR-005 D1.7).
 * - **There is no system role.** `MessageRole` is `assistant`, `user`,
 *   `tool_result` only; the SDK carries system text as the agent's
 *   `instructions()`, so our `System` messages are folded into those.
 * - **A tool result is a `ToolResultMessage`.** Content that came from outside
 *   the app enters the model this way and never as a user or system message,
 *   which is the invariant the S0-21 injection suite checks.
 */
final readonly class SdkMessageMapper
{
    /**
     * The history to hand `withMessages()`: everything except the trailing user
     * turn, which travels as the prompt.
     *
     * @return list<Message>
     */
    public function history(AiRequest $request): array
    {
        $messages = $request->messages;
        $trailing = $this->trailingUserMessageIndex($request);
        $history = [];

        foreach ($messages as $index => $message) {
            if ($index === $trailing || $message->role === AiMessageRole::System) {
                continue;
            }

            $history[] = $this->map($message);
        }

        return $history;
    }

    /**
     * The text of the trailing user turn, or an empty string when the request
     * ends on a tool result (a continuation, where the model is answering what
     * it already asked for).
     */
    public function prompt(AiRequest $request): string
    {
        $trailing = $this->trailingUserMessageIndex($request);

        return $trailing === null ? '' : $request->messages[$trailing]->content;
    }

    /**
     * The attachments of the trailing user turn, as SDK files.
     *
     * @return list<File>
     */
    public function attachments(AiRequest $request): array
    {
        $trailing = $this->trailingUserMessageIndex($request);

        if ($trailing === null) {
            return [];
        }

        return array_map($this->file(...), $request->messages[$trailing]->attachments);
    }

    /**
     * Everything the model is told as instruction rather than as data: the
     * request's own instructions first, then any `System` messages in order.
     */
    public function instructions(AiRequest $request): string
    {
        $parts = [];

        if ($request->instructions !== null && trim($request->instructions) !== '') {
            $parts[] = $request->instructions;
        }

        foreach ($request->messages as $message) {
            if ($message->role === AiMessageRole::System && trim($message->content) !== '') {
                $parts[] = $message->content;
            }
        }

        return implode("\n\n", $parts);
    }

    private function map(AiMessage $message): Message
    {
        return match ($message->role) {
            AiMessageRole::User => new UserMessage($message->content, $this->files($message)),
            AiMessageRole::Assistant => new AssistantMessage($message->content),
            AiMessageRole::ToolResult => new ToolResultMessage(new Collection([
                new ToolResult(
                    id: $message->toolCallId ?? '',
                    name: '',
                    arguments: [],
                    result: $message->content,
                ),
            ])),
            // Folded into instructions(); history() filters it out before this.
            AiMessageRole::System => new Message('user', $message->content),
        };
    }

    /** @return list<File> */
    private function files(AiMessage $message): array
    {
        return array_map($this->file(...), $message->attachments);
    }

    private function file(AiAttachment $attachment): File
    {
        $base64 = base64_encode($attachment->bytes);

        return str_starts_with($attachment->mimeType, 'image/')
            ? Image::fromBase64($base64, $attachment->mimeType)
            : Document::fromBase64($base64, $attachment->mimeType);
    }

    private function trailingUserMessageIndex(AiRequest $request): ?int
    {
        $last = count($request->messages) - 1;

        for ($index = $last; $index >= 0; $index--) {
            $role = $request->messages[$index]->role;

            if ($role === AiMessageRole::User) {
                return $index;
            }

            if ($role === AiMessageRole::Assistant || $role === AiMessageRole::ToolResult) {
                // The conversation does not end on a user turn, so there is no
                // prompt to extract: the whole history is replayed instead.
                return null;
            }
        }

        return null;
    }
}
