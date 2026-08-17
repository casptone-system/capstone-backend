<?php
// Check dean permissions and assignment

$host = getenv('DB_HOST');
$database = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error;
    exit;
}

echo "=== Checking Permissions Table ===\n";
$result = $conn->query("SELECT id, name FROM permissions WHERE name LIKE '%access%' OR name LIKE '%dean%' OR name LIKE '%dashboard%'");
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "- ID: " . $row["id"] . ", Name: " . $row["name"] . "\n";
    }
} else {
    echo "No permissions found matching criteria\n";
}

echo "\n=== Checking Dean Role ===\n";
$result = $conn->query("SELECT id, name FROM roles WHERE name = 'Dean'");
if ($result->num_rows > 0) {
    $dean_role = $result->fetch_assoc();
    echo "Dean role ID: " . $dean_role["id"] . "\n";
    
    echo "\n=== Checking Dean Role Permissions ===\n";
    $result2 = $conn->query("
        SELECT p.id, p.name 
        FROM permissions p
        INNER JOIN role_has_permissions rhp ON p.id = rhp.permission_id
        WHERE rhp.role_id = " . $dean_role["id"]
    );
    
    if ($result2->num_rows > 0) {
        echo "Dean has " . $result2->num_rows . " permissions:\n";
        while($row = $result2->fetch_assoc()) {
            echo "  - " . $row["name"] . "\n";
        }
    } else {
        echo "Dean role has NO permissions assigned!\n";
    }
} else {
    echo "Dean role not found!\n";
}

echo "\n=== Checking Dean Users ===\n";
$result = $conn->query("
    SELECT u.id, u.name, u.email, u.college_id 
    FROM users u
    INNER JOIN model_has_roles mhr ON u.id = mhr.model_id
    INNER JOIN roles r ON mhr.role_id = r.id
    WHERE r.name = 'Dean'
");

if ($result->num_rows > 0) {
    echo "Found " . $result->num_rows . " dean users:\n";
    while($row = $result->fetch_assoc()) {
        echo "  - " . $row["name"] . " (" . $row["email"] . ") - College: " . ($row["college_id"] ?? 'NULL') . "\n";
    }
} else {
    echo "No dean users found!\n";
}

$conn->close();
?>
