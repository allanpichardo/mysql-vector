<?php

namespace MHz\MysqlVector\Nlp;

class PreTokenizer
{
    public function __construct(array $config= [])
    {
        $this->config = $config;
    }

    /**
     * Method that should be implemented by the child class
     * @param string $text
     * @param array $options
     * @return array
     * @throws \Exception
     */
    public function preTokenizeText(string $text, array $options = []): array {
        throw new \Exception('Not implemented');
    }

    /**
     * Tokenizes the given text into pre-tokens
     * @param string|array $text The text or array of text
     * @param array $options Options for the pre-tokenizer
     * @return array Array of pre-tokens
     * @throws \Exception
     */
    public function preTokenize(string|array $text, array $options = []): array {
        $result = [];
        if(is_array($text)) {
            foreach ($text as $x) {
                $result[] = $this->preTokenizeText($x, $options);
            }
        } else {
            $result = $this->preTokenizeText($text, $options);
        }

        return array_merge(...$result);
    }
}