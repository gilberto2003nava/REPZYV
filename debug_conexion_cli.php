<?php
$host = 'localhost';
$db   = 'u543028883_zoonosis';
$user = 'u543028883_subzo';
$pass = 'Zoonosis26@';

echo "Attempting connection to $host with user $user...\n";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    echo "Connection failed: " . mysqli_connect_error() . "\n";
    echo "Errno: " . mysqli_connect_errno() . "\n";
} else {
    echo "Connection successful!\n";
    mysqli_close($conn);
}
