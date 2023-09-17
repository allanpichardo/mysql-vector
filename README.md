# MysqlVector Library

The MysqlVector library provides an abstraction to perform CRUD and search operations on vectors stored in a MySQL database, without requiring any extensions. This PHP-based solution allows efficient vector manipulation, including dot product calculations and similarity search in a normalized space.

## Installation

**Note:** Before using this library, ensure you have a MySQL database set up and accessible.

Clone this repository or download the library to your project.

## Features

1. **Easy Setup:** Quickly create tables to store vector meta-data and values with built-in methods.
2. **CRUD Operations:** Insert (upsert), delete, and fetch vectors directly.
3. **Dot Product:** Calculate the dot product between vectors, where one or both vectors are stored in the database.
4. **Similarity Search:** Find vectors most similar to a given input.
5. **Normalization:** Retrieve the normalized version of a vector.

## Usage

### Instantiation

To create a new vector table instance:

```php
use MHz\MysqlVector\VectorTable;

$vectorTable = new VectorTable('table_name', 3);  // 'table_name' and 3-dimensional vector.
```

### Creating Tables

For the first time, or when you wish to create tables:

```php
foreach ($vectorTable->getCreateStatements() as $statement) {
    $mysqli->query($statement);
}
```

### Upserting a Vector

To insert or update (upsert) a vector:

```php
$id = $vectorTable->upsert($mysqli, [1.0, 2.0, 3.0]);  // Returns the vector ID.
```

### Retrieving a Vector

To retrieve vectors by their IDs:

```php
$result = $vectorTable->select($mysqli, [$id1, $id2]);
```

### Calculating the Dot Product

To calculate the dot product between two vectors:

```php
$dotProduct = $vectorTable->dot($mysqli, $idA, $idB);
```

### Searching for Similar Vectors

To find vectors most similar to an input:

```php
$results = $vectorTable->search($mysqli, [1.0, 2.0, 3.0], 5);  // Returns top 5 most similar vectors.
```

## Testing

The library includes a test suite using PHPUnit. Ensure you have PHPUnit installed, and then run the tests from the root directory:

```
phpunit --bootstrap vendor/autoload.php tests/
```

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate.

## License

Please refer to the `LICENSE` file in the repository for licensing information.