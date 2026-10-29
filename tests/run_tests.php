#!/usr/bin/env php
<?php
/**
 * OpenCart Universal MCP Server
 * Central Test Suite Runner
 */

$testFiles = [
    __DIR__ . '/DetectorTest.php',
    __DIR__ . '/SecurityTest.php',
    __DIR__ . '/ProtocolTest.php',
    __DIR__ . '/EndToEndToolsTest.php',
    __DIR__ . '/RealSitesAuditTest.php',
    __DIR__ . '/ComplianceAndStressTest.php',
];

$failed = 0;
echo "=========================================\n";
echo " Running OpenCart-MCP Full Test Suite\n";
echo "=========================================\n\n";

foreach ($testFiles as $file) {
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($cmd, $returnVar);
    if ($returnVar !== 0) {
        $failed++;
        echo "❌ FAILED: " . basename($file) . "\n\n";
    } else {
        echo "✅ PASSED: " . basename($file) . "\n\n";
    }
}

if ($failed > 0) {
    echo "Summary: {$failed} test suite(s) failed.\n";
    exit(1);
}

echo "All OpenCart-MCP test suites passed successfully! 🎉\n";
exit(0);
