<?php
// Get the base public URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'];

// Get the current folder relative to the document root
$rootDir = realpath($_SERVER['DOCUMENT_ROOT']);

// Recursive function to display directory contents
function listDirectory($dir, $rootDir, $baseUrl)
{
    if ($handle = opendir($dir)) {
        echo "<ul>";

        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                $path = $dir . DIRECTORY_SEPARATOR . $entry;

                // Convert full path to public URL
                $relativePath = str_replace($rootDir, '', realpath($path));
                $url = $baseUrl . $relativePath;

                if (is_dir($path)) {
                    echo "<li><strong>[Folder]</strong> <a href='$url/'>$entry</a>";
                    listDirectory($path, $rootDir, $baseUrl);
                    echo "</li>";
                } else {
                    echo "<li>[File] <a href='$url' target='_blank'>$entry</a></li>";
                }
            }
        }

        echo "</ul>";
        closedir($handle);
    }
}

// Build the public base URL (e.g., https://saireddy.site)
$baseUrl = $protocol . $domain;

// Get current directory
$currentDir = __DIR__;

echo "ALL CSV FILES ARE UPDATED FROM June 23, 2025   to February 27, 2026</h2>";

// Some simple styling
echo '<style>
    body { font-family: Arial; background: #fafafa; }
    ul { list-style-type: none; margin-left: 20px; }
    li { margin: 3px 0; }
    a { text-decoration: none; color: #0066cc; }
    a:hover { text-decoration: underline; }
</style>';

listDirectory($currentDir, $rootDir, $baseUrl);
?>
