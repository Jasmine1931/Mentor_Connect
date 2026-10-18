<?php
/**
 * Admin - Users Management
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/SessionHandler.php';
require_once __DIR__ . '/../../models/User.php';

SessionHandler::requireRole('admin');

$userModel = new User();
$role_filter = $_GET['role'] ?? null;
$page = intval($_GET['page'] ?? 1);
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$users = $userModel->getAllUsers($role_filter, $limit, $offset);

$page_title = 'Users Management';
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
                <a href="users.php" class="active">
                    <i class="fas fa-users"></i> Users
                </a>
                <a href="mentorships.php">
                    <i class="fas fa-handshake"></i> Mentorships
                </a>
                <a href="sessions.php">
                    <i class="fas fa-calendar"></i> Sessions
                </a>
                <a href="feedback.php">
                    <i class="fas fa-star"></i> Feedback
                </a>
                <a href="reports.php">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </div>
        </div>

        <div class="col-md-10 main-content">
            <div class="page-header">
                <h1><i class="fas fa-users"></i> Users Management</h1>
            </div>

            <!-- Filter Buttons -->
            <div class="btn-group mb-3" role="group">
                <a href="?role=" class="btn btn-outline-primary <?php echo !$role_filter ? 'active' : ''; ?>">All</a>
                <a href="?role=mentor" class="btn btn-outline-primary <?php echo $role_filter === 'mentor' ? 'active' : ''; ?>">Mentors</a>
                <a href="?role=mentee" class="btn btn-outline-primary <?php echo $role_filter === 'mentee' ? 'active' : ''; ?>">Mentees</a>
                <a href="?role=admin" class="btn btn-outline-primary <?php echo $role_filter === 'admin' ? 'active' : ''; ?>">Admins</a>
            </div>

            <div class="card">
                <div class="card-header">Users List</div>
                <div class="card-body">
                    <?php if (empty($users)): ?>
                        <div class="no-data">
                            <i class="fas fa-folder-open"></i>
                            <p>No users found</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Domain</th>
                                        <th>Status</th>
                                        <th>Rating</th>
                                        <th>Sessions</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <span class="badge" style="background-color: #dbeafe; color: #1e40af;">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['domain']); ?></td>
                                            <td>
                                                <?php if ($user['is_active']): ?>
                                                    <span class="badge badge-active">Active</span>
                                                <?php else: ?>
                                                    <span class="badge" style="background-color: #fee2e2; color: #991b1b;">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($user['role'] === 'mentor'): ?>
                                                    <div class="rating">
                                                        <?php for ($i = 0; $i < 5; $i++): ?>
                                                            <i class="fas fa-star<?php echo $i < floor($user['average_rating']) ? '' : '-o'; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $user['total_sessions_completed']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
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
