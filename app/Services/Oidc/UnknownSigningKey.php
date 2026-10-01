<?php

namespace App\Services\Oidc;

/**
 * The token names a key the cached JWKS does not have (the provider may have rotated its keys).
 */
class UnknownSigningKey extends OidcException {}
