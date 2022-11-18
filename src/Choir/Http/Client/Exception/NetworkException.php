<?php

declare(strict_types=1);

namespace Choir\Http\Client\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

class NetworkException extends \Exception implements NetworkExceptionInterface
{
    private RequestInterface $request;

    public function __construct(RequestInterface $request, $message = '', $code = 0, \Throwable $previous = null)
    {
        $this->request = $request;
        parent::__construct($message, $code, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
