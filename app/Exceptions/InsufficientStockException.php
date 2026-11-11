<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InsufficientStockException extends HttpException
{
    public function __construct(string $message = 'Insufficient stock.')
    {
        parent::__construct(422, $message);
    }
}
