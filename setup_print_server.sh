#!/bin/bash
# WMS Print Server Auto-Setup for macOS
# Run with: bash setup_print_server.sh

echo "🖨️  Setting up WMS Print Server..."

# Create print server directory
PRINT_DIR="$HOME/print_server"
mkdir -p "$PRINT_DIR"
cd "$PRINT_DIR"

# Create print_server.php
cat > print_server.php << 'EOF'
<?php
// Enhanced print_server.php for WMS
error_reporting(E_ALL);
ini_set("display_errors", 1);

// CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit(0);
}

// Log requests
$logFile = __DIR__ . "/print_server.log";
$timestamp = date("Y-m-d H:i:s");
$logEntry = "[$timestamp] " . $_SERVER["REQUEST_METHOD"] . " " . $_SERVER["REQUEST_URI"] . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

if (!isset($_GET["url"])) {
    http_response_code(400);
    echo "Missing URL parameter.";
    exit;
}

$url = $_GET["url"];
$printer = $_GET["printer"] ?? "Brother_DCP_L3520CDW_series";

try {
    // Download PDF
    $tempFile = sys_get_temp_dir() . "/invoice_" . time() . ".pdf";
    $pdfContent = file_get_contents($url);
    
    if ($pdfContent === false) {
        throw new Exception("Failed to download PDF from: $url");
    }
    
    file_put_contents($tempFile, $pdfContent);
    
    // Print command
    if (PHP_OS_FAMILY === "Darwin") {
        $cmd = "lp -d " . escapeshellarg($printer) . " " . escapeshellarg($tempFile);
    } else {
        $cmd = "lp -d " . escapeshellarg($printer) . " " . escapeshellarg($tempFile);
    }
    
    $output = [];
    $returnCode = 0;
    exec($cmd . " 2>&1", $output, $returnCode);
    
    @unlink($tempFile);
    
    if ($returnCode === 0) {
        $logEntry = "[$timestamp] SUCCESS: Printed $url to $printer\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        echo "Trimis la imprimantă: $printer";
    } else {
        $error = implode("\n", $output);
        $logEntry = "[$timestamp] ERROR: $error\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        throw new Exception("Print command failed: $error");
    }
    
} catch (Exception $e) {
    http_response_code(500);
    $logEntry = "[$timestamp] EXCEPTION: " . $e->getMessage() . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    echo "Error: " . $e->getMessage();
}
?>
EOF

# Create LaunchAgent plist
PLIST_FILE="$HOME/Library/LaunchAgents/com.wms.printserver.plist"
mkdir -p "$HOME/Library/LaunchAgents"

cat > "$PLIST_FILE" << EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.wms.printserver</string>
    <key>ProgramArguments</key>
    <array>
        <string>/usr/bin/php</string>
        <string>-S</string>
        <string>0.0.0.0:8000</string>
        <string>-t</string>
        <string>$PRINT_DIR</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$PRINT_DIR</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>$PRINT_DIR/output.log</string>
    <key>StandardErrorPath</key>
    <string>$PRINT_DIR/error.log</string>
</dict>
</plist>
EOF

# Load the service
echo "📋 Loading print server service..."
launchctl unload "$PLIST_FILE" 2>/dev/null || true
launchctl load "$PLIST_FILE"

# Test the service
sleep 2
echo "🧪 Testing print server..."
if curl -s "http://localhost:8000/print_server.php" | grep -q "Missing URL parameter"; then
    echo "✅ Print server is running successfully!"
    echo "📁 Files created in: $PRINT_DIR"
    echo "📋 Service: com.wms.printserver"
    echo ""
    echo "🔧 Useful commands:"
    echo "  Check status: launchctl list | grep printserver"
    echo "  Stop service: launchctl unload $PLIST_FILE"
    echo "  Start service: launchctl load $PLIST_FILE"
    echo "  View logs: tail -f $PRINT_DIR/print_server.log"
else
    echo "❌ Print server failed to start. Check the logs:"
    echo "  $PRINT_DIR/error.log"
fi