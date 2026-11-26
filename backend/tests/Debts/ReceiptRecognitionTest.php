<?php

declare(strict_types=1);

namespace App\Tests\Debts;

use App\Debts\Adapter\OpenAiReceiptReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReceiptRecognitionTest extends TestCase
{
    public function testFixtureReaderIsRegisteredOnlyInTheIsolatedBrowserEnvironment(): void
    {
        $directory = dirname(__DIR__, 2).'/config/';
        $fixture = \App\Tests\Fixture\E2e\FixedReceiptReader::class;
        $config = \Symfony\Component\Yaml\Yaml::parseFile($directory.'services_e2e.yaml');
        self::assertSame($fixture, $config['services'][\App\Debts\Application\Port\ReceiptReader::class]['class']);

        foreach (glob($directory.'services*.yaml') ?: [] as $file) {
            if ('services_e2e.yaml' !== basename($file)) {
                self::assertStringNotContainsString($fixture, (string) file_get_contents($file));
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function receipts(): iterable
    {
        foreach (['water', 'coffee', 'lunch', 'discount', 'cropped', 'unreadable'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('receipts')]
    public function testReadsOneOrderedSetOfPhotos(string $case): void
    {
        $base = __DIR__.'/receipts/'.$case;
        $expected = json_decode((string) file_get_contents($base.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $images = [];
        foreach (glob($base.'-*.png') ?: [] as $path) {
            $images[] = 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
        }
        $reply = ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($expected, JSON_THROW_ON_ERROR)]]]]];
        $mock = new MockResponse(json_encode($reply, JSON_THROW_ON_ERROR));
        $reader = new OpenAiReceiptReader(new MockHttpClient($mock), 'test');
        self::assertSame($expected, $reader->read($images));
        $request = json_decode($mock->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($images, array_column($request['input'][0]['content'], 'image_url'));
        self::assertTrue($request['text']['format']['strict']);
        self::assertFalse($request['store']);
        self::assertSame('gpt-6-luna', $request['model']);
    }

    public function testUnavailableProviderAndMalformedOutputReturnNoDraft(): void
    {
        foreach ([new MockResponse('', ['http_code' => 503]), new MockResponse('{}')] as $response) {
            $reader = new OpenAiReceiptReader(new MockHttpClient($response), 'test');
            self::assertNull($reader->read(['data:image/png;base64,YQ==']));
        }
    }
}
