<?php
// Render.com specific optimizations
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't show errors to users
ini_set('log_errors', 1);

// Set appropriate timezone
date_default_timezone_set('UTC');

// Handle CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// For long polling processes, set appropriate timeouts
set_time_limit(30); // 30 seconds max execution time

define('BOT_TOKEN', '8265350006:AAHMM70L4-w3bdtr6VnFIPK2NhO5uijfrpI');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('OWNER_ID', 8124361866);

define('DATA_FILE', 'data.json');
define('USERS_FILE', 'users.json');
define('SUCCESSFUL_USERS_FILE', 'successful_users.json');
define('ADMINS_FILE', 'admins.json');
define('JOIN_REQUESTS_FILE', 'join_requests.json');

// --- ROBUST FILE HANDLING (PREVENTS DATA LOSS AND CORRUPTION) ---

/**
 * NEW ROBUST loadJsonFile FUNCTION
 * Automatically detects corruption or empty files and restores from a .bak file.
 */
function loadJsonFile($filePath) {
    $backupFile = $filePath . '.bak';
    $defaultData = [];

    if (!file_exists($filePath)) {
        file_put_contents($filePath, json_encode($defaultData));
        return $defaultData;
    }

    $contents = file_get_contents($filePath);

    if (empty(trim($contents))) {
        if (file_exists($backupFile)) {
            $backupContents = file_get_contents($backupFile);
            $data = json_decode($backupContents, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                error_log("INFO: {$filePath} was empty. Restored from backup.");
                file_put_contents($filePath, $backupContents);
                return $data;
            }
        }
        return $defaultData;
    }

    $data = json_decode($contents, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        error_log("JSON Decode Error in {$filePath}. Attempting to restore from backup.");
        if (file_exists($backupFile)) {
            $backupContents = file_get_contents($backupFile);
            $backupData = json_decode($backupContents, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($backupData)) {
                error_log("SUCCESS: {$filePath} was corrupted. Restored from backup.");
                file_put_contents($filePath, $backupContents);
                return $backupData;
            }
        }
        return $defaultData;
    }

    return $data;
}

/**
 * NEW ROBUST saveJsonFile FUNCTION
 * Creates a backup, writes to a temporary file, and then atomically renames it.
 */
function saveJsonFile($filePath, $data) {
    $tempFile = $filePath . '.tmp';
    $backupFile = $filePath . '.bak';

    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($jsonData === false) {
        error_log("Could not encode JSON data for {$filePath}: " . json_last_error_msg());
        return;
    }
    
    if (file_exists($filePath)) {
        copy($filePath, $backupFile);
    }

    $bytesWritten = file_put_contents($tempFile, $jsonData, LOCK_EX);

    if ($bytesWritten !== false && $bytesWritten === strlen($jsonData)) {
        if (!rename($tempFile, $filePath)) {
            error_log("Failed to rename temp file {$tempFile} to {$filePath}.");
            copy($backupFile, $filePath);
        }
    } else {
        error_log("Failed to write data to temp file {$tempFile}. Original file is untouched.");
        if(file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
}

// --- DATA MANAGEMENT FUNCTIONS ---

function loadData() {
    $defaultData = [
        'caption_text' => '<b>Default Caption</b>',
        'caption_image' => 'https://via.placeholder.com/400',
        'caption_image_enabled' => true,
        'home_text' => "<b>😎 Congratulations You have received your Promo Code! ❤️</b>\n\n<b>Your Refer Link ~</b> {link}\n\n<b>Per Refer ~ Get Upto ₹100 Big Promo Code ~Yono 777</b>",
        'home_text_entities' => null,
        'home_photo' => null,
        'home_photo_enabled' => false,
        'referral_success_text' => "<b>You've received a Promo Code for a successful referral! 😱❤️</b>",
        'referral_success_entities' => null,
        'referral_success_photo' => null,
        'referral_success_photo_enabled' => false,
        'joined_button_text' => 'Get Promo Code',
        'channels' => [],
        'admin_state' => [],
        'user_stats' => [],
        'referrals' => []
    ];

    $data = loadJsonFile(DATA_FILE);
    
    if (empty($data) && !file_exists(DATA_FILE)) {
        saveJsonFile(DATA_FILE, $defaultData);
        return $defaultData;
    }
    
    return array_merge($defaultData, $data);
}

function saveData(&$data) {
    saveJsonFile(DATA_FILE, $data);
}

function loadUsers() { return loadJsonFile(USERS_FILE); }
function saveUsers($users) { saveJsonFile(USERS_FILE, array_values(array_unique($users))); }
function loadJoinRequests() { return loadJsonFile(JOIN_REQUESTS_FILE); }
function saveJoinRequests($requests) { saveJsonFile(JOIN_REQUESTS_FILE, $requests); }
function loadSuccessfulUsers() { return loadJsonFile(SUCCESSFUL_USERS_FILE); }
function saveSuccessfulUsers($users) { saveJsonFile(SUCCESSFUL_USERS_FILE, array_values(array_unique($users))); }
function loadAdmins() {
    $admins = loadJsonFile(ADMINS_FILE);
    if (!is_array($admins) || empty($admins)) {
        $admins = [OWNER_ID];
    }
    if (!in_array(OWNER_ID, $admins)) {
        $admins[] = OWNER_ID;
    }
    return $admins;
}
function saveAdmins($admins) {
    if (!in_array(OWNER_ID, $admins)) {
        $admins[] = OWNER_ID;
    }
    saveJsonFile(ADMINS_FILE, array_values(array_unique($admins)));
}

// --- CORE BOT HELPER FUNCTIONS ---

function isAdmin($userId) {
    return in_array($userId, loadAdmins());
}

function apiRequest($method, $parameters) {
    if (!is_string($method)) { error_log("Method name must be a string"); return false; }
    if (!$parameters) { $parameters = array(); } 
    else if (!is_array($parameters)) { error_log("Parameters must be an array"); return false; }
    
    $handle = curl_init(API_URL . $method);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $parameters
    ]);
    $response = curl_exec($handle);
    $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
    curl_close($handle);

    if ($http_code >= 500) { return false; }
    
    $response_decoded = json_decode($response, true);
    if ($http_code != 200) {
        error_log("Request failed with error {$response_decoded['error_code']}: {$response_decoded['description']}");
        return false;
    }
    return $response_decoded['result'];
}

