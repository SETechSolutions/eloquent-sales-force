<?php

namespace SETechSolutions\EloquentSalesForce\Contracts;

use SETechSolutions\EloquentSalesForce\Exceptions\RestAPIException;

/**
 * The single boundary between this package and the Salesforce REST API.
 *
 * Everything the package sends to Salesforce goes through an implementation
 * of this interface, which is what makes it possible to swap the real
 * transport for a fake in tests (see SObjects::fake()).
 */
interface SalesforceClient
{
    /**
     * Make sure a usable access token exists, requesting one if needed.
     *
     * For the WebServer flow this returns the redirect response that sends
     * the user to Salesforce; for every other flow it returns the token data.
     *
     * @return mixed
     */
    public function authenticate();

    /**
     * Start the configured authentication flow unconditionally.
     *
     * @return mixed
     */
    public function authorize();

    /**
     * Complete the WebServer flow from the OAuth callback request.
     *
     * @return mixed
     */
    public function callback();

    /**
     * The instance URL of the authenticated org, or null when unauthenticated.
     */
    public function instanceUrl(): ?string;

    /**
     * Call a REST resource (query, queryAll, next, sobjects, composite,
     * describe, versions, ...) and return the decoded response.
     *
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws RestAPIException
     */
    public function call(string $method, array $arguments = []);
}
