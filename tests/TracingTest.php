<?php

namespace AiObservability\Tests;

use AiObservability\Agent\TracedAgent;
use AiObservability\Tests\Fixtures\AvailabilityTool;
use AiObservability\Tests\Fixtures\TestKernel;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class TracingTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['config'] ?? []);
    }

    public function testChatCallBecomesInferenceSpanWithoutContentByDefault(): void
    {
        self::bootKernel();
        $messages = new MessageBag(Message::forSystem('You book hotel rooms.'), Message::ofUser('A room for tonight?'));

        $answer = $this->platform(static function (): TextResult {
            $result = new TextResult('Room 12 is free.');
            $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 42, completionTokens: 7, cacheReadTokens: 30));

            return $result;
        })->invoke('gpt-test', $messages, ['temperature' => 0.2, 'max_tokens' => 200])->asText();

        self::assertSame('Room 12 is free.', $answer);
        $span = $this->singleSpan();
        self::assertSame('chat gpt-test', $span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame([
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => 'gpt-test',
            'gen_ai.request.temperature' => 0.2,
            'gen_ai.request.max_tokens' => 200,
            'gen_ai.conversation.id' => $messages->getId()->toRfc4122(),
            'gen_ai.provider.name' => 'mock',
            'gen_ai.usage.input_tokens' => 42,
            'gen_ai.usage.output_tokens' => 7,
            'gen_ai.usage.cache_read.input_tokens' => 30,
        ], $span->getAttributes()->toArray());
    }

    public function testContentIsCapturedWhenEnabled(): void
    {
        self::bootKernel(['config' => ['capture_content' => true]]);

        $this->platform('Room 12 is free.')
            ->invoke('gpt-test', new MessageBag(Message::forSystem('You book hotel rooms.'), Message::ofUser('A room for tonight?')))
            ->asText();

        $attributes = $this->singleSpan()->getAttributes();
        self::assertSame([['type' => 'text', 'content' => 'You book hotel rooms.']], json_decode($attributes->get('gen_ai.system_instructions'), true));
        self::assertSame([['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'A room for tonight?']]]], json_decode($attributes->get('gen_ai.input.messages'), true));
        self::assertSame([['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => 'Room 12 is free.']]]], json_decode($attributes->get('gen_ai.output.messages'), true));
    }

    public function testStreamedCallEndsWhenTheStreamIsDrained(): void
    {
        self::bootKernel(['config' => ['capture_content' => true]]);

        $result = $this->platform(static fn (): StreamResult => new StreamResult((static function (): \Generator {
            yield new TextDelta('Room 12 ');
            yield new TextDelta('is free.');
            yield new TokenUsage(promptTokens: 10, completionTokens: 4);
        })()))->invoke('gpt-test', new MessageBag(Message::ofUser('A room?')), ['stream' => true])->asStream();

        self::assertSame([], $this->exportedSpans());

        iterator_to_array($result, false);

        $attributes = $this->singleSpan()->getAttributes();
        self::assertTrue($attributes->get('gen_ai.request.stream'));
        self::assertSame(10, $attributes->get('gen_ai.usage.input_tokens'));
        self::assertSame(4, $attributes->get('gen_ai.usage.output_tokens'));
        self::assertIsFloat($attributes->get('gen_ai.response.time_to_first_chunk'));
        self::assertSame([['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => 'Room 12 is free.']]]], json_decode($attributes->get('gen_ai.output.messages'), true));
    }

    public function testCallFailingBeforeTheModelAnswersIsEndedOnReset(): void
    {
        self::bootKernel();
        $platform = $this->platform(static fn () => throw new \RuntimeException('Provider unreachable.'));

        try {
            $platform->invoke('gpt-test', new MessageBag(Message::ofUser('A room?')));
            self::fail('The call should have failed.');
        } catch (\RuntimeException) {
        }

        self::getContainer()->get('services_resetter')->reset();

        $span = $this->singleSpan();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('_OTHER', $span->getAttributes()->get('error.type'));
    }

    public function testAgentRunParentsItsModelAndToolSpans(): void
    {
        self::bootKernel(['config' => ['capture_content' => true]]);
        $calls = 0;
        $platform = $this->platform(static function () use (&$calls) {
            return 1 === ++$calls
                ? new ToolCallResult([new ToolCall('call_1', 'search_availability', ['date' => '2026-12-01'])])
                : new TextResult('Room 12 is available on 2026-12-01.');
        });
        $agent = $this->agent($platform);

        $content = $agent->call(new MessageBag(Message::ofUser('A room on December 1st?')))->getContent();

        self::assertSame('Room 12 is available on 2026-12-01.', $content);
        $spans = $this->exportedSpans();
        self::assertSame(['chat gpt-test', 'execute_tool search_availability', 'chat gpt-test', 'invoke_agent booking'], array_map(static fn (ImmutableSpan $span): string => $span->getName(), $spans));

        [$firstChat, $tool, $secondChat, $agentSpan] = $spans;
        foreach ([$firstChat, $tool, $secondChat] as $child) {
            self::assertSame($agentSpan->getSpanId(), $child->getParentSpanId());
            self::assertSame($agentSpan->getTraceId(), $child->getTraceId());
        }

        self::assertSame('booking', $agentSpan->getAttributes()->get('gen_ai.agent.name'));
        self::assertSame(['tool_calls'], $firstChat->getAttributes()->get('gen_ai.response.finish_reasons'));
        self::assertSame('call_1', $tool->getAttributes()->get('gen_ai.tool.call.id'));
        self::assertSame('{"date":"2026-12-01"}', $tool->getAttributes()->get('gen_ai.tool.call.arguments'));
        self::assertSame('Room 12 is available on 2026-12-01', $tool->getAttributes()->get('gen_ai.tool.call.result'));
        self::assertSame(
            $agentSpan->getAttributes()->get('gen_ai.conversation.id'),
            $tool->getAttributes()->get('gen_ai.conversation.id'),
        );
    }

    public function testFailedToolCallIsRecordedAsError(): void
    {
        self::bootKernel();
        $calls = 0;
        $platform = $this->platform(static function () use (&$calls) {
            return 1 === ++$calls
                ? new ToolCallResult([new ToolCall('call_1', 'search_availability', ['date' => '2000-01-01'])])
                : new TextResult('That date is in the past.');
        });

        try {
            $this->agent($platform)->call(new MessageBag(Message::ofUser('A room on January 1st 2000?')))->getContent();
            self::fail('The tool failure should reach the caller.');
        } catch (ToolExecutionException) {
        }

        [, $tool, $agentSpan] = $this->exportedSpans();
        self::assertSame('execute_tool search_availability', $tool->getName());
        self::assertSame(StatusCode::STATUS_ERROR, $tool->getStatus()->getCode());
        self::assertSame(\RuntimeException::class, $tool->getAttributes()->get('error.type'));
        self::assertSame('invoke_agent booking', $agentSpan->getName());
        self::assertSame(ToolExecutionException::class, $agentSpan->getAttributes()->get('error.type'));
    }

    public function testExperimentReplacesTheSystemPromptAndTagsTheAgentSpan(): void
    {
        self::bootKernel(['config' => ['capture_content' => true, 'experiments' => ['booking_prompt' => [
            'agent' => 'booking',
            'variants' => [
                'control' => ['system_prompt' => 'You book hotel rooms.', 'weight' => 0],
                'concise' => ['system_prompt' => 'You book hotel rooms. Answer in one sentence.', 'weight' => 1],
            ],
        ]]]]);
        $messages = new MessageBag(Message::forSystem('Prompt of the app.'), Message::ofUser('A room for tonight?'));

        $this->agent($this->platform('Room 12 is free.'))->call($messages)->getContent();

        [$chat, $agentSpan] = $this->exportedSpans();
        self::assertSame([['type' => 'text', 'content' => 'You book hotel rooms. Answer in one sentence.']], json_decode($chat->getAttributes()->get('gen_ai.system_instructions'), true));
        self::assertSame('booking_prompt', $agentSpan->getAttributes()->get('ai_observability.experiment.id'));
        self::assertSame('concise', $agentSpan->getAttributes()->get('ai_observability.experiment.variant'));
        self::assertSame($messages->getId()->toRfc4122(), $agentSpan->getAttributes()->get('gen_ai.conversation.id'));
    }

    public function testStringInputGetsAVariantToo(): void
    {
        self::bootKernel(['config' => ['experiments' => ['booking_prompt' => [
            'agent' => 'booking',
            'variants' => ['concise' => ['system_prompt' => 'Answer in one sentence.']],
        ]]]]);

        $this->agent($this->platform('Room 12 is free.'))->call('A room for tonight?')->getContent();

        [, $agentSpan] = $this->exportedSpans();
        self::assertSame('concise', $agentSpan->getAttributes()->get('ai_observability.experiment.variant'));
        self::assertNotNull($agentSpan->getAttributes()->get('gen_ai.conversation.id'));
    }

    public function testAgentWithoutExperimentKeepsItsPrompt(): void
    {
        self::bootKernel(['config' => ['capture_content' => true]]);

        $this->agent($this->platform('Room 12 is free.'))->call(new MessageBag(Message::forSystem('Prompt of the app.'), Message::ofUser('A room?')))->getContent();

        [$chat, $agentSpan] = $this->exportedSpans();
        self::assertSame([['type' => 'text', 'content' => 'Prompt of the app.']], json_decode($chat->getAttributes()->get('gen_ai.system_instructions'), true));
        self::assertNull($agentSpan->getAttributes()->get('ai_observability.experiment.id'));
    }

    public function testAgentsDeclaredInTheContainerAreDecorated(): void
    {
        self::bootKernel();

        self::assertInstanceOf(TracedAgent::class, self::getContainer()->get('ai.agent.booking'));
    }

    public function testNothingIsTracedWhenDisabled(): void
    {
        self::bootKernel(['config' => ['enabled' => false]]);

        self::assertFalse(self::getContainer()->has('ai_observability.platform_subscriber'));
        self::assertNotInstanceOf(TracedAgent::class, self::getContainer()->get('ai.agent.booking'));
    }

    private function platform(\Closure|string $responses): Platform
    {
        return MockPlatformFactory::createPlatform($responses, eventDispatcher: self::getContainer()->get('event_dispatcher'));
    }

    private function agent(Platform $platform): TracedAgent
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $agent = new Agent(
            $platform,
            'gpt-test',
            name: 'booking',
            toolbox: new Toolbox([new AvailabilityTool()], eventDispatcher: $dispatcher),
            eventDispatcher: $dispatcher,
        );

        return new TracedAgent(
            $agent,
            self::getContainer()->get('ai_observability.tracer'),
            self::getContainer()->get('ai_observability.span_stack'),
            self::getContainer()->get('ai_observability.experiments'),
        );
    }

    private function singleSpan(): ImmutableSpan
    {
        $spans = $this->exportedSpans();
        self::assertCount(1, $spans);

        return $spans[0];
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function exportedSpans(): array
    {
        return array_values(self::getContainer()->get('test.span_exporter')->getSpans());
    }
}
