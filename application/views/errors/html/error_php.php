<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>PHP Error</title>
</head>
<body>

<div style="padding: 20px;">
    <h1>
        <?php echo isset($heading) ? $heading : 'PHP Error'; ?>
    </h1>

    <p>
        <?php echo isset($message) ? $message : 'An unexpected PHP error occurred.'; ?>
    </p>
</div>

</body>
</html>