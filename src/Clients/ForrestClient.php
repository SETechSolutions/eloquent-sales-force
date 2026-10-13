<?php

namespace SETechSolutions\EloquentSalesForce\Clients;

use Illuminate\Contracts\Container\Container;
use Omniphx\Forrest\Exceptions\MissingResourceException;
use Omniphx\Forrest\Exceptions\MissingTokenException;
use Omniphx\Forrest\Exceptions\MissingVersionException;
use SETechSolutions\EloquentSalesForce\Contracts\SalesforceClient;
use SETechSolutions\EloquentSalesForce\Exceptions\MalformedQueryException;
use SETechSolutions\EloquentSalesForce\Exceptions\RequestLimitExceeded;
use SETechSolutions\EloquentSalesForce\Exceptions\RestAPIException;
use SETechSolutions\EloquentSalesForce\Exceptions\UnableToLockRowException;
use Throwable;

/**
 * Talks to Salesforce through omniphx/forrest.
 *
 * This is the only class in the package that touches Forrest directly.
 */
class ForrestClient implements SalesforceClient
{
    /**
     * Salesforce error codes that map to a dedicated exception class.
     *
     * @var array<string, class-string<RestAPIException>>
     */
    protected const EXCEPTIONS = [
        'UNABLE_TO_LOCK_ROW' => UnableToLockRowException::class,
        'MALFORMED_QUERY' => MalformedQueryException::class,
        'REQUEST_LIMIT_EXCEEDED' => RequestLimitExceeded::class,
    ];

    public function __construct(protected Container $container) {}

    /**
     * The Forrest client, resolved lazily so configuration changes made
     * before the first request are honoured.
     *
     * @return mixed
     */
    protected function forrest()
    {
        return $this->container->make('forrest');
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate()
    {
        return $this->attempt(function () {
            $forrest = $this->forrest();

            if (! $forrest->hasToken()) {
                $response = $forrest->authenticate();

                // The WebServer flow answers with a redirect to Salesforce
                // and has no token until the callback has been handled.
                if ($this->container->make('config')->get('eloquent_sf.forrest.authentication') === 'WebServer') {
                    return $response;
                }
            }

            return (object) $forrest->getToken();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function authorize()
    {
        return $this->attempt(fn () => $this->forrest()->authenticate());
    }

    /**
     * {@inheritDoc}
     */
    public function callback()
    {
        return $this->attempt(fn () => $this->forrest()->callback());
    }

    /**
     * {@inheritDoc}
     */
    public function instanceUrl(): ?string
    {
        try {
            return $this->forrest()->getInstanceURL() ?: null;
        } catch (MissingTokenException) {
            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function call(string $method, array $arguments = [])
    {
        return $this->attempt(function () use ($method, $arguments) {
            $forrest = $this->forrest();

            try {
                return $forrest->$method(...$arguments);
            } catch (MissingTokenException) {
                $forrest->authenticate();
            } catch (MissingResourceException) {
                $forrest->authenticate();
                $forrest->resources();
            } catch (MissingVersionException) {
                $forrest->authenticate();
                $forrest->versions();
            }

            // The token, resource list or version list was missing from
            // storage (typically an expired cache entry); it has been
            // restored above, so the request is retried exactly once.
            return $forrest->$method(...$arguments);
        });
    }

    /**
     * Run a callback, converting Salesforce error payloads into exceptions.
     *
     * @return mixed
     */
    protected function attempt(callable $callback)
    {
        try {
            return $callback();
        } catch (RestAPIException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw static::translate($e) ?? $e;
        }
    }

    /**
     * Turn a Forrest exception carrying a Salesforce error payload into the
     * matching package exception, or null when it is something else.
     */
    public static function translate(Throwable $e): ?RestAPIException
    {
        $errors = json_decode($e->getMessage(), true);

        if (! is_array($errors) || ! isset($errors[0]) || ! is_array($errors[0])) {
            return null;
        }

        $error = $errors[0];
        $code = isset($error['errorCode']) ? (string) $error['errorCode'] : null;
        $class = static::EXCEPTIONS[$code] ?? RestAPIException::class;

        return $class::fromSalesforce(
            (string) ($error['message'] ?? $e->getMessage()),
            $code,
            array_values((array) ($error['fields'] ?? [])),
            $e
        );
    }
}
