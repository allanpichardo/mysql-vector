# A Library for MySQL Vector Operations and Text Embeddings

## Overview
The `VectorTable` class is a PHP implementation designed to facilitate the storage, retrieval, and comparison of high-dimensional vectors in a MySQL database. This class utilizes MySQL JSON data types and a custom cosine similarity function (`COSIM`) to perform vector comparisons efficiently.

**Note:** This library is only suitable for small datasets (less than 100,000 vectors). However, it is not recommended for large datasets. For large datasets, it is recommended that you use a dedicated vector database such as [Milvus](https://milvus.io/).

## Features
- Store vectors in a MySQL database using JSON data types.
- Calculate cosine similarity between vectors using a custom MySQL function.
- Normalize vectors and handle vector operations such as insertion, deletion, and searching.
- Support for vector quantization for optimized search operations.
- Native PHP support for generating for text embeddings using the [BGE embedding model](https://huggingface.co/BAAI/bge-base-en-v1.5).

## Requirements
- PHP 8.0 or higher.
- MySQL 5.7 or higher with support for JSON data types and stored functions.
- A MySQLi extension for PHP.

## Installation
1. Ensure that PHP and MySQL are installed and properly configured on your system.
2. Install the library using [Composer](https://getcomposer.org/).

   ```bash
   composer require allanpichardo/mysql-vector
   ```

## Usage

### Initializing the Vector Table
Import the `VectorTable` class and create a new instance using the MySQLi connection, table name, and vector dimension.
```php
use MHz\MysqlVector\VectorTable;


$mysqli = new mysqli("hostname", "username", "password", "database");
$tableName = "my_vector_table";
$dimension = 384;
$engine = 'InnoDB';

$vectorTable = new VectorTable($mysqli, $tableName, $dimension, $engine);
```

### Setting Up the Vector Table in MySQL
The `initialize` method will create the vector table in MySQL if it does not already exist. This method will also create the `COSIM` function in MySQL if it does not already exist.
```php
$vectorTable->initialize();
```

### Inserting and Managing Vectors
```php
// Insert a new vector
$vector = [0.1, 0.2, 0.3, ..., 0.384];
$vectorId = $vectorTable->upsert($vector);

// Update an existing vector
$vectorTable->upsert($vector, $vectorId);

// Delete a vector
$vectorTable->delete($vectorId);
```

### Calculating Cosine Similarity
```php
// Calculate cosine similarity between two vectors
$similarity = $vectorTable->cosim($vector1, $vector2);
```

### Searching for Similar Vectors
Perform a search for vectors similar to a given vector using the cosine similarity criteria. The `topN` parameter specifies the maximum number of similar vectors to return.
```php
// Find vectors similar to a given vector
$similarVectors = $vectorTable->search($vector, $topN);
```

### Quantizing Vectors to Improve Search Performance
Quantization is a technique that can be used to improve the performance of vector searches. This technique involves dividing the vector space into a set of regions and assigning each vector to a region. When performing a vector search, only vectors in the same region as the query vector are considered. This technique can significantly reduce the number of vectors that need to be compared when performing a vector search.

The `VectorTable` class supports vector quantization using the `performVectorQuantization` method. It is recommended that you perform vector quantization after inserting roughly 1000 vectors or more. Depending on your use case, as your dataset grows, you may need to perform vector quantization again to maintain performance and accuracy.

To perform vector quantization, you will need a second `\mysqli` connection to the database.

```php
// Create a new mysqli connection
$mysqli2 = new mysqli("hostname", "username", "password", "database");

// Perform vector quantization
$vectorTable->performVectorQuantization($mysqli2);
```

## Text Embeddings
The `Embedder` class calculates 384-dimensional text embeddings using the [BGE embedding model](https://huggingface.co/BAAI/bge-base-en-v1.5). The first time you instanciate the `Embedder` class, the ONNX runtime will be installed automatically.
The maximum length of the input text is 512 characters. The `Embedder` class will automatically truncate the input text to 512 characters if it is longer than 512 characters.

```php
use MHz\MysqlVector\Nlp\Embedder;

$embedder = new Embedder();

// Calculate the embeddings for a batch of text
$texts = ["Hello world!", "This is a test."];
$embeddings = $embedder->embed($texts);

print_r($embeddings[0][0]); // [0.1, 0.2, 0.3, ..., 0.384]
print_r($embeddings[1][0]); // [0.1, 0.2, 0.3, ..., 0.384]
```

## Contributions
Contributions to this project are welcome. Please ensure that your code adheres to the existing coding standards and includes appropriate tests.

## License
MIT License