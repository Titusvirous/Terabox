<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

// Bot configuration with hardcoded token
$BOT_TOKEN = '8339486458:AAEQWpibqBGXc3wWBpZPu3e6JBnhIE5Nd74';
$ADMIN_IDS = [7392785352]; // Add your real admin user IDs here

// Force join channels - Replace with your actual channel usernames/IDs
$forceJoinChannels = [
    '@ToxicBack2025',  // Replace with your public channel username
    // '-1002413581975' // Private channel ID (Example: -1001234567890).
                       // Private channels require the bot to be an admin and a specific invite link (not just ID).
                       // For private channels, ensure your bot can access chat members.
                       // For simplicity and common use, public channel example is provided.
                       // If using a private channel, you'll need its invite link (e.g., in showForceJoinMessage).
];

// Check bot token
if (empty($BOT_TOKEN) || $BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE') {
    die("❌ Bot token is not properly configured!\n");
}

// Database files for JSON storage
$USERS_DB = 'users.json';
$BLOCKED_USERS_DB = 'blocked_users.json';
$ADMIN_STATES_DB = 'admin_states.json'; // New: For persistent admin states

// Initialize JSON databases
function initDatabase() {
    global $USERS_DB, $BLOCKED_USERS_DB, $ADMIN_STATES_DB;
    if (!file_exists($USERS_DB)) {
        file_put_contents($USERS_DB, '{}');
    }
    if (!file_exists($BLOCKED_USERS_DB)) {
        file_put_contents($BLOCKED_USERS_DB, '[]');
    }
    if (!file_exists($ADMIN_STATES_DB)) { // Initialize admin states DB
        file_put_contents($ADMIN_STATES_DB, '{}');
    }
}

// User management functions
function getUser($userId) {
    global $USERS_DB;
    $users = json_decode(file_get_contents($USERS_DB), true);
    return $users[$userId] ?? null;
}

function addUser($userId, $userData) {
    global $USERS_DB;
    $users = json_decode(file_get_contents($USERS_DB), true);

    if (!isset($users[$userId])) {
        $users[$userId] = [
            'userId' => $userId,
            'username' => $userData['username'] ?? '',
            'firstName' => $userData['firstName'] ?? '',
            'credits' => 2, // Initial credits for new users
            'totalReferrals' => 0,
            'joinDate' => date('c'),
            'lastUsed' => date('c'),
            'isBlocked' => false
        ];
    } else {
        $users[$userId]['lastUsed'] = date('c');
        if (isset($userData['username'])) $users[$userId]['username'] = $userData['username'];
        if (isset($userData['firstName'])) $users[$userId]['firstName'] = $userData['firstName'];
    }

    file_put_contents($USERS_DB, json_encode($users, JSON_PRETTY_PRINT));
    return $users[$userId];
}

function updateUser($userId, $updates) {
    global $USERS_DB;
    $users = json_decode(file_get_contents($USERS_DB), true);

    if (isset($users[$userId])) {
        foreach ($updates as $key => $value) {
            $users[$userId][$key] = $value;
        }
        $users[$userId]['lastUsed'] = date('c');
        file_put_contents($USERS_DB, json_encode($users, JSON_PRETTY_PRINT));
        return $users[$userId];
    }

    return null;
}

function addReferral($referrerId) {
    global $USERS_DB;
    $users = json_decode(file_get_contents($USERS_DB), true);

    if (isset($users[$referrerId])) {
        $users[$referrerId]['totalReferrals']++;
        $users[$referrerId]['credits']++; // 1 credit per referral
        file_put_contents($USERS_DB, json_encode($users, JSON_PRETTY_PRINT));
        return true;
    }

    return false;
}

function isUserBlocked($userId) {
    global $BLOCKED_USERS_DB;
    $blockedUsers = json_decode(file_get_contents($BLOCKED_USERS_DB), true);
    return in_array($userId, $blockedUsers);
}

