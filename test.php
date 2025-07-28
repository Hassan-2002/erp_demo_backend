<?php

   header("Access-Control-Allow-Origin: *"); // Allow requests from any origin
   header("Content-Type: application/json; charset=UTF-8"); // Tell client we're sending JSON
   header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE"); // Allowed HTTP methods for future expansion
   header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With
   ");
    $servername = "localhost";
    $username = "root"; // Default XAMPP MySQL username
    $password = "";     // Default XAMPP MySQL password (empty)
    $dbname = "store_erp_db"; // Your database name
    echo $dbname;
   $conn = new mysqli($servername, $username, $password, $dbname);
   if ($conn->connect_error) {
    http_response_code(500); // Internal Server Error
    echo json_encode(["message" => "Database connection failed: " . $conn->connect_error]);
    exit();
    }
    else{
        echo "connected";
    }
   echo "hello world";
   
?>  