<?php

namespace MHz\MysqlVector\Tests;

use MHz\MysqlVector\VectorTable;
use PHPUnit\Framework\TestCase;

class VectorTableTest extends TestCase
{
    private $vectorTable;
    private $dimension = 384;
    private $testVectorAmount = 1000;

    protected function setUp(): void
    {
        VectorTableTest::tearDownAfterClass();

        $mysqli = new \mysqli('localhost', 'root', '', 'mysql-vector');

        // Check connection
        if ($mysqli->connect_error) {
            die("Connection failed: " . $mysqli->connect_error);
        }

        // Setup VectorTable for testing
        $this->vectorTable = new VectorTable($mysqli, 'test_table', $this->dimension);

        // Create required tables for testing
        $this->vectorTable->initialize();
    }

    private function getRandomVectors($count, $dimension) {
        $vecs = [];
        for ($i = 0; $i < $count; $i++) {
            for($j = 0; $j < $dimension; $j++) {
                $vecs[$i][$j] = 2 * (mt_rand(0, 1000) / 1000) - 1;
            }
        }
        return $vecs;
    }

    public function testGetVectorTableName()
    {
        $tableName = $this->vectorTable->getVectorTableName();
        $this->assertEquals('test_table_vectors', $tableName);
    }

    public function testUpsertSingle() {
        $this->vectorTable->getConnection()->begin_transaction();

        $vecs = $this->getRandomVectors(1, $this->dimension);

        $ids = [];

        echo "Inserting 1 vector...\n";
        $time = microtime(true);
        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($vec);
        }
        $time = microtime(true) - $time;
        echo "Elapsed time: " . sprintf("%.2f", $time) . " seconds\n";

        $this->assertEquals(count($vecs), $this->vectorTable->count());
        $this->vectorTable->getConnection()->rollback();
    }

    public function testUpsert() {
        $this->vectorTable->getConnection()->begin_transaction();

        $lastId = 0;
        $vecArray = [];
        echo "Inserting $this->testVectorAmount vectors one-at-a-time...\n";
        $time = microtime(true);
        for($i = 0; $i < $this->testVectorAmount; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $lastId = $this->vectorTable->upsert($vec);
            $vecArray[] = $vec;
        }

        $time = microtime(true) - $time;
        echo "Elapsed time: " . sprintf("%.2f", $time) . " seconds\n";

        $this->assertEquals($this->testVectorAmount, count($this->vectorTable->selectAll()));

        $secondConnection = new \mysqli('localhost', 'root', '', 'mysql-vector');

        echo "Inserting $this->testVectorAmount vectors in a batch...\n";
        $time = microtime(true);
        $this->vectorTable->batchInsert($secondConnection, $vecArray);

        $time = microtime(true) - $time;
        echo "Elapsed time: " . sprintf("%.2f", $time) . " seconds\n";

        $this->assertEquals($this->testVectorAmount, $this->vectorTable->count());

        $id = $lastId;
        $newVec = $this->getRandomVectors(1, $this->dimension)[0];
        $this->vectorTable->upsert($newVec, $id);
        $r = $this->vectorTable->select([$id]);
        $this->assertCount(1, $r);
        $this->assertEqualsWithDelta($newVec, $r[0]['vector'], 0.00001);

        $this->vectorTable->getConnection()->rollback();
    }

    public function testCosim() {
        $this->vectorTable->getConnection()->begin_transaction();

        $vecs = $this->getRandomVectors(2, $this->dimension);
        $dotProduct = 0;
        for ($i = 0; $i < count($vecs[0]); $i++) {
            $dotProduct += $vecs[0][$i] * $vecs[1][$i];
        }

        $this->assertEqualsWithDelta($dotProduct, $this->vectorTable->cosim($vecs[0], $vecs[1]), 0.0001);
    }

    public function testSelectAll() {
        $this->vectorTable->getConnection()->begin_transaction();

        $vecs = $this->getRandomVectors(10, $this->dimension);
        foreach ($vecs as $vec) {
            $this->vectorTable->upsert($vec);
        }

        $results = $this->vectorTable->selectAll();
        $this->assertSameSize($vecs, $results);

        $i = 0;
        foreach ($results as $result) {
            $this->assertEqualsWithDelta($vecs[$i], $result['vector'], 0.00001);
            $i++;
        }

        $this->vectorTable->getConnection()->rollback();
    }

    public function testSearch() {
        $this->vectorTable->getConnection()->begin_transaction();

        // Insert $this->testVectorAmount random vectors
        $conn = new \mysqli('localhost', 'root', '', 'mysql-vector');
        $conn->begin_transaction();
        $vecs = $this->getRandomVectors($this->testVectorAmount, $this->dimension);
        $this->vectorTable->batchInsert($conn, $vecs);
        $conn->commit();
        $conn->close();

        // Let's insert a known vector
        $targetVector = array_fill(0, $this->dimension, 0.5);
        $this->vectorTable->upsert($targetVector);

        // Now, we search for this vector
        echo "Searching for 1 vector among $this->testVectorAmount with binary quantization...\n";
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 100);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        echo sprintf("Search completed in %.2f seconds\n", $time);

        // At least the first result should be our target vector or very close
        $firstResultVector = $results[0]['vector'];
        $firstResultSimilarity = $results[0]['similarity'];

        $this->assertEqualsWithDelta($targetVector, $firstResultVector, 0.00001, "The most similar vector should be the target vector itself");
        $this->assertEqualsWithDelta(1.0, $firstResultSimilarity, 0.001, "The similarity of the most similar vector should be the highest possible value");

        $this->vectorTable->getConnection()->rollback();
    }

    public function testDelete(): void {
        $this->vectorTable->getConnection()->begin_transaction();

        $ids = [];
        $vecs = $this->getRandomVectors(10, $this->dimension);
        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($vec);
        }

        $this->assertEquals(count($ids), $this->vectorTable->count());

        foreach ($ids as $id) {
            $this->vectorTable->delete($id);
        }

        $this->assertEquals(0, $this->vectorTable->count());

        $this->vectorTable->getConnection()->rollback();
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up the database and close connection
        $mysqli = new \mysqli('localhost', 'root', '', 'mysql-vector');
        $vectorTable = new VectorTable($mysqli, 'test_table', 3);
        $mysqli->query("DROP TABLE IF EXISTS " . $vectorTable->getVectorTableName());
        $mysqli->query("DROP FUNCTION IF EXISTS COSIM");
        $mysqli->close();
    }

    protected function tearDown(): void
    {
        // Clean up the database and close connection
        $this->vectorTable->getConnection()->query("DROP TABLE IF EXISTS " . $this->vectorTable->getVectorTableName());
        $this->vectorTable->getConnection()->query("DROP FUNCTION IF EXISTS COSIM");
        $this->vectorTable->getConnection()->close();
    }

}
