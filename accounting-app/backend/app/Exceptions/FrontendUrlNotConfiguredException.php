<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a public frontend URL is needed but FRONTEND_URL is missing or
 * unusable.
 *
 * This is a deployment fault, not a client fault, so it is left to surface as
 * a server error rather than being rendered as one of the API's 4xx envelopes.
 * Throwing (instead of falling back to a wrong origin) guarantees that a
 * password-reset email is never sent with a link that points at the API or at
 * a placeholder host.
 */
class FrontendUrlNotConfiguredException extends RuntimeException {}
