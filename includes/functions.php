<?php

$conn;

function getTotalIncomeFromDB($conn, $tableName, $columnName) {
    try {
        $query = "SELECT SUM($columnName) as total FROM $tableName";
        $result = $conn->query($query);
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    } catch (Exception $e) {
        error_log("Error getting total: " . $e->getMessage());
        return 0;
    }
}