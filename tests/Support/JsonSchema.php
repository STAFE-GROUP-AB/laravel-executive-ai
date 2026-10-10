<?php

declare(strict_types=1);

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates PHP data against one of the frozen JSON Schema 2020-12 files in
 * packaging/fixtures/ with opis/json-schema, the PHP counterpart of the ajv
 * run in packaging/fixtures/validate-schema.mjs. The three published schema
 * ids resolve to the local files, so cross-schema `$ref`s (the IPC schema
 * embeds the brief and health schemas) never touch the network.
 */
final class JsonSchema
{
    private const string ID_PREFIX = 'https://raw.githubusercontent.com/STAFE-GROUP-AB/laravel-executive-ai/main/packaging/fixtures/';

    private const array LOCAL_SCHEMAS = ['brief.schema.json', 'health.schema.json', 'ipc.schema.json'];

    /** @return array<int, string> validation errors, empty when valid */
    public static function errors(string $schemaFile, mixed $data): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(20);

        $resolver = $validator->resolver();

        if ($resolver !== null) {
            foreach (self::LOCAL_SCHEMAS as $name) {
                $resolver->registerFile(self::ID_PREFIX.$name, self::fixturePath($name));
            }
        }

        $schema = json_decode((string) file_get_contents($schemaFile), false, 512, JSON_THROW_ON_ERROR);
        $document = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = $validator->validate($document, $schema);

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();

        if ($error === null) {
            return ['invalid'];
        }

        $formatted = (new ErrorFormatter)->formatFlat($error);

        return array_map(strval(...), $formatted);
    }

    public static function fixturePath(string $relative): string
    {
        return base_path('packaging/fixtures/'.$relative);
    }
}
