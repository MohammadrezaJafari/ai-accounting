<?php

namespace App\Services\Oidc;

use RuntimeException;

/**
 * A token or a response from the identity provider that cannot be trusted.
 */
class OidcException extends RuntimeException {}
