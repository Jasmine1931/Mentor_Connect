<?php
/**
 * Mentor - Availability Management
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/Availability.php';

SessionHandler::requireRole('mentor');

$user_id = SessionHandler::getUserId();
$availabilityModel = new Availability();

$message = '';

// Handle adding availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_availability'])) {
    $date = $_POST['date'] ?? '';
    $start_time = $_POST['stAart_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    $result = $availabilityModel->addAvailability($user_id, $date, $start_time, $end_time);
    if ($result['success']) {
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($result['message']) . '</div>';
    } else {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($result['message']) . '</div>';
    }
}

// Handle deleting availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_availability'])) {
    $availability_id = intval($_POST['availability_id']);
    $result = $availabilityModel->deleteAvailability($availability_id, $user_id);
    if ($result['success']) {
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Availability deleted.</div>';
    } else {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($result['message']) . '</div>';
    }
}

$availability_slots = $availabilityModel->getMentorAvailability($user_id);
$booked_slots = $availabilityModel->getBookedSlots($user_id);
$stats = $availabilityModel->getAvailabilityStats($user_id);

$page_title = 'Availability';
?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar">
            <div class="sidebar-menu">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="my-mentees.php">
                    <i class="fas fa-users"></i> My Mentees
                </a>
                <a href="requests.php">
                    <i class="fas fa-envelope"></i> Requests
                </a>
                <a href="availability.php" class="active">
                    <i class="fas fa-calendar"></i> Availability
                </a>
                <a href="sessions.php">
                    <i class="fas fa-tasks"></i> Sessions
                </a>
                <a href="feedback.php">
                    <i class="fas fa-comments"></i> Feedback
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header">
                <h1><i class="fas fa-calendar"></i> Availability Management</h1>
            </div>

            <?php echo $message; ?>

            <!-- Stats -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Total Slots</p>
                        <p class="stat-value"><?php echo $stats['total_slots']; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Available</p>
                        <p class="stat-value" style="color: var(--success-color);"><?php echo $stats['available_slots']; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <p class="stat-label">Booked</p>
                        <p class="stat-value" style="color: var(--warning-color);"><?php echo $stats['booked_slots']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Add Availability Form -->
            <div class="card mb-4">
                <div class="card-header">Add New Availability</div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <div class="col-md-3">
                            <label for="date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="date" name="date" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="start_time" class="form-label">Start Time</label>
                            <input type="time" class="form-control" id="start_time" name="start_time" required>
                        </div>
                        <div class="col-md-3">
                            <label for="end_time" class="form-label">End Time</label>
                            <input type="time" class="form-control" id="end_time" name="end_time" required>
                        </div>
                        <div class="col-md-3">
                            <label>&nbsp;</label>
                            <button type="submit" name="add_availability" class="btn btn-primary w-100">
                                <i class="fas fa-plus"></i> Add Slot
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Available Slots -->
            <div class="card mb-4">
                <div class="card-header">Available Slots</div>
                <div class="card-body">
                    <?php if (empty($availability_slots)): ?>
                        <div class="no-data">
                            <i class="fas fa-calendar-times"></i>
                            <p>No available slots</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($availability_slots as $slot): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($slot['available_date'])); ?></td>
                                            <td><?php echo date('H:i', strtotime($slot['start_time'])); ?> - <?php echo date('H:i', strtotime($slot['end_time'])); ?></td>
                                            <td><span class="badge badge-active">Available</span></td>
                                            <td>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="availability_id" value="<?php echo $slot['availability_id']; ?>">
                                                    <button type="submit" name="delete_availability" class="btn btn-sm btn-danger" onclick="return confirm('Delete this slot?')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Booked Slots -->
            <div class="card">
                <div class="card-header">Booked Slots</div>
                <div class="card-body">
                    <?php if (empty($booked_slots)): ?>
                        <div class="no-data">
                            <i class="fas fa-folder-open"></i>
                            <p>No booked slots</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Mentee</th>
                                        <th>Session</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($booked_slots as $slot): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($slot['available_date'])); ?></td>
                                            <td><?php echo date('H:i', strtotime($slot['start_time'])); ?> - <?php echo date('H:i', strtotime($slot['end_time'])); ?></td>
                                            <td><?php echo htmlspecialchars($slot['first_name'] . ' ' . $slot['last_name']); ?></td>
                                            <td><?php echo $slot['session_number'] ? '#' . $slot['session_number'] : 'N/A'; ?></td>
                                            <td><span class="badge" style="background-color: #c6f6d5; color: #22543d;">Booked</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
