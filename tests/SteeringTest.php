<?php

namespace AiObservability\Tests;

use AiObservability\Steering\HubConfigLoader;
use AiObservability\Steering\SteeringConfig;
use AiObservability\Steering\SteeringProvider;
use AiObservability\User\SecurityUserIdResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SteeringTest extends TestCase
{
    private const HUB_CONFIG = [
        'agents' => ['booking' => ['model' => 'gpt-hub', 'system_prompt' => 'Hub prompt.', 'prompt_version' => '4', 'options' => ['temperature' => 0.3]]],
        'experiments' => [],
    ];

    public function testHubSettingsReplaceTheConfiguredOnesAgentByAgent(): void
    {
        $static = SteeringConfig::fromArray(['agents' => [
            'booking' => ['model' => 'gpt-static'],
            'support' => ['model' => 'gpt-support'],
        ]]);

        $merged = $static->merge(SteeringConfig::fromArray(self::HUB_CONFIG));

        self::assertSame('gpt-hub', $merged->resolve('booking', 'c1')->settings->model);
        self::assertSame('gpt-support', $merged->resolve('support', 'c1')->settings->model);
        self::assertNull($merged->resolve('unknown', 'c1'));
    }

    public function testVariantPromptDropsTheVersionOfTheAgentPrompt(): void
    {
        $config = SteeringConfig::fromArray([
            'agents' => ['booking' => ['system_prompt' => 'Base.', 'prompt_version' => '3']],
            'experiments' => ['tone' => ['agent' => 'booking', 'variants' => ['warm' => ['system_prompt' => 'Warm.']]]],
        ]);

        $resolution = $config->resolve('booking', 'c1');

        self::assertSame('Warm.', $resolution->settings->systemPrompt);
        self::assertNull($resolution->settings->promptVersion);
        self::assertSame('tone', $resolution->experiment);
    }

    public function testInvalidSettingsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SteeringConfig::fromArray(['agents' => ['booking' => ['model' => 42]]]);
    }

    public function testHubConfigurationIsCached(): void
    {
        $requests = 0;
        $loader = $this->loader(static function (string $method, string $url, array $options) use (&$requests): JsonMockResponse {
            ++$requests;
            self::assertSame('https://hub.test/api/steering', $url);
            self::assertContains('Authorization: Bearer secret', $options['headers']);

            return new JsonMockResponse(self::HUB_CONFIG, ['response_headers' => ['ETag' => '"v1"']]);
        });

        self::assertSame('gpt-hub', $loader->load()->resolve('booking', 'c1')->settings->model);
        self::assertSame('gpt-hub', $loader->load()->resolve('booking', 'c1')->settings->model);
        self::assertSame(1, $requests);
    }

    public function testLastConfigurationIsKeptWhenTheHubIsDown(): void
    {
        $cache = new ArrayAdapter();
        $responses = [
            new JsonMockResponse(self::HUB_CONFIG),
            static fn (): never => throw new TransportException('Connection refused.'),
        ];
        $loader = $this->loader(static function () use (&$responses) {
            $response = array_shift($responses);

            return $response instanceof \Closure ? $response() : $response;
        }, $cache);

        $loader->load();
        $cache->deleteItem('ai_observability.hub_config');

        self::assertSame('gpt-hub', $loader->load()->resolve('booking', 'c1')->settings->model);
        self::assertSame([], $responses);
    }

    public function testNothingIsAppliedWhenTheHubWasNeverReached(): void
    {
        $loader = $this->loader(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        self::assertNull($loader->load());
        self::assertNull((new SteeringProvider([], $loader))->config()->resolve('booking', 'c1'));
    }

    public function testInvalidHubPayloadIsIgnored(): void
    {
        $loader = $this->loader(static fn (): JsonMockResponse => new JsonMockResponse(['agents' => ['booking' => ['model' => ['not', 'a', 'string']]]]));

        self::assertNull($loader->load());
    }

    public function testNotModifiedReusesTheLastConfiguration(): void
    {
        $cache = new ArrayAdapter();
        $responses = [
            new JsonMockResponse(self::HUB_CONFIG, ['response_headers' => ['ETag' => '"v1"']]),
            new MockResponse('', ['http_code' => 304]),
        ];
        $loader = $this->loader(static function (string $method, string $url, array $options) use (&$responses): MockResponse {
            if (1 === \count($responses)) {
                self::assertContains('If-None-Match: "v1"', $options['headers']);
            }

            return array_shift($responses);
        }, $cache);

        $loader->load();
        $cache->deleteItem('ai_observability.hub_config');

        self::assertSame('gpt-hub', $loader->load()->resolve('booking', 'c1')->settings->model);
    }

    public function testEmptyHubUrlDisablesTheHub(): void
    {
        $loader = new HubConfigLoader(new MockHttpClient(static fn () => self::fail('The hub should not be called.')), new ArrayAdapter(), '', null);

        self::assertNull($loader->load());
    }

    public function testUserIdIsAHashOfTheIdentifier(): void
    {
        $storage = new TokenStorage();
        $resolver = new SecurityUserIdResolver($storage, 'kernel-secret');

        self::assertNull($resolver->resolve());

        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('alice@example.com', null), 'main'));

        self::assertSame(substr(hash_hmac('sha256', 'alice@example.com', 'kernel-secret'), 0, 32), $resolver->resolve());
    }

    private function loader(callable $responseFactory, ArrayAdapter $cache = new ArrayAdapter()): HubConfigLoader
    {
        return new HubConfigLoader(new MockHttpClient($responseFactory), $cache, 'https://hub.test/', 'secret');
    }
}
