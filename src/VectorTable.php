<?php

namespace MHz\MysqlVector;

class VectorTable
{
    private string $name;
    private int $dimension;
    private string $engine;
    private float $pruningThreshold;
    private int $centroids;
    private array $centroidCache;

    /**
     * Instantiate a new VectorTable object.
     * @param string $name Name of the table.
     * @param int $dimension Dimension of the vectors.
     * @param float $pruningThreshold Threshold for bounding box pruning. This value must be consistent across your dataset and can't be changed once set. Lower values will result in more pruning. Default is 0.7.
     * @param string $engine
     */
    public function __construct(string $name, int $dimension = 384, int $centroids = 10, float $pruningThreshold = 0.7, string $engine = 'InnoDB')
    {
        $this->name = $name;
        $this->dimension = $dimension;
        $this->engine = $engine;
        $this->pruningThreshold = $pruningThreshold;
        $this->centroids = $centroids;
        $this->centroidCache = [];
    }

    public function getMetaTableName(): string
    {
        return sprintf('vector_meta_%s', $this->name);
    }

    public function getValuesTableName(): string
    {
        return sprintf('vector_values_%s', $this->name);
    }

    protected function getCreateStatements(bool $ifNotExists = true): array {
        $metaQuery =
            "CREATE TABLE %s %s (
                vector_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                magnitude DOUBLE NOT NULL,
                centroid_id INT UNSIGNED,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=%s;";
        $metaQuery = sprintf($metaQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getMetaTableName(), $this->engine);

        $valuesQuery =
            "CREATE TABLE %s %s (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vector_id INT UNSIGNED NOT NULL,
                element_position INT,
                vector_value DOUBLE,
                normalized_value DOUBLE,
                FOREIGN KEY (vector_id) REFERENCES vector_meta_%s(vector_id)
            ) ENGINE=%s;";
        $valuesQuery = sprintf($valuesQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getValuesTableName(), $this->name, $this->engine);

        $centroidMetaQuery =
            "CREATE TABLE %s centroids_%s (
                centroid_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=%s;";
        $centroidMetaQuery = sprintf($centroidMetaQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getMetaTableName(), $this->engine);