function sendMessage($chatId, $text, $replyMarkup = null, $disablePreview = false, $entities = null) {
    $params = [
        'chat_id' => $chatId, 
        'text' => $text, 
        'disable_web_page_preview' => $disablePreview
    ];
    if ($entities) {
        $params['entities'] = json_encode($entities);
    } else {
        $params['parse_mode'] = 'HTML';
    }
    if ($replyMarkup) { $params['reply_markup'] = json_encode($replyMarkup); }
    return apiRequest('sendMessage', $params);
}

function editMessage($chatId, $messageId, $text, $replyMarkup = null) {
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($replyMarkup) { $params['reply_markup'] = json_encode($replyMarkup); }
    apiRequest('editMessageText', $params);
}

function sendPhoto($chatId, $photo, $caption, $replyMarkup = null, $caption_entities = null) {
    $params = ['chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption];
    if ($caption_entities) {
        $params['caption_entities'] = json_encode($caption_entities);
    } else {
        $params['parse_mode'] = 'HTML';
    }
    if ($replyMarkup) { $params['reply_markup'] = json_encode($replyMarkup); }
    return apiRequest('sendPhoto', $params);
}

function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false) {
    $params = ['callback_query_id' => $callbackQueryId];
    if ($text) { $params['text'] = $text; $params['show_alert'] = $showAlert; }
    apiRequest('answerCallbackQuery', $params);
}

function getChatMember($chatId, $userId) {
    return apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
}

function deleteMessage($chatId, $messageId) {
    apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
}

function deleteChannel($channelIndex, &$data) {
    if (isset($data['channels'][$channelIndex])) {
        unset($data['channels'][$channelIndex]);
        $data['channels'] = array_values($data['channels']);
        saveData($data);
        return true;
    }
    return false;
}

function updateUserStats(&$data, $userId) {
    $today = date('Y-m-d');
    if (!isset($data['user_stats'][$userId])) {
        $data['user_stats'][$userId] = ['first_seen' => $today, 'last_seen' => $today];
    } else {
        $data['user_stats'][$userId]['last_seen'] = $today;
    }
    saveData($data);
}

function getBotUsername() {
    $botInfo = apiRequest('getMe', []);
    return $botInfo['username'] ?? 'UnknownBot';
}

function getManualTextFormats() {
    return "<b>Manual Text Formats (HTML):</b>\n"
        . "1. Bold: <code>" . htmlspecialchars("<b>text</b>") . "</code>\n"
        . "2. Italic: <code>" . htmlspecialchars("<i>text</i>") . "</code>\n"
        . "3. Underline: <code>" . htmlspecialchars("<u>text</u>") . "</code>\n"
        . "4. Strikethrough: <code>" . htmlspecialchars("<s>text</s>") . "</code>\n"
        . "5. Code (inline): <code>" . htmlspecialchars("<code>text</code>") . "</code>\n"
        . "6. Code block: <code>" . htmlspecialchars("<pre>text</pre>") . "</code>\n"
        . "7. Quote: <code>" . htmlspecialchars("<blockquote>text</blockquote>") . "</code>\n"
        . "8. Spoiler: <code>" . htmlspecialchars("<tg-spoiler>text</tg-spoiler>") . "</code>\n"
        . "9. Link: <code>" . htmlspecialchars("<a href='URL'>text</a>") . "</code>\n"
        . "10. Mention (by ID): <code>" . htmlspecialchars("<a href='tg://user?id=USER_ID'>text</a>") . "</code>";
}

function processTextWithLink($text, $entities, $link) {
    $placeholder = '{link}';
    if (!$entities || mb_strpos($text, $placeholder) === false) {
        return [
            'text' => str_replace($placeholder, "<code>{$link}</code>", $text),
            'entities' => null
        ];
    }

    $newText = $text;
    $newEntities = $entities;
    $placeholderLen = mb_strlen($placeholder);
    $linkLen = mb_strlen($link);
    $lengthDifference = $linkLen - $placeholderLen;
    
    $offsetAdjustment = 0;
    while (($pos = mb_strpos($newText, $placeholder, 0)) !== false) {
        $realPos = $pos + $offsetAdjustment;
        
        $newText = mb_substr($newText, 0, $pos) . $link . mb_substr($newText, $pos + $placeholderLen);

        foreach ($newEntities as &$entity) {
            if ($entity['offset'] > $realPos) {
                $entity['offset'] += $lengthDifference;
            } elseif ($entity['offset'] + $entity['length'] > $realPos) {
                 if($entity['offset'] === $realPos && $entity['length'] === $placeholderLen){
                    $entity['length'] = $linkLen;
                 } else {
                    $entity['length'] += $lengthDifference;
                 }
            }
        }
        unset($entity);
        $offsetAdjustment += $lengthDifference;
    }
    
    return ['text' => $newText, 'entities' => $newEntities];
}

// --- ADMIN PANEL DISPLAY FUNCTIONS (FIXED) ---
// Note: All 'show...' functions now accept &$data to work with the current script's data state.

