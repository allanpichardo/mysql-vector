#!/bin/bash
# Create the test database
mysql -uroot -proot -e "
CREATE DATABASE IF NOT EXISTS db;
GRANT ALL PRIVILEGES ON db.* TO 'db'@'%';
FLUSH PRIVILEGES;
"