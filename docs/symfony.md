# Symfony

Omnibus runs without a framework ([installation](installation.md)); in a Symfony application its
bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` - are not required by `glitchr/omnibus`: the application has them, and
nothing of them is loaded outside Symfony.

Register `Omnibus\Bridge\Symfony\OmnibusBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnibus\Bridge\Symfony\OmnibusBundle::class => ['all' => true],
];
```

```yaml
# config/packages/omnibus.yaml
omnibus:
    gateways:                      # by name: a factory and its options
        relais:
            factory: mondial_relay
            options: { enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%', private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%' }
        retrait:
            factory: offline
```

```sh
# .env.local, or bin/console secrets:set
MONDIAL_RELAY_ENSEIGNE=...
MONDIAL_RELAY_PRIVATE_KEY=...
```

Every `omnibus/*` package installed registers its factory, on the application's `http_client`.
What is autowired:

| Service | |
|---|---|
| `GatewayInterface $relais` | one gateway by the argument's name (the configured name) |
| `Registry` | every configured gateway by name (`get()`, `has()`, `names()`, `create()` with other options) |

```php
public function __construct(private readonly GatewayInterface $relais)
{
}
```

Nothing is built when the container compiles: a gateway is built the first time it is asked
for, and an option left empty only shows then (`InvalidConfigException`). Keys typed in a back
office rather than set in the environment: `$registry->create('relais', ['private_key' => $stored])`.

An application's own carrier - a class implementing `GatewayFactoryInterface` - is registered
too, autoconfigured, and can be named as a `factory`.