function showMainAdminPanel($chatId, &$data, $messageId = null) {
    $totalUsers = count(loadUsers());
    $todayUsers = 0; $today = date('Y-m-d');
    if(isset($data['user_stats'])) {
        foreach ($data['user_stats'] as $stats) {
            if (isset($stats['last_seen']) && $stats['last_seen'] === $today) $todayUsers++;
        }
    }

    $text = "⚙️ <b>Admin Panel</b>\n\nWelcome! Manage your bot settings from here.\n\n"
        . "📊 <b>Statistics</b>\n"
        . " • Total Users: {$totalUsers}\n"
        . " • Today Users: {$todayUsers}";
    
    $keyboard = ['inline_keyboard' => [
        [['text' => '✏️ Change Caption Text', 'callback_data' => 'change_caption_text'], ['text' => '🖼️ Caption Image Setting', 'callback_data' => 'caption_image_settings']],
        [['text' => '🤑 Promo Code Setting', 'callback_data' => 'promo_code_setting'], ['text' => '🏠 Home Text (Refer Text)', 'callback_data' => 'home_text_setting']],
        [['text' => '🔗 Manage Channels', 'callback_data' => 'manage_channels'], ['text' => '↕️ Change Position', 'callback_data' => 'change_position']],
        [['text' => '📣 Broadcast', 'callback_data' => 'broadcast_menu'], ['text' => '📊 Statistics', 'callback_data' => 'show_statistics']],
        [['text' => '🔄 All Name Channel', 'callback_data' => 'all_name_channel_menu'], ['text' => '👤 Manage Admin', 'callback_data' => 'manage_admins']]
    ]];
    
    if ($messageId) {
        @editMessage($chatId, $messageId, $text, $keyboard);
    } else {
        $sentMessage = sendMessage($chatId, $text, $keyboard);
        if ($sentMessage) {
            $messageId = $sentMessage['message_id'];
        }
    }
    
    if ($messageId) {
        if (!isset($data['admin_state'][$chatId])) $data['admin_state'][$chatId] = [];
        $data['admin_state'][$chatId]['panel_message_id'] = $messageId;
        saveData($data);
    }
}

function showAllNameChannelMenu($chatId, $messageId) {
    $text = "🔄 <b>All Name Channel Settings</b>\n\nChoose which button names you would like to edit.";
    $keyboard = ['inline_keyboard' => [
        [['text' => '✏️ Edit All Button Name', 'callback_data' => 'edit_all_channel_names']],
        [['text' => '✅ Name Joined Button Name', 'callback_data' => 'edit_joined_button_name']],
        [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'back_to_main']]
    ]];
    editMessage($chatId, $messageId, $text, $keyboard);
}

function showManageAdminPanel($chatId, $messageId) {
    $admins = loadAdmins();
    $adminList = '';
    foreach($admins as $admin_id) {
        $adminList .= "• <code>{$admin_id}</code>";
        if($admin_id == OWNER_ID) {
            $adminList .= " (Owner)";
        }
        $adminList .= "\n";
    }

    $text = "👤 <b>Manage Admins</b>\n\nCurrent Admins:\n{$adminList}";
    $keyboard = ['inline_keyboard' => [
        [['text' => '➕ Add Admin', 'callback_data' => 'add_admin'], ['text' => '➖ Remove Admin', 'callback_data' => 'remove_admin_list']],
        [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'back_to_main']]
    ]];
    editMessage($chatId, $messageId, $text, $keyboard);
}

function showRemoveAdminPanel($chatId, $messageId) {
    $admins = loadAdmins();
    $text = "➖ <b>Remove an Admin</b>\n\nSelect an admin to remove. The owner cannot be removed.";
    $buttons = [];
    foreach($admins as $admin_id) {
        if($admin_id != OWNER_ID) {
            $buttons[] = [['text' => "🗑️ Remove {$admin_id}", 'callback_data' => "remove_admin_{$admin_id}"]];
        }
    }
    $buttons[] = [['text' => '⬅️ Back to Manage Admins', 'callback_data' => 'manage_admins']];
    $keyboard = ['inline_keyboard' => $buttons];
    editMessage($chatId, $messageId, $text, $keyboard);
}

function showCaptionImageSettings($chatId, &$data, $messageId) {
    $status = $data['caption_image_enabled'] ?? true;
    $status_text = $status ? '🟢 Image ON' : '🔴 Image OFF';

    $text = "🖼️ <b>Caption Image Settings</b>\n\nEnable or disable the image shown to users with the /start command.";
    $keyboard = ['inline_keyboard' => [
        [['text' => '✏️ Change Image', 'callback_data' => 'change_caption_image']],
        [['text' => $status_text, 'callback_data' => 'toggle_caption_image']],
        [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'back_to_main']]
    ]];
    editMessage($chatId, $messageId, $text, $keyboard);
}

function showPromoCodeSettings($chatId, &$data, $messageId) {
    $status = $data['referral_success_photo_enabled'] ?? false;
    $status_text = $status ? '🟢 Photo ON' : '🔴 Photo OFF';

    $text = "🤑 <b>Promo Code Setting</b>\n\nThis is the message a user receives when their referral is successful.";
    $keyboard = ['inline_keyboard' => [
        [['text' => '🖼️ Set Photo + Text', 'callback_data' => 'set_promo_photo_text']],
        [['text' => $status_text, 'callback_data' => 'toggle_promo_photo']],
        [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'back_to_main']]
    ]];
    editMessage($chatId, $messageId, $text, $keyboard);
}

function showHomeTextSettings($chatId, &$data, $messageId) {
    $status = $data['home_photo_enabled'] ?? false;
    $status_text = $status ? '🟢 Photo ON' : '🔴 Photo OFF';

    $text = "🏠 <b>Home Text Setting</b>\n\nThis is the final message a user receives after joining all channels. Use the placeholder <code>{link}</code> to place the user's referral link.";
    $keyboard = ['inline_keyboard' => [
        [['text' => '🖼️ Set Photo + Text', 'callback_data' => 'set_home_photo_text']],
        [['text' => $status_text, 'callback_data' => 'toggle_home_photo']],
        [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'back_to_main']]
    ]];
    editMessage($chatId, $messageId, $text, $keyboard);
}

function showBroadcastMenu($chatId, $messageId) {
    $text = "📣 <b>Broadcast Menu</b>\n\nSelect the type of broadcast you want to send.";
    $keyboard = ['inline_keyboard' => [
        [['text' => '🖼️ Photo + Text', 'callback_data' => 'broadcast_photo_text'], ['text' => '📝 Text Only', 'callback_data' => 'broadcast_text']],
        [['text' => '📢 Channel Broadcast', 'callback_data' => 'channel_broadcast_menu']],
        [['text' => '⬅️ Back', 'callback_data' => 'back_to_main']]
    ]];
    editMessage($chatId, $messageId, $text, $keyboard);
}

