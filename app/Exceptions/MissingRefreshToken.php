<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Google returned no refresh token and there is no stored one to carry forward,
 * so the grant could never be renewed. Storing it would produce a connection that
 * silently stops working at the first token expiry.
 */
class MissingRefreshToken extends RuntimeException {}
