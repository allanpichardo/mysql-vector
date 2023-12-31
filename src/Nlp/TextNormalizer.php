<?php

namespace MHz\MysqlVector\Nlp;

class TextNormalizer
{
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Normalize the input text
     * @param string $text The text to normalize
     * @return string The normalized text
     * @throws \Exception If normalize is not implemented in a child class
     */
    public function normalize(string $text): string {
        throw new \Exception("normalize should be implemented in a child class");
    }

    protected function _call(string $text) {
        return $this->normalize($text);
    }
}