        $centroidValuesQuery =
            "CREATE TABLE %s centroids_%s (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                centroid_id INT UNSIGNED NOT NULL,
                element_position INT,
                vector_value DOUBLE,
                normalized_value DOUBLE,
                FOREIGN KEY (centroid_id) REFERENCES centroids_%s(centroid_id)
            ) ENGINE=%s;";
        $centroidValuesQuery = sprintf($centroidValuesQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getValuesTableName(), $this->getMetaTableName(), $this->engine);

        $indexValuesQuery = "CREATE INDEX vector_id_index_%s ON vector_values_%s (vector_id);";
        $indexValuesQuery = sprintf($indexValuesQuery, $this->name, $this->name);

        $indexMetaQuery = "CREATE INDEX meta_vector_id_index_%s ON vector_meta_%s (vector_id);";
        $indexMetaQuery = sprintf($indexMetaQuery, $this->name, $this->name);

        $indexPositionQuery = "CREATE INDEX element_position_index_%s ON vector_values_%s (element_position);";
        $indexPositionQuery = sprintf($indexPositionQuery, $this->name, $this->name);

        $indexNormalizedQuery = "CREATE INDEX normalized_value_index_%s ON vector_values_%s (normalized_value);";
        $indexNormalizedQuery = sprintf($indexNormalizedQuery, $this->name, $this->name);

        $uniqueConstraintQuery = "ALTER TABLE vector_values_%s ADD CONSTRAINT unique_vector_id_element_position UNIQUE (vector_id, element_position);";
        $uniqueConstraintQuery = sprintf($uniqueConstraintQuery, $this->name);

        $compositeIndexQuery = "CREATE INDEX composite_index_%s ON vector_values_%s (element_position, vector_id, normalized_value);";
        $compositeIndexQuery = sprintf($compositeIndexQuery, $this->name, $this->name);

        $centroidCompositeIndexQuery = "CREATE INDEX composite_index_%s ON centroids_vector_values_%s (element_position, centroid_id, normalized_value);";
        $centroidCompositeIndexQuery = sprintf($centroidCompositeIndexQuery, $this->name, $this->name);

        return [$metaQuery, $valuesQuery, $centroidMetaQuery, $centroidValuesQuery, $indexValuesQuery, $indexPositionQuery, $uniqueConstraintQuery, $indexMetaQuery, $indexNormalizedQuery, $compositeIndexQuery, $centroidCompositeIndexQuery];
    }

    /**
     * Create the tables required for storing vectors
     * @param \mysqli $mysqli The mysqli connection
     * @param bool $ifNotExists Whether to use IF NOT EXISTS in the CREATE TABLE statements
     * @return void
     * @throws \Exception If the tables could not be created
     */
    public function initialize(\mysqli $mysqli, bool $ifNotExists = true): void
    {
        $mysqli->begin_transaction();

        foreach ($this->getCreateStatements($ifNotExists) as $statement) {
            $success = $mysqli->query($statement);
            if (!$success) {
                $mysqli->rollback();
                throw new \Exception($mysqli->error);
            }
        }

        $mysqli->commit();

        // todo: allow changing the number of centroids
        $this->generateRandomCentroids($mysqli, $this->centroids);
    }

    protected function generateRandomCentroids(\mysqli $mysqli, int $count = 50) {
        $mysqli->begin_transaction();

        $centroids = $this->getRandomVectors($count, $this->dimension);
        foreach ($centroids as $centroid) {
            $this->upsert($mysqli, $centroid, null, true);
        }

        $mysqli->commit();

        $this->centroidCache = $this->selectAll($mysqli, true);
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
    public function upsert(\mysqli $mysqli, array $vector, int $id = null, $isCentroid = false): int
    {
        $magnitude = $this->getMagnitude($vector);

        $centroidId = 1;
        if(!$isCentroid) {
            // Find the closest centroid
            if(empty($this->centroidCache)) {
                $this->centroidCache = $this->selectAll($mysqli, true);
            }

            $nearest = null;
            foreach ($this->centroidCache as $cid => $centroid) {
                $dotProduct = $this->dotProduct(array_map(function($x) use ($magnitude) {return $x/$magnitude;}, $vector), $centroid['normalized_vector']);
                if ($nearest === null || $dotProduct > $nearest['similarity']) {
                    $nearest = [
                        'id' => $cid,
                        'similarity' => $dotProduct
                    ];
                }
            }

            $centroidId = $nearest['id'];
        }

        if(count($vector) != $this->dimension) {
            throw new \Exception('Vector dimension does not match');
        }

        $vectorId = $id;
        if(empty($vectorId)) {
            $metaTableName = $isCentroid ? sprintf("centroids_%s", $this->getMetaTableName()) : $this->getMetaTableName();

            if(!$isCentroid) {
                $statement = $mysqli->prepare("INSERT INTO $metaTableName (magnitude, centroid_id) VALUES (?, ?)");
                $statement->bind_param('di', $magnitude, $centroidId);
            } else {
                $statement = $mysqli->prepare("INSERT INTO $metaTableName VALUES ()");
            }

            $success = $statement->execute();

            if (!$success) {
                throw new \Exception($statement->error);
            }

            $vectorId = $statement->insert_id;
            $statement->close();
        }

        $valuesTableName = $isCentroid ? sprintf("centroids_%s", $this->getValuesTableName()) : $this->getValuesTableName();

        $placeholders = implode(', ', array_fill(0, count($vector), '(?, ?, ?, ?)'));
        if(!$isCentroid) {
            $statement = $mysqli->prepare("INSERT INTO $valuesTableName (vector_id, element_position, vector_value, normalized_value) VALUES $placeholders ON DUPLICATE KEY UPDATE vector_value = VALUES(vector_value), normalized_value = VALUES(normalized_value)");
        } else {
            $statement = $mysqli->prepare("INSERT INTO $valuesTableName (centroid_id, element_position, vector_value, normalized_value) VALUES $placeholders ON DUPLICATE KEY UPDATE vector_value = VALUES(vector_value), normalized_value = VALUES(normalized_value)");
        }

        if(!$statement) {
            throw new \Exception($mysqli->error);
        }

        $bindParams = [];
        $types = '';
        foreach ($vector as $position => $value) {
            $bindParams[] = $vectorId;
            $bindParams[] = $position;
            $bindParams[] = $vector[$position];
            $bindParams[] = $vector[$position] / $magnitude;
            $types .= 'iidd';
        }
        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }

        call_user_func_array([$statement, 'bind_param'], array_merge([$types], $refs));

        $success = $statement->execute();

        if (!$success) {
            $this->delete($mysqli, $vectorId);
            throw new \Exception($statement->error);
        }

        $statement->close();

        return $vectorId;
    }

    /**
     * Select one or more vectors by id
     * @param \mysqli $mysqli The mysqli connection
     * @param array $ids The ids of the vectors to select
     * @return array Array of vectors
     */
    public function select(\mysqli $mysqli, array $ids): array {
        $valuesTableName = $this->getValuesTableName();

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $mysqli->prepare("SELECT vector_id, element_position, vector_value FROM $valuesTableName WHERE vector_id IN ($placeholders)");
        $types = str_repeat('i', count($ids));

        $refs = [];
        foreach ($ids as $key => $id) {
            $refs[$key] = &$ids[$key];
        }

        call_user_func_array([$statement, 'bind_param'], array_merge([$types], $refs));
        $statement->execute();
        $statement->bind_result($vectorId, $position, $value);

        $result = [];
        while ($statement->fetch()) {
            if (!isset($result[$vectorId])) {
                $result[$vectorId] = [];
            }

            $result[$vectorId][$position] = $value;
        }

        $statement->close();

        return $result;
    }

    function selectBatch(\mysqli $mysqli, $batchSize, $offset) {
        $valuesTableName = $this->getValuesTableName();
        $metaTableName = $this->getMetaTableName();

        $mysqli->query("SET SESSION group_concat_max_len = 1000000;");

        // Adjust the query to join with meta table and use GROUP_CONCAT to aggregate values
        $query = "
        SELECT 
            meta.vector_id, 
            GROUP_CONCAT(val.vector_value ORDER BY val.element_position ASC) AS vector_values,
            GROUP_CONCAT(val.normalized_value ORDER BY val.element_position ASC) AS normalized_values
        FROM 
            $metaTableName AS meta
        JOIN 
            $valuesTableName AS val ON meta.vector_id = val.vector_id
        GROUP BY 
            meta.vector_id
        LIMIT ?, ?
    ";

        $statement = $mysqli->prepare($query);
        $statement->bind_param('ii', $offset, $batchSize);
        $statement->execute();
        $statement->bind_result($vectorId, $vectorValues, $normalizedValues);

        $result = [];
        while ($statement->fetch()) {
            $result[$vectorId] = [
                'vector' => array_map('floatval', explode(',', $vectorValues)),
                'normalized_vector' => array_map('floatval', explode(',', $normalizedValues))
            ];
        }

        $statement->close();

        return $result;
    }

    public function selectAll(\mysqli $mysqli, bool $isCentroid = false): array {
        $idName = $isCentroid ? 'centroid_id' : 'vector_id';
        $valuesTableName = $isCentroid ? 'centroids_' . $this->getValuesTableName() : $this->getValuesTableName();

        $statement = $mysqli->prepare("SELECT $idName, element_position, vector_value, normalized_value FROM $valuesTableName");
        $statement->execute();
        $statement->bind_result($vectorId, $position, $value, $normalizedValue);

        $result = [];
        while ($statement->fetch()) {
            if (!isset($result[$vectorId])) {
                $result[$vectorId] = [
                    'vector' => [],
                    'normalized_vector' => []
                ];
            }

            $result[$vectorId]['vector'][$position] = $value;
            $result[$vectorId]['normalized_vector'][$position] = $normalizedValue;
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

    public function searchPHP(\mysqli $mysqli, array $vector, int $n = 10, int $batchSize = 10): array {

        $offset = 0;
        // Normalize the input vector
        $normalizedVector = $this->normalize($vector);
        $boundingBox = $this->getBoundingBox($vector);
        $lowerBound = $boundingBox[0];
        $upperBound = $boundingBox[1];

        $results = [];
        do {
            $vectors = $this->selectBatch($mysqli, $batchSize, $offset);
            foreach ($vectors as $vectorId => $storedVector) {
                $isWithinBoundingBox = true;
                foreach ($storedVector['normalized_vector'] as $position => $value) {
                    if ($value < $lowerBound[$position] || $value > $upperBound[$position]) {
                        $isWithinBoundingBox = false;
                        break;
                    }
                }

                if ($isWithinBoundingBox) {
                    $dotProduct = $this->dotProduct($normalizedVector, $storedVector['normalized_vector']);

                    $results[] = [
                        'id' => $vectorId,
                        'similarity' => $dotProduct,
                        'vector' => $storedVector['vector']
                    ];
                }
            }
            $offset += $batchSize;
        } while (!empty($vectors));

        // Sort results based on similarity
        usort($results, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($results, 0, $n);
    }

    protected function dotInputWithStored(\mysqli $mysqli, array $vector, int $id) {
        $valuesTableName = $this->getValuesTableName();

        // Constructing a part of the query to multiply the corresponding elements
        $multipliers = [];
        foreach ($vector as $position => $value) {
            $multipliers[] = "WHEN element_position = $position THEN vector_value * $value";
        }
        $caseStatement = "CASE " . implode(' ', $multipliers) . " ELSE 0 END";

        $sql = "SELECT SUM($caseStatement) as dotProduct FROM $valuesTableName WHERE vector_id = ?";
        $statement = $mysqli->prepare($sql);

        $statement->bind_param('i', $id);
        $statement->execute();
        $statement->bind_result($result);
        $statement->fetch();
        $statement->close();

        return $result;
    }

    protected function dotStored(\mysqli $mysqli, int $idA, int $idB) {
        $valuesTableName = $this->getValuesTableName();

        $statement = $mysqli->prepare("
        SELECT SUM(a.vector_value * b.vector_value) as dot_product
        FROM {$valuesTableName} a
        JOIN {$valuesTableName} b ON a.element_position = b.element_position
        WHERE a.vector_id = ? AND b.vector_id = ?;");

        if(!$statement) {
            throw new \Exception($mysqli->error);
        }

        $statement->bind_param('ii', $idA, $idB);
        $success = $statement->execute();

        if(!$success) {
            throw new \Exception($statement->error);
        }

        $statement->bind_result($dotProduct);
        $statement->fetch();
        $statement->close();

        return $dotProduct;
    }

    /**
     * Computes the dot product between two vectors. At least
     * one of the vectors must be stored in the database.
     * @param \mysqli $mysqli
     * @param int $idA vector id
     * @param int|array $vectorB Either a vector id or a vector
     * @return float
     * @throws \Exception
     */
    public function dot(\mysqli $mysqli, int $idA, int|array $vectorB): float {
        if(is_array($vectorB)) {
            return $this->dotInputWithStored($mysqli, $vectorB, $idA);
        } else {
            return $this->dotStored($mysqli, $idA, $vectorB);
        }
    }

    /**
     * Returns the number of vectors stored in the database
     * @param \mysqli $mysqli The mysqli connection
     * @return int The number of vectors
     */
    public function count(\mysqli $mysqli): int {
        $metaTableName = $this->getMetaTableName();
        $statement = $mysqli->prepare("SELECT COUNT(vector_id) FROM $metaTableName");
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

    private function getBoundingBox(array $vector, float $threshold = 0.7): array {
        $min_vec = [];
        $max_vec = [];
        for($i = 0; $i < count($vector); $i++) {
            $min_vec[$i] = min($vector[$i] - $threshold, $vector[$i] + $threshold);
            $max_vec[$i] = max($vector[$i] - $threshold, $vector[$i] + $threshold);
        }

        return [$min_vec, $max_vec];
    }

    /**
     * Finds the vectors that are most similar to the given vector
     * @param \mysqli $mysqli The mysqli connection
     * @param array $vector The vector to query for
     * @param int $n The number of results to return
     * @return array Array of results containing the id, similarity, and vector
     * @throws \Exception
     */
    public function search(\mysqli $mysqli, array $vector, int $n = 10, bool $isCentroid = false): array {

        if(!$isCentroid) {
            // Find the closest centroid
            $centroids = $this->search($mysqli, $vector, 1, true);
            $centroidId = $centroids[0]['id'];
        }

        $valuesTableName = $isCentroid ? 'centroids_' . $this->getValuesTableName() : $this->getValuesTableName();
        $metaTableName = $isCentroid ? 'centroids_' . $this->getMetaTableName() : $this->getMetaTableName();

        // Normalize the input vector
        $normalizedVector = $this->normalize($vector);

        $mysqli->query("SET SESSION group_concat_max_len = 1000000;");

        // Calculate dot products for these vector_ids
        $dotProducts = [];
        foreach ($normalizedVector as $position => $value) {
            $dotProducts[] = "SUM(CASE WHEN element_position = $position THEN normalized_value ELSE 0 END) * $value";
        }
        $dotProductQuery = implode(' + ', $dotProducts);

        $whereClause = '';
        if(!$isCentroid) {
            $whereClause = "WHERE centroid_id = $centroidId";
        }

        $finalQuery = sprintf("
        SELECT 
            meta.%s, 
            ($dotProductQuery) as similarity,
            GROUP_CONCAT(val.vector_value ORDER BY val.element_position ASC) as vector_values
        FROM 
            $valuesTableName AS val
        JOIN 
            $metaTableName AS meta ON val.%s = meta.%s
        %s
        GROUP BY 
            meta.%s
        ORDER BY 
            similarity DESC
        LIMIT 
            $n
    ", $isCentroid ? 'centroid_id' : 'vector_id',
            $isCentroid ? 'centroid_id' : 'vector_id',
            $isCentroid ? 'centroid_id' : 'vector_id',
            $whereClause,
            $isCentroid ? 'centroid_id' : 'vector_id');

        $stmt = $mysqli->query($finalQuery);
        if (!$stmt) {
            throw new \Exception($mysqli->error);
        }

        $results = [];
        while ($row = $stmt->fetch_assoc()) {
            $results[] = [
                'id' => $isCentroid ? $row['centroid_id'] : $row['vector_id'],
                'similarity' => (double)$row['similarity'],
                'vector' => array_map('floatval', explode(',', $row['vector_values']))
            ];
        }

        return $results;
    }

    private function normalize(array $vector, float $epsilon = 1e-10): array {
        $magnitude = $this->getMagnitude($vector);
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
     * @param \mysqli $mysqli The mysqli connection
     * @param int $id The id of the vector to remove
     * @return void
     * @throws \Exception
     */
    public function delete(\mysqli $mysqli, int $id): void {
        $metaTableName = $this->getMetaTableName();
        $valuesTableName = $this->getValuesTableName();

        $mysqli->begin_transaction();

        $statement = $mysqli->prepare("DELETE FROM $valuesTableName WHERE vector_id = ?");
        $statement->bind_param('i', $id);
        $success = $statement->execute();

        if(!$success) {
            $mysqli->rollback();
            throw new \Exception($statement->error);
        }

        $statement->close();

        $statement = $mysqli->prepare("DELETE FROM $metaTableName WHERE vector_id = ?");
        $statement->bind_param('i', $id);
        $success = $statement->execute();

        if(!$success) {
            $mysqli->rollback();
            throw new \Exception($statement->error);
        }

        $statement->close();

        $mysqli->commit();
    }
}