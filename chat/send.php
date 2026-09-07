<?php
session_start();
header('Content-Type: application/json');

// Include AI configuration
$config = require_once __DIR__ . '/../config/ai.php';

if (empty($config['gemini_api_key'])) {
    echo json_encode([
        'success' => false,
        'reply' => 'The AI Assistant has not been set up yet. Please check your config/ai.php file.'
    ]);
    exit;
}

$userMessage = trim($_POST['message'] ?? '');
$hasFile = false;
$inlineDataPart = [];

// Handle file attachment securely and robustly
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['attachment']['tmp_name'];
        $fileName = $_FILES['attachment']['name'];
        $fileType = mime_content_type($fileTmpPath);
        
        $fileContent = file_get_contents($fileTmpPath);
        if ($fileContent !== false) {
            $fileData = base64_encode($fileContent);
            
            if ($fileData !== false) {
                $hasFile = true;
                $inlineDataPart = [
                    'inline_data' => [
                        'mime_type' => $fileType,
                        'data' => $fileData
                    ]
                ];
                
                if (empty($userMessage)) {
                    $userMessage = "Please review this attached file (" . $fileName . ") and provide your analysis based on IRB SOP guidelines.";
                } else {
                    $userMessage = "Please review the attached file (" . $fileName . ") alongside this request: " . $userMessage;
                }
            }
        }
    } else {
        // Handle specific upload errors if needed
        echo json_encode([
            'success' => false,
            'reply' => 'File upload error code: ' . $_FILES['attachment']['error']
        ]);
        exit;
    }
}

// Default message if both text and file are empty
if (empty($userMessage) && !$hasFile) {
    echo json_encode([
        'success' => false,
        'reply' => 'Message or file attachment cannot be empty.'
    ]);
    exit;
}

// Construct system prompt correctly for Gemini API structure
$systemInstruction = [
    'parts' => [
        ['text' => "You are the AWHSC-IRB Assistant for Debre Berhan University. Help users review protocol documents, check submission checklist compliance, and understand SOP workflows. Do not give official ethics rulings."]
    ]
];

// Build the parts array correctly for Gemini API contents
$parts = [];
if ($hasFile) {
    $parts[] = $inlineDataPart;
}
$parts[] = [
    'text' => $userMessage
];

$payload = [
    'system_instruction' => $systemInstruction,
    'contents' => [
        [
            'role' => 'user',
            'parts' => $parts
        ]
    ]
];

$ch = curl_init($config['api_url'] . '?key=' . $config['gemini_api_key']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode([
        'success' => false,
        'reply' => 'Network error communicating with Google AI: ' . $curlError
    ]);
    exit;
}

// Handle 503 High Demand or other API errors gracefully
if ($httpCode !== 200) {
    $errorData = json_decode($response, true);
    $errorMessage = $errorData['error']['message'] ?? 'Unknown error';
    
    if ($httpCode === 503 || strpos($errorMessage, 'high demand') !== false) {
        $replyText = 'ሰርቨሩ በአሁኑ ሰዓት በከፍተኛ ጥያቄ ተጨናንቋል (High Demand - 503)። እባክዎ ከጥቂት ደቂቃዎች በኋላ እንደገና ይሞክሩ።';
    } else {
        $replyText = 'Gemini API returned an error (Code ' . $httpCode . '). Details: ' . $errorMessage;
    }

    echo json_encode([
        'success' => false,
        'reply' => $replyText
    ]);
    exit;
}

$responseData = json_decode($response, true);
$aiReply = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? 'Sorry, I could not process the file or generate a response.';

echo json_encode([
    'success' => true,
    'reply' => trim($aiReply)
]);