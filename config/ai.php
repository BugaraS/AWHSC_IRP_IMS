<?php
/**
 * AWHSC-IRB MIS - AI Assistant configuration (Google Gemini)
 */

define('GEMINI_API_KEY', 'AIzaSyDe0PNcqUJE7U-sKwf0cHmYbOm7uysC_ac');
define('GEMINI_MODEL', 'gemini-3.6-flash');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');
define('AI_CHAT_HISTORY_LIMIT', 12);

return [
    'gemini_api_key' => GEMINI_API_KEY,
    'model' => GEMINI_MODEL,
    'api_url' => GEMINI_API_URL,
    'chat_history_limit' => AI_CHAT_HISTORY_LIMIT,
];