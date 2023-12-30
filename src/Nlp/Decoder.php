<?php

namespace MHz\MysqlVector\Nlp;

class Decoder
{
    protected array $config;

    public function __construct(array $config = []) {
        $this->config = $config;

        $this->addedTokens = [];
        $this->endOfWordSuffix = null;
        $this->trimOffsets = $config['trim_offsets'] ?? false;
    }

    /**
     * Decode a list of tokens into a string.
     * @param array $tokens The list of tokens to decode.
     * @return string The decoded string.
     * @throws \Exception
     */
    public function decode(array $tokens): string
    {
        $decoded = $this->decodeChain($tokens);
        return join('', $decoded);
    }

    /**
     * Apply the decoder to a list of tokens.
     * @param array $tokens
     * @return array The decoded list of tokens.
     * @throws \Exception
     */
    public function decodeChain(array $tokens): array {
        throw new \Exception('Not implemented');
    }
}