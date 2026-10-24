<?php

namespace App\Services;

use RuntimeException;

/**
 * The document changed on Google's side since the client last fetched it:
 * `applyEdit` was called with a `requiredRevisionId` that no longer matches
 * the latest revision (HTTP 400, `error.status == INVALID_ARGUMENT`, message
 * containing "does not match the latest revision").
 */
class DocumentChanged extends RuntimeException {}
