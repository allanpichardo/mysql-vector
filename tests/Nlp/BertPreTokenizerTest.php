<?php

namespace MHz\MysqlVector\Tests\Nlp;

use MHz\MysqlVector\Nlp\BertPreTokenizer;
use PHPUnit\Framework\TestCase;

class BertPreTokenizerTest extends TestCase
{
    public function testPreTokenizeText() {
        $config = []; // Define the configuration if needed
        $tokenizer = new BertPreTokenizer($config);

        $text = "Hello, world! 42.";
        $expectedResult = ["Hello", ",", "world", "!", "42", "."];
        $result = $tokenizer->preTokenizeText($text, []);

        $this->assertEquals($expectedResult, $result);
    }
}
