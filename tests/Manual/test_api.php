<?php

// Simple API test script
$url = 'http://localhost:8000/api/genai';
$data = json_encode([
    'prompt' => 'Hello, how are you?',
    'systemPrompt' => 'You are a helpful assistant',
    'options' => ['temperature' => 0.8],
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $data,
    ],
]);

echo "Testing GenAI API endpoint...\n";
echo "URL: $url\n";
echo "Data: $data\n\n";

$result = file_get_contents($url, false, $context);

if ($result === false) {
    echo "Error: Failed to connect to API\n";
    $error = error_get_last();
    echo 'Error details: '.$error['message']."\n";
} else {
    echo "Response:\n";
    echo $result."\n";

    $decoded = json_decode($result, true);
    if ($decoded) {
        echo "\nParsed Response:\n";
        print_r($decoded);
    }
}
