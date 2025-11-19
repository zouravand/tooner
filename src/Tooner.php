<?php

namespace Tedon\Tooner;

use Tedon\Tooner\Exceptions\ToonEncodingException;
use Tedon\Tooner\Exceptions\ToonDecodingException;

class Tooner
{
    protected ToonDecoder $decoder;
    protected ToonEncoder $encoder;
    public function __construct(protected array $config = [])
    {
    }

    public function getDecoder(): ToonDecoder
    {
        if(!isset($this->decoder)) {
            $this->decoder = new ToonDecoder($this->config);
        }
        return $this->decoder->setConfig($this->config);
    }

    public function getEncoder(): ToonEncoder
    {
        if(!isset($this->encoder)) {
            $this->encoder = new ToonEncoder($this->config);
        }
        return $this->encoder->setConfig($this->config);
    }

    /**
     * @param mixed $value
     * @param array $options
     * @return string
     * @throws ToonEncodingException
     */
    public function encode(mixed $value, array $options = []): string
    {
        return $this->getEncoder()->encode($value, $options);
    }

    /**
     * @param string $value
     * @param array $options
     * @return string
     * @throws ToonDecodingException
     */
    public function decode(string $value, array $options = []): mixed
    {
        return $this->getDecoder()->decode($value, $options);
    }

    public function getConfig($key = null)
    {
        if ($key) {
            return $this->config[$key] ?? null;
        }
        return $this->config;
    }
}