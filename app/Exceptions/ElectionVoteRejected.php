<?php

namespace App\Exceptions;

use InvalidArgumentException;

class ElectionVoteRejected extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
