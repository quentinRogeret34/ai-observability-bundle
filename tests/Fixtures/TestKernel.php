<?php

namespace AiObservability\Tests\Fixtures;

use AiObservability\AiObservabilityBundle;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\AI\Agent\MockAgent;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig
     */
    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly bool $profiler = false,
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new AiObservabilityBundle();

        if ($this->profiler) {
            yield new TwigBundle();
            yield new WebProfilerBundle();
        }
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/ai-observability-bundle/'.md5(serialize([$this->bundleConfig, $this->profiler]));
    }

    public function chat(): Response
    {
        $platform = MockPlatformFactory::createPlatform(static function (): TextResult {
            $result = new TextResult('Room 12 is free.');
            $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 42, completionTokens: 7));

            return $result;
        }, eventDispatcher: $this->getContainer()->get('test.service_container')->get('event_dispatcher'));

        return new Response($platform->invoke('gpt-test', new MessageBag(Message::ofUser('A room?')))->asText());
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'http_method_override' => false, 'profiler' => ['enabled' => $this->profiler, 'collect' => false]]);
        $container->extension('ai_observability', $this->bundleConfig + ['tracer_provider' => 'test.tracer_provider']);

        if ($this->profiler) {
            $container->extension('web_profiler', ['toolbar' => false]);
        }

        $services = $container->services();

        if ($this->bundleConfig['enabled'] ?? true) {
            $services->alias('test.feedback', 'ai_observability.feedback')->public();
            $services->alias('test.agent_tracer', 'ai_observability.agent_tracer')->public();
        }

        $services
            ->set('test.user_id_resolver', FixedUserIdResolver::class)
            ->set('ai.agent.booking', MockAgent::class)->public()
                ->args([[], 'booking'])
                ->tag('ai.agent', ['name' => 'booking'])
            ->set('test.span_exporter', InMemoryExporter::class)->public()
            ->set('test.tracer_provider', TracerProvider::class)->public()
                ->args([inline_service(SimpleSpanProcessor::class)->args([service('test.span_exporter')])]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->profiler) {
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
            $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')->prefix('/_wdt');
            $routes->add('chat', '/chat')->controller('kernel::chat');
        }
    }
}
