<?php

namespace MHz\MysqlVector;

class VectorTable
{
    private string $name;
    private int $dimension;
    private string $engine;
    private array $centroidCache;
    private \mysqli $mysqli;

    const SQL_COSIM_FUNCTION = "
CREATE FUNCTION COSIM(v1 JSON, v2 JSON) RETURNS FLOAT DETERMINISTIC BEGIN DECLARE sim FLOAT DEFAULT 0; DECLARE i INT DEFAULT 0; DECLARE len INT DEFAULT JSON_LENGTH(v1); IF JSON_LENGTH(v1) != JSON_LENGTH(v2) THEN RETURN NULL; END IF; WHILE i < len DO SET sim = sim + (JSON_EXTRACT(v1, CONCAT('$[', i, ']')) * JSON_EXTRACT(v2, CONCAT('$[', i, ']'))); SET i = i + 1; END WHILE; RETURN sim; END";

    /**
     * Instantiate a new VectorTable object.
     * @param \mysqli $mysqli The mysqli connection
     * @param string $name Name of the table.
     * @param int $dimension Dimension of the vectors.
     * @param int $quantizationSampleSize Number of vectors to use for quantization.
     * @param string $engine The storage engine to use for the tables
     */
    public function __construct(\mysqli $mysqli, string $name, int $dimension = 384, int $quantizationSampleSize = 400, string $engine = 'InnoDB')
    {
        $this->mysqli = $mysqli;
        $this->name = $name;
        $this->dimension = $dimension;
        $this->engine = $engine;
        $this->centroidCache = [];
    }

    public function getVectorTableName(): string
    {
        return sprintf('%s_vectors', $this->name);
    }

    public function getCentroidTableName(): string
    {
        return sprintf('%s_centroids', $this->name);
    }

    protected function getCreateStatements(bool $ifNotExists = true): array {
        $vectorsQuery =
            "CREATE TABLE %s %s (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vector JSON,
                normalized_vector JSON,
                magnitude DOUBLE,
                centroid_id INT UNSIGNED DEFAULT 0,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (centroid_id)
            ) ENGINE=%s;";
        $vectorsQuery = sprintf($vectorsQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getVectorTableName(), $this->engine);

        $centroidsQuery =
            "CREATE TABLE %s %s (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vector JSON,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=%s;";
        $centroidsQuery = sprintf($centroidsQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getCentroidTableName(), $this->engine);