function blockUser($userId) {
    global $BLOCKED_USERS_DB;
    $blockedUsers = json_decode(file_get_contents($BLOCKED_USERS_DB), true);
    if (!in_array($userId, $blockedUsers)) {
        $blockedUsers[] = $userId;
        file_put_contents($BLOCKED_USERS_DB, json_encode($blockedUsers, JSON_PRETTY_PRINT));
    }

    updateUser($userId, ['isBlocked' => true]);
}

function unblockUser($userId) {
    global $BLOCKED_USERS_DB;
    $blockedUsers = json_decode(file_get_contents($BLOCKED_USERS_DB), true);
    $blockedUsers = array_filter($blockedUsers, function($id) use ($userId) {
        return $id != $userId;
    });
    file_put_contents($BLOCKED_USERS_DB, json_encode(array_values($blockedUsers), JSON_PRETTY_PRINT));

    updateUser($userId, ['isBlocked' => false]);
}

function getAllUsers() {
    global $USERS_DB;
    return json_decode(file_get_contents($USERS_DB), true) ?? [];
}

function getTotalReferrals() {
    $users = getAllUsers();
    $total = 0;
    foreach ($users as $user) {
        $total += $user['totalReferrals'] ?? 0;
    }
    return $total;
}

function isAdmin($userId) {
    global $ADMIN_IDS;
    return in_array($userId, $ADMIN_IDS);
}

// Persistent Admin state management
function setAdminState($userId, $state) {
    global $ADMIN_STATES_DB;
    $adminStates = json_decode(file_get_contents($ADMIN_STATES_DB), true);
    if ($state === null) {
        unset($adminStates[$userId]);
    } else {
        $adminStates[$userId] = $state;
    }
    file_put_contents($ADMIN_STATES_DB, json_encode($adminStates, JSON_PRETTY_PRINT));
}

function getAdminState($userId) {
    global $ADMIN_STATES_DB;
    $adminStates = json_decode(file_get_contents($ADMIN_STATES_DB), true);
    return $adminStates[$userId] ?? null;
}


function checkUserMembership($userId, $channelId) {
    global $BOT_TOKEN;

    $url = "https://api.telegram.org/bot$BOT_TOKEN/getChatMember";
    $data = [
        'chat_id' => $channelId,
        'user_id' => $userId
    ];

    $response = makeRequest($url, $data);

    if ($response && isset($response['ok']) && $response['ok']) {
        $status = $response['result']['status'];
        // 'left' या 'kicked' नहीं होना चाहिए
        return in_array($status, ['member', 'administrator', 'creator']);
    }
    error_log("Failed to get chat member for UserID: $userId, Channel: $channelId. Response: " . json_encode($response));
    return false;
}

function checkForceJoin($userId) {
    global $forceJoinChannels;

    foreach ($forceJoinChannels as $channel) {
        if (!checkUserMembership($userId, $channel)) {
            return false;
        }
    }

    return true;
}

function showForceJoinMessage($chatId) {
    global $forceJoinChannels;

    $message = "🔒 *You must join our channels to use this bot!*\n\n";
    $message .= "📢 *Required Channels:*\n";

    $keyboard = ['inline_keyboard' => []];

    foreach ($forceJoinChannels as $index => $channel) {
        if (strpos($channel, '@') === 0) { // Public channel
            $channelName = str_replace('@', '', $channel);
            $message .= "• @$channelName (Public Channel)\n";
            $keyboard['inline_keyboard'][] = [
                ['text' => "📢 Join Public Channel", 'url' => "https://t.me/$channelName"]
            ];
        } else { // Private channel (assuming ID is provided)
            // For a private channel ID, you need to provide a valid invite link
            // Replace 'YOUR_PRIVATE_INVITE_LINK' with the actual link you generate from Telegram
            $message .= "• Private Channel (Join via link)\n";
            $keyboard['inline_keyboard'][] = [
                ['text' => "🔐 Join Private Channel", 'url' => "https://t.me/joinchat/YOUR_PRIVATE_INVITE_LINK"] // Replace this!
            ];
        }
    }

    $message .= "\n✅ *After joining all channels, click 'Check Membership' to continue.*";

    $keyboard['inline_keyboard'][] = [
        ['text' => '✅ Check Membership', 'callback_data' => 'check_membership']
    ];

    sendMessage($chatId, $message, ['reply_markup' => $keyboard]);
}

