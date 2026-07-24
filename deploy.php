<?php

$token = $_GET['token'] ?? '';

if ($token !== 'easychart_2026_4A8xP9Lm2Qv7YzR1kF6Nc3Bw') {
    http_response_code(403);
    exit('Forbidden');
}

exec('git -C /home4/visualpt/public_html/easychart pull origin main 2>&1', $output, $code);

echo "<pre>";
echo implode("\n", $output);
echo "</pre>";