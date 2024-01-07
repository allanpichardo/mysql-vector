<?php

namespace MHz\MysqlVector\Tests;

use MHz\MysqlVector\VectorTable;
use PHPUnit\Framework\TestCase;

class PerformanceBenchmarkTest extends TestCase
{
    private $vectorTable;
    private $dimension = 384;
    private $testVectorAmount = 1000;
    private $quantizationSampleSize = 400;

    protected function setUp(): void
    {
        $mysqli = new \mysqli('localhost', 'root', '', 'mysql-vector');

        // Check connection
        if ($mysqli->connect_error) {
            die("Connection failed: " . $mysqli->connect_error);
        }

        // Setup VectorTable for testing
        $this->vectorTable = new VectorTable($mysqli, 'test_table', $this->dimension, $this->quantizationSampleSize);

        // Create required tables for testing
        $this->vectorTable->initialize();
    }

    public function testSearchPerformanceWithQuantization() {
        $this->vectorTable->getConnection()->begin_transaction();

        $connection = new \mysqli('localhost', 'root', '', 'mysql-vector');

        // Let's insert a known vector
        $targetVector = array_fill(0, $this->dimension, 0.5);
        $this->vectorTable->upsert($targetVector);

        echo "Starting quantization test...\n";
        ob_flush();

        for($i = 0; $i < 100; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $this->vectorTable->upsert($vec);
            echo ".";
            ob_flush();
        }

        echo "\n";

        echo "Quantizing vectors...\n";
        ob_flush();
        $time = microtime(true);
        $this->vectorTable->performVectorQuantization($connection);
        $time = microtime(true) - $time;
        echo "Quantized into " . floor(100 / $this->quantizationSampleSize) . " regions.";
        echo "Elapsed time: " . sprintf('%02d:%02d:%02d', ($time/3600), ($time/60%60), $time%60) . "\n";
        ob_flush();

        // Now, we search for this vector
        echo "Searching for 1 vector among 100 with quantization...\n";
        ob_flush();
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";
        ob_flush();

        for($i = 0; $i < 900; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $this->vectorTable->upsert($vec);
            echo ".";
            ob_flush();
        }

        echo "\n";

        echo "Quantizing vectors...\n";
        ob_flush();
        $time = microtime(true);
        $this->vectorTable->performVectorQuantization($connection);
        $time = microtime(true) - $time;
        echo "Quantized into " . floor(1000 / $this->quantizationSampleSize) . " regions.";
        echo "Elapsed time: " . sprintf('%02d:%02d:%02d', ($time/3600), ($time/60%60), $time%60) . "\n";
        ob_flush();

        // Now, we search for this vector
        echo "Searching for 1 vector among 1000 with quantization...\n";
        ob_flush();
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";
        ob_flush();

        for($i = 0; $i < 9000; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $this->vectorTable->upsert($vec);
            echo ".";
            ob_flush();
        }

        echo "\n";

        echo "Quantizing vectors...\n";
        ob_flush();
        $time = microtime(true);
        $this->vectorTable->performVectorQuantization($connection);
        $time = microtime(true) - $time;
        echo "Quantized into " . floor(10000 / $this->quantizationSampleSize) . " regions.";
        echo "Elapsed time: " . sprintf('%02d:%02d:%02d', ($time/3600), ($time/60%60), $time%60) . "\n";
        ob_flush();

        // Now, we search for this vector
        echo "Searching for 1 vector among 10000 with quantization...\n";
        ob_flush();
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";
        ob_flush();

        for($i = 0; $i < 90000; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $this->vectorTable->upsert($vec);
            echo ".";
            ob_flush();
        }

        echo "\n";

        echo "Quantizing vectors...\n";
        ob_flush();
        $time = microtime(true);
        $this->vectorTable->performVectorQuantization($connection);
        $time = microtime(true) - $time;
        echo "Quantized into " . floor(100000 / $this->quantizationSampleSize) . " regions.";
        echo "Elapsed time: " . sprintf('%02d:%02d:%02d', ($time/3600), ($time/60%60), $time%60) . "\n";
        ob_flush();

        // Now, we search for this vector
        echo "Searching for 1 vector among 100000 with quantization...\n";
        ob_flush();
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";
        ob_flush();

        $connection->close();

        // At least the first result should be our target vector or very close
        $firstResultVector = $results[0]['vector'];
        $firstResultSimilarity = $results[0]['similarity'];

        $this->assertEqualsWithDelta($targetVector, $firstResultVector, 0.00001, "The most similar vector should be the target vector itself");
        $this->assertEqualsWithDelta(1.0, $firstResultSimilarity, 0.001, "The similarity of the most similar vector should be the highest possible value");

        $this->vectorTable->getConnection()->rollback();
    }

