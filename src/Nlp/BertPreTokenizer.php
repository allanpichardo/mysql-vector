<?php

namespace MHz\MysqlVector\Nlp;

define("PUNCTUATION_REGEX", '\\p{P}\\x{0021}-\\x{002F}\\x{003A}-\\x{0040}\\x{005B}-\\x{0060}\\x{007B}-\\x{007E}');


class BertPreTokenizer extends PreTokenizer
{
    private $pattern;

    public function __construct(array $config= [])
    {
        parent::__construct($config);

        $this->pattern = "/[^\\s" . PUNCTUATION_REGEX . "]+|[" . PUNCTUATION_REGEX . "]/u";
    }

    /**
     * Tokenizes a single text using the BERT pre-tokenizer
     * @param string $text
     * @param array $options
     * @return array
     */
    public function preTokenizeText(string $text, array $options = []): array
    {
        $text = trim($text);
        preg_match_all($this->pattern, $text, $matches);

        return $matches[0] ?? [];
    }
}