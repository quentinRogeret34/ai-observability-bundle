<?php

namespace AiObservability\Tests\Fixtures;

use AiObservability\AiObservabilityBundle;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Symfony\AI\Agent\MockAgent;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig
     */
    public function __construct(private readonly array $bundleConfig = [])
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new AiObservabilityBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/ai-observability-bundle/'.md5(serialize($this->bundleConfig));
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'http_method_override' => false]);
        $container->extension('ai_observability', $this->bundleConfig + ['tracer_provider' => 'test.tracer_provider']);

        $container->services()
            ->set('ai.agent.booking', MockAgent::class)->public()
                ->args([[], 'booking'])
                ->tag('ai.agent', ['name' => 'booking'])
            ->set('test.span_exporter', InMemoryExporter::class)->public()
            ->set('test.tracer_provider', TracerProvider::class)->public()
                ->args([inline_service(SimpleSpanProcessor::class)->args([service('test.span_exporter')])]);
    }
}
