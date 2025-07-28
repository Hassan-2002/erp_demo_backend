<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "store_erp_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

$resource = $_GET['resource'] ?? 'products';
$id = $_GET['id'] ?? null;

$response_data = [];
$current_datetime = date('Y-m-d H:i:s');
$today_date = date('Y-m-d');

switch ($resource) {
    case 'products':
        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            if ($id) {
                $stmt = $conn->prepare("SELECT id, name, price, stock_quantity, description FROM products WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $response_data = $result->fetch_assoc();
                $stmt->close();
                if (!$response_data) {
                    http_response_code(404);
                    $response_data = ["message" => "Product not found."];
                }
            } else {
                $result = $conn->query("SELECT id, name, price, stock_quantity, description FROM products");
                $products_list = [];
                while($row = $result->fetch_assoc()) {
                    $products_list[] = $row;
                }
                $response_data = ["success" => true, "products" => $products_list];
            }
        } else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $data = json_decode(file_get_contents("php://input"), true);
            $name = $data['name'] ?? '';
            $price = $data['price'] ?? 0.0;
            $stock_quantity = $data['stock_quantity'] ?? 0;
            $description = $data['description'] ?? '';

            if (empty($name) || $price <= 0) {
                http_response_code(400);
                echo json_encode(["message" => "Name and valid price are required."]);
                $conn->close();
                exit();
            }

            $stmt = $conn->prepare("INSERT INTO products (name, price, stock_quantity, description) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sdis", $name, $price, $stock_quantity, $description);

            if ($stmt->execute()) {
                http_response_code(201);
                $response_data = ["message" => "Product created.", "id" => $conn->insert_id];
            } else {
                http_response_code(500);
                $response_data = ["message" => "Failed to create product: " . $stmt->error];
            }
            $stmt->close();
        } else {
            http_response_code(405);
            $response_data = ["message" => "Method not allowed for products resource."];
        }
        break;

    case 'sales':
        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            $startDate = $_GET['startDate'] ?? null;
            $endDate = $_GET['endDate'] ?? null;

            $sql = "SELECT
                        oi.id AS id,
                        o.id AS order_id,
                        o.order_date AS sale_date,
                        o.total_amount AS order_total_amount,
                        p.name AS product_name,
                        oi.quantity AS quantity,
                        oi.price_at_order AS amount,
                        c.name AS customer_name
                    FROM
                        orders o
                    JOIN
                        order_items oi ON o.id = oi.order_id
                    JOIN
                        products p ON oi.product_id = p.id
                    LEFT JOIN
                        customers c ON o.customer_id = c.id
                    WHERE 1=1";

            $params = [];
            $types = "";

            if ($startDate) {
                $sql .= " AND DATE(o.order_date) >= ?";
                $types .= "s";
                $params[] = $startDate;
            }
            if ($endDate) {
                $sql .= " AND DATE(o.order_date) <= ?";
                $types .= "s";
                $params[] = $endDate;
            }

            $sql .= " ORDER BY o.order_date DESC, o.id DESC, oi.id ASC";
            $stmt = $conn->prepare($sql);

            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            $sales_records = [];
            while($row = $result->fetch_assoc()) {
                $sales_records[] = $row;
            }
            $stmt->close();
            $response_data = ["success" => true, "sales" => $sales_records];
        } else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $data = json_decode(file_get_contents("php://input"), true);

            $customer_id = $data['customer_id'] ?? null;
            $customer_name_input = trim($data['customer_name_input'] ?? '');
            $items = $data['items'] ?? [];

            if (empty($items)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "No items provided for the sale."]);
                $conn->close();
                exit();
            }

            $conn->begin_transaction();
            try {
                if ($customer_id) {
                    $stmt_check_cust = $conn->prepare("SELECT id FROM customers WHERE id = ?");
                    $stmt_check_cust->bind_param("i", $customer_id);
                    $stmt_check_cust->execute();
                    $result_check_cust = $stmt_check_cust->get_result();
                    if ($result_check_cust->num_rows === 0) {
                        throw new Exception("Selected customer ID does not exist.");
                    }
                    $stmt_check_cust->close();
                } elseif (!empty($customer_name_input)) {
                    $stmt_find_cust = $conn->prepare("SELECT id FROM customers WHERE name = ?");
                    $stmt_find_cust->bind_param("s", $customer_name_input);
                    $stmt_find_cust->execute();
                    $result_find_cust = $stmt_find_cust->get_result();
                    if ($result_find_cust->num_rows > 0) {
                        $customer_row = $result_find_cust->fetch_assoc();
                        $customer_id = $customer_row['id'];
                    } else {
                        $stmt_create_cust = $conn->prepare("INSERT INTO customers (name) VALUES (?)");
                        $stmt_create_cust->bind_param("s", $customer_name_input);
                        if (!$stmt_create_cust->execute()) {
                            throw new Exception("Failed to create new customer: " . $stmt_create_cust->error);
                        }
                        $customer_id = $conn->insert_id;
                        $stmt_create_cust->close();
                    }
                    $stmt_find_cust->close();
                } else {
                    $customer_id = null;
                }

                $total_amount = 0;
                $products_to_update_stock = [];
                $processed_items = [];

                foreach ($items as $item) {
                    $product_id = $item['product_id'] ?? 0;
                    $quantity = $item['quantity'] ?? 0;

                    if ($product_id <= 0 || $quantity <= 0) {
                        throw new Exception("Invalid product ID or quantity for an item.");
                    }

                    $stmt_product = $conn->prepare("SELECT price, stock_quantity FROM products WHERE id = ?");
                    $stmt_product->bind_param("i", $product_id);
                    $stmt_product->execute();
                    $result_product = $stmt_product->get_result();
                    $product_row = $result_product->fetch_assoc();
                    $stmt_product->close();

                    if (!$product_row) {
                        throw new Exception("Product with ID $product_id not found.");
                    }

                    $price_at_order = $product_row['price'];
                    $current_stock = $product_row['stock_quantity'];

                    if ($current_stock < $quantity) {
                        throw new Exception("Insufficient stock for product ID $product_id. Available: $current_stock, Requested: $quantity.");
                    }

                    $total_amount += ($price_at_order * $quantity);
                    $products_to_update_stock[$product_id] = ($products_to_update_stock[$product_id] ?? 0) + $quantity;

                    $processed_items[] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'price_at_order' => $price_at_order
                    ];
                }

                $stmt_order = $conn->prepare("INSERT INTO orders (customer_id, order_date, total_amount, status) VALUES (?, ?, ?, 'completed')");

                $types_order = "";
                $params_order = [];

                if ($customer_id !== null) {
                    $types_order = "isd";
                    $params_order = [$customer_id, $current_datetime, $total_amount];
                } else {
                    $types_order = "ssd";
                    $params_order = [null, $current_datetime, $total_amount];
                }

                $stmt_order->bind_param($types_order, ...$params_order);

                if (!$stmt_order->execute()) {
                    throw new Exception("Failed to create order: " . $stmt_order->error);
                }
                $order_id = $conn->insert_id;
                $stmt_order->close();

                $stmt_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price_at_order) VALUES (?, ?, ?, ?)");
                foreach ($processed_items as $item) {
                    $product_id = $item['product_id'];
                    $quantity = $item['quantity'];
                    $price_at_order = $item['price_at_order'];

                    $stmt_item->bind_param("iiid", $order_id, $product_id, $quantity, $price_at_order);
                    if (!$stmt_item->execute()) {
                        throw new Exception("Failed to add order item for product ID $product_id: " . $stmt_item->error);
                    }
                }
                $stmt_item->close();

                $stmt_update_stock = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                foreach ($products_to_update_stock as $product_id => $quantity_sold) {
                    $stmt_update_stock->bind_param("ii", $quantity_sold, $product_id);
                    if (!$stmt_update_stock->execute()) {
                        throw new Exception("Failed to update stock for product ID $product_id: " . $stmt_update_stock->error);
                    }
                }
                $stmt_update_stock->close();

                $conn->commit();

                http_response_code(201);
                $response_data = ["success" => true, "message" => "Sale recorded successfully.", "order_id" => $order_id];

            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500);
                $response_data = ["success" => false, "message" => "Error recording sale: " . $e->getMessage()];
            }
            $conn->close();
            exit();

        } else {
            http_response_code(405);
            $response_data = ["message" => "Method not allowed for sales resource."];
        }
        break;

    case 'dashboard_summary':
        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            $stmt = $conn->prepare("SELECT SUM(total_amount) AS total_today FROM orders WHERE DATE(order_date) = ?");
            $stmt->bind_param("s", $today_date);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $response_data['todaySales'] = (float)($row['total_today'] ?? 0.00);
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT SUM(oi.quantity) AS items_sold_today
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE DATE(o.order_date) = ?
            ");
            $stmt->bind_param("s", $today_date);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $response_data['itemsSoldToday'] = (int)($row['items_sold_today'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("SELECT SUM(total_amount) AS grand_total FROM orders");
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $response_data['totalSales'] = (float)($row['grand_total'] ?? 0.00);
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT o.id AS order_id, o.order_date, o.total_amount
                FROM orders o
                ORDER BY o.order_date DESC
                LIMIT 5
            ");
            $stmt->execute();
            $result = $stmt->get_result();
            $recent_orders = [];
            while ($row = $result->fetch_assoc()) {
                $recent_orders[] = [
                    "id" => "#" . $row['order_id'],
                    "date" => $row['order_date'],
                    "amount" => (float)$row['total_amount']
                ];
            }
            $response_data['recentOrders'] = $recent_orders;
            $stmt->close();

            $first_day_of_month = date('Y-m-01');
            $last_day_of_month = date('Y-m-t');

            $stmt = $conn->prepare("
                SELECT
                    p.name AS product_name,
                    SUM(oi.quantity) AS units_sold,
                    SUM(oi.quantity * oi.price_at_order) AS revenue
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                JOIN orders o ON oi.order_id = o.id
                WHERE o.order_date BETWEEN ? AND ?
                GROUP BY p.name
                ORDER BY revenue DESC
                LIMIT 5
            ");
            $stmt->bind_param("ss", $first_day_of_month, $last_day_of_month);
            $stmt->execute();
            $result = $stmt->get_result();
            $top_products = [];
            $rank = 1;
            while ($row = $result->fetch_assoc()) {
                $top_products[] = [
                    "rank" => $rank++,
                    "name" => $row['product_name'],
                    "units" => (int)$row['units_sold'],
                    "revenue" => (float)$row['revenue']
                ];
            }
            $response_data['topSellingProducts'] = $top_products;
            $stmt->close();

            $response_data['lastUpdated'] = date('Y-m-d H:i:s');
        } else {
            http_response_code(405);
            $response_data = ["message" => "Method not allowed for dashboard_summary."];
        }
        break;

    case 'customers':
        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            $result = $conn->query("SELECT id, name FROM customers ORDER BY name ASC");
            $customer_list = [];
            while($row = $result->fetch_assoc()) {
                $customer_list[] = $row;
            }
            $response_data = ["success" => true, "customers" => $customer_list];
        } else {
            http_response_code(405);
            $response_data = ["message" => "Method not allowed for customers resource."];
        }
        break;

    default:
        http_response_code(404);
        $response_data = ["message" => "Resource not found."];
        break;
}

$conn->close();
echo json_encode($response_data);