// Telegram API functions
function sendMessage($chatId, $text, $options = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";

    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $options['parse_mode'] ?? 'Markdown'
    ];

    if (isset($options['reply_markup'])) {
        $data['reply_markup'] = json_encode($options['reply_markup']);
    }

    return makeRequest($url, $data);
}

function sendPhoto($chatId, $photo, $options = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendPhoto";

    $data = [
        'chat_id' => $chatId,
        'photo' => $photo,
        'parse_mode' => $options['parse_mode'] ?? 'Markdown'
    ];

    if (isset($options['caption'])) {
        $data['caption'] = $options['caption'];
    }

    if (isset($options['reply_markup'])) {
        $data['reply_markup'] = json_encode($options['reply_markup']);
    }

    return makeRequest($url, $data);
}

function editMessageText($chatId, $messageId, $text, $options = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/editMessageText";

    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => $options['parse_mode'] ?? 'Markdown'
    ];

    if (isset($options['reply_markup'])) {
        $data['reply_markup'] = json_encode($options['reply_markup']);
    }

    $response = makeRequest($url, $data);
    if (!$response || !isset($response['ok']) || !$response['ok']) {
        error_log("Failed to edit message text: " . json_encode($response));
        // Fallback to sendMessage if edit fails (e.g., message too old)
        return sendMessage($chatId, $text, $options);
    }
    return $response;
}

function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/answerCallbackQuery";

    $data = [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
        'show_alert' => $showAlert
    ];

    return makeRequest($url, $data);
}

function getBotInfo() {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/getMe";
    return makeRequest($url, []);
}

function makeRequest($url, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development, set to true in production
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Telegram API HTTP Error: $httpCode - URL: $url - Data: " . json_encode($data) . " - Response: $response");
        return false;
    }

    return json_decode($response, true);
}

// Terabox download function using the provided API
function downloadTerabox($url) {
    $encodedUrl = urlencode($url);
    $apiUrl = "https://theteraboxdownloader.com/api?data=$encodedUrl";

    error_log("Terabox API Requesting: " . $apiUrl); // Debugging line

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Terabox API HTTP Error: $httpCode - Curl Error: $curlError - Response: $response"); // Detailed error log
        return ['success' => false, 'error' => "API request failed (HTTP $httpCode). Error: $curlError"];
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Terabox API JSON Decode Error: " . json_last_error_msg() . " - Raw Response: " . $response);
        return ['success' => false, 'error' => 'Invalid API response format (JSON error)'];
    }

    if (isset($data['direct_link']) && !empty($data['direct_link'])) {
        error_log("Terabox API Success: Direct link found.");
        return [
            'success' => true,
            'downloadLink' => $data['direct_link'],
            'fileName' => $data['file_name'] ?? 'Unknown File',
            'fileSize' => $data['size'] ?? 'Unknown Size',
            'thumbnail' => $data['thumb'] ?? null
        ];
    }

    if (isset($data['link']) && !empty($data['link'])) {
        error_log("Terabox API Success: General link found.");
        return [
            'success' => true,
            'downloadLink' => $data['link'],
            'fileName' => $data['file_name'] ?? 'Unknown File',
            'fileSize' => $data['size'] ?? 'Unknown Size',
            'thumbnail' => $data['thumb'] ?? null
        ];
    }
    error_log("Terabox API Error: Download link not found in valid response. Response Data: " . json_encode($data)); // Log response when link not found
    return ['success' => false, 'error' => 'Download link not found in API response.'];
}

function isTeraboxUrl($url) {
    $teraboxDomains = [
        'terabox.com', '1024terabox.com', '4funbox.com', 'mirrobox.com',
        'nephobox.com', 'teraboxapp.com', 'terasharelink.com', 'terafileshare.com'
    ];

    $urlLower = strtolower($url);
    foreach ($teraboxDomains as $domain) {
        if (strpos($urlLower, $domain) !== false) {
            return true;
        }
    }

    return false;
}

