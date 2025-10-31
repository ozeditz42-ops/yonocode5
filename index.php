<?php
echo "✅ Telegram Bot Working!";
echo "<br>PHP Version: " . PHP_VERSION;
echo "<br>Time: " . date('Y-m-d H:i:s');

// Test file permissions
$files = ['data.json', 'users.json', 'admins.json'];
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "<br>✅ " . $file . " exists";
    } else {
        echo "<br>❌ " . $file . " missing";
    }
}
?>
