<?php

namespace AiObservability\Tests;

use AiObservability\Profiler\TracingDataCollector;
use AiObservability\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ProfilerTest extends WebTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(['trace_url' => 'https://traces.test/trace/{trace_id}'], profiler: true);
    }

    public function testSpansOfTheRequestAreShownInTheProfiler(): void
    {
        $client = self::createClient();
        $client->enableProfiler();

        $client->request('GET', '/chat');

        self::assertResponseIsSuccessful();
        /** @var TracingDataCollector $collector */
        $collector = $client->getProfile()->getCollector(TracingDataCollector::NAME);
        [$span] = $collector->getSpans();
        self::assertSame('chat gpt-test', $span['name']);
        self::assertSame('client', $span['kind']);
        self::assertNotNull($span['duration']);
        self::assertSame(42, $collector->getInputTokens());
        self::assertSame(7, $collector->getOutputTokens());
        self::assertSame(['https://traces.test/trace/'.$span['trace_id']], array_values($collector->getTraces()));

        $token = $client->getProfile()->getToken();
        $client->request('GET', '/_wdt/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sf-toolbar-block-'.TracingDataCollector::NAME, '42 in / 7 out');

        $client->request('GET', '/_profiler/'.$token.'?panel='.TracingDataCollector::NAME);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#collector-content h2', 'AI traces');
        self::assertSelectorTextContains('#collector-content table', 'chat gpt-test');
    }
}
