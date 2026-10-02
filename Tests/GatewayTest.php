<?php

namespace Omnibus\Tests;

use Omnibus\Action\ActionInterface;
use Omnibus\Config;
use Omnibus\Exception\InvalidConfigException;
use Omnibus\Exception\RequestNotSupportedException;
use Omnibus\GatewayFactory;
use Omnibus\Model\Label;
use Omnibus\Registry;
use Omnibus\Request\Cancel;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;
use PHPUnit\Framework\TestCase;

final class GatewayTest extends TestCase
{
    public function testTheActionSupportingARequestAnswersIt(): void
    {
        $gateway = (new StubFactory())->create(['token' => 't']);

        $label = $gateway->ship(Fixtures::shipment());

        self::assertSame('stub', $label->carrier);
        self::assertSame('CMD-1042', $label->trackingNumber);
        self::assertTrue($gateway->supports(Shipping::class));
        self::assertFalse($gateway->supports(Cancel::class));
    }

    public function testAnUnsupportedRequestSaysWhichGatewayAndWhat(): void
    {
        $gateway = (new StubFactory())->create(['token' => 't']);

        $this->expectException(RequestNotSupportedException::class);
        $this->expectExceptionMessage('The "stub" gateway does not support Cancel.');
        $gateway->cancel('X');
    }

    public function testRequiredOptionsAreChecked(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "stub" gateway needs: token.');
        (new StubFactory())->create();
    }

    public function testConfiguredRatesPricePerParcelCheapestFirst(): void
    {
        $gateway = (new StubFactory())->create(['token' => 't', 'rates' => [
            ['service' => 'home', 'label' => 'Domicile', 'bands' => [1000 => 690, 5000 => 990], 'days' => 2],
            ['service' => 'relay', 'label' => 'Point relais', 'bands' => [500 => 390, 1000 => 450, 3000 => 590], 'to_pickup_point' => true],
            ['service' => 'belgium', 'bands' => [30000 => 1500], 'countries' => ['BE']],
        ]]);

        $rates = $gateway->rate(Fixtures::shipment(800, 2500));

        self::assertSame(['relay', 'home'], array_map(static fn ($r) => $r->service, $rates));
        self::assertSame(450 + 590, $rates[0]->amount);
        self::assertTrue($rates[0]->toPickupPoint);
        self::assertSame(690 + 990, $rates[1]->amount);
        self::assertSame(2, $rates[1]->days);
        self::assertTrue($gateway->supports(Rating::class));
    }

    public function testAParcelBeyondTheLastBandGetsNoRate(): void
    {
        $gateway = (new StubFactory())->create(['token' => 't', 'rates' => [['service' => 'relay', 'bands' => [1000 => 450]]]]);

        self::assertSame([], $gateway->rate(Fixtures::shipment(1200)));
    }

    public function testTheRegistryBuildsEachGatewayOnceByName(): void
    {
        $registry = new Registry([new StubFactory()], ['boutique' => ['factory' => 'stub', 'options' => ['token' => 't']]]);

        self::assertSame($registry->get('boutique'), $registry->get('boutique'));
        self::assertTrue($registry->has('boutique'));
        self::assertSame(['token' => 't'], $registry->options('boutique'));
        self::assertNotSame($registry->get('boutique'), $registry->create('boutique', ['token' => 'u']), 'with overrides: a fresh gateway');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No "entrepot" gateway; configured: boutique.');
        $registry->get('entrepot');
    }
}

final class StubFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'stub',
            'omnibus.factory_title' => 'Stub',
            'omnibus.required_options' => ['token'],
            'omnibus.action.shipping' => new class implements ActionInterface {
                public function supports(Request $request): bool
                {
                    return $request instanceof Shipping;
                }

                public function execute(Request $request): void
                {
                    $request->setResult(new Label('stub', (string) $request->shipment->reference));
                }
            },
        ]);
    }
}