// Handle webhook
function handleWebhook() {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);

    if (!$update) {
        error_log("Received invalid webhook update: " . $input);
        return;
    }

    if (isset($update['message'])) {
        handleMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
    } else {
        error_log("Unhandled update type: " . json_encode($update));
    }
}

// Handle regular messages
function handleMessage($message) {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $text = $message['text'] ?? '';
    $firstName = $message['from']['first_name'] ?? 'User';
    $username = $message['from']['username'] ?? '';

    // Check if user is blocked first
    if (isUserBlocked($userId)) {
        sendMessage($chatId, '❌ You are blocked from using this bot.');
        return; // Important: Exit here
    }

    // Handle admin states before general message processing
    if (isAdmin($userId)) {
        $state = getAdminState($userId);

        if ($state === 'waiting_broadcast') {
            $users = getAllUsers();
            $successCount = 0;

            foreach ($users as $user) {
                // Don't send broadcast to the admin who initiated it or blocked users
                if ($user['userId'] != $userId && !$user['isBlocked']) {
                    $result = sendMessage($user['userId'], "📢 *Broadcast Message*\n\n$text");
                    if ($result && $result['ok']) {
                        $successCount++;
                    } else {
                        error_log("Failed to send broadcast to user " . $user['userId'] . ": " . json_encode($result));
                    }
                }
            }

            sendMessage($chatId, "✅ Broadcast sent to $successCount/" . count(array_filter($users, fn($u) => !$u['isBlocked'])) . " active users.");
            setAdminState($userId, null);
            return; // Exit after handling admin state
        }

        if ($state === 'waiting_block_id') {
            $targetUserId = (int)$text;
            if (getUser($targetUserId)) {
                blockUser($targetUserId);
                sendMessage($chatId, "✅ User $targetUserId has been blocked.");
            } else {
                sendMessage($chatId, "❌ User ID $targetUserId not found in database.");
            }
            setAdminState($userId, null);
            return; // Exit after handling admin state
        }

        if ($state === 'waiting_unblock_id') {
            $targetUserId = (int)$text;
            if (getUser($targetUserId)) {
                unblockUser($targetUserId);
                sendMessage($chatId, "✅ User $targetUserId has been unblocked.");
            } else {
                sendMessage($chatId, "❌ User ID $targetUserId not found in database.");
            }
            setAdminState($userId, null);
            return; // Exit after handling admin state
        }

        if ($state === 'waiting_credit_data') {
            $parts = explode(' ', $text, 2);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $targetUserId = (int)$parts[0];
                $creditsToAdd = (int)$parts[1];

                $user = getUser($targetUserId);
                if ($user) {
                    $newCredits = ($user['credits'] ?? 0) + $creditsToAdd;
                    updateUser($targetUserId, ['credits' => $newCredits]);
                    sendMessage($chatId, "✅ Added $creditsToAdd credits to user $targetUserId. New balance: $newCredits");
                } else {
                    sendMessage($chatId, "❌ User not found in database. Please ensure the user has started the bot at least once.");
                }
            } else {
                sendMessage($chatId, "❌ Invalid format. Use: USER_ID CREDITS_AMOUNT\nExample: 123456789 50");
            }
            setAdminState($userId, null);
            return; // Exit after handling admin state
        }
    }
    // End of admin state handling

    // Handle /start command
    if (strpos($text, '/start') === 0) {
        $referralCode = trim(str_replace('/start', '', $text));

        // Check force join for all users (including new ones)
        if (!checkForceJoin($userId)) {
            showForceJoinMessage($chatId);
            return;
        }

        // Add or update user data
        $user = addUser($userId, [
            'username' => $username,
            'firstName' => $firstName
        ]);

        // Handle referral if present and valid
        if (!empty($referralCode) && $referralCode !== (string)$userId) {
            $referrerId = (int)$referralCode;
            $referrerUser = getUser($referrerId);
            if ($referrerUser && !$referrerUser['isBlocked']) { // Ensure referrer exists and is not blocked
                if (addReferral($referrerId)) {
                    sendMessage($referrerId, "🎉 New referral! User $firstName joined using your link. You earned 1 credit!");
                    sendMessage($chatId, '🎁 You joined via referral! Your referrer got 1 credit.');
                }
            }
        }

        $welcomeMessage = "🎉 *Welcome to Terabox Downloader Bot!*

Hello $firstName! 👋

💳 *Your Credits:* {$user['credits']}
👥 *Your Referrals:* " . ($user['totalReferrals'] ?? 0) . "

🔥 *How to Use This Bot:*

*Step 1:* Copy any Terabox link
*Step 2:* Send it here (costs 1 credit)  
*Step 3:* Get instant direct download link! ⚡

*📱 Supported Links:*
• terabox.com • 1024terabox.com  
• 4funbox.com • mirrobox.com
• nephobox.com • teraboxapp.com

*💰 Get More Credits:*
🔗 Share referral link - Get 1 credit per friend!
💳 Buy credit packages - Instant activation

*🎯 Example:*
Just paste: `https://terabox.com/s/1abc123...`
Bot replies: ✅ Direct download link ready!

Ready to download? Send me a Terabox link! 🚀";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🎁 Refer & Earn', 'callback_data' => 'referral']],
                [['text' => '💰 Buy Credits', 'callback_data' => 'buy_credits']],
                [['text' => '📊 My Account', 'callback_data' => 'my_account']],
                [['text' => '❓ Help', 'callback_data' => 'help_info']]
            ]
        ];

        if (isAdmin($userId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Admin Panel', 'callback_data' => 'admin_panel']];
        }

        $teraboxImageUrl = 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/c7/Terabox_logo.png/300px-Terabox_logo.png';

        // Try to send photo with caption, fallback to text message if photo fails
        $photoResult = sendPhoto($chatId, $teraboxImageUrl, [
            'caption' => $welcomeMessage,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard
        ]);

        if (!$photoResult || !$photoResult['ok']) {
            error_log("Failed to send welcome photo to $chatId. Falling back to text message. Response: " . json_encode($photoResult));
            sendMessage($chatId, $welcomeMessage, [
                'parse_mode' => 'Markdown',
                'reply_markup' => $keyboard
            ]);
        }
        return; // Exit after handling /start
    }

    // Handle /admin command (if not handled by state above)
    if ($text === '/admin' && isAdmin($userId)) {
        showAdminPanel($chatId);
        return; // Exit after handling /admin
    }

    // Check force join for non-start/non-admin users
    if (!isAdmin($userId) && !checkForceJoin($userId)) {
        showForceJoinMessage($chatId);
        return; // Exit if force join is not met
    }

    // Handle Terabox URLs
    if (isTeraboxUrl($text)) {
        $user = getUser($userId);
        // This check is already done at the beginning, but good to have a redundant check here
        if (!$user) {
            sendMessage($chatId, '❌ Please start the bot first with /start');
            return;
        }

        if ($user['credits'] <= 0) {
            $message = "❌ *Insufficient Credits!*

💳 Your Credits: 0

To download files, you need credits:
• Get 1 credit per referral
• Buy credit packages

🎁 *Get Free Credits:*
Share your referral link and earn 1 credit for each friend who joins!

💰 *Buy Credits:*
Contact @CDMAXX for packages";

            sendMessage($chatId, $message);
            return;
        }

        sendMessage($chatId, '⏳ Processing your Terabox link...');

        $result = downloadTerabox($text); // Call the download function

        if ($result['success']) {
            updateUser($userId, ['credits' => $user['credits'] - 1]);

            $successMessage = "✅ *Download Ready!*

📁 *File:* {$result['fileName']}
📊 *Size:* {$result['fileSize']}
💳 *Credits Left:* " . (getUser($userId)['credits']) . "

🔗 *Download Link:*
{$result['downloadLink']}

⚡ *Note:* Link expires after some time. Download quickly!";

            sendMessage($chatId, $successMessage);
        } else {
            $errorMessage = "❌ *Error Processing Link*

" . ($result['error'] ?? 'Unknown error occurred.') . "

Please try again or check if the link is valid. If the issue persists, the Terabox download service might be temporarily unavailable.";

            sendMessage($chatId, $errorMessage);
        }
        return; // Exit after handling Terabox URL
    }

    // Default response for unhandled messages
    if (!empty($text)) {
        sendMessage($chatId, "❌ This doesn't appear to be a valid Terabox URL or a recognized command. Please send a Terabox link.\n\nType /help for more information.");
    }
}

