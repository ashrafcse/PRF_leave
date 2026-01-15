<?php
// download_phpmailer.php
echo "<h2>Download PHPMailer</h2>";

$phpVersion = PHP_VERSION;
echo "<p>PHP Version: $phpVersion</p>";

if (version_compare($phpVersion, '7.0.0') >= 0) {
    echo "<p>Recommended: PHPMailer v6+</p>";
    echo "<p><a href='https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.8.0.zip' target='_blank'>
        Download PHPMailer v6.8.0 (for PHP 7+)
    </a></p>";
} else {
    echo "<p>Recommended: PHPMailer v5.2 (for PHP 5.6)</p>";
    echo "<p><a href='https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v5.2.27.zip' target='_blank'>
        Download PHPMailer v5.2.27 (for PHP 5.6)
    </a></p>";
}

echo "<h3>Installation:</h3>";
echo "<ol>
    <li>Download the ZIP file</li>
    <li>Extract it</li>
    <li>Copy the 'src' folder (v6) or all files (v5) to: C:/xampp/htdocs/PRF_Leave/include/PHPMailer/</li>
    <li>Test with: <a href='pages/leave/test_gmail.php'>Email Test Page</a></li>
</ol>";
?>