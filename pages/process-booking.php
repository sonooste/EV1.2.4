<?php
// Set page title
$pageTitle = 'Processing Booking';

// Include configuration and required functions
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/station-functions.php';
require_once dirname(__DIR__) . '/includes/booking-functions.php';

// Require login
requireLogin();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('error', 'Invalid request method.');
    redirect('bookings.php');
}

// Get form data
$stationId = isset($_POST['station_id']) ? (int)$_POST['station_id'] : null;
$chargingPointId = isset($_POST['charging_point_id']) ? (int)$_POST['charging_point_id'] : null;
$date = isset($_POST['date']) ? $_POST['date'] : null;
$startTime = isset($_POST['start_time']) ? $_POST['start_time'] : null;
$endTime = isset($_POST['end_time']) ? $_POST['end_time'] : null;

// Validate required fields
if (!$stationId || !$chargingPointId || !$date || !$startTime || !$endTime) {
    setFlashMessage('error', 'Please fill in all required fields.');
    redirect('bookings.php');
}

try {
    // Get database connection
    $conn = getDbConnection();

    // Create booking datetime
    $bookingDatetime = $date . ' ' . $startTime;

    // Start transaction
    $conn->begin_transaction();

    // Check if charging point is available
    $stmt = $conn->prepare("SELECT charging_point_state FROM Charging_Points WHERE charging_point_id = ?");
    $stmt->bind_param("i", $chargingPointId);
    $stmt->execute();
    $result = $stmt->get_result();
    $chargingPoint = $result->fetch_assoc();

    if (!$chargingPoint || $chargingPoint['charging_point_state'] !== 'available') {
        throw new Exception('Charging point is not available.');
    }

    // Insert booking
    $stmt = $conn->prepare("INSERT INTO Bookings (booking_datetime, user_id, charging_point_id) VALUES (?, ?, ?)");
    $stmt->bind_param("sii", $bookingDatetime, $_SESSION['user_id'], $chargingPointId);

    if (!$stmt->execute()) {
        throw new Exception('Failed to create booking.');
    }

    // Update charging point status
    $stmt = $conn->prepare("UPDATE Charging_Points SET charging_point_state = 'reserved' WHERE charging_point_id = ?");
    $stmt->bind_param("i", $chargingPointId);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update charging point status.');
    }

    // Commit transaction
    $conn->commit();

    setFlashMessage('success', 'Booking created successfully!');
    redirect('pages/dashboard.php');

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        $conn->rollback();
    }

    error_log("Booking error: " . $e->getMessage());
    setFlashMessage('error', 'Failed to create booking. Please try again.');
    redirect('bookings.php?station_id=' . $stationId);
}