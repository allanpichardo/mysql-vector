<?php

namespace MHz\MysqlVector\Nlp;

class PostProcessor
{
    private array $config;

    public function __construct(array $config = []) {
        $this->config = $config;
    }

    public function postProcess(array $tokens, ...$args): array
    {
        throw new \Exception('Not implemented');
    }
}