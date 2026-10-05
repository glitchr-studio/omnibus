<?php

namespace Omnibus\Tests;

use Omnibus\Bridge\Symfony\OmnibusBundle;
use Omnibus\Offline\OfflineGatewayFactory;
use Omnibus\Registry;
use Omnibus\Ups\UpsGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Omnibus outside Symfony: the harness's bare script (docker/harness/bin/bare)
 * run in a PHP process of its own - this one has loaded the bundle's tests -
 * builds the registry by hand, prices and hands over a parcel through
 * omnibus/offline, asks UPS what it would cost from the answers kept in
 * docker/harness/recorded/, and reports every class PHP loaded on the way and
 * every file since the autoloader. None may be a framework's.
 */
final class BareTest extends TestCase
{
    private const FRAMEWORK = '~^(?:Symfony\\\\Component\\\\(?:DependencyInjection|Config|HttpKernel|HttpFoundation)|Symfony\\\\Bundle|Symfony\\\\Bridge|Doctrine|Twig)\\\\~';
    private const FRAMEWORK_FILES = '~/vendor/(?:symfony/(?:dependency-injection|config|http-kernel|http-foundation|[a-z-]*bundle|[a-z-]*bridge)|doctrine|twig)/~';

    public function testTheRegistryIsBuiltByHandAndNoClassOfAFrameworkIsLoaded(): void
    {
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertContains(Registry::class, $report['symbols'], 'the registry was built there');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
        $installed = array_values(array_filter(array_map(static fn (string $ns) => 'Omnibus\\'.$ns.'\\'.$ns.'GatewayFactory', array_values(require __DIR__.'/../docker/harness/plugins.php')), 'class_exists'));
        foreach ($installed as $factory) {
            self::assertContains($factory, $report['symbols'], 'every carrier package installed, its factory built');
        }
        self::assertCount(\count($installed), $report['factories']);
    }

    public function testAParcelIsPricedAndHandedOverWithNoCarrierAndNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(OfflineGatewayFactory::class)) {
            self::markTestSkipped('omnibus/offline is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertSame(['rate', 'ship', 'track', 'pickup'], $report['gateways']['offline']);
        self::assertSame(['pickup' => 0, 'courier' => 690], array_column($report['offline']['rates'], 'amount', 'service'), 'the band of 500 g, cheapest first');
        self::assertSame(['tracking_number' => '6A12345678901', 'tracking_url' => 'https://www.laposte.fr/outils/suivre-vos-envois?code=6A12345678901', 'has_document' => false], $report['offline']['label']);
        self::assertSame(['shop: La boutique'], $report['offline']['pickup_points']);
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    public function testACarrierAnswersFromRecordedAnswersWithNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(UpsGatewayFactory::class)) {
            self::markTestSkipped('omnibus/ups is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertTrue($report['recorded']);
        self::assertSame(['rate', 'ship', 'track', 'pickup'], $report['gateways']['ups']);
        self::assertSame([
            ['service' => '11', 'label' => 'Standard', 'amount' => 1290, 'currency' => 'EUR', 'days' => null, 'to_pickup_point' => false],
            ['service' => '07', 'label' => 'Worldwide Express', 'amount' => 4210, 'currency' => 'EUR', 'days' => 1, 'to_pickup_point' => false],
        ], $report['ups']['rates'], 'cheapest first, the service named');
        self::assertContains('Symfony\\Component\\HttpClient\\MockHttpClient', $report['symbols'], 'the answers came through the HTTP client given');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    /** The check is not blind: the same report, once the bundle is loaded, names the framework. */
    public function testTheBundleDoesLoadTheFramework(): void
    {
        if (!class_exists(AbstractBundle::class)) {
            self::markTestSkipped('symfony/http-kernel is not installed.');
        }
        [$status, $report] = self::php(['-r', 'require getenv("OMNIBUS_AUTOLOAD"); $autoloaded = get_included_files(); class_exists($argv[1]) || exit(2); echo json_encode(["symbols" => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()], "files" => array_values(array_diff(get_included_files(), $autoloaded))]);', '--', OmnibusBundle::class]);

        self::assertSame(0, $status);
        $framework = self::framework($report);
        self::assertContains(AbstractBundle::class, $framework);
        self::assertNotEmpty(preg_grep('~/symfony/http-kernel/~', $framework));
    }

    /**
     * @param array{symbols: list<string>, files: list<string>} $report
     *
     * @return list<string> the classes, interfaces, traits and files of a framework among those loaded
     */
    private static function framework(array $report): array
    {
        return [...array_values(preg_grep(self::FRAMEWORK, $report['symbols'])), ...array_values(preg_grep(self::FRAMEWORK_FILES, $report['files']))];
    }

    /**
     * Runs PHP apart, on the autoloader of this run.
     *
     * @param list<string> $arguments
     *
     * @return array{int, array<string, mixed>} the exit status, the JSON printed
     */
    private static function php(array $arguments): array
    {
        $autoload = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $process = proc_open([\PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['OMNIBUS_AUTOLOAD' => $autoload] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($out, true);
        self::assertIsArray($report, 'PHP exited '.$status.': '.$err.$out);

        return [$status, $report];
    }
}