function showChannelBroadcastMenu($chatId, $messageId) {
    $text = "📢 <b>Channel Broadcast Menu</b>\n\nSelect the type of message to broadcast to all added channels.";
    $keyboard = ['inline_keyboard' => [
        [['text' => '🖼️ Photo + Text', 'callback_data' => 'channel_broadcast_photo_text'], ['text' => '📝 Text Only', 'callback_data' => 'channel_broadcast_text']],
        [['text' => '⬅️ Back to Broadcast Menu', 'callback_data' => 'broadcast_menu']]
    ]];
    editMessage($chatId, $messageId, $text, $keyboard);
}

function showManageChannelsPanel($chatId, &$data, $messageId) {
    $text = "🔗 <b>Manage Channels</b>\n\nAdd, edit, delete, or toggle force-join for channels. The original channel name is shown.\n🟢 = Enforced Join, 🔴 = Optional Join";
    $buttons = [];

    if (!empty($data['channels'])) {
        foreach ($data['channels'] as $index => $channel) {
            $status_icon = (isset($channel['enforced']) && $channel['enforced']) ? '🟢' : '🔴';
            $adminDisplayName = htmlspecialchars($channel['original_name'] ?? $channel['name']);
            
            $buttons[] = [['text' => '✏️ ' . $adminDisplayName, 'callback_data' => 'edit_channel_name_' . $index], ['text' => $status_icon . ' Status', 'callback_data' => 'toggle_enforce_' . $index], ['text' => '🗑️', 'callback_data' => 'delete_channel_' . $index]];
        }
    }

    $buttons[] = [['text' => '➕ Add Channel', 'callback_data' => 'add_channel']];
    $buttons[] = [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'back_to_main']];
    editMessage($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);
}

function showChangePositionPanel($chatId, &$data, $messageId) {
    $text = "↕️ <b>Change Position</b>\n\nUse the buttons to reorder the channels. The original channel names are shown for reference.";
    $buttons = [];
    $channelCount = count($data['channels']);

    if ($channelCount > 0) {
        foreach ($data['channels'] as $index => $channel) {
            $row = [];
            $displayName = htmlspecialchars($channel['original_name'] ?? $channel['name']);
            $row[] = ['text' => $displayName, 'callback_data' => 'noop'];
            
            $row[] = ($index > 0) ? ['text' => '🔼', 'callback_data' => 'move_up_' . $index] : ['text' => ' ', 'callback_data' => 'noop'];
            $row[] = ($index < $channelCount - 1) ? ['text' => '🔽', 'callback_data' => 'move_down_' . $index] : ['text' => ' ', 'callback_data' => 'noop'];
            
            $buttons[] = $row;
        }
    } else {
        $text .= "\n\nNo channels to reorder.";
    }

    $buttons[] = [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'back_to_main']];
    editMessage($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);
}

