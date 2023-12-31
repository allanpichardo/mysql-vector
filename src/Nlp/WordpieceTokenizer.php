<?php

namespace MHz\MysqlVector\Nlp;

use MHz\MysqlVector\Nlp\TokenizerModel;

class WordpieceTokenizer extends TokenizerModel
{
    public function __construct(array $config = []) {
        parent::__construct($config);
    }

    /**
     * Encodes an array of tokens using WordPiece encoding.
     * @param array $tokens The input tokens to encode.
     * @return array The encoded tokens.
     */
    public function encode(array $tokens): array {
        $outputTokens = [];
        foreach ($tokens as $token) {
            $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
            if (count($chars) > $this->maxInputCharsPerWord) {
                $outputTokens[] = $this->unkToken;
                continue;
            }

            $isUnknown = false;
            $start = 0;
            $subTokens = [];

            while ($start < count($chars)) {
                $end = count($chars);
                $currentSubstring = null;
                while ($start < $end) {
                    $substr = implode('', array_slice($chars, $start, $end - $start));

                    if ($start > 0) {
                        $substr = $this->config['continuing_subword_prefix'] . $substr;
                    }
                    if (isset($this->tokensToIds[$substr])) {
                        $currentSubstring = $substr;
                        break;
                    }

                    --$end;
                }
                if ($currentSubstring === null) {
                    $isUnknown = true;
                    break;
                }
                $subTokens[] = $currentSubstring;
                $start = $end;
            }
            if ($isUnknown) {
                $outputTokens[] = $this->unkToken;
            } else {
                $outputTokens = array_merge($outputTokens, $subTokens);
            }
        }

        return $outputTokens;
    }
}