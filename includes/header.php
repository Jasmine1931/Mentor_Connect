<?php
/**
 * includes/header.php
 * VAPT Fixes: #4 XSS on title, #9 target=_blank noopener, #10 meta referrer
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- FIXED #10: Referrer policy meta tag (belt-and-suspenders with HTTP header) -->
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <!-- FIXED #4: htmlspecialchars on page_title prevents HTML injection in <title> -->
    <title>
        <?php
        echo isset($page_title)
            ? htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') . ' — '
            : '';
        echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
        ?>
    </title>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #48bb78;
            --danger-color:  #f56565;
            --warning-color: #ed8936;
            --info-color:    #4299e1;
        }
        body { background-color: #f7fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .navbar-brand { font-weight: 700; font-size: 1.5rem; }
        .sidebar { background: white; border-right: 1px solid #e2e8f0; min-height: calc(100vh - 60px); padding: 20px; }
        .sidebar-menu a { display: flex; align-items: center; padding: 12px 15px; margin-bottom: 8px; color: #4a5568; text-decoration: none; border-radius: 5px; transition: all 0.3s ease; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background-color: #f0f4f8; color: var(--primary-color); padding-left: 20px; }
        .sidebar-menu a i { margin-right: 12px; width: 20px; }
        .main-content { padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { color: #2d3748; font-weight: 700; margin-bottom: 5px; }
        .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 8px; margin-bottom: 20px; }
        .card-header { background-color: #f7fafc; border-bottom: 1px solid #e2e8f0; border-radius: 8px 8px 0 0; font-weight: 600; color: #2d3748; }
        .btn-primary { background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); border: none; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); color: white; }
        .badge { padding: 6px 12px; border-radius: 20px; font-weight: 500; }
        .badge-active { background-color: #c6f6d5; color: #22543d; }
        .badge-completed { background-color: #bee3f8; color: #2c5282; }
        .table th { background-color: #f7fafc; border-bottom: 2px solid #e2e8f0; font-weight: 600; color: #2d3748; }
        .table td { vertical-align: middle; color: #4a5568; }
        .table-hover tbody tr:hover { background-color: #f7fafc; }
        .no-data { text-align: center; padding: 40px 20px; color: #718096; }
        .no-data i { font-size: 48px; color: #cbd5e0; margin-bottom: 15px; }
        .alert { border-radius: 8px; border: none; }
        .modal-content { border-radius: 8px; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25); }
        @media (max-width: 768px) { .sidebar { display: none; } .main-content { padding: 15px; } }
    </style>
    <?php if (isset($extra_css)): ?>
        <?php echo $extra_css; ?>
    <?php endif; ?>
</head>
<body>
