<?php

namespace MHz\MysqlVector\Tests\Nlp;

use MHz\MysqlVector\Nlp\WordPieceDecoder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WordPieceDecoderTest extends TestCase
{
    public function testCleanUpTokenization() {
        $decoder = new WordPieceDecoder();
        $reflection = new ReflectionClass($decoder);
        $method = $reflection->getMethod('cleanUpTokenization');
        $method->setAccessible(true);

        $this->assertEquals("Hello world!", $method->invokeArgs($decoder, ["Hello world !"]));
    }

    public function testDecodeChain() {
        $config = ['prefix' => '##', 'cleanup' => true];
        $decoder = new WordPieceDecoder($config);
        $tokens = ["Hello", "##world", "!"];

        $this->assertEquals(["Hello", "world", "!"], $decoder->decodeChain($tokens));
    }
}
