<?php
// check_phpmailer.php
$srcPath = __DIR__ . '/include/PHPMailer/src/';
echo "Checking: $srcPath<br>";

if (is_dir($srcPath)) {
    $files = scandir($srcPath);
    echo "Files in src folder:<br>";
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "- $file<br>";
        }
    }
} else {
    echo "src folder not found!";
}
?>