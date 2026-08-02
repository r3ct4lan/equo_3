<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HttpInfrastructureTest extends WebTestCase
{
    private const string PAYLOAD_PATH = '/api/v1/_test/http/payload';
    private const string UUID = '550e8400-e29b-41d4-a716-446655440000';

    public function testValidJsonIsMappedToDto(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, sprintf(
            '{"name":"Ada","id":"%s"}',
            self::UUID,
        ));

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            sprintf('{"id":"%s","name":"Ada"}', self::UUID),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testMalformedJsonReturnsSafeError(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, '{"name":');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $error = $this->error($client);
        self::assertSame('INVALID_JSON', $error['code']);
        self::assertSame('Request body must contain valid JSON.', $error['message']);
        self::assertArrayNotHasKey('details', $error);
    }

    public function testEmptyRequiredJsonBodyReturnsInvalidJson(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, '');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('INVALID_JSON', $this->error($client)['code']);
    }

    public function testUnsupportedContentTypeReturns415(): void
    {
        $client = self::createClient();
        $client->request('POST', self::PAYLOAD_PATH, server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $this->error($client)['code']);
    }

    #[DataProvider('unsupportedJsonMediaTypeProvider')]
    public function testAlternativeJsonMediaTypesAreRejected(string $contentType): void
    {
        $client = self::createClient();
        $client->request('POST', self::PAYLOAD_PATH, server: ['CONTENT_TYPE' => $contentType], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $this->error($client)['code']);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedJsonMediaTypeProvider(): iterable
    {
        yield 'legacy application/x-json' => ['application/x-json'];
        yield 'vendor structured JSON' => ['application/vnd.equo+json'];
    }

    public function testApplicationJsonWithCharsetIsAccepted(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            self::PAYLOAD_PATH,
            server: ['CONTENT_TYPE' => 'application/json; charset=utf-8'],
            content: sprintf('{"name":"Ada","id":"%s"}', self::UUID),
        );

        self::assertResponseIsSuccessful();
    }

    public function testMissingRequiredFieldReturnsValidationViolation(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, sprintf('{"id":"%s"}', self::UUID));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $error = $this->error($client);
        self::assertSame('VALIDATION_ERROR', $error['code']);
        self::assertSame([
            ['field' => 'name', 'code' => 'REQUIRED', 'message' => 'Value is required.'],
        ], $error['details']['violations']);
    }

    public function testWrongTypeReturnsValidationErrorInsteadOf500(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, sprintf(
            '{"name":[],"id":"%s"}',
            self::UUID,
        ));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $violations = $this->error($client)['details']['violations'];
        self::assertContains(
            ['field' => 'name', 'code' => 'INVALID_TYPE', 'message' => 'Value has invalid type.'],
            $violations,
        );
    }

    public function testMultipleViolationsHaveDeterministicOrder(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([
            ['field' => 'id', 'code' => 'REQUIRED', 'message' => 'Value is required.'],
            ['field' => 'name', 'code' => 'REQUIRED', 'message' => 'Value is required.'],
        ], $this->error($client)['details']['violations']);
    }

    public function testDtoConstraintUsesStablePublicCode(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, '{"name":"Ada","id":"not-a-uuid"}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([
            ['field' => 'id', 'code' => 'INVALID_UUID', 'message' => 'Value must be a UUID.'],
        ], $this->error($client)['details']['violations']);
    }

    public function testUnknownFieldIsRejected(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, sprintf(
            '{"name":"Ada","id":"%s","unexpected":true}',
            self::UUID,
        ));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('INVALID_REQUEST', $this->error($client)['code']);
    }

    public function testResponseSerializesUuidDateEnumNullEmptyCollectionAndNestedDto(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/_test/http/response');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(<<<'JSON'
            {
              "id": "550e8400-e29b-41d4-a716-446655440000",
              "createdAt": "2026-07-31T12:15:16Z",
              "status": "READY",
              "nested": {"label": "nested"},
              "optional": null,
              "items": []
            }
            JSON,
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testInternalErrorDoesNotExposeTechnicalDetails(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/_test/http/error');

        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        $content = (string) $client->getResponse()->getContent();
        self::assertSame('INTERNAL_SERVER_ERROR', $this->error($client)['code']);
        self::assertStringNotContainsString('SQLSTATE', $content);
        self::assertStringNotContainsString('password=secret', $content);
        self::assertStringNotContainsString('/var/www', $content);
    }

    public function testInternalErrorLogsOnlySafeDiagnosticContext(): void
    {
        $client = self::createClient();
        $handler = self::getContainer()->get('monolog.handler.api_test');
        $handler->clear();

        $client->request('GET', '/api/v1/_test/http/error');

        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        $records = $handler->getRecords();
        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame('Unhandled API exception.', $record->message);
        self::assertSame(RuntimeException::class, $record->context['exceptionClass'] ?? null);
        self::assertSame($client->getResponse()->headers->get('X-Request-Id'), $record->context['requestId'] ?? null);

        $diagnostic = serialize($record->toArray());
        self::assertStringNotContainsString('SQLSTATE', $diagnostic);
        self::assertStringNotContainsString('password=secret', $diagnostic);
        self::assertStringNotContainsString('/var/www', $diagnostic);
    }

    public function testErrorResponseIsJsonAndContainsSameRequestIdAsHeader(): void
    {
        $client = self::createClient();
        $this->jsonRequest($client, 'POST', self::PAYLOAD_PATH, '{"name":');

        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $requestId = $client->getResponse()->headers->get('X-Request-Id');
        self::assertNotNull($requestId);
        self::assertSame($requestId, $this->error($client)['requestId']);
    }

    public function testSafeClientRequestIdIsPreserved(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/_test/http/response', server: ['HTTP_X_REQUEST_ID' => 'client.request-42']);

        self::assertResponseHeaderSame('X-Request-Id', 'client.request-42');
    }

    public function testUnsafeClientRequestIdIsReplacedWithUlid(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/_test/http/response', server: ['HTTP_X_REQUEST_ID' => "unsafe\nvalue"]);

        $requestId = $client->getResponse()->headers->get('X-Request-Id');
        self::assertNotNull($requestId);
        self::assertMatchesRegularExpression('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $requestId);
    }

    public function testMethodNotAllowedUsesStandardEnvelope(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PAYLOAD_PATH);

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertSame('METHOD_NOT_ALLOWED', $this->error($client)['code']);
        self::assertResponseHeaderSame('Allow', 'POST');
    }

    public function testUnknownApiRouteUsesStandardEnvelope(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/_test/http/not-found');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('RESOURCE_NOT_FOUND', $this->error($client)['code']);
    }

    private function jsonRequest(KernelBrowser $client, string $method, string $path, string $content): void
    {
        $client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: $content);
    }

    /** @return array<string, mixed> */
    private function error(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
        self::assertIsArray($decoded['error']);

        return $decoded['error'];
    }
}
