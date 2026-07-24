<?php
require __DIR__ . '/../vendor/autoload.php';

$single = require __DIR__ . '/../coverage/single.cov';
$multi  = require __DIR__ . '/../coverage/multisite.cov';
$single->merge($multi);
$report = $single->getReport();

$queue = [$report];
$files_seen = [];
while ($node = array_shift($queue)) {
    if (method_exists($node, 'files')) {
        foreach ($node->files() as $file) {
            $path = $file->pathAsString();
            if (isset($files_seen[$path])) continue;
            $files_seen[$path] = true;
            $cov = $file->lineCoverageData();
            foreach ($file->classes() as $cname => $cinfo) {
                // show ALL classes with uncovered lines
                $printed_header = false;
                foreach ($cinfo['methods'] as $mname => $m) {
                    $exec = $m['executableLines'] ?? 0;
                    $exed = $m['executedLines']   ?? 0;
                    if ($exec === 0 || $exed >= $exec) continue;
                    if (!$printed_header) { echo "\n=== $cname ===\n"; $printed_header = true; }
                    echo sprintf("  %s (lines %d-%d, %d/%d executed)\n",
                        $mname, $m['startLine'], $m['endLine'], $exed, $exec);
                    $uncov = [];
                    for ($l = $m['startLine']; $l <= $m['endLine']; $l++) {
                        if (array_key_exists($l, $cov) && is_array($cov[$l]) && count($cov[$l]) === 0) {
                            $uncov[] = $l;
                        }
                    }
                    if ($uncov) echo "      uncovered lines: " . implode(',', $uncov) . "\n";
                }
            }
        }
    }
    if (method_exists($node, 'children')) {
        foreach ($node->children() as $child) {
            $queue[] = $child;
        }
    }
}
