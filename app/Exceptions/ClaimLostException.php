<?php

namespace App\Exceptions;

use LogicException;

/**
 * The reviewer no longer holds a live claim on the task they tried to decide.
 */
class ClaimLostException extends LogicException {}