function showAdminPanel($chatId) {
    $users = getAllUsers();
    $totalUsers = count($users);
    $activeUsers = count(array_filter($users, function($user) { return !$user['isBlocked']; }));
    $blockedUsers = count(array_filter($users, function($user) { return $user['isBlocked']; }));
    $totalCreditsSum = array_sum(array_column($users, 'credits'));
    $totalReferrals = getTotalReferrals();

    $adminMessage = "👑 *ADMIN PANEL*

📊 *Bot Statistics:*
👥 Total Users: $totalUsers
✅ Active Users: $activeUsers
🚫 Blocked Users: $blockedUsers
💳 Total Credits: $totalCreditsSum
🎁 Total Referrals: $totalReferrals

🛠️ *Management Options:*";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📢 Broadcast', 'callback_data' => 'admin_broadcast']],
            [['text' => '👥 Member Status', 'callback_data' => 'admin_users']],
            [['text' => '🚫 Block User', 'callback_data' => 'admin_block'], ['text' => '✅ Unblock User', 'callback_data' => 'admin_unblock']],
            [['text' => '💳 Add Credit', 'callback_data' => 'admin_credits']],
            [['text' => '🔄 Refresh', 'callback_data' => 'admin_refresh']]
        ]
    ];

    sendMessage($chatId, $adminMessage, ['reply_markup' => $keyboard]);
}

