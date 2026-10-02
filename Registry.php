<?php

namespace Omnibus;

use Omnibus\Exception\InvalidConfigException;

/**
 * The shop's carriers by name, each built once from its factory and
 * options:
 *
 *   new Registry([new MondialRelayGatewayFactory($http)], [
 *       'relais' => ['factory' => 'mondial_relay', 'options' => [...]],
 *   ]);
 */
final class Registry
{
    /** @var array<string, GatewayFactoryInterface> */
    private array $factories = [];

    /** @var array<string, GatewayInterface> */
    private array $gateways = [];

    /**
     * @param iterable<GatewayFactoryInterface>                               $factories
     * @param array<string, array{factory: string, options?: array<string, mixed>}> $config
     */
    public function __construct(iterable $factories, private readonly array $config)
    {
        foreach ($factories as $factory) {
            $this->factories[$factory->getName()] = $factory;
        }
    }

    public function get(string $name): GatewayInterface
    {
        if (isset($this->gateways[$name])) {
            return $this->gateways[$name];
        }
        $gateway = $this->config[$name] ?? throw new InvalidConfigException(\sprintf('No "%s" gateway; configured: %s.', $name, implode(', ', array_keys($this->config)) ?: 'none'));
        $factory = $this->factories[$gateway['factory']] ?? throw new InvalidConfigException(\sprintf('No "%s" factory for the "%s" gateway; installed: %s.', $gateway['factory'], $name, implode(', ', array_keys($this->factories)) ?: 'none'));

        return $this->gateways[$name] = $factory->create($gateway['options'] ?? []);
    }

    public function has(string $name): bool
    {
        return isset($this->config[$name]);
    }

    /** @return array<string, mixed> the options a gateway is configured with */
    public function options(string $name): array
    {
        return $this->config[$name]['options'] ?? [];
    }

    /**
     * A gateway built afresh, its configured options with $overrides over
     * them - keys typed in a back office, say. Not kept: get() still gives
     * the configured one.
     *
     * @param array<string, mixed> $overrides
     */
    public function create(string $name, array $overrides = []): GatewayInterface
    {
        $gateway = $this->config[$name] ?? throw new InvalidConfigException(\sprintf('No "%s" gateway; configured: %s.', $name, implode(', ', array_keys($this->config)) ?: 'none'));
        $factory = $this->factories[$gateway['factory']] ?? throw new InvalidConfigException(\sprintf('No "%s" factory for the "%s" gateway; installed: %s.', $gateway['factory'], $name, implode(', ', array_keys($this->factories)) ?: 'none'));

        return $factory->create(array_replace($gateway['options'] ?? [], $overrides));
    }

    /** @return list<string> the configured gateways' names */
    public function names(): array
    {
        return array_keys($this->config);
    }

    /** @return array<string, GatewayInterface> */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->config) as $name) {
            $all[$name] = $this->get($name);
        }

        return $all;
    }
}
