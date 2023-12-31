<?php

namespace MHz\MysqlVector\Tests\Nlp;

use MHz\MysqlVector\Nlp\WordpieceTokenizer;
use PHPUnit\Framework\TestCase;

class WordpieceTokenizerTest extends TestCase
{
    public function testEncode() {
        $config = [
            'vocab' => ['hello' => 1, 'world' => 2, '[UNK]' => 0],
            'max_input_chars_per_word' => 100,
            'continuing_subword_prefix' => '##',
            'unk_token' => '[UNK]'
        ];
        $tokenizer = new WordpieceTokenizer($config);
        $tokens = ['hello', 'world', '!'];

        $expectedResult = ['hello', 'world', '[UNK]']; // Expected result might vary based on actual implementation
        $result = $tokenizer->encode($tokens);

        $this->assertEquals($expectedResult, $result);
    }
}
