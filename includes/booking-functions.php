<?php
/**
 * Booking related functions
 */

/**
 * Create a new booking
 *
 * @param int $userId User ID
 * @param int $chargingPointId Charging point ID
 * @param string $date Booking date (Y-m-d)
 * @param string $startTime Start time (H:i)
 * @param string $endTime End time (H:i)
 * @return int|bool Booking ID on success, false on failure
 */
function createBooking($userId, $chargingPointId, $date, $startTime, $endTime) {
    try {
        $conn = getDbConnection();
        $conn->begin_transaction();

        // Check if the charging point is available
        if (!isTimeSlotAvailable($chargingPointId, $date, $startTime, $endTime)) {
            throw new Exception('Selected time slot is not available.');
        }

        // Insert booking data
        $stmt = $conn->prepare("
            INSERT INTO Bookings (user_id, charging_point_id, booking_date, start_time, end_time, status)
            VALUES (?, ?, ?, ?, ?, 'scheduled')
        ");

        $stmt->bind_param("iisss", $userId, $chargingPointId, $date, $startTime, $endTime);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create booking.');
        }

        $bookingId = $conn->insert_id;

        // Update charging point status
        $stmt = $conn->prepare("
            UPDATE Charging_Points 
            SET charging_point_state = 'reserved' 
            WHERE charging_point_id = ?
        ");

        $stmt->bind_param("i", $chargingPointId);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update charging point status.');
        }

        $conn->commit();
        return $bookingId;

    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        error_log("Booking error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a time slot is available
 *
 * @param int $chargingPointId Charging point ID
 * @param string $date Date (Y-m-d)
 * @param string $startTime Start time (H:i)
 * @param string $endTime End time (H:i)
 * @return bool True if available, false if not
 */
function isTimeSlotAvailable($chargingPointId, $date, $startTime, $endTime) {
    $conn = getDbConnection();
    
    // Check if charging point exists and is operational
    $stmt = $conn->prepare("
        SELECT charging_point_state 
        FROM Charging_Points 
        WHERE charging_point_id = ?
    ");
    
    $stmt->bind_param("i", $chargingPointId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || !($point = $result->fetch_assoc())) {
        return false;
    }

    if ($point['charging_point_state'] === 'maintenance') {
        return false;
    }

    // Check for overlapping bookings
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM Bookings 
        WHERE charging_point_id = ? 
        AND booking_date = ?
        AND status IN ('scheduled', 'active')
        AND (
            (start_time <= ? AND end_time > ?) OR
            (start_time < ? AND end_time >= ?) OR
            (start_time >= ? AND end_time <= ?)
        )
    ");

    $stmt->bind_param("isssssss", 
        $chargingPointId, 
        $date, 
        $startTime, $startTime,
        $endTime, $endTime,
        $startTime, $endTime
    );

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return $row['count'] == 0;
}

/**
 * Get available time slots for a charging point
 *
 * @param int $chargingPointId Charging point ID
 * @param string $date Date (Y-m-d)
 * @return array Array of available time slots
 */
function getAvailableTimeSlots($chargingPointId, $date) {
    $timeSlots = [];
    $startHour = 6; // 6 AM
    $endHour = 22; // 10 PM
    $interval = 60; // 60 minutes per slot

    for ($hour = $startHour; $hour < $endHour; $hour++) {
        $startTime = sprintf("%02d:00", $hour);
        $endTime = sprintf("%02d:00", $hour + 1);

        if (isTimeSlotAvailable($chargingPointId, $date, $startTime, $endTime)) {
            $timeSlots[] = [
                'start' => $startTime,
                'end' => $endTime
            ];
        }
    }

    return $timeSlots;
}

/**
 * Cancel a booking
 *
 * @param int $bookingId Booking ID
 * @param int $userId User ID (for verification)
 * @return bool True on success, false on failure
 */
function cancelBooking($bookingId, $userId) {
    try {
        $conn = getDbConnection();
        $conn->begin_transaction();

        // Get booking details
        $stmt = $conn->prepare("
            SELECT charging_point_id 
            FROM Bookings 
            WHERE booking_id = ? AND user_id = ? AND status = 'scheduled'
        ");
        
        $stmt->bind_param("ii", $bookingId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || !($booking = $result->fetch_assoc())) {
            throw new Exception('Booking not found or cannot be cancelled.');
        }

        // Update booking status
        $stmt = $conn->prepare("
            UPDATE Bookings 
            SET status = 'cancelled' 
            WHERE booking_id = ?
        ");
        
        $stmt->bind_param("i", $bookingId);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to cancel booking.');
        }

        // Update charging point status
        $stmt = $conn->prepare("
            UPDATE Charging_Points 
            SET charging_point_state = 'available' 
            WHERE charging_point_id = ?
        ");
        
        $stmt->bind_param("i", $booking['charging_point_id']);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update charging point status.');
        }

        $conn->commit();
        return true;

    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        error_log("Cancel booking error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user's upcoming bookings
 *
 * @param int $userId User ID
 * @return array Array of upcoming bookings
 */
function getUpcomingBookings($userId) {
    $conn = getDbConnection();
    
    $sql = "
        SELECT b.*, cp.charging_point_state, s.address_street, s.address_city
        FROM Bookings b
        JOIN Charging_Points cp ON b.charging_point_id = cp.charging_point_id
        JOIN Stations s ON cp.station_id = s.station_id
        WHERE b.user_id = ? 
        AND b.booking_date >= CURDATE()
        AND b.status = 'scheduled'
        ORDER BY b.booking_date, b.start_time
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}