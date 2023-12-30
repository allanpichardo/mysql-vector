<?php

namespace MHz\MysqlVector\Nlp;

use MHz\MysqlVector\Nlp\Decoder;

class WordPieceDecoder extends Decoder
{
    protected bool $cleanup;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->cleanup = $config['cleanup'] ?? true;
    }

    /**
     * Clean up a list of simple English tokenization artifacts.
     * @param $text
     * @return string
     */
    private function cleanUpTokenization($text): string {
        $text = preg_replace('/ \./', '.', $text);
        $text = preg_replace('/ \?/', '?', $text);
        $text = preg_replace('/ \!/', '!', $text);
        $text = preg_replace('/ ,/', ',', $text);
        $text = preg_replace('/ \' /', "'", $text);
        $text = preg_replace('/ n\'t/', "n't", $text);
        $text = preg_replace('/ \'m/', "'m", $text);
        $text = preg_replace('/ \'s/', "'s", $text);
        $text = preg_replace('/ \'ve/', "'ve", $text);
        $text = preg_replace('/ \'re/', "'re", $text);

        return $text;
    }

    public function decodeChain($tokens): array {
        return array_map(function($token, $i) {
            if ($i !== 0) {
                if (str_starts_with($token, $this->config['prefix'])) {
                    // Replace only the first occurrence of prefix
                    $token = substr_replace($token, '', 0, strlen($this->config['prefix']));
                } else {
                    $token = ' ' . $token;
                }
            }
            if ($this->cleanup) {
                $token = $this->cleanUpTokenization($token); // Assume cleanUpTokenization is a method in this class
            }

            return $token;
        }, $tokens, array_keys($tokens));
    }
}