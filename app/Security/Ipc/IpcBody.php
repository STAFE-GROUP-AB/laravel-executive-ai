<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use JsonException;

/**
 * §4.4 step 10: the body (or query) schema. Every request schema in the
 * contract is `additionalProperties: false`, so an unknown field is a
 * validation failure — which is also how §11.4 rejects a body that tries
 * to assert an identity (`user_id`, `owner`, `approver`). Malformed JSON
 * is 400 / bad_request; a JSON value that is not an object likewise.
 */
final class IpcBody
{
    /**
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     *
     * @throws IpcException bad_request | validation_failed
     */
    public static function validate(Request $request, array $rules): array
    {
        $content = $request->getContent();

        if ($content === '') {
            return self::check([], $rules);
        }

        if (! str_starts_with(ltrim($content), '{')) {
            throw IpcException::badRequest('body_not_an_object');
        }

        try {
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw IpcException::badRequest('invalid_json');
        }

        return self::check(is_array($decoded) ? $decoded : [], $rules);
    }

    /**
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     *
     * @throws IpcException validation_failed
     */
    public static function validateQuery(Request $request, array $rules): array
    {
        return self::check($request->query->all(), $rules);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     */
    private static function check(array $input, array $rules): array
    {
        $unknown = array_diff(array_map(strval(...), array_keys($input)), array_keys($rules));

        if ($unknown !== []) {
            throw IpcException::validationFailed(array_fill_keys(array_values($unknown), 'unknown field'));
        }

        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            $details = [];

            foreach ($validator->errors()->toArray() as $field => $messages) {
                $details[(string) $field] = (string) ($messages[0] ?? 'invalid');
            }

            throw IpcException::validationFailed($details);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }
}
