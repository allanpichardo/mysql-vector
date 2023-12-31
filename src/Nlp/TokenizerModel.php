<?php

namespace MHz\MysqlVector\Nlp;

class TokenizerModel
{
    protected array $config;

    /**
     * @var array<string>
     */
    public array $vocab;

    /**
     * A map from tokens to their ids.
     * @var array<string, int>
     */
    public array $tokensToIds;
    protected $unkTokenId;
    protected $unkToken;
    public $endOfWordSuffix;

    /**
     * Whether to fuse unknown tokens when encoding. Defaults to false.
     * @var bool
     */
    protected mixed $fuseUnk;
    /**
     * @var int
     */
    protected mixed $maxInputCharsPerWord;

    public function __construct(array $config = []) {
        $this->config = $config;

        $this->endOfWordSuffix = null;
        $this->fuseUnk = $config['fuse_unk'] ?? false;

        $this->tokensToIds = $this->objectToMap($config['vocab']);
        $this->unkTokenId = $this->tokensToIds[$config['unk_token']];
        $this->unkToken = $config['unk_token'];
        $this->maxInputCharsPerWord = $config['max_input_chars_per_word'] ?? 100;

        /**
         * An array of tokens.
         * @var array<string> $vocab
         */
        $this->vocab = [];
        foreach ($this->tokensToIds as $key => $value) {
            $this->vocab[$value] = $key;
        }
    }

    /**
     * Encodes a list of tokens into a list of token IDs.
     * @param array $tokens
     * @return array
     * @throws \Exception
     */
    public function encode(array $tokens): array {
        throw new \Exception('Not implemented');
    }

    /**
     * Converts a list of tokens to a list of token IDs.
     * @param array $tokens The list of tokens to convert.
     * @return array The converted list of token IDs.
     */
    public function convertTokensToIds(array $tokens): array {
        $ids = [];
        foreach ($tokens as $token) {
            $ids[] = $this->tokensToIds[$token] ?? $this->unkTokenId;
        }

        if($this->fuseUnk) {
            // Fuse unknown tokens.
            $ids = $this->fuse($ids, $this->unkTokenId);
        }

        return $ids;
    }

    /**
     * Helper function to fuse consecutive values in an array equal to the specified value.
     * @param array $arr The array to fuse.
     * @param mixed $value The value to fuse on.
     * @return array
     */
    protected function fuse(array $arr, mixed $value): array {
        $fused = [];
        $i = 0;
        $length = count($arr);

        while ($i < $length) {
            $fused[] = $arr[$i];
            if ($arr[$i] !== $value) {
                ++$i;
                continue;
            }

            while ($i < $length && $arr[$i] === $value) {
                ++$i;
            }
        }

        return $fused;
    }

    /**
     * Converts a list of token IDs to a list of tokens.
     * @param array $ids The list of token IDs to convert.
     * @return array The converted list of tokens.
     */
    public function convertIdsToTokens(array $ids): array {
        $tokens = [];
        foreach ($ids as $id) {
            $tokens[] = $this->vocab[$id] ?? $this->unkToken;
        }

        return $tokens;
    }

    protected function objectToMap($object): array {
        $map = [];
        foreach ($object as $key => $value) {
            $map[$key] = $value;
        }

        return $map;
    }
}