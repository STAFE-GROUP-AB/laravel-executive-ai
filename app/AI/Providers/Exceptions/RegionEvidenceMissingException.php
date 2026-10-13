<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;

/**
 * A provider was excluded under `EU_RESTRICTED` because its evidence is missing
 * or expired. ADR-004 D5: "Missing or expired evidence ⇒ not eligible; the
 * router refuses with the field names that are missing", and region is never
 * inferred from a domain name.
 */
final class RegionEvidenceMissingException extends AiProviderException
{
    /**
     * @param  list<string>  $missingFields  D5 field names, verbatim
     */
    public function __construct(
        public readonly string $providerId,
        public readonly array $missingFields,
    ) {
        $fields = implode(', ', $missingFields);

        parent::__construct(new AiError(
            kind: AiErrorKind::PolicyDenied,
            message: "Europe Restricted needs verified evidence for {$providerId}; missing: {$fields}.",
            retryable: false,
        ));
    }
}
