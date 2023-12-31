<?php

namespace MHz\MysqlVector\Tests\Nlp;

use MHz\MysqlVector\Nlp\TokenizerModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class TokenizerModelTest extends TestCase
{
    public function testConvertTokensToIds() {
        $config = [
            'vocab' => ['hello' => 1, 'world' => 2, '[UNK]' => 0],
            'unk_token' => '[UNK]',
            'unk_token_id' => 0,
            'fuse_unk' => false
        ];
        $tokenizer = new TokenizerModel($config);
        $tokens = ['hello', 'world', 'unknown'];

        $expectedResult = [1, 2, 0];
        $result = $tokenizer->convertTokensToIds($tokens);

        $this->assertEquals($expectedResult, $result);
    }

    public function testConvertIdsToTokens() {
        $config = [
            'vocab' => ['hello' => 1, 'world' => 2, '[UNK]' => 0],
            'unk_token' => '[UNK]',
            'unk_token_id' => 0,
            'fuse_unk' => false
        ];
        $tokenizer = new TokenizerModel($config);
        $ids = [1, 2, 0];

        $expectedResult = ['hello', 'world', '[UNK]'];
        $result = $tokenizer->convertIdsToTokens($ids);

        $this->assertEquals($expectedResult, $result);
    }

    public function testFuse() {
        $config = [
            'vocab' => ['hello' => 1, 'world' => 2, '[UNK]' => 0],
            'unk_token' => '[UNK]',
            'unk_token_id' => 0,
            'fuse_unk' => false
        ];
        $tokenizer = new TokenizerModel($config);
        $reflection = new ReflectionClass($tokenizer);
        $method = $reflection->getMethod('fuse');
        $method->setAccessible(true);

        $arr = [0, 0, 1, 0, 0];
        $expectedResult = [0, 1, 0];
        $result = $method->invokeArgs($tokenizer, [$arr, 0]);

        $this->assertEquals($expectedResult, $result);
    }
}
