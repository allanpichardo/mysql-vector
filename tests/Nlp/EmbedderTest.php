<?php

namespace MHz\MysqlVector\Tests\Nlp;

use MHz\MysqlVector\Nlp\Embedder;
use PHPUnit\Framework\TestCase;

class EmbedderTest extends TestCase
{
    public function testEmbed() {
        $embedder = new Embedder();
        $embeddings = $embedder->embed(['Hello world!', 'This is a test.', 'Hi world', 'Snow is white.', 'Hello world']);
        $this->assertIsArray($embeddings);
        $this->assertCount(5, $embeddings);
        $this->assertCount($embedder->getDimensions(), $embeddings[0][0]);

        $this->assertGreaterThan(0.99, $embedder->getCosineSimilarity($embeddings[0][0], $embeddings[0][0]));
        $this->assertGreaterThan(0.89, $embedder->getCosineSimilarity($embeddings[0][0], $embeddings[4][0]));
        $this->assertLessThan(0.6, $embedder->getCosineSimilarity($embeddings[0][0], $embeddings[1][0]));
        $this->assertGreaterThan(0.7, $embedder->getCosineSimilarity($embeddings[0][0], $embeddings[2][0]));
        $this->assertLessThan(0.6, $embedder->getCosineSimilarity($embeddings[0][0], $embeddings[3][0]));
    }
}
