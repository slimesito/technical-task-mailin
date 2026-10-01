<?php

namespace App\Exceptions;

use RuntimeException;

/** Every base DNS lookup failed without a definitive answer: worth retrying the whole check. */
class TransientDnsException extends RuntimeException {}