        return [$vectorsQuery, $centroidsQuery];
    }

    /**
     * Create the tables required for storing vectors
     * @param bool $ifNotExists Whether to use IF NOT EXISTS in the CREATE TABLE statements
     * @return void
     * @throws \Exception If the tables could not be created
     */
    public function initialize(bool $ifNotExists = true): void
    {
        $this->mysqli->begin_transaction();
        foreach ($this->getCreateStatements($ifNotExists) as $statement) {
            $success = $this->mysqli->query($statement);
            if (!$success) {
                $this->mysqli->rollback();
                throw new \Exception($this->mysqli->error);
            }
        }

        // Add COSIM function
        $this->mysqli->query("DROP FUNCTION IF EXISTS COSIM");
        $res = $this->mysqli->query(self::SQL_COSIM_FUNCTION);

        if(!$res) {
            $this->mysqli->rollback();
            throw new \Exception($this->mysqli->error);
        }

        $this->mysqli->commit();
    }

    /**
     * Compute the cosine similarity between two normalized vectors
     * @param array $v1 The first vector
     * @param array $v2 The second vector
     * @return float The cosine similarity between the two vectors [0, 1]
     * @throws \Exception
     */
    public function cosim(array $v1, array $v2): float
    {
        $statement = $this->mysqli->prepare("SELECT COSIM(?, ?)");

        if(!$statement) {
            throw new \Exception($this->mysqli->error);
        }

        $v1 = json_encode($v1);
        $v2 = json_encode($v2);

        $statement->bind_param('ss', $v1, $v2);
        $statement->execute();
        $statement->bind_result($similarity);
        $statement->fetch();
        $statement->close();

        return $similarity;
    }

    private function getRandomVectors($count, $dimension) {
        $vecs = [];
        for ($i = 0; $i < $count; $i++) {
            for($j = 0; $j < $dimension; $j++) {
                $vecs[$i][$j] = 2 * (mt_rand() / mt_getrandmax()) - 1;
            }
        }
        return $vecs;
    }

    /**
     * Insert or update a vector
     * @param \mysqli $mysqli The mysqli connection
     * @param array $vector The vector to insert or update
     * @param int|null $id Optional ID of the vector to update
     * @return int The ID of the inserted or updated vector
     * @throws \Exception If the vector could not be inserted or updated
     */
    public function upsert(array $vector, int $id = null, $isCentroid = false): int
    {
        $magnitude = $this->getMagnitude($vector);
        $normalizedVector = $this->normalize($vector, $magnitude);
        $tableName = $isCentroid ? $this->getCentroidTableName() : $this->getVectorTableName();

        $insertQuery = '';
        if($isCentroid) {
            $insertQuery = "INSERT INTO $tableName (vector) VALUES (?)";
        } else {
            $insertQuery = empty($id) ?
                "INSERT INTO $tableName (vector, normalized_vector, magnitude, centroid_id) VALUES (?, ?, ?, ?)" :
                "UPDATE $tableName SET vector = ?, normalized_vector = ?, magnitude = ?, centroid_id = ? WHERE id = $id";
        }

        $centroidId = 0;
        if(!$isCentroid) {
            $centroidId = $this->getClosestCentroid($normalizedVector);
        }

        $statement = $this->mysqli->prepare($insertQuery);
        if(!$statement) {
            throw new \Exception($this->mysqli->error);
        }

        $vector = json_encode($vector);
        $normalizedVector = json_encode($normalizedVector);

        if($isCentroid) {
            $statement->bind_param('s', $normalizedVector);
        } else {
            $statement->bind_param('ssdi', $vector, $normalizedVector, $magnitude, $centroidId);
        }

        $success = $statement->execute();
        if(!$success) {
            throw new \Exception($statement->error);
        }

        $id = $statement->insert_id;
        $statement->close();

        return $id;
    }

    private function getClosestCentroid($normalizedVector): int
    {
        // todo: cache centroids
        $centroidId = 0;
        $minDistance = 0;
        foreach($this->centroidCache as $id => $centroid) {
            $distance = $this->dotProduct($normalizedVector, $centroid);
            if($distance > $minDistance) {
                $centroidId = $id;
                $minDistance = $distance;
            }
        }
        return $centroidId;
    }

    /**
     * Select one or more vectors by id
     * @param \mysqli $mysqli The mysqli connection
     * @param array $ids The ids of the vectors to select
     * @return array Array of vectors
     */
    public function select(array $ids): array {
        $tableName = $this->getVectorTableName();

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->mysqli->prepare("SELECT id, vector, normalized_vector, magnitude, centroid_id FROM $tableName WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($ids));

        $refs = [];
        foreach ($ids as $key => $id) {
            $refs[$key] = &$ids[$key];
        }

        call_user_func_array([$statement, 'bind_param'], array_merge([$types], $refs));
        $statement->execute();
        $statement->bind_result($vectorId, $vector, $normalizedVector, $magnitude, $centroidId);

        $result = [];
        while ($statement->fetch()) {
            $result[] = [
                'id' => $vectorId,
                'vector' => json_decode($vector, true),
                'normalized_vector' => json_decode($normalizedVector, true),
                'magnitude' => $magnitude,
                'centroid_id' => $centroidId
            ];
        }

        $statement->close();

        return $result;
    }

    public function selectAll(bool $isCentroid = false): array {
        $tableName = $isCentroid ? $this->getCentroidTableName() : $this->getVectorTableName();

        $statement = $this->mysqli->prepare("SELECT id, vector, normalized_vector, magnitude, centroid_id FROM $tableName");
        $statement->execute();
        $statement->bind_result($vectorId, $vector, $normalizedVector, $magnitude, $centroidId);

        $result = [];
        while ($statement->fetch()) {
            $result[] = [
                'id' => $vectorId,
                'vector' => json_decode($vector, true),
                'normalized_vector' => json_decode($normalizedVector, true),
                'magnitude' => $magnitude,
                'centroid_id' => $centroidId
            ];
        }

        $statement->close();

        return $result;
    }

    private function dotProduct(array $vectorA, array $vectorB): float {
        $product = 0;

        foreach ($vectorA as $position => $value) {
            if (isset($vectorB[$position])) {
                $product += $value * $vectorB[$position];
            }
        }

        return $product;
    }

    /**
     * Returns the number of vectors stored in the database
     * @return int The number of vectors
     */
    public function count(): int {
        $tableName = $this->getVectorTableName();
        $statement = $this->mysqli->prepare("SELECT COUNT(id) FROM $tableName");
        $statement->execute();
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();
        return $count;
    }

    private function getMagnitude(array $vector): float
    {
        $sum = 0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }

    /**
     * Finds the vectors that are most similar to the given vector
     * @param array $vector The vector to query for
     * @param int $n The number of results to return
     * @return array Array of results containing the id, similarity, and vector
     * @throws \Exception
     */
    public function search(array $vector, int $n = 10): array {
        $tableName = $this->getVectorTableName();
        $normalizedVector = $this->normalize($vector);

        // Find nearest centroid
        $centroidId = $this->getClosestCentroid($normalizedVector);
        $normalizedVector = json_encode($normalizedVector);

        $statement = $this->mysqli->prepare("SELECT id, vector, normalized_vector, magnitude, centroid_id, COSIM(normalized_vector, ?) AS similarity FROM $tableName WHERE centroid_id = ? ORDER BY similarity DESC LIMIT $n");
        $statement->bind_param('si', $normalizedVector, $centroidId);

        if(!$statement) {
            throw new \Exception($this->mysqli->error);
        }

        $statement->execute();
        $statement->bind_result($vectorId, $v, $nv, $m, $cid, $s);

        $result = [];
        while ($statement->fetch()) {
            $result[] = [
                'id' => $vectorId,
                'vector' => json_decode($v, true),
                'normalized_vector' => json_decode($nv, true),
                'magnitude' => $m,
                'centroid_id' => $cid,
                'similarity' => $s
            ];
        }

        return $result;
    }

    /**
     * Normalize a vector
     * @param array $vector The vector to normalize
     * @param float|null $magnitude The magnitude of the vector. If not provided, it will be calculated.
     * @param float $epsilon The epsilon value to use for normalization
     * @return array The normalized vector
     */
    private function normalize(array $vector, float $magnitude = null, float $epsilon = 1e-10): array {
        $magnitude = !empty($magnitude) ? $magnitude : $this->getMagnitude($vector);
        if ($magnitude == 0) {
            $magnitude = $epsilon;
        }
        foreach ($vector as $key => $value) {
            $vector[$key] = $value / $magnitude;
        }
        return $vector;
    }

    /**
     * Remove a vector from the database
     * @param int $id The id of the vector to remove
     * @return void
     * @throws \Exception
     */
    public function delete(int $id): void {
        $tableName = $this->getVectorTableName();
        $statement = $this->mysqli->prepare("DELETE FROM $tableName WHERE id = ?");
        $statement->bind_param('i', $id);
        $success = $statement->execute();
        if(!$success) {
            throw new \Exception($statement->error);
        }
        $statement->close();
    }

    public function getConnection(): \mysqli {
        return $this->mysqli;
    }
}