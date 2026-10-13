<?php

namespace Lester\EloquentSalesForce\Exceptions;

use Exception;
use Throwable;

class RestAPIException extends Exception
{
    /**
     * The Salesforce error code, e.g. MALFORMED_QUERY.
     */
    public ?string $errorCode = null;

    /**
     * The fields Salesforce reported as the cause of the error, if any.
     *
     * @var array<int, string>
     */
    public array $fields = [];

    /**
     * @param  array<int, string>  $fields
     */
    public static function fromSalesforce(string $message, ?string $errorCode = null, array $fields = [], ?Throwable $previous = null): static
    {
        $exception = new static($message, 0, $previous);
        $exception->errorCode = $errorCode;
        $exception->fields = $fields;

        return $exception;
    }
}
