<?php
$duplicateTimes = [];
$mergedFilePath = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $file1 = $_FILES['file1']['tmp_name'];
    $file2 = $_FILES['file2']['tmp_name'];

    $name1 = pathinfo($_FILES['file1']['name'], PATHINFO_FILENAME);
    $name2 = pathinfo($_FILES['file2']['name'], PATHINFO_FILENAME);

    // Extract base name before comma (e.g., FX_AUDCAD from "FX_AUDCAD, 5_1aef0")
    preg_match('/^[^,]+/', $name1, $baseMatch1);
    preg_match('/^[^,]+/', $name2, $baseMatch2);

    $base1 = isset($baseMatch1[0]) ? trim($baseMatch1[0]) : $name1;
    $base2 = isset($baseMatch2[0]) ? trim($baseMatch2[0]) : $name2;

    // Use common prefix if same base, else join both
    $mergedBase = ($base1 === $base2) ? $base1 : ($base1 . "_" . $base2);

    // Output file name
    $mergedFilePath = $mergedBase . "_merged.csv";

    if (!$file1 || !$file2) {
        echo "<p style='color:red;'>Please upload both CSV files.</p>";
    } else {
        function readCsv($filename) {
            $rows = [];
            $headers = [];

            if (($handle = fopen($filename, "r")) !== false) {
                $headers = fgetcsv($handle);
                if ($headers === false) return [$rows, []];
                $headers = array_map('trim', $headers);

                while (($data = fgetcsv($handle)) !== false) {
                    $row = array_combine($headers, $data);
                    if (isset($row['time'])) {
                        // Keep latest occurrence in the same file
                        $rows[$row['time']] = $row;
                    }
                }
                fclose($handle);
            }
            return [$rows, $headers];
        }

        // Read both files
        list($data1, $headers1) = readCsv($file1);
        list($data2, $headers2) = readCsv($file2);

        // Merge headers
        $allHeaders = array_values(array_unique(array_merge($headers1, $headers2)));

        // Merge data (keep only latest)
        $merged = $data1;
        foreach ($data2 as $time => $row) {
            if (isset($merged[$time])) {
                $duplicateTimes[] = $time;
            }
            $merged[$time] = $row; // overwrite older
        }

        // Sort by time
        ksort($merged);

        // Write to output file
        $output = fopen($mergedFilePath, "w");
        fputcsv($output, $allHeaders);
        foreach ($merged as $row) {
            $line = [];
            foreach ($allHeaders as $col) {
                $line[] = isset($row[$col]) ? $row[$col] : '';
            }
            fputcsv($output, $line);
        }
        fclose($output);

        echo "<h3 style='color:green;'>✅ Merged file created successfully.</h3>";
        echo "<a href='$mergedFilePath' download>⬇️ Download $mergedFilePath</a><br><br>";

        if (!empty($duplicateTimes)) {
            echo "<h4>Duplicate Times Found (showing up to 20):</h4><pre>";
            echo implode("\n", array_slice($duplicateTimes, 0, 20));
            echo "</pre>";
        } else {
            echo "<p>No duplicate timestamps found.</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Merge Two CSV Files by Time</title>
</head>
<body style="font-family: Arial; margin: 40px;">
    <h2>📁 Merge Two CSV Files (Unique by Time)</h2>
    <form method="POST" enctype="multipart/form-data">
        <label>Select First CSV:</label><br>
        <input type="file" name="file1" accept=".csv" required><br><br>

        <label>Select Second CSV:</label><br>
        <input type="file" name="file2" accept=".csv" required><br><br>

        <button type="submit">Merge Files</button>
    </form>
</body>
</html>
