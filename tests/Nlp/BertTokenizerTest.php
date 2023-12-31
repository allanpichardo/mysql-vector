<?php

namespace MHz\MysqlVector\Tests\Nlp;

use MHz\MysqlVector\Nlp\BertTokenizer;
use PHPUnit\Framework\TestCase;

class BertTokenizerTest extends TestCase
{
    private BertTokenizer $tokenizer;

    protected function setUp(): void {
        // Initialize tokenizer with mock data

        // load the file tokenizer.json
        $t = file_get_contents(__DIR__ . '/../../src/Nlp/tokenizer.json');
        $tokenizerJSON = json_decode($t, true);

        // load the file tokenizer_config.json
        $t = file_get_contents(__DIR__ . '/../../src/Nlp/tokenizer_config.json');
        $tokenizerConfig = json_decode($t, true);

        $this->tokenizer = new BertTokenizer($tokenizerJSON, $tokenizerConfig);
    }

    public function testCall() {
        // Test the encode method with sample data
        $text = "Hello how are U tday?";
        $encoded = $this->tokenizer->call($text);
        $decoded = $this->tokenizer->decode($encoded['input_ids']);

        // Assert that the output is as expected
        $this->assertIsArray($encoded['input_ids']);
        $this->assertEquals("[CLS] hello how are u tday? [SEP]", $decoded);
    }
}
