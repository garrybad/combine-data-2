<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Error</title>
</head>
<body>

<div style="padding: 20px;">
    <h1>
        <?php echo isset($heading) ? $heading : 'An Error Was Encountered'; ?>
    </h1>

    <p>
        <?php echo isset($message) ? $message : 'An unexpected error occurred.'; ?>
    </p>
</div>

</body>
</html>