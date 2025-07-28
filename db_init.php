<?php

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "store_erp_db";

header("Access-Control-Allow-Origin: *");
header("Content-Type: text/plain; charset=UTF-8");

echo "Attempting to initialize the ERP database.\n";

$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die("ERROR: Could not connect to MySQL server. Error: " . $conn->connect_error);
}
echo "Connected to MySQL server successfully.\n";

$sql_create_db = "CREATE DATABASE IF NOT EXISTS " . $dbname . ";";
if ($conn->query($sql_create_db) === TRUE) {
    echo "Database '" . $dbname . "' created or already exists.\n";
} else {
    die("ERROR: Could not create database '" . $dbname . "'. Error: " . $conn->error);
}

$conn->select_db($dbname);
echo "Selected database '" . $dbname . "'.\n";

echo "Dropping existing tables (if any) for a clean setup.\n";
$conn->query("DROP TABLE IF EXISTS order_items;");
$conn->query("DROP TABLE IF EXISTS orders;");
$conn->query("DROP TABLE IF EXISTS products;");
$conn->query("DROP TABLE IF EXISTS customers;");
echo "Existing tables dropped.\n";

echo "Creating new tables.\n";
$sql_create_tables = "
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE,
    phone VARCHAR(50),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT,
    order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10, 2) NOT NULL,
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price_at_order DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
";

if ($conn->multi_query($sql_create_tables)) {
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    echo "Tables created successfully.\n";
} else {
    die("ERROR: Could not create tables. Error: " . $conn->error);
}

echo "Inserting sample data.\n";
$current_date_time = date('Y-m-d H:i:s');
$yesterday_date_time = date('Y-m-d H:i:s', strtotime('-1 day'));
$earlier_month_date_time = date('Y-m-d H:i:s', strtotime('-18 days'));

$sql_insert_data = "
INSERT INTO products (name, price, stock_quantity, description) VALUES
('Gaming Laptop Ultra', 75000.00, 30, 'High-performance gaming laptop, 16GB RAM, 1TB SSD'),
('Wireless Earbuds Pro', 2500.00, 150, 'Premium sound, active noise cancellation'),
('Smartwatch Series 7', 15000.00, 80, 'Health tracking, notifications, long battery'),
('External SSD 1TB', 6000.00, 120, 'Ultra-fast portable storage'),
('Mechanical Keyboard RGB', 3500.00, 90, 'Customizable RGB lighting, clicky switches');

INSERT INTO customers (name, email, phone) VALUES
('Abdullah Khan', 'abdullah.k@example.com', '+919876543210'),
('Rizwan Malik', 'rizwan.m@example.com', '+918765432109'),
('Fawwaz Ahmed', 'fawwaz.a@example.com', '+917654321098');
";

$conn->query($sql_insert_data);

$stmt = $conn->prepare("INSERT INTO orders (customer_id, order_date, total_amount, status) VALUES ((SELECT id FROM customers WHERE name = 'Abdullah Khan'), ?, 77500.00, 'completed')");
$stmt->bind_param("s", $current_date_time);
$stmt->execute();
$order_id_1 = $conn->insert_id;
$stmt->close();

$stmt = $conn->prepare("INSERT INTO orders (customer_id, order_date, total_amount, status) VALUES ((SELECT id FROM customers WHERE name = 'Rizwan Malik'), ?, 21000.00, 'completed')");
$stmt->bind_param("s", $current_date_time);
$stmt->execute();
$order_id_2 = $conn->insert_id;
$stmt->close();

$stmt = $conn->prepare("INSERT INTO orders (customer_id, order_date, total_amount, status) VALUES (NULL, ?, 3500.00, 'completed')");
$stmt->bind_param("s", $yesterday_date_time);
$stmt->execute();
$order_id_3 = $conn->insert_id;
$stmt->close();

$stmt = $conn->prepare("INSERT INTO orders (customer_id, order_date, total_amount, status) VALUES ((SELECT id FROM customers WHERE name = 'Abdullah Khan'), ?, 5000.00, 'completed')");
$stmt->bind_param("s", $current_date_time);
$stmt->execute();
$order_id_4 = $conn->insert_id;
$stmt->close();

$stmt = $conn->prepare("INSERT INTO orders (customer_id, order_date, total_amount, status) VALUES ((SELECT id FROM customers WHERE name = 'Fawwaz Ahmed'), ?, 75000.00, 'completed')");
$stmt->bind_param("s", $earlier_month_date_time);
$stmt->execute();
$order_id_5 = $conn->insert_id;
$stmt->close();

$stmt = $conn->prepare("INSERT INTO orders (customer_id, order_date, total_amount, status) VALUES ((SELECT id FROM customers WHERE name = 'Fawwaz Ahmed'), ?, 15000.00, 'completed')");
$stmt->bind_param("s", $yesterday_date_time);
$stmt->execute();
$order_id_6 = $conn->insert_id;
$stmt->close();

$stmt_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price_at_order) VALUES (?, ?, ?, ?)");

$stmt_item->bind_param("iiid", $order_id_1, (int)$conn->query("SELECT id FROM products WHERE name = 'Gaming Laptop Ultra'")->fetch_assoc()['id'], 1, 75000.00); $stmt_item->execute();
$stmt_item->bind_param("iiid", $order_id_1, (int)$conn->query("SELECT id FROM products WHERE name = 'Wireless Earbuds Pro'")->fetch_assoc()['id'], 1, 2500.00); $stmt_item->execute();

$stmt_item->bind_param("iiid", $order_id_2, (int)$conn->query("SELECT id FROM products WHERE name = 'Smartwatch Series 7'")->fetch_assoc()['id'], 1, 15000.00); $stmt_item->execute();
$stmt_item->bind_param("iiid", $order_id_2, (int)$conn->query("SELECT id FROM products WHERE name = 'External SSD 1TB'")->fetch_assoc()['id'], 1, 6000.00); $stmt_item->execute();

$stmt_item->bind_param("iiid", $order_id_3, (int)$conn->query("SELECT id FROM products WHERE name = 'Mechanical Keyboard RGB'")->fetch_assoc()['id'], 1, 3500.00); $stmt_item->execute();

$stmt_item->bind_param("iiid", $order_id_4, (int)$conn->query("SELECT id FROM products WHERE name = 'Wireless Earbuds Pro'")->fetch_assoc()['id'], 2, 2500.00); $stmt_item->execute();

$stmt_item->bind_param("iiid", $order_id_5, (int)$conn->query("SELECT id FROM products WHERE name = 'Gaming Laptop Ultra'")->fetch_assoc()['id'], 1, 75000.00); $stmt_item->execute();

$stmt_item->bind_param("iiid", $order_id_6, (int)$conn->query("SELECT id FROM products WHERE name = 'Smartwatch Series 7'")->fetch_assoc()['id'], 1, 15000.00); $stmt_item->execute();

$stmt_item->close();

echo "Sample data inserted successfully.\n";

$conn->close();
echo "\nDatabase initialization complete!";

?>