// Handle callback queries
function handleCallbackQuery($callbackQuery) {
    $chatId = $callbackQuery['message']['chat']['id'];
    $userId = $callbackQuery['from']['id'];
    $data = $callbackQuery['data'];
    $messageId = $callbackQuery['message']['message_id'];

    answerCallbackQuery($callbackQuery['id']); // Always answer callback query to remove loading state

    // Check if user is blocked (even for callbacks)
    if (isUserBlocked($userId)) {
        sendMessage($chatId, '❌ You are blocked from using this bot.');
        return;
    }

    switch ($data) {
        case 'buy_credits':
            $buyMessage = "💰 *Buy Credits - Price List*

💎 *STARTER PACK* - 25 Credits
💵 Price: ₹49

🔥 *BASIC PACK* - 100 Credits  
💵 Price: ₹149

⭐ *PRO PACK* - 500 Credits
💵 Price: ₹499

🚀 *ULTRA PACK* - 1000 Credits
💵 Price: ₹899

👑 *RESELLER PACK* - 5000 Credits
💵 Price: ₹3999

💳 *Payment Methods:* UPI, PayTM, Bank Transfer
⚡ *Instant Activation* after payment confirmation
🔒 *100% Secure* transactions

💬 To buy credits, contact: @CDMAXX";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '💬 Contact for Purchase', 'url' => 'https://t.me/CDMAXX']],
                    [['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']]
                ]
            ];

            editMessageText($chatId, $messageId, $buyMessage, ['reply_markup' => $keyboard]);
            break;

        case 'referral':
            $user = getUser($userId);
            $botInfo = getBotInfo();
            $botUsername = $botInfo['result']['username'] ?? 'your_bot_username';

            $referralMessage = "🔗 *Your Referral Link*

`https://t.me/{$botUsername}?start=$userId`

💰 *How it works:*
• Share your link with friends
• When someone joins using your link, you get 1 credit
• No limit on referrals!

