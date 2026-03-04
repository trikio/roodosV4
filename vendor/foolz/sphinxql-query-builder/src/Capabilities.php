<?php

namespace Foolz\SphinxQL;

/**
 * Describes detected engine/runtime capabilities.
 */
class Capabilities
{
    /**
     * @var string
     */
    private string $engine;

    /**
     * @var string
     */
    private string $version;

    /**
     * @var array<string,bool>
     */
    private array $features;

    /**
     * @param string            $engine
     * @param string            $version
     * @param array<string,bool> $features
     */
    public function __construct(string $engine, string $version, array $features)
    {
        $this->engine = strtoupper($engine);
        $this->version = $version;
        $this->features = $features;
    }

    /**
     * @return string
     */
    public function getEngine(): string
    {
        return $this->engine;
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @return array<string,bool>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * @return bool
     */
    public function isManticore(): bool
    {
        return $this->engine === 'MANTICORE';
    }

    /**
     * @return bool
     */
    public function isSphinx2(): bool
    {
        return $this->engine === 'SPHINX2';
    }

    /**
     * @return bool
     */
    public function isSphinx3(): bool
    {
        return $this->engine === 'SPHINX3';
    }

    /**
     * @param string $feature
     *
     * @return bool
     */
    public function supports(string $feature): bool
    {
        return !empty($this->features[$feature]);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'engine' => $this->engine,
            'version' => $this->version,
            'features' => $this->features,
        );
    }
}
