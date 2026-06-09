<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneContracts\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an envelope cannot be parsed because a required field is missing or
 * malformed. A malformed envelope is a sender/receiver contract violation — distinct
 * from a well-formed envelope whose major the receiver simply doesn't support yet.
 */
final class InvalidEnvelope extends InvalidArgumentException {}
