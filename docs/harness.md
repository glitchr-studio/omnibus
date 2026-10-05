# The Docker harness

`docker/` runs this package with every `omnibus/*` carrier installed - from GitHub (branch 2.x),
or from the checkouts beside this one when `OMNIBUS_PLUGINS=../..` is set in `docker/.env` - and
a console that exercises them with the test keys in `docker/.env`.

```sh
cd docker && cp .env.dist .env       # your carriers' test keys, when you have them
docker compose run --rm omnibus gateways
```

| Command | |
|---|---|
| `gateways` | the carriers installed and configured, what each does |
| `rate <gateway>` | the carrier's prices for a parcel |
| `ship <gateway>` | a shipment booked, its label saved under `docker/labels/` |
| `track <gateway> <number>` | where a parcel is |
| `pickup <gateway>` | pickup points near the recipient |
| `slip <gateway> <number>` | a label fetched again |
| `cancel <gateway> <number>` | a shipment cancelled |
| `bare` | plain PHP: the registry built by hand, a parcel priced and handed over, UPS asked, what PHP loaded |
| `test` | every package's tests |

```sh
docker compose run --rm omnibus rate ups --to-country GB --weight 1200
docker compose run --rm omnibus ship chronopost --service 01
docker compose run --rm omnibus track ups 1Z999AA10123456784
docker compose run --rm omnibus pickup mondial_relay --to-postcode 75009
```

Mondial Relay runs on its public test brand until `MONDIAL_RELAY_ENSEIGNE` is set; Chronopost has
no sandbox, its published test account is in `.env.dist`. Both are test identifiers the carriers
publish for everyone, not secrets.

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the carrier packages installed, asks each gateway that can
be built what it does, prices a parcel of 500 g from Paris to Strasbourg and hands it over
through `omnibus/offline` - the shop's own rates, no network - then asks UPS what the same parcel
would cost: the real Rating API when `UPS_CLIENT_ID`, `UPS_CLIENT_SECRET` and `UPS_ACCOUNT` are
set, or with `--recorded` the answers kept in `docker/harness/recorded/`. Nothing is booked. Then
it lists what PHP loaded and exits 1 if a class of a framework is among it
(`Symfony\Component\DependencyInjection`, `Config`, `HttpKernel`, `HttpFoundation`, a bundle, a
bridge, Doctrine, Twig):

```
$ docker compose run --rm omnibus bare --recorded
Omnibus in bare PHP: the registry built by hand, no bundle, no container.

  offline        rate ship track pickup
  ups            rate ship track pickup

omnibus/offline, 500 g from Paris to Strasbourg (the shop's own rates, no network):
  0.00 EUR (Retrait en boutique), 6.90 EUR (Coursier)
  handed over under 6A12345678901, followed at https://www.laposte.fr/outils/suivre-vos-envois?code=6A12345678901
  collected at shop: La boutique

omnibus/ups, the same parcel, from the answers kept in recorded/:
  12.90 EUR (Standard), 42.10 EUR (Worldwide Express, 1 day)

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, a bridge, Doctrine, Twig): none
```

The answers in `recorded/` were not taken from UPS: they are written in the shape its Rating API
documents, as in omnibus/ups's tests, on credentials that are manifestly not credentials.
`bare --recorded --json` prints the same whole - every class loaded, every file since the
autoloader - without a call: `Tests/BareTest.php` runs it in a process of its own and checks the
list.

The image is `php:8.4-cli-alpine` with Composer; the harness's packages live in the `harness`
volume of the `omnibus-harness` project.