    public function testSearchPerformanceWithoutQuantization() {
        $this->vectorTable->getConnection()->begin_transaction();

        // Let's insert a known vector
        $targetVector = array_fill(0, $this->dimension, 0.5);
        $this->vectorTable->upsert($targetVector);

        for($i = 0; $i < 100; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $this->vectorTable->upsert($vec);
        }

        // Now, we search for this vector
        echo "Searching for 1 vector among 100 without quantization...\n";
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";

        for($i = 0; $i < 900; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $this->vectorTable->upsert($vec);
        }

        // Now, we search for this vector
        echo "Searching for 1 vector among 1000 without quantization...\n";
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";

        for($i = 0; $i < 9000; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $this->vectorTable->upsert($vec);
        }

        // Now, we search for this vector
        echo "Searching for 1 vector among 10000 without quantization...\n";
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";

        for($i = 0; $i < 90000; $i++) {
            $vec = $this->getRandomVectors(1, $this->dimension)[0];
            $this->vectorTable->upsert($vec);
        }

        // Now, we search for this vector
        echo "Searching for 1 vector among 100000 without quantization...\n";
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";

        $connection = new \mysqli('localhost', 'root', '', 'mysql-vector');

        echo "Quantizing vectors...\n";
        $time = microtime(true);
        $this->vectorTable->performVectorQuantization($connection);
        $time = microtime(true) - $time;
        echo "Quantized into " . floor($this->testVectorAmount / $this->quantizationSampleSize) . " regions.";
        echo "Elapsed time: " . sprintf('%02d:%02d:%02d', ($time/3600), ($time/60%60), $time%60) . "\n";

        $connection->close();

        echo "Searching for 1 vector among $this->testVectorAmount with quantization...\n";
        $time = microtime(true);
        $results = $this->vectorTable->search($targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";

        // At least the first result should be our target vector or very close
        $firstResultVector = $results[0]['vector'];
        $firstResultSimilarity = $results[0]['similarity'];

        $this->assertEqualsWithDelta($targetVector, $firstResultVector, 0.00001, "The most similar vector should be the target vector itself");
        $this->assertEqualsWithDelta(1.0, $firstResultSimilarity, 0.001, "The similarity of the most similar vector should be the highest possible value");

        $this->vectorTable->getConnection()->rollback();
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

    public static function tearDownAfterClass(): void
    {
        // Clean up the database and close connection
        $mysqli = new \mysqli('localhost', 'root', '', 'mysql-vector');
        $vectorTable = new VectorTable($mysqli, 'test_table', 3);
        $mysqli->query("DROP TABLE " . $vectorTable->getVectorTableName());
        $mysqli->query("DROP TABLE " . $vectorTable->getCentroidTableName());
        $mysqli->query("DROP FUNCTION IF EXISTS COSIM");
        $mysqli->close();
    }

    protected function tearDown(): void
    {
        // Clean up the database and close connection
        $this->vectorTable->getConnection()->query("DROP TABLE " . $this->vectorTable->getVectorTableName());
        $this->vectorTable->getConnection()->query("DROP TABLE " . $this->vectorTable->getCentroidTableName());
        $this->vectorTable->getConnection()->query("DROP FUNCTION IF EXISTS COSIM");
        $this->vectorTable->getConnection()->close();
    }
}