👥 *Your Referrals:* " . ($user['totalReferrals'] ?? 0) . "
💳 *Your Credits:* " . ($user['credits'] ?? 0);

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']]
                ]
            ];

            editMessageText($chatId, $messageId, $referralMessage, ['reply_markup' => $keyboard]);
            break;

        case 'my_account':
            $user = getUser($userId);
            $joinDate = isset($user['joinDate']) ? date('d/m/Y', strtotime($user['joinDate'])) : 'Unknown';

            $statsMessage = "📊 *Your Statistics*

💳 Credits: " . ($user['credits'] ?? 0) . "
👥 Total Referrals: " . ($user['totalReferrals'] ?? 0) . "
📅 Joined: $joinDate";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']]
                ]
            ];

            editMessageText($chatId, $messageId, $statsMessage, ['reply_markup' => $keyboard]);
            break;

        case 'help_info':
            $helpMessage = "🆘 *Help - Terabox Downloader Bot*

*How to use:*
1. Send any Terabox link (costs 1 credit)
2. Get direct download link instantly

*Supported domains:*
• terabox.com • 1024terabox.com
• 4funbox.com • mirrobox.com
• nephobox.com • teraboxapp.com

*Credit System:*
• 1 credit = 1 download
• Earn credits through referrals
• Buy credit packages for more downloads";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu']]
                ]
            ];

            editMessageText($chatId, $messageId, $helpMessage, ['reply_markup' => $keyboard]);
            break;

        case 'main_menu':
            $user = getUser($userId);
            $firstName = $user['firstName'] ?? 'User';

            $welcomeMessage = "🎉 *Welcome Back!*

Hello $firstName! 👋

💳 *Your Credits:* " . ($user['credits'] ?? 0) . "
👥 *Your Referrals:* " . ($user['totalReferrals'] ?? 0) . "

