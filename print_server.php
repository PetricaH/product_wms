<?php
if (!isset($_GET['url'])) {
    http_response_code(400);
    echo "Missing URL parameter.";
    exit;
}

$url = $_GET['url'];
$tempFile = sys_get_temp_dir() . '/invoice_to_print.pdf';

// Descarcă fișierul PDF
file_put_contents($tempFile, file_get_contents($url));

// Trimite-l la imprimantă
$printerName = 'Brother_DCP_L3520CDW_series';
$cmd = "lp -d $printerName \"$tempFile\"";
shell_exec($cmd);

echo "Trimis la imprimantă.";
?>
