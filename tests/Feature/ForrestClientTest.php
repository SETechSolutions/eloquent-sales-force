<?php

namespace SETechSolutions\EloquentSalesForce\Tests\Feature;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Mockery;
use Mockery\MockInterface;
use Omniphx\Forrest\Exceptions\MissingResourceException;
use Omniphx\Forrest\Exceptions\MissingTokenException;
use Omniphx\Forrest\Exceptions\MissingVersionException;
use Omniphx\Forrest\Exceptions\SalesforceException;
use PHPUnit\Framework\Attributes\DataProvider;
use SETechSolutions\EloquentSalesForce\Clients\ForrestClient;
use SETechSolutions\EloquentSalesForce\Contracts\SalesforceClient;
use SETechSolutions\EloquentSalesForce\Exceptions\MalformedQueryException;
use SETechSolutions\EloquentSalesForce\Exceptions\RequestLimitExceeded;
use SETechSolutions\EloquentSalesForce\Exceptions\RestAPIException;
use SETechSolutions\EloquentSalesForce\Exceptions\UnableToLockRowException;
use SETechSolutions\EloquentSalesForce\Facades\SObjects;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestLead;
use SETechSolutions\EloquentSalesForce\Tests\TestCase;

class ForrestClientTest extends TestCase
{
    private MockInterface $forrest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forrest = Mockery::mock();

        $this->app->instance('forrest', $this->forrest);
        $this->app->instance(SalesforceClient::class, new ForrestClient($this->app));
    }

    public function test_the_service_provider_binds_the_forrest_client(): void
    {
        $this->app->forgetInstance(SalesforceClient::class);

        $this->assertInstanceOf(ForrestClient::class, $this->app->make(SalesforceClient::class));
    }

    public function test_authenticate_requests_a_token_only_when_none_is_stored(): void
    {
        $this->forrest->shouldReceive('hasToken')->twice()->andReturn(false, true);
        $this->forrest->shouldReceive('authenticate')->once();
        $this->forrest->shouldReceive('getToken')->twice()->andReturn(['instance_url' => 'https://example.my.salesforce.com']);

        $this->assertSame('https://example.my.salesforce.com', SObjects::authenticate()->instance_url);

        SObjects::authenticate();
    }

    public function test_the_web_server_flow_returns_the_redirect(): void
    {
        config(['eloquent_sf.forrest.authentication' => 'WebServer']);

        $this->forrest->shouldReceive('hasToken')->once()->andReturn(false);
        $this->forrest->shouldReceive('authenticate')->once()->andReturn('redirect-response');

        $this->assertSame('redirect-response', SObjects::authenticate());
    }

    public function test_instance_url_is_null_without_a_token(): void
    {
        $this->forrest->shouldReceive('getInstanceURL')->once()->andThrow(new MissingTokenException('No token'));

        $this->assertNull(SObjects::instanceUrl());
    }

    public function test_queries_go_through_forrest_and_follow_pagination(): void
    {
        $this->forrest->shouldReceive('query')->once()->with('select Id from Lead')->andReturn([
            'records' => [['Id' => '00Q000000000001AAA']],
            'nextRecordsUrl' => '/services/data/v62.0/query/01g-2000',
        ]);
        $this->forrest->shouldReceive('next')->once()->with('/services/data/v62.0/query/01g-2000')->andReturn([
            'records' => [['Id' => '00Q000000000002AAA']],
        ]);

        $this->assertSame(
            ['00Q000000000001AAA', '00Q000000000002AAA'],
            TestLead::select('Id')->get()->pluck('Id')->all()
        );
    }

    /**
     * @param  class-string<\Throwable>  $exception
     */
    #[DataProvider('recoverableExceptions')]
    public function test_a_request_is_retried_once_after_restoring_missing_state(string $exception, ?string $restoredBy): void
    {
        $this->forrest->shouldReceive('query')->twice()->andReturnUsing(
            fn () => throw new $exception('missing'),
            fn () => ['records' => []]
        );
        $this->forrest->shouldReceive('authenticate')->once();

        if ($restoredBy) {
            $this->forrest->shouldReceive($restoredBy)->once();
        }

        $this->assertSame(['records' => []], SObjects::query('select Id from Lead'));
    }

    /**
     * @return array<string, array{0: class-string<\Throwable>, 1: string|null}>
     */
    public static function recoverableExceptions(): array
    {
        return [
            'missing token' => [MissingTokenException::class, null],
            'missing resources' => [MissingResourceException::class, 'resources'],
            'missing version' => [MissingVersionException::class, 'versions'],
        ];
    }

    /**
     * @param  class-string<RestAPIException>  $expected
     */
    #[DataProvider('salesforceErrors')]
    public function test_salesforce_error_payloads_become_typed_exceptions(string $code, string $expected): void
    {
        $payload = json_encode([['message' => 'Something failed', 'errorCode' => $code, 'fields' => ['Email']]]);

        $this->forrest->shouldReceive('query')->once()->andThrow(new SalesforceException($payload, new RequestException('Bad Request', new Request('GET', '/query'))));

        try {
            SObjects::query('select Id from Lead');

            $this->fail('No exception was thrown.');
        } catch (RestAPIException $e) {
            $this->assertSame($expected, get_class($e));
            $this->assertSame('Something failed', $e->getMessage());
            $this->assertSame($code, $e->errorCode);
            $this->assertSame(['Email'], $e->fields);
            $this->assertInstanceOf(SalesforceException::class, $e->getPrevious());
        }
    }

    /**
     * @return array<string, array{0: string, 1: class-string<RestAPIException>}>
     */
    public static function salesforceErrors(): array
    {
        return [
            'malformed query' => ['MALFORMED_QUERY', MalformedQueryException::class],
            'row lock' => ['UNABLE_TO_LOCK_ROW', UnableToLockRowException::class],
            'request limit' => ['REQUEST_LIMIT_EXCEEDED', RequestLimitExceeded::class],
            'anything else' => ['INVALID_FIELD', RestAPIException::class],
        ];
    }

    public function test_other_exceptions_are_passed_through_untouched(): void
    {
        $this->forrest->shouldReceive('query')->once()->andThrow(new \RuntimeException('Connection reset'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection reset');

        TestLead::select('Id')->get();
    }

    public function test_process_exceptions_keeps_its_behaviour(): void
    {
        $this->expectException(UnableToLockRowException::class);
        $this->expectExceptionMessage('locked');

        SObjects::processExceptions([(object) ['message' => 'locked', 'errorCode' => 'UNABLE_TO_LOCK_ROW']]);
    }
}
