# Create test tables
# ------------------
CREATE TABLE IF NOT EXISTS vector_meta_test_table (
                                                      vector_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                                      created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS vector_values_test_table (
                                                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                                        vector_id INT UNSIGNED NOT NULL,
                                                        element_position INT,
                                                        vector_value DOUBLE,
                                                        FOREIGN KEY (vector_id) REFERENCES vector_meta_test_table(vector_id)
    ) ENGINE=InnoDB;
CREATE INDEX vector_id_index_test_table ON vector_values_test_table (vector_id);
CREATE INDEX element_position_index_test_table ON vector_values_test_table (element_position);

# Create test data
# ----------------
-- Inserting data into vector_meta_test_table
INSERT INTO vector_meta_test_table (vector_id) VALUES (1);
INSERT INTO vector_meta_test_table (vector_id) VALUES (2);
INSERT INTO vector_meta_test_table (vector_id) VALUES (3);

-- Inserting data into vector_values_test_table
INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (1, 1, 0.5);
INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (1, 2, 0.6);
INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (1, 3, 0.7);

INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (2, 1, 0.8);
INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (2, 2, 0.9);
INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (2, 3, 1.0);

INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (3, 1, 1.1);
INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (3, 2, 1.2);
INSERT INTO vector_values_test_table (vector_id, element_position, vector_value) VALUES (3, 3, 1.3);