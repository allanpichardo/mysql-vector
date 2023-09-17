---

# **MySQLVector Library**

## **Overview**

A PHP-based utility designed for efficient management and querying of vectors in a MySQL database. It supports operations like insertion, updating, deletion, and cosine similarity-based search.

## **Usage Instructions**

### **1. Initialize `VectorTable`**

To begin, instantiate the `VectorTable` class:

```php
use MHz\MysqlVector\VectorTable;

$dimension = 512;
$vectorTable = new VectorTable('test_table', $dimension);
```

### **2. Create a New Vector Table**

To prepare the necessary tables in the database:

```php
$vectorTable->initialize($mysqli);
```

### **3. Inserting and Updating Vectors**

To insert a new vector:

```php
$vec = [/* ... vector values ... */];
$id = $vectorTable->upsert($mysqli, $vec);
```

To update an existing vector, provide its ID:

```php
$vectorTable->upsert($mysqli, $vec, $specificId);
```

### **4. Delete Vectors**

To remove a specific vector:

```php
$vectorTable->delete($mysqli, $id);
```

### **5. Search Based on Cosine Similarity**

Find vectors most similar to a target vector:

```php
$results = $vectorTable->search($mysqli, $targetVector, $limit);
```

## **Contributions**

We welcome contributions! If you'd like to contribute, please follow the standard pull request process:

1. Fork the repository.
2. Create a new branch with a meaningful name.
3. Implement your changes or enhancements.
4. Submit a pull request to the main branch.

Ensure you've tested your code before submitting a pull request.

## **License**

This project is licensed under the MIT License. The MIT License is a permissive free software license allowing reuse within proprietary software provided all copies of the licensed software include a copy of the MIT License terms.

---

**Note:** This library is pure SQL and does not use any native C bindings. Therefore it is not as fast as it could be. However, it is still useful for small-scale applications (300 high-dimensional vectors or less).
If you would like to contribute any performance optimizations, please feel free to submit a pull request.