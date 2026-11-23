
<?php
// GoalLive - Sportmonks API connection test

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';

header('Content-Type: text/html; charset=UTF-8');

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoalLive - API Connection Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #172033;
            max-width: 750px;
            margin: 50px auto;
            padding: 20px;
        }
        .panel {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0,0,0,.08);
        }
        h1 { color: #087443; }
        .status { padding: 12px; border-radius: 7px; }
        .success { background: #dcfce7; color: #166534; }
        .failure { background: #fee2e2; color: #991b1b; }
        .info { background: #e0f2fe; padding: 12px; border-radius: 7px; }
        code { overflow-wrap: anywhere; }
    </style>
</head>
<body>
<div class="panel">
    <h1>GoalLive API Test</h1>
    <p>Testing the connection to Sportmonks football data.</p>

<?php

if (!defined('SPORTMONKS_API_TOKEN') ||
    trim((string) SPORTMONKS_API_TOKEN) === '' ||
    SPORTMONKS_API_TOKEN === 'PASTE_YOUR_API_TOKEN_HERE') {
    echo '<div class="status failure">API token is missing. Check config/config.php and the SPORTMONKS_API_TOKEN setting.</div>';

} elseif (!function_exists('curl_init')) {
    echo '<div class="status failure">PHP cURL is not enabled. Enable extension=curl in your XAMPP php.ini, restart Apache, and try again.</div>';

} else {
    $url = 'https://api.sportmonks.com/v3/football/leagues?per_page=1';

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . SPORTMONKS_API_TOKEN,
            'Accept: application/json'
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($response === false) {
        echo '<div class="status failure">Connection failed. Check your internet connection and whether your server can reach api.sportmonks.com.</div>';

        if ($curlError !== '') {
            echo '<p>Technical detail: ' . h($curlError) . '</p>';
        }

    } else {
        $result = json_decode($response, true);

        if ($httpCode === 200 && is_array($result) && isset($result['data'])) {
            echo '<div class="status success"><strong>Success!</strong> GoalLive connected to the Sportmonks API.</div>';
            echo '<p>HTTP status: ' . h($httpCode) . '</p>';

            if (isset($result['data'][0]['name'])) {
                echo '<p>Sample league received: <strong>' .
                    h($result['data'][0]['name']) . '</strong></p>';
            } else {
                echo '<p>The API responded successfully. No league name was included in this sample.</p>';
            }

            echo '<p class="info">Next, we can connect real football data to GoalLive one file at a time.</p>';

        } else {
            echo '<div class="status failure"><strong>API request was not successful.</strong></div>';
            echo '<p>HTTP status: ' . h($httpCode) . '</p>';

            $message = $result['message'] ?? '';

            if (is_string($message) && $message !== '') {
                echo '<p>API message: ' . h($message) . '</p>';
            } else {
                echo '<p>Check that your token is valid and your Sportmonks plan permits this endpoint.</p>';
            }
        }
    }
}
?>

</div>
</body>
</html>