function showStatisticsPanel($chatId, $messageId) {
    $totalUsers = count(loadUsers());
    $successfulUsers = count(loadSuccessfulUsers());
    // This function doesn't rely on live $data from the script, so it can load its own stats.
    $stats_data = loadData();
    $todayUsers = 0; $today = date('Y-m-d');
     if(isset($stats_data['user_stats'])) {
        foreach ($stats_data['user_stats'] as $stats) {
            if (isset($stats['last_seen']) && $stats['last_seen'] === $today) $todayUsers++;
        }
    }
    $text = "📊 <b>Statistics</b>\n\n" 
        . "• Total Users: {$totalUsers}\n" 
        . "• Today Users: {$todayUsers}\n"
        . "• Total Success Users Joined: {$successfulUsers}\n\n"
        . "Bot Username: @" . getBotUsername();
    editMessage($chatId, $messageId, $text, ['inline_keyboard' => [[['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'back_to_main']]]]);
}


// --- MAIN SCRIPT LOGIC ---

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { 
    // Render.com health check response
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo "Telegram Bot is running!";
    }
    exit(); 
}

// Load data ONCE at the beginning of the script
$data = loadData();

// --- HANDLERS ---

if (isset($update['chat_join_request'])) {
    $joinRequest = $update['chat_join_request'];
    $userId = $joinRequest['from']['id'];
    $chatId = $joinRequest['chat']['id'];

    $requests = loadJoinRequests();
    if (!isset($requests[$userId])) {
        $requests[$userId] = [];
    }
    if (!in_array($chatId, $requests[$userId])) {
        $requests[$userId][] = $chatId;
    }
    saveJoinRequests($requests);
    exit();
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $messageId = $message['message_id'];
    $photo = $message['photo'] ?? null;
    $caption = $message['caption'] ?? null;
    $entities = $message['entities'] ?? null;
    $caption_entities = $message['caption_entities'] ?? null;
    
    if (isAdmin($chatId) && $text == '/cancel') {
        if(isset($data['admin_state'][$chatId]['action'])){
             $panelMessageId = $data['admin_state'][$chatId]['panel_message_id'] ?? null;
             unset($data['admin_state'][$chatId]);
             saveData($data);
             if($panelMessageId) {
                 showMainAdminPanel($chatId, $data, $panelMessageId);
             }
             sendMessage($chatId, "Action cancelled.");
             exit;
        }
    }
    
    if (isAdmin($chatId) && isset($data['admin_state'][$chatId]['action'])) {
        $state = &$data['admin_state'][$chatId];
        $action = $state['action'];
        $panelMessageId = $state['panel_message_id'] ?? null;
        $responseMessage = '';

        switch ($action) {
            case 'broadcast_awaiting_photo':
            case 'channel_broadcast_awaiting_photo':
            case 'awaiting_promo_photo':
            case 'awaiting_home_photo':
                if ($photo) {
                    if($action == 'broadcast_awaiting_photo') $state['action'] = 'broadcast_awaiting_text';
                    elseif($action == 'channel_broadcast_awaiting_photo') $state['action'] = 'channel_broadcast_awaiting_text';
                    elseif($action == 'awaiting_promo_photo') $state['action'] = 'awaiting_promo_text';
                    elseif($action == 'awaiting_home_photo') $state['action'] = 'awaiting_home_text';
                    
                    $state['photo_id'] = $photo[count($photo) - 1]['file_id'];
                    $prompt = "🖼️ Photo received. Now, please send the text/caption.\n\n" . getManualTextFormats() . "\n\nOr send /cancel to abort.";
                    if($state['action'] === 'awaiting_home_text') {
                        $prompt .= "\n\nRemember to use <code>{link}</code> for the referral link.";
                    }
                    editMessage($chatId, $panelMessageId, $prompt, null);
                    deleteMessage($chatId, $messageId);
                } else {
                    sendMessage($chatId, "❌ That's not a photo. Please send a photo or /cancel.");
                }
                break;

            case 'broadcast_awaiting_text':
            case 'broadcast_awaiting_photo_text':
            case 'channel_broadcast_awaiting_text':
            case 'channel_broadcast_awaiting_photo_text':
                $state['text'] = $text ?: $caption;
                $state['entities'] = $entities ?: $caption_entities;
                $isPhotoBroadcast = isset($state['photo_id']);
                
                $isChannelBroadcast = (strpos($action, 'channel_') === 0);
                $state['action'] = $isChannelBroadcast ? 'channel_broadcast_awaiting_confirmation' : 'broadcast_awaiting_confirmation';
                $confirm_callback = $isChannelBroadcast ? 'confirm_channel_broadcast' : 'confirm_broadcast';

                $keyboard = ['inline_keyboard' => [[['text' => '✅ Confirm & Send', 'callback_data' => $confirm_callback], ['text' => '❌ Cancel', 'callback_data' => 'cancel_broadcast']]]];
                
                if ($panelMessageId) {
                    editMessage($chatId, $panelMessageId, "👇 Please confirm the broadcast preview below.", null);
                }
                
                deleteMessage($chatId, $messageId);

                if ($isPhotoBroadcast) {
                    sendPhoto($chatId, $state['photo_id'], $state['text'], $keyboard, $state['entities']);
                } else {
                    sendMessage($chatId, $state['text'], $keyboard, false, $state['entities']);
                }
                break;

            case 'awaiting_promo_text':
                $data['referral_success_text'] = $text ?: $caption;
                $data['referral_success_entities'] = $entities ?: $caption_entities;
                if(isset($state['photo_id'])) $data['referral_success_photo'] = $state['photo_id'];
                $responseMessage = "✅ Referral success message has been updated.";
                unset($state['action'], $state['photo_id']);
                break;
            
            case 'awaiting_home_text':
                $data['home_text'] = $text ?: $caption;
                $data['home_text_entities'] = $entities ?: $caption_entities;
                if(isset($state['photo_id'])) $data['home_photo'] = $state['photo_id'];
                $responseMessage = "✅ Home text message has been updated.";
                unset($state['action'], $state['photo_id']);
                break;
            
            case 'awaiting_admin_id':
                if(is_numeric($text)){
                    $newAdminId = (int)$text;
                    $admins = loadAdmins();
                    if(!in_array($newAdminId, $admins)){
                        $admins[] = $newAdminId;
                        saveAdmins($admins);
                        $responseMessage = "✅ Admin <code>{$newAdminId}</code> added successfully.";
                    } else {
                        $responseMessage = "ℹ️ This user is already an admin.";
                    }
                    unset($state['action']);
                } else {
                    $responseMessage = "❌ Invalid User ID. Please send a numeric ID or /cancel.";
                }
                break;

            case 'awaiting_forwarded_message':
                 if(isset($message['forward_from_chat'])) {
                    $channel_id = $message['forward_from_chat']['id']; 
                    $channel_name = $message['forward_from_chat']['title'];
                    $channel_username = $message['forward_from_chat']['username'] ?? null;
                    if ($channel_username) {
                        $data['channels'][] = ['id' => $channel_id, 'name' => $channel_name, 'original_name' => $channel_name, 'link' => 'https://t.me/' . $channel_username, 'enforced' => true];
                        $responseMessage = "✅ Public channel '<b>{$channel_name}</b>' added.";
                        unset($state['action']);
                    } else {
                        $state['action'] = 'awaiting_private_channel_link';
                        $state['data'] = ['id' => $channel_id, 'name' => $channel_name];
                        editMessage($chatId, $panelMessageId, "🔒 <b>Enter Private Link</b>\n\nThis is a private channel. Please send me the invite link for '<b>{$channel_name}</b>'.\n\nTo cancel, send /cancel.", null);
                    }
                } else { $responseMessage = "❌ Invalid forward. Please forward a message from the channel or /cancel."; }
                break;
            case 'awaiting_private_channel_link':
                if (preg_match('/^(https?:\/\/)?(www\.)?(telegram\.me|t\.me)\/(joinchat\/|\+).+$/', $text)) {
                     $channel_id = $state['data']['id']; $channel_name = $state['data']['name'];
                     $data['channels'][] = ['id' => $channel_id, 'name' => $channel_name, 'original_name' => $channel_name, 'link' => $text, 'enforced' => true];
                     $responseMessage = "✅ Private channel '<b>{$channel_name}</b>' added.";
                     unset($state['action'], $state['data']);
                } else { $responseMessage = "❌ Invalid private channel link. Please try again or /cancel."; }
                break;
            case 'awaiting_caption_text':
                $data['caption_text'] = $text; 
                $responseMessage = "✅ Caption text updated."; 
                unset($state['action']);
                break;
            case 'awaiting_caption_image':
                if ($photo) {
                    $data['caption_image'] = $photo[count($photo) - 1]['file_id'];
                    $responseMessage = "✅ Caption image updated successfully.";
                    unset($state['action']);
                } elseif (filter_var($text, FILTER_VALIDATE_URL)) {
                    $data['caption_image'] = $text;
                    $responseMessage = "✅ Caption image updated successfully from URL.";
                    unset($state['action']);
                } else {
                    $responseMessage = "❌ This is not a photo or a valid URL. Please try again or send /cancel.";
                }
                break;
            case 'awaiting_button_name':
                $channelIndex = $state['data']['channel_index'];
                if (isset($data['channels'][$channelIndex])) {
                    $data['channels'][$channelIndex]['name'] = $text;
                    $responseMessage = "✅ Button name updated.";
                } else { $responseMessage = "❌ Error: Channel not found."; }
                unset($state['action'], $state['data']);
                break;
            case 'awaiting_all_channel_names':
                $newName = htmlspecialchars($text);
                if (!empty($data['channels'])) {
                    foreach ($data['channels'] as &$channel) { $channel['name'] = $newName; } unset($channel);
                    $responseMessage = "✅ All channel button names have been updated to '<b>{$newName}</b>'.";
                } else { $responseMessage = "ℹ️ No channels to update."; }
                unset($state['action']);
                break;
            case 'awaiting_joined_button_name':
                $data['joined_button_text'] = $text;
                $responseMessage = "✅ Joined button name updated.";
                unset($state['action']);
                break;
        }

        saveData($data);
        if (!empty($responseMessage)) {
             deleteMessage($chatId, $messageId);
             sendMessage($chatId, $responseMessage);
        }

        if (!isset($state['action'])) {
            if ($panelMessageId) {
                if (in_array($action, ['awaiting_forwarded_message', 'awaiting_private_channel_link', 'awaiting_button_name'])) {
                    showManageChannelsPanel($chatId, $data, $panelMessageId);
                } elseif ($action === 'awaiting_caption_image') {
                    showCaptionImageSettings($chatId, $data, $panelMessageId);
                } elseif ($action === 'awaiting_promo_text') {
                    showPromoCodeSettings($chatId, $data, $panelMessageId);
                } elseif ($action === 'awaiting_home_text') {
                    showHomeTextSettings($chatId, $data, $panelMessageId);
                } elseif ($action === 'awaiting_admin_id') {
                    showManageAdminPanel($chatId, $panelMessageId);
                } elseif (in_array($action, ['awaiting_all_channel_names', 'awaiting_joined_button_name'])) {
                    showAllNameChannelMenu($chatId, $panelMessageId);
                } else {
                    showMainAdminPanel($chatId, $data, $panelMessageId);
                }
            }
        }
        exit;
    }

    if (preg_match('/^\/start(?:\s+|$)(.*)/s', $text, $matches)) {
        $startPayload = trim($matches[1] ?? '');
        $userId = $chatId;
        $users = loadUsers();
        if (!in_array($userId, $users)) {
            $users[] = $userId;
            saveUsers($users);
            if (!empty($startPayload) && is_numeric($startPayload)) {
                $referrerId = (int)$startPayload;
                if ($referrerId != $userId) { 
                    $data['referrals'][$userId] = $referrerId;
                    saveData($data);
                }
            }
        }
        updateUserStats($data, $userId);

        $buttons = []; $row = [];

        foreach ($data['channels'] as $channel) {
            $show_button = false;
            if (isset($channel['enforced']) && $channel['enforced']) {
                $member_status = getChatMember($channel['id'], $userId);
                $status = $member_status['status'] ?? 'left';
                if (!in_array($status, ['creator', 'administrator', 'member'])) {
                    $show_button = true;
                }
            } else {
                $show_button = true;
            }

            if ($show_button) {
                $row[] = ['text' => $channel['name'], 'url' => $channel['link']];
                if (count($row) == 2) { $buttons[] = $row; $row = []; }
            }
        }
        if (!empty($row)) $buttons[] = $row;
        
        $buttons[] = [['text' => $data['joined_button_text'], 'callback_data' => 'claim_reward']];
        $replyMarkup = ['inline_keyboard' => $buttons];

        if ($data['caption_image_enabled']) {
            sendPhoto($chatId, $data['caption_image'], $data['caption_text'], $replyMarkup);
        } else {
            sendMessage($chatId, $data['caption_text'], $replyMarkup);
        }
    
    } 
    elseif ($text == '/admin' && isAdmin($chatId)) {
        showMainAdminPanel($chatId, $data);
    }
} 

elseif (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $callbackQueryId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $messageId = $callbackQuery['message']['message_id'];
    $callbackData = $callbackQuery['data'];
    $userId = $callbackQuery['from']['id'];
    
    $bot_username = getBotUsername();
    $refer_link = "https://t.me/{$bot_username}?start={$userId}";

    if ($callbackData == 'claim_reward') {
        answerCallbackQuery($callbackQueryId, "Checking your status, please wait...");

        $enforced_channels = array_filter($data['channels'], function($ch) { return isset($ch['enforced']) && $ch['enforced']; });
        $not_joined_channels = [];
        $bot_error = false;

        if (!empty($enforced_channels)) {
            $join_requests = loadJoinRequests();
            foreach ($enforced_channels as $channel) {
                $channel_id = $channel['id'];
                $member_status = getChatMember($channel_id, $userId);

                if ($member_status === false || !isset($member_status['status'])) {
                    sendMessage(OWNER_ID, "‼️ <b>BOT CONFIG ERROR</b> ‼️\n`getChatMember` FAILED for channel '{$channel['name']}' (`{$channel_id}`).\n<b>REASON:</b> Bot is NOT an admin or doesn't have 'Add Members' permission.");
                    $bot_error = true; break;
                }
                
                $status = $member_status['status'];
                if (!in_array($status, ['creator', 'administrator', 'member'])) {
                    $has_sent_request = false;
                    if (isset($join_requests[$userId]) && in_array($channel_id, $join_requests[$userId])) {
                        $has_sent_request = true;
                    }
                    if (!$has_sent_request) {
                        $not_joined_channels[] = $channel;
                    }
                }
            }
        }

        if ($bot_error) {
            sendMessage($chatId, "<b>❌ Bot Configuration Error!</b>\nPlease report this to the admin.");
        } elseif (!empty($not_joined_channels)) {
            $message = "<b>❌ Task Incomplete</b>\n\nYou must join the following channels to claim your reward:\n";
            $channel_links = [];
            foreach ($not_joined_channels as $channel_to_join) {
                $displayName = htmlspecialchars($channel_to_join['original_name'] ?? $channel_to_join['name']);
                $url = $channel_to_join['link'];
                $channel_links[] = "👉 <a href=\"{$url}\">{$displayName}</a>";
            }
            $message .= "\n" . implode("\n", $channel_links);
            sendMessage($chatId, $message, null, true);
        } else {
            deleteMessage($chatId, $messageId);
            
            $processedText = processTextWithLink($data['home_text'], $data['home_text_entities'], $refer_link);
            
            $share_url = "https://t.me/share/url?url=" . urlencode($refer_link) . "&text=" . urlencode("Join and get your reward!");
            $final_keyboard = ['inline_keyboard' => [
                [['text' => '🔗 Copy Link', 'callback_data' => 'copy_link'], ['text' => '🚀 Share Link', 'url' => $share_url]]
            ]];

            if($data['home_photo_enabled'] && !empty($data['home_photo'])){
                sendPhoto($chatId, $data['home_photo'], $processedText['text'], $final_keyboard, $processedText['entities']);
            } else {
                sendMessage($chatId, $processedText['text'], $final_keyboard, false, $processedText['entities']);
            }

            $successfulUsers = loadSuccessfulUsers();
            if (!in_array($userId, $successfulUsers)) {
                $successfulUsers[] = $userId;
                saveSuccessfulUsers($successfulUsers);
            }

            if(isset($data['referrals'][$userId])) {
                $referrerId = $data['referrals'][$userId];
                if(is_numeric($referrerId) && $referrerId != $userId){
                    if($data['referral_success_photo_enabled'] && !empty($data['referral_success_photo'])){
                        sendPhoto($referrerId, $data['referral_success_photo'], $data['referral_success_text'], null, $data['referral_success_entities']);
                    } else {
                        sendMessage($referrerId, $data['referral_success_text'], null, false, $data['referral_success_entities']);
                    }
                }
                unset($data['referrals'][$userId]);
                saveData($data);
            }
        }
    } 
    elseif ($callbackData == 'copy_link') {
        answerCallbackQuery($callbackQueryId);
        sendMessage($chatId, "<code>" . $refer_link . "</code>");
    }
    elseif (isAdmin($chatId)) {
        answerCallbackQuery($callbackQueryId);
        
        if (!isset($data['admin_state'][$chatId])) $data['admin_state'][$chatId] = [];
        $panelMessageId = $data['admin_state'][$chatId]['panel_message_id'] ?? $messageId;
        $state = &$data['admin_state'][$chatId];

        switch (true) {
            case $callbackData === 'noop': break;
            
            case $callbackData === 'back_to_main': showMainAdminPanel($chatId, $data, $panelMessageId); break;
            case $callbackData === 'manage_channels': showManageChannelsPanel($chatId, $data, $panelMessageId); break;
            case $callbackData === 'show_statistics': showStatisticsPanel($chatId, $panelMessageId); break;
            case $callbackData === 'change_position': showChangePositionPanel($chatId, $data, $panelMessageId); break;
            case $callbackData === 'broadcast_menu': showBroadcastMenu($chatId, $panelMessageId); break;
            case $callbackData === 'channel_broadcast_menu': showChannelBroadcastMenu($chatId, $panelMessageId); break;
            
            case $callbackData === 'manage_admins': showManageAdminPanel($chatId, $panelMessageId); break;
            case $callbackData === 'add_admin': 
                $state['action'] = 'awaiting_admin_id';
                editMessage($chatId, $panelMessageId, "➕ Please send the numeric User ID of the new admin, or /cancel.", null);
                break;
            case $callbackData === 'remove_admin_list': showRemoveAdminPanel($chatId, $panelMessageId); break;
            case strpos($callbackData, 'remove_admin_') === 0:
                $adminIdToRemove = (int)substr($callbackData, strlen('remove_admin_'));
                if ($adminIdToRemove != OWNER_ID) {
                    $admins = loadAdmins();
                    $admins_updated = array_filter($admins, fn($admin) => $admin != $adminIdToRemove);
                    saveAdmins($admins_updated);
                    showRemoveAdminPanel($chatId, $panelMessageId);
                }
                break;

            case $callbackData === 'caption_image_settings': showCaptionImageSettings($chatId, $data, $panelMessageId); break;
            case $callbackData === 'promo_code_setting': showPromoCodeSettings($chatId, $data, $panelMessageId); break;
            case $callbackData === 'home_text_setting': showHomeTextSettings($chatId, $data, $panelMessageId); break;
            case $callbackData === 'all_name_channel_menu': showAllNameChannelMenu($chatId, $panelMessageId); break;

            case $callbackData === 'toggle_caption_image':
                $data['caption_image_enabled'] = !($data['caption_image_enabled'] ?? true);
                saveData($data);
                showCaptionImageSettings($chatId, $data, $panelMessageId);
                break;
            case $callbackData === 'toggle_promo_photo':
                $data['referral_success_photo_enabled'] = !($data['referral_success_photo_enabled'] ?? false);
                saveData($data);
                showPromoCodeSettings($chatId, $data, $panelMessageId);
                break;
            case $callbackData === 'toggle_home_photo':
                $data['home_photo_enabled'] = !($data['home_photo_enabled'] ?? false);
                saveData($data);
                showHomeTextSettings($chatId, $data, $panelMessageId);
                break;
            
            case $callbackData === 'change_caption_text':
                $state['action'] = 'awaiting_caption_text'; editMessage($chatId, $panelMessageId, "✏️ Send new caption text or /cancel.", null); break;
            case $callbackData === 'change_caption_image':
                $state['action'] = 'awaiting_caption_image'; editMessage($chatId, $panelMessageId, "🖼️ Please send the new photo for the caption, or /cancel.", null); break;
            
            case $callbackData === 'set_promo_photo_text':
                $state['action'] = 'awaiting_promo_photo'; editMessage($chatId, $panelMessageId, "🖼️ Please send the photo for the referral success message, or /cancel.", null); break;
            case $callbackData === 'set_home_photo_text':
                $state['action'] = 'awaiting_home_photo'; 
                $prompt = "🖼️ First, please send the photo for the home text message.\n\nTo place the user's referral link, use the placeholder <code>{link}</code> in the text you send next.\n\nOr /cancel.";
                editMessage($chatId, $panelMessageId, $prompt, null); break;

            case $callbackData === 'add_channel':
                $state['action'] = 'awaiting_forwarded_message'; editMessage($chatId, $panelMessageId, "➕ Forward a message from the channel you want to add, or /cancel.", null); break;
            
            case $callbackData === 'edit_all_channel_names':
                $state['action'] = 'awaiting_all_channel_names'; editMessage($chatId, $panelMessageId, "🔄 Enter the new name for ALL channel buttons, or /cancel.", null); break;
            case $callbackData === 'edit_joined_button_name':
                $state['action'] = 'awaiting_joined_button_name'; editMessage($chatId, $panelMessageId, "✅ Enter the new name for the 'Get Promo Code' button, or /cancel.", null); break;
            
            case $callbackData === 'broadcast_text':
                $state['action'] = 'broadcast_awaiting_text'; 
                editMessage($chatId, $panelMessageId, "📣 Send the message you want to broadcast.\n\n" . getManualTextFormats() . "\n\nOr /cancel to abort.", null); break;
            case $callbackData === 'broadcast_photo_text':
                $state['action'] = 'broadcast_awaiting_photo'; 
                editMessage($chatId, $panelMessageId, "🖼️ Send the photo for the broadcast, or /cancel.", null); break;
            case $callbackData === 'channel_broadcast_text':
                $state['action'] = 'channel_broadcast_awaiting_text';
                editMessage($chatId, $panelMessageId, "📢 Send the text to broadcast to all channels.\n\n" . getManualTextFormats() . "\n\nOr /cancel to abort.", null); break;
            case $callbackData === 'channel_broadcast_photo_text':
                $state['action'] = 'channel_broadcast_awaiting_photo';
                editMessage($chatId, $panelMessageId, "🖼️ Send the photo for the channel broadcast, or /cancel.", null); break;

            case $callbackData === 'cancel_broadcast':
                deleteMessage($chatId, $messageId);
                unset($data['admin_state'][$chatId]);
                showMainAdminPanel($chatId, $data, $panelMessageId);
                sendMessage($chatId, "Broadcast cancelled.");
                break;
            
            case $callbackData === 'confirm_broadcast':
            case $callbackData === 'confirm_channel_broadcast':
                $isChannelBroadcast = ($callbackData === 'confirm_channel_broadcast');
                $broadcastText = $state['text'] ?? '';
                $broadcastEntities = $state['entities'] ?? null;
                $photoId = $state['photo_id'] ?? null;
                
                unset($data['admin_state'][$chatId]);
                saveData($data);

                deleteMessage($chatId, $messageId); 
                editMessage($chatId, $panelMessageId, "⏳ Starting broadcast... Please wait.", null);

                $targets = $isChannelBroadcast ? array_column($data['channels'], 'id') : loadUsers();
                $sentCount = 0; $failedCount = 0;
                
                foreach ($targets as $target_id) {
                    $success = false;
                    if ($photoId) {
                        $success = sendPhoto($target_id, $photoId, $broadcastText, null, $broadcastEntities);
                    } else {
                        $success = sendMessage($target_id, $broadcastText, null, false, $broadcastEntities);
                    }

                    if ($success) $sentCount++; else $failedCount++;
                    usleep(50000);
                }
                
                $type = $isChannelBroadcast ? "Channel" : "User";
                $responseMessage = "✅ {$type} Broadcast finished.\nSent: {$sentCount}\nFailed: {$failedCount}\nTotal Targets: " . count($targets);
                sendMessage($chatId, $responseMessage);
                showMainAdminPanel($chatId, $data, $panelMessageId);
                break;

            case strpos($callbackData, 'edit_channel_name_') === 0:
                $channelIndex = (int)substr($callbackData, strlen('edit_channel_name_'));
                $state['action'] = 'awaiting_button_name';
                $state['data'] = ['channel_index' => $channelIndex];
                editMessage($chatId, $panelMessageId, "✏️ <b>Enter New Button Name</b> or /cancel...", null);
                break;
            case strpos($callbackData, 'delete_channel_') === 0:
                $channelIndex = (int)substr($callbackData, strlen('delete_channel_'));
                if(deleteChannel($channelIndex, $data)) { 
                    showManageChannelsPanel($chatId, $data, $panelMessageId);
                }
                break;
            case strpos($callbackData, 'toggle_enforce_') === 0:
                $channelIndex = (int)substr($callbackData, strlen('toggle_enforce_'));
                if(isset($data['channels'][$channelIndex])) {
                    $data['channels'][$channelIndex]['enforced'] = !($data['channels'][$channelIndex]['enforced'] ?? false);
                    saveData($data);
                    showManageChannelsPanel($chatId, $data, $panelMessageId);
                }
                break;
            
            case strpos($callbackData, 'move_up_') === 0:
                $index = (int)substr($callbackData, strlen('move_up_'));
                if ($index > 0 && isset($data['channels'][$index])) {
                    $temp = $data['channels'][$index - 1];
                    $data['channels'][$index - 1] = $data['channels'][$index];
                    $data['channels'][$index] = $temp;
                    saveData($data);
                    showChangePositionPanel($chatId, $data, $panelMessageId); 
                }
                break;
            case strpos($callbackData, 'move_down_') === 0:
                $index = (int)substr($callbackData, strlen('move_down_'));
                if ($index < count($data['channels']) - 1 && isset($data['channels'][$index])) {
                    $temp = $data['channels'][$index + 1];
                    $data['channels'][$index + 1] = $data['channels'][$index];
                    $data['channels'][$index] = $temp;
                    saveData($data);
                    showChangePositionPanel($chatId, $data, $panelMessageId); 
                }
                break;
        }
        // Save data at the end of any state-changing callback
        saveData($data);
    } 
    else {
        answerCallbackQuery($callbackQueryId);
    }
}
?>
