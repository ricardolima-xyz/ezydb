# ezydb

**ezydb** is a PHP library aimed at simplifying database interoperability and manipulation. It provides a foundational implementation of the [Data Access Object (DAO)](https://en.wikipedia.org/wiki/Data_access_object) design pattern, enabling basic [Create-Read-Update-Delete (CRUD)](https://en.wikipedia.org/wiki/Create,_read,_update_and_delete) operations with minimal SQL writing. Additionally, it includes utilities for SQL interoperability, making it easier to work with different database engines. With built-in support for prepared statements, it focuses on providing a secure and efficient way to interact with databases, while continuing to evolve as a project.

## Testing
```bash
composer update
composer test
```

## Notes - DAO
* Currently tested with MySQL, MariaDB, SQLite and PostgreSQL.
* It depends on PDO
* It is compatible with mysql if it uses ANSI mode. Make sure that tables were creaded in ANSI mode and set ANSI Mode when creating the PDO connection 
```php
$connection = new PDO(
    $connectionString, $dbUser, $dbPassword, [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET sql_mode="ANSI"']
); 
```

## Getting started - DAO

Fist you need a database. In our example, we are using SQLite as our database engine.
```sql
    CREATE TABLE IF NOT EXISTS testEntity (
        id INTEGER PRIMARY KEY,
        columnone VARCHAR(255),
        columntwo DOUBLE PRECISION,
        active CHAR(1) DEFAULT '1'
    );
```
Now, in your code you start by creating your dao;

```php
include_once 'dao.class.php';

$myDAO = new DAO(
    // PDO Connection string
    new PDO("sqlite:./test.sqlite"),
    // DB Table name
    'table',
    // Mapping of table column names and PDO param types
    [
        'id'            => PDO::PARAM_INT,
        'columnone'     => PDO::PARAM_STR,
        'columntwo'     => PDO::PARAM_STR,
        'active'        => PDO::PARAM_STR
    ],
    [
        // Defines which column is the primary key (requirement of this library)
        DAO::KEY                    => 'id',
        // Specifies whether the primary key is auto generated or not
        DAO::KEY_IS_AUTOGENERATED   => true
        // Specifies delete method, which could be deletion or deactivation
        DAO::DEL_METHOD             => DAO::DEL_METHOD_DEACTIVATE,
        // Case the delete method is deactivation, we should specify the column which serves as a flag
        // Deleted objects receive 0 and active objects receive 1.
        DAO::DEACTIVATE_COLUMN      => 'active'
    ]
);
```

Now you have an object which is ready to perform basic CRUD operations without writing one line of SQL:

```php
// Creates a new object in the database. Note that id doesn't need to be specified if it
// is autogenerated in database and this information was passed in the constructor
$id = $myDAO->create(['columnone'=>'abc', columntwo=>'1.2']);

// Retrieves the object with given primary key
$object = $myDAO->get($id);

// Checks the existence of the object without retrieving it
$itExists =  $myDAO->exists($id);
$itDoesNotExist =  $myDAO->exists('BLAHBLAHBLAH');

// Retrieves a list of objects
$result = $myDAO->list();

// Deletes the object
$result = $myDAO->del($id);
```