Ready to download? Send me a Terabox link! 🚀";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🎁 Refer & Earn', 'callback_data' => 'referral']],
                    [['text' => '💰 Buy Credits', 'callback_data' => 'buy_credits']],
                    [['text' => '📊 My Account', 'callback_data' => 'my_account']],
                    [['text' => '❓ Help', 'callback_data' => 'help_info']]
                ]
            ];

            if (isAdmin($userId)) {
                $keyboard['inline_keyboard'][] = [['text' => '👑 Admin Panel', 'callback_data' => 'admin_panel']];
            }

            editMessageText($chatId, $messageId, $welcomeMessage, ['reply_markup' => $keyboard]);
            break;

        case 'admin_panel':
            if (!isAdmin($userId)) {
                sendMessage($chatId, "❌ You are not authorized to access the admin panel.");
                return;
            }
            showAdminPanel($chatId);
            break;

        case 'admin_broadcast':
            if (!isAdmin($userId)) return;
            setAdminState($userId, 'waiting_broadcast');
            sendMessage($chatId, "📢 *Broadcast Message*\n\nSend the message you want to broadcast to all users:");
            break;

        case 'admin_users':
            if (!isAdmin($userId)) return;
            $users = getAllUsers();
            $usersList = [];
            $count = 0;
            // Display latest 15 users, or adjust as needed
            $latestUsers = array_slice(array_reverse($users), 0, 15, true); // Get latest users

            foreach ($latestUsers as $user) {
                $status = $user['isBlocked'] ? '🚫 (Blocked)' : '✅ (Active)';
                $usersList[] = "• {$user['firstName']} (ID: {$user['userId']})\n  Status: $status | Credits: {$user['credits']} | Refs: " . ($user['totalReferrals'] ?? 0);
                $count++;
            }

            $totalUsers = count($users);
            $totalReferrals = getTotalReferrals();

            $usersText = empty($usersList) ? "No users found." : implode("\n\n", $usersList);
            $message = "👥 *Member Status (Latest $count Users)*\n\n$usersText\n\n📊 *Summary:*\n👥 Total Users: $totalUsers\n🎁 All Referrals: $totalReferrals";

            sendMessage($chatId, $message);
            break;

        case 'admin_block':
            if (!isAdmin($userId)) return;
            setAdminState($userId, 'waiting_block_id');
            sendMessage($chatId, "🚫 *Block User*\n\nSend the user ID you want to block:");
            break;

        case 'admin_unblock':
            if (!isAdmin($userId)) return;
            setAdminState($userId, 'waiting_unblock_id');
            sendMessage($chatId, "✅ *Unblock User*\n\nSend the user ID you want to unblock:");
            break;

        case 'admin_credits':
            if (!isAdmin($userId)) return;
            setAdminState($userId, 'waiting_credit_data');
            sendMessage($chatId, "💳 *Add Credits*\n\nSend in format: `USER_ID CREDITS_AMOUNT`\nExample: `123456789 50`", ['parse_mode' => 'Markdown']);
            break;

        case 'admin_refresh':
            if (!isAdmin($userId)) return;
            // You can optionally edit the message to show "Refreshing..." then show panel
            editMessageText($chatId, $messageId, "🔄 Refreshing Admin Panel...", ['reply_markup' => ['inline_keyboard' => []]]);
            showAdminPanel($chatId);
            break;

        case 'check_membership':
            // This is crucial for force join. The user has clicked "I have joined!"
            $isMember = checkForceJoin($userId);
            if ($isMember) {
                // If member, add/update user and show main menu
                $user = addUser($userId, [
                    'username' => $callbackQuery['from']['username'] ?? '',
                    'firstName' => $callbackQuery['from']['first_name'] ?? 'User'
                ]);

                $welcomeMessage = "🎉 *Welcome to Terabox Downloader Bot!*

Hello {$callbackQuery['from']['first_name']}! 👋

💳 *Your Credits:* {$user['credits']}
👥 *Your Referrals:* " . ($user['totalReferrals'] ?? 0) . "

🔥 *How to Use This Bot:*

*Step 1:* Copy any Terabox link
*Step 2:* Send it here (costs 1 credit)  
*Step 3:* Get instant direct download link! ⚡

*📱 Supported Links:*
• terabox.com • 1024terabox.com  
• 4funbox.com • mirrobox.com
• nephobox.com • teraboxapp.com

🎁 *Earn Credits:*
• Refer friends = 1 credit per referral
• Buy credit packages for unlimited downloads

💬 Need help? Type /help";

                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🎁 Refer & Earn', 'callback_data' => 'referral']],
                        [['text' => '💰 Buy Credits', 'callback_data' => 'buy_credits']],
                        [['text' => '📊 My Account', 'callback_data' => 'my_account']]
                    ]
                ];
                if (isAdmin($userId)) {
                    $keyboard['inline_keyboard'][] = [['text' => '👑 Admin Panel', 'callback_data' => 'admin_panel']];
                }

                editMessageText($chatId, $messageId, $welcomeMessage, ['reply_markup' => $keyboard]);
            } else {
                // If still not a member, show an alert and re-show the force join message
                answerCallbackQuery($callbackQuery['id'], "❌ Please join all required channels first and click 'Check Membership' again!", true);
                showForceJoinMessage($chatId); // Re-show force join message
            }
            break;
    }
}

// Initialize database (create JSON files if they don't exist)
initDatabase();

// Handle webhook or run as web server
if (php_sapi_name() === 'cli') {
    // CLI mode - start built-in server for testing
    echo "🤖 Starting PHP built-in server on 0.0.0.0:5000\n";
    echo "🔗 Bot Token: " . substr($BOT_TOKEN, 0, 10) . "...\n";
    echo "To set webhook: https://api.telegram.org/bot{$BOT_TOKEN}/setWebhook?url=http://YOUR_PUBLIC_IP:5000\n";
    echo "🚀 Server running...\n";
    
    // This requires a public IP and port forwarding for webhook to work
    passthru("php -S 0.0.0.0:5000");
} else {
    // Web mode - handle webhook requests from Telegram
    handleWebhook();
}
?>