# glitchr/omnibus

One contract for parcel carriers - rates, labels, tracking, pickup points - the Payum of shipping.

```php
$gateway = $registry->get('relais');
$gateway->rate($shipment);                 // Rate[]
$gateway->ship($shipment);                 // Label: tracking number, PDF
$gateway->track('12345678');               // Tracking: status, events
$gateway->pickupPoints($address, 10);      // PickupPoint[]
```

This package holds the contract (`GatewayInterface`, `GatewayFactory`, `Registry`), the models
(`Shipment`, `Parcel`, `Address`, `Rate`, `Label`, `Tracking`, `PickupPoint`...), the requests and a
bridge for Symfony. It needs no framework: it requires nothing but `symfony/http-client-contracts`; a
carrier that calls an API requires `symfony/http-client`, `omnibus/offline` neither. Each carrier is a
package of its own:

| Package | Carrier |
|---|---|
| `omnibus/offline` | No carrier: the shop's own rates, a tracking number typed by hand, click & collect |
| `omnibus/mondial-relay` | Mondial Relay: relay points, labels, tracking (no ext-soap needed) |
| `omnibus/colissimo` | Colissimo (La Poste): rates from configuration, web services to come |
| `omnibus/chronopost` | Chronopost: Quickcost prices, skybills, tracking, Pickup relays (SOAP) |
| `omnibus/ups` | UPS: rates, labels, tracking, Access Points (OAuth2 REST) |
| `omnibus/fedex` | FedEx: rate quotes, labels, tracking, locations (OAuth2 REST) |
| `omnibus/dhl` | DHL Express: rates, labels, tracking (MyDHL API), service points (Location Finder) |
| `omnibus/tnt` | TNT: prices, consignments, tracking (ExpressConnect, existing accounts) |
| `omnibus/gls` | GLS: shipments and labels (ShipIT), cancellation, public tracking; configured rates |
| `omnibus/db-schenker` | DB Schenker: public tracking; bookings (Open API, unverified); configured rates |
| `omnibus/usps` | USPS: prices, domestic labels, tracking, locations (APIs v3) |
| `omnibus/royal-mail` | Royal Mail: Click & Drop orders and labels, Tracking API; configured rates |
| `omnibus/canada-post` | Canada Post: rates, (non-)contract shipments, tracking, post offices (XML REST) |
| `omnibus/purolator` | Purolator: estimates, shipments and documents, tracking, locations, voids (SOAP) |
| `omnibus/canpar` | Canpar: rates, shipments and labels, tracking, voids (CanShip SOAP) |
| `omnibus/auspost` | Australia Post: prices, shipments and labels, tracking, cancellation |
| `omnibus/aramex` | Aramex: rates, shipments and labels, tracking, offices (JSON web services) |
| `omnibus/bluedart` | Blue Dart: transit times, waybills and labels, tracking, cancellation (unverified) |
| `omnibus/dtdc` | DTDC: consignments and labels, tracking, cancellation; configured rates (unverified) |
| `omnibus/sf-express` | SF Express: orders, cloud-print labels, routes, cancellation; configured rates (unverified) |
| `omnibus/jdl-express` | JD Logistics: orders, labels, traces, cancellation; configured rates (unverified) |
| `omnibus/zto-express` | ZTO Express: orders with electronic waybills, traces, cancellation; configured rates (unverified) |
| `omnibus/amazon` | Amazon Shipping: rates, purchased shipments and labels, tracking, cancellation (SP-API) |

A carrier's factory fills a `Config` - its name, options, API client and actions - and the gateway runs
the actions that support each request. A `rates` option always adds prices from configuration
(`Action\ConfiguredRatingAction`) when the carrier has no rating service.

## Plain PHP

```sh
composer require glitchr/omnibus omnibus/offline
```

```php
require __DIR__.'/vendor/autoload.php';

use Omnibus\Model\{Address, Parcel, Shipment};
use Omnibus\Offline\OfflineGatewayFactory;
use Omnibus\Registry;

$registry = new Registry([new OfflineGatewayFactory()], [
    'boutique' => ['factory' => 'offline', 'options' => ['rates' => [['service' => 'courier', 'label' => 'Coursier', 'bands' => [1000 => 690, 30000 => 1290]]]]],
]);
$gateway = $registry->get('boutique');

$shipment = new Shipment(
    new Address('La boutique', ['1 rue de Rivoli'], '75001', 'Paris', 'FR'),
    new Address('Camille Durand', ['12 rue des Juifs'], '67000', 'Strasbourg', 'FR'),
    [new Parcel(500)],
    options: ['tracking_number' => '6A12345678901'],
);
echo $gateway->rate($shipment)[0]->amount, "\n";          // 690: cents, for 500 g
echo $gateway->ship($shipment)->trackingNumber, "\n";     // 6A12345678901
```

A carrier that calls an API takes the HTTP client to call with - the application's, a
`MockHttpClient` in a test - and makes its own when given none:
`new Registry([new UpsGatewayFactory($http)], ['ups' => ['factory' => 'ups', 'options' => [...]]])`.
A whole script that runs as it is, and the rest: [docs/installation.md](docs/installation.md).
No class of a framework is loaded on the way: `Tests/BareTest.php` checks it in a process of its
own, and so does `docker compose run --rm omnibus bare` ([docs/harness.md](docs/harness.md)).

## Symfony

In a Symfony application a bundle does the wiring; its components (`symfony/config`,
`symfony/dependency-injection`, `symfony/http-kernel`) are not required by this package: the
application has them ([docs/symfony.md](docs/symfony.md)).
`Omnibus\Bridge\Symfony\OmnibusBundle`: every `omnibus/*` carrier installed registered, `Omnibus\Registry`
autowired, and each configured gateway injectable by its name.

```yaml
omnibus:
    gateways:
        relais:
            factory: mondial_relay
            options: { enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%', private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%' }
        retrait:
            factory: offline
```

```php
public function __construct(GatewayInterface $relais) {}
```

An application's own `GatewayFactoryInterface` is registered too (autoconfigured).

## Docker: every carrier with your test keys

`docker/` runs this package with every `omnibus/*` carrier installed - from GitHub, or from the
checkouts beside this one when `OMNIBUS_PLUGINS=../..` is set - and a console that exercises them
with the keys in `docker/.env` (copy `.env.dist`; `docker compose run --rm omnibus gateways` says
which carriers are configured and what each one does):

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnibus gateways
docker compose run --rm omnibus rate ups --to-country GB --weight 1200
docker compose run --rm omnibus ship chronopost --service 01      # the label lands in docker/labels/
docker compose run --rm omnibus track ups 1Z999AA10123456784
docker compose run --rm omnibus pickup mondial_relay --to-postcode 75009
docker compose run --rm omnibus bare --recorded                    # plain PHP: no bundle, no container, and what PHP loaded
docker compose run --rm omnibus test                               # every package's tests
```

## Documentation

- [Installation and first calls](docs/installation.md): plain PHP first
- [Symfony](docs/symfony.md)
- [The Docker harness](docs/harness.md)

License: LGPL-3.0-or-later.
