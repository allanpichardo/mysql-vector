<?php

use MHz\MysqlVector\Nlp\BertNormalizer;
use PHPUnit\Framework\TestCase;

class BertNormalizerTest extends TestCase {

    public function testTokenizeChineseChars() {
        $normalizer = new BertNormalizer(['handle_chinese_chars' => true]);
        $this->assertEquals('你 好', $normalizer->normalize('你好'));
    }

    public function testStripAccents() {
        $normalizer = new BertNormalizer(['strip_accents' => true]);
        $this->assertEquals('cafe', $normalizer->normalize('café'));
    }

    public function testIsControlCharacter() {
        $normalizer = new BertNormalizer();
        $reflection = new ReflectionClass($normalizer);
        $method = $reflection->getMethod('isControlCharacter');
        $method->setAccessible(true);

        $this->assertFalse($method->invokeArgs($normalizer, [" "]));
        $this->assertFalse($method->invokeArgs($normalizer, ["\n"]));
        $this->assertFalse($method->invokeArgs($normalizer, ["\r"]));
        $this->assertFalse($method->invokeArgs($normalizer, ["\t"]));
        $this->assertTrue($method->invokeArgs($normalizer, ["\x00"]));
    }

    public function testCleanText() {
        $normalizer = new BertNormalizer(['clean_text' => true]);
        $this->assertEquals('text', $normalizer->normalize("te\x00xt"));
    }

    public function testNormalize() {
        $normalizer = new BertNormalizer(['clean_text' => true, 'handle_chinese_chars' => true, 'strip_accents' => true, 'lowercase' => true]);
        $this->assertEquals('hello world, 世 界!', $normalizer->normalize("Hèllo  WOrld, 世界!"));
    }
}