<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The upload can't be turned into a safe image. Retrying won't help.
 */
class UnprocessableMediaException extends RuntimeException {}
