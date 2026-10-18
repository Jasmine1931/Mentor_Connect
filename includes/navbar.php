<?php
$user = MySessionHandler::getUser();
?>

<nav class="navbar navbar-dark bg-dark p-3">
    <span class="navbar-brand">Mentor Connect</span>

    <div class="text-white">
        Welcome, <?php echo htmlspecialchars($user['name']); ?>
        <a href="logout.php" class="btn btn-danger btn-sm ms-3">Logout</a>
    </div>
</nav>