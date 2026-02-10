<?php
$servername = "localhost";
$username = "cookyjyv_root";
$password = "M0ns7er10!";
$dbname = "cookyjyv_cookventory";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
echo "Connected successfully";
?>