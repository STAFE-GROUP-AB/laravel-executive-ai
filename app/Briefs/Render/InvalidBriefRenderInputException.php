<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use RuntimeException;

/**
 * The document cannot be rendered: unknown source reference, a bare claim
 * (§4.2), or a schema major the renderer does not support (brief schema §4).
 */
final class InvalidBriefRenderInputException extends RuntimeException {}
