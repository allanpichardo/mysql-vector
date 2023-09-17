<?php

namespace MHz\MysqlVector;

class VectorTable
{
    private string $name;
    private int $dimension;
    private string $engine;

    public function __construct(string $name, int $dimension, string $engine = 'InnoDB')
    {
        $this->name = $name;
        $this->dimension = $dimension;
        $this->engine = $engine;
    }

    public function getMetaTableName(): string
    {
        return sprintf('vector_meta_%s', $this->name);
    }

    public function getValuesTableName(): string
    {
        return sprintf('vector_values_%s', $this->name);
    }

    public function getCreateStatements(bool $ifNotExists = true): array {
        $metaQuery =
            "CREATE TABLE %s %s (
                vector_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=%s;";
        $metaQuery = sprintf($metaQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getMetaTableName(), $this->engine);

        $valuesQuery =
            "CREATE TABLE %s %s (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vector_id INT UNSIGNED NOT NULL,
                element_position INT,
                vector_value DOUBLE,
                FOREIGN KEY (vector_id) REFERENCES vector_meta_%s(vector_id)
            ) ENGINE=%s;";
        $valuesQuery = sprintf($valuesQuery, $ifNotExists ? 'IF NOT EXISTS' : '', $this->getValuesTableName(), $this->name, $this->engine);

        $indexValuesQuery = "CREATE INDEX vector_id_index_%s ON vector_values_%s (vector_id);";
        $indexValuesQuery = sprintf($indexValuesQuery, $this->name, $this->name);

        $indexPositionQuery = "CREATE INDEX element_position_index_%s ON vector_values_%s (element_position);";
        $indexPositionQuery = sprintf($indexPositionQuery, $this->name, $this->name);

        $uniqueConstraintQuery = "ALTER TABLE vector_values_%s ADD CONSTRAINT unique_vector_id_element_position UNIQUE (vector_id, element_position);";
        $uniqueConstraintQuery = sprintf($uniqueConstraintQuery, $this->name);

        return [$metaQuery, $valuesQuery, $indexValuesQuery, $indexPositionQuery, $uniqueConstraintQuery];
    }

    public function delete(\mysqli $mysqli, $id): bool {
        $del = $mysqli->prepare("DELETE FROM {$this->getMetaTableName()} WHERE vector_id = ?");
        $del->bind_param('i', $id);
        return $del->execute();
    }

    /**
     * @throws \Exception
     */
    public function upsert(\mysqli $mysqli, array $vector, int $id = null): int
    {
        if(count($vector) != $this->dimension) {
            throw new \Exception('Vector dimension does not match');
        }

        $vectorId = $id;
        if(empty($vectorId)) {
            $metaTableName = $this->getMetaTableName();

            $statement = $mysqli->prepare("INSERT INTO $metaTableName () VALUES ()");
            $success = $statement->execute();

            if (!$success) {
                throw new \Exception($statement->error);
            }

            $vectorId = $statement->insert_id;
            $statement->close();
        }

        $valuesTableName = $this->getValuesTableName();

        $placeholders = implode(', ', array_fill(0, count($vector), '(?, ?, ?)'));
        $statement = $mysqli->prepare("INSERT INTO $valuesTableName (vector_id, element_position, vector_value) VALUES $placeholders ON DUPLICATE KEY UPDATE vector_value = VALUES(vector_value)");

        $bindParams = [];
        $types = '';
        foreach ($vector as $position => $value) {
            $bindParams[] = $vectorId;
            $bindParams[] = $position;
            $bindParams[] = $vector[$position];
            $types .= 'iid';
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
     * @param \mysqli $mysqli
     * @param array $ids
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

    public function count(\mysqli $mysqli): int {
        $metaTableName = $this->getMetaTableName();
        $statement = $mysqli->prepare("SELECT COUNT(vector_id) FROM $metaTableName");
        $statement->execute();
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();
        return $count;
    }
}