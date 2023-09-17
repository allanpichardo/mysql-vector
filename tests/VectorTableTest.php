<?php

namespace MHz\MysqlVector\Tests;

use MHz\MysqlVector\VectorTable;
use PHPUnit\Framework\TestCase;

class VectorTableTest extends TestCase
{
    private $mysqli;
    private $vectorTable;

    protected function setUp(): void
    {
        $this->mysqli = new \mysqli('localhost', 'root', '', 'mysql-vector');

        // Check connection
        if ($this->mysqli->connect_error) {
            die("Connection failed: " . $this->mysqli->connect_error);
        }

        // Setup VectorTable for testing
        $this->vectorTable = new VectorTable('test_table', 3);

        // Create required tables for testing
        foreach ($this->vectorTable->getCreateStatements() as $statement) {
            $this->mysqli->query($statement);
        }
    }

    public function testGetMetaTableName()
    {
        $metaTableName = $this->vectorTable->getMetaTableName();
        $this->assertEquals('vector_meta_test_table', $metaTableName);
    }

    public function testGetValuesTableName()
    {
        $valuesTableName = $this->vectorTable->getValuesTableName();
        $this->assertEquals('vector_values_test_table', $valuesTableName);
    }

    public function testUpsert() {
        $this->mysqli->begin_transaction();

        $vecs = [
            [1.0, 2.0, 3.0],
            [4.0, 5.0, 6.0],
            [7.0, 8.0, 9.0]
        ];

        $ids = [];

        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($this->mysqli, $vec);
        }

        $this->assertEquals(3, $this->vectorTable->count($this->mysqli));

        $results = $this->vectorTable->select($this->mysqli, $ids);
        $i = 0;
        foreach ($results as $result) {
            $this->assertEquals($vecs[$i], $result);
            $i++;
        }

        foreach ($results as $id => $result) {
            $this->vectorTable->upsert($this->mysqli, [9, 8, 7], $id);
            $results = $this->vectorTable->select($this->mysqli, [$id]);
            $this->assertEquals(1, count($results));
            $this->assertEquals([9, 8, 7], $results[$id]);
        }

        $this->mysqli->rollback();
    }

    public function testDot() {
        $this->mysqli->begin_transaction();

        $vecs = [
            [1.0, 2.0, 3.0],
            [4.0, 5.0, 6.0],
            [7.0, 8.0, 9.0]
        ];

        $ids = [];
        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($this->mysqli, $vec);
        }

        $this->assertEquals(32, $this->vectorTable->dot($this->mysqli, $ids[0], $ids[1]));
        $this->assertEquals(122, $this->vectorTable->dot($this->mysqli, $ids[2], $ids[1]));

        $this->assertEquals(194, $this->vectorTable->dot($this->mysqli, $ids[2], [7, 8, 9]));

        $this->mysqli->rollback();
    }

    public function testSearch() {
        $this->mysqli->begin_transaction();

        $vecs = [
            [1.0, 2.0, 3.0],
            [4.0, 5.0, 6.0],
            [7.0, 8.0, 9.0],
            [10.0, 11.0, 12.0],
            [13.0, 14.0, 15.0],
        ];

        $ids = [];
        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($this->mysqli, $vec);
        }

        $results = $this->vectorTable->search($this->mysqli, [1, 2, 3], 2);
        $this->assertEquals(2, count($results));
        $this->assertEquals($ids[0], $results[0]['id']);
        $this->assertEquals($ids[1], $results[1]['id']);

        $results = $this->vectorTable->search($this->mysqli, [13.0, 14.0, 15.0], 2);
        $this->assertEquals(2, count($results));
        $this->assertEquals($ids[4], $results[0]['id']);
        $this->assertEquals($ids[3], $results[1]['id']);
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up the database and close connection
        $mysqli = new \mysqli('localhost', 'root', '', 'mysql-vector');
        $vectorTable = new VectorTable('test_table', 3);
        $mysqli->query("DROP TABLE " . $vectorTable->getMetaTableName());
        $mysqli->query("DROP TABLE " . $vectorTable->getValuesTableName());
        $mysqli->close();
    }

    protected function tearDown(): void
    {
        $this->mysqli->close();
    }

}
