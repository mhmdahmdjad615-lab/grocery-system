<?php

function printTreeFolderByFolder($dir, $prefix = '')
{
    if (!is_dir($dir) || !is_readable($dir)) {
        return;
    }

    $items = array_values(array_diff(scandir($dir), ['.', '..']));

    $dirs  = [];
    $files = [];

    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            $dirs[] = $item;
        } else {
            $files[] = $item;
        }
    }

    $ordered = array_merge($dirs, $files);
    $count   = count($ordered);

    foreach ($ordered as $index => $item) {

        $path   = $dir . DIRECTORY_SEPARATOR . $item;
        $isLast = ($index === $count - 1);

        echo $prefix . ($isLast ? "└── " : "├── ");

        if (is_dir($path)) {

            echo "📁 {$item}" . PHP_EOL;

            $newPrefix = $prefix . ($isLast ? "    " : "│   ");

            // نُنهي هذا المجلد بالكامل قبل الانتقال لغيره
            printTreeFolderByFolder($path, $newPrefix);

        } else {

            echo "📄 {$item}" . PHP_EOL;
        }
    }
}


$root = __DIR__;

echo "<pre>";
echo "📁 " . basename($root) . PHP_EOL;
printTreeFolderByFolder($root);
echo "</pre>";