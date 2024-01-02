# A Library for MySQL Vector Operations and Text Embeddings

## Overview
The `VectorTable` class is a PHP implementation designed to facilitate the storage, retrieval, and comparison of high-dimensional vectors in a MySQL database. This class utilizes MySQL JSON data types and a custom cosine similarity function (`COSIM`) to perform vector comparisons efficiently.

## Features
- Store vectors in a MySQL database using JSON data types.
- Calculate cosine similarity between vectors using a custom MySQL function.
- Normalize vectors and handle vector operations such as insertion, deletion, and searching.
- Support for vector quantization for optimized search operations.

## Requirements
- PHP 8.0 or higher.
- MySQL 5.7 or higher with support for JSON data types and stored functions.
- A MySQLi extension for PHP.

## Installation
1. Ensure that PHP and MySQL are installed and properly configured on your system.
2. Include the `VectorTable.php` file in your PHP project.
3. Use the provided namespace to access the `VectorTable` class.

   ```php
   use MHz\MysqlVector\VectorTable;
   ```

## Usage

### Initializing the Class
```php
$mysqli = new mysqli("hostname", "username", "password", "database");
$tableName = "my_vector_table";
$dimension = 384;
$engine = 'InnoDB';

$vectorTable = new VectorTable($mysqli, $tableName, $dimension, $engine);
```

### Creating Vector Tables
```php
$vectorTable->initialize();
```

### Inserting and Managing Vectors
```php
// Insert a new vector
$vector = [0.1, 0.2, 0.3, ..., 0.384];
$vectorId = $vectorTable->upsert($vector);

// Delete a vector
$vectorTable->delete($vectorId);
```

### Calculating Cosine Similarity
```php
// Calculate cosine similarity between two vectors
$similarity = $vectorTable->cosim($vector1, $vector2);
```

### Searching for Similar Vectors
```php
// Find vectors similar to a given vector
$similarVectors = $vectorTable->search($vector, $topN);
```

## Customization
You can customize the table name, vector dimension, and other parameters by adjusting the arguments passed to the `VectorTable` constructor.

## Contributions
Contributions to this project are welcome. Please ensure that your code adheres to the existing coding standards and includes appropriate tests.

## License
MIT License