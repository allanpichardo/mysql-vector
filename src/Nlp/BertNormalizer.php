<?php

namespace MHz\MysqlVector\Nlp;

class BertNormalizer extends TextNormalizer
{
    public function __construct(array $config= [
        'lowercase' => true,
        'strip_accents' => false,
        'clean_text' => true,
        'handle_chinese_chars' => true
    ])
    {
        parent::__construct($config);
    }

    private function tokenizeChineseChars($text) {
        $output = '';
        for ($i = 0; $i < mb_strlen($text); ++$i) {
            $char = mb_substr($text, $i, 1);
            $cp = mb_ord($char);

            if ($this->isChineseChar($cp)) {  // Assuming isChineseChar is a function you define to check Chinese characters
                $output .= " " . $char;
            } else {
                $output .= $char;
            }
        }
        $output = trim($output);
        return preg_replace('/\s+/', ' ', $output);
    }

    private function isChineseChar($cp): bool {
        return (
            ($cp >= 0x4E00 && $cp <= 0x9FFF) ||
            ($cp >= 0x3400 && $cp <= 0x4DBF) ||
            ($cp >= 0x20000 && $cp <= 0x2A6DF) ||
            ($cp >= 0x2A700 && $cp <= 0x2B73F) ||
            ($cp >= 0x2B740 && $cp <= 0x2B81F) ||
            ($cp >= 0x2B820 && $cp <= 0x2CEAF) ||
            ($cp >= 0xF900 && $cp <= 0xFAFF) ||
            ($cp >= 0x2F800 && $cp <= 0x2FA1F)
        );
    }

    private function stripAccents($text): string {
        // Normalize the text to decompose the accents
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_D);

        // Remove the accents using a regex and return the result
        return preg_replace('/\p{Mn}/u', '', $normalized);
    }

    private function isControlCharacter($char) : bool {
        if (in_array($char, ["\t", "\n", "\r"])) {
            // These are control characters but counted as whitespace characters.
            return false;
        }

        // Check if the character is a control/format/private use/surrogate character
        return preg_match('/\p{Cc}|\p{Cf}|\p{Co}|\p{Cs}/u', $char) === 1;
    }

    private function cleanText($text): string {
        $output = '';
        for ($i = 0; $i < mb_strlen($text); ++$i) {
            $char = mb_substr($text, $i, 1);
            $cp = mb_ord($char);

            if ($cp === 0 || $cp === 0xFFFD || $this->isControlCharacter($char)) {
                continue;
            }

            if (preg_match('/^\s$/u', $char)) { // is whitespace
                $output .= " ";
            } else {
                $output .= $char;
            }
        }
        return $output;
    }

    public function normalize($text): string {
        if (!empty($this->config['clean_text'])) {
            $text = $this->cleanText($text);
        }

        if (!empty($this->config['handle_chinese_chars'])) {
            $text = $this->tokenizeChineseChars($text);
        }

        if (!empty($this->config['lowercase'])) {
            $text = strtolower($text);

            if (!empty($this->config['strip_accents'] !== false)) {
                $text = $this->stripAccents($text);
            }
        } elseif (!empty($this->config['strip_accents'])) {
            $text = $this->stripAccents($text);
        }

        return $text;
    }
}