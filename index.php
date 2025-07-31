
<?php
// Adding force join channel functionality to the bot.
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

// Bot configuration with hardcoded token
$BOT_TOKEN = '8339486458:AAEQWpibqBGXc3wWBpZPu3e6JBnhIE5Nd74';
$ADMIN_IDS = [7392785352]; // Add your real admin user IDs here

// Force join channels
$forceJoinChannels = [
    '@ToxicBack2025',  // Replace with your public channel username
    '-1002413581975'         // Replace with your private channel ID
];

// Check bot token
if (empty($BOT_TOKEN) || $BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE') {
    die("❌ Bot token is not properly configured!\n");
}

// Database files
$USERS_DB = 'users.json';
$BLOCKED_USERS_DB = 'blocked_users.json';

// Initialize JSON databases
function initDatabase() {
    global $USERS_DB, $BLOCKED_USERS_DB;
    if (!file_exists($USERS_DB)) {
        file_put_contents($USERS_DB, '{}');
    }
    if (!file_exists($BLOCKED_USERS_DB)) {
        file_put_contents($BLOCKED_USERS_DB, '[]');
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
            'credits' => 2,
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
        $users[$referrerId]['credits']++;
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
        return in_array($status, ['member', 'administrator', 'creator']);
    }

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
        if (strpos($channel, '@') === 0) {
            // Public channel
            $channelName = str_replace('@', '', $channel);
            $message .= "• @$channelName (Public Channel)\n";
            $keyboard['inline_keyboard'][] = [
                ['text' => "📢 Join Public Channel", 'url' => "https://t.me/$channelName"]
            ];
        } else {
            // Private channel
            $message .= "• Private Channel\n";
            $keyboard['inline_keyboard'][] = [
                ['text' => "🔐 Join Private Channel", 'url' => "https://t.me/joinchat/YOUR_PRIVATE_INVITE_LINK"]
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

    return makeRequest($url, $data);
}

function answerCallbackQuery($callbackQueryId, $text = '') {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/answerCallbackQuery";

    $data = [
        'callback_query_id' => $callbackQueryId,
        'text' => $text
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("HTTP Error: $httpCode - $response");
        return false;
    }

    return json_decode($response, true);
}

// New Terabox download function using the provided API
function downloadTerabox($url) {
    $encodedUrl = urlencode($url);
    $apiUrl = "https://theteraboxdownloader.com/api?data=$encodedUrl";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'API request failed'];
    }

    $data = json_decode($response, true);

    if (isset($data['direct_link']) && !empty($data['direct_link'])) {
        return [
            'success' => true,
            'downloadLink' => $data['direct_link'],
            'fileName' => $data['file_name'] ?? 'Unknown File',
            'fileSize' => $data['size'] ?? 'Unknown Size',
            'thumbnail' => $data['thumb'] ?? null
        ];
    }

    if (isset($data['link']) && !empty($data['link'])) {
        return [
            'success' => true,
            'downloadLink' => $data['link'],
            'fileName' => $data['file_name'] ?? 'Unknown File',
            'fileSize' => $data['size'] ?? 'Unknown Size',
            'thumbnail' => $data['thumb'] ?? null
        ];
    }

    return ['success' => false, 'error' => 'Download link not found in response'];
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

// Store admin states
$adminStates = [];

function setAdminState($userId, $state) {
    global $adminStates;
    if ($state === null) {
        unset($adminStates[$userId]);
    } else {
        $adminStates[$userId] = $state;
    }
}

function getAdminState($userId) {
    global $adminStates;
    return $adminStates[$userId] ?? null;
}

// Handle webhook
function handleWebhook() {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);

    if (!$update) {
        return;
    }

    if (isset($update['message'])) {
        handleMessage($update['message']);
    }

    if (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
    }
}

// Handle regular messages
function handleMessage($message) {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $text = $message['text'] ?? '';
    $firstName = $message['from']['first_name'] ?? 'User';
    $username = $message['from']['username'] ?? '';

    if (strpos($text, '/start') === 0) {
        $referralCode = trim(str_replace('/start', '', $text));

        if (isUserBlocked($userId)) {
            sendMessage($chatId, '❌ You are blocked from using this bot.');
            return;
        }

        if (!checkForceJoin($userId)) {
            showForceJoinMessage($chatId);
            return;
        }

        $user = addUser($userId, [
            'username' => $username,
            'firstName' => $firstName
        ]);

        if (!empty($referralCode) && $referralCode !== (string)$userId) {
            $referrerId = (int)$referralCode;
            if (addReferral($referrerId)) {
                sendMessage($referrerId, "🎉 New referral! User $firstName joined using your link. You earned 1 credit!");
                sendMessage($chatId, '🎁 You joined via referral! Your referrer got 1 credit.');
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
Just paste: https://terabox.com/s/1abc123...
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

        $photoResult = sendPhoto($chatId, $teraboxImageUrl, [
            'caption' => $welcomeMessage,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard
        ]);

        if (!$photoResult || !$photoResult['ok']) {
            sendMessage($chatId, $welcomeMessage, [
                'parse_mode' => 'Markdown',
                'reply_markup' => $keyboard
            ]);
        }

        return;
    }

    if ($text === '/admin' && isAdmin($userId)) {
        showAdminPanel($chatId);
        return;
    }

    // Check force join for non-admin users
    if (!isAdmin($userId) && !checkForceJoin($userId)) {
        showForceJoinMessage($chatId);
        return;
    }

    // Handle admin states
    if (isAdmin($userId)) {
        $state = getAdminState($userId);

        if ($state === 'waiting_broadcast') {
            $users = getAllUsers();
            $successCount = 0;

            foreach ($users as $user) {
                if (!$user['isBlocked']) {
                    $result = sendMessage($user['userId'], "📢 *Broadcast Message*\n\n$text");
                    if ($result && $result['ok']) {
                        $successCount++;
                    }
                }
            }

            sendMessage($chatId, "✅ Broadcast sent to $successCount/" . count($users) . " users");
            setAdminState($userId, null);
            return;
        }

        if ($state === 'waiting_block_id') {
            $targetUserId = (int)$text;
            blockUser($targetUserId);
            sendMessage($chatId, "✅ User $targetUserId has been blocked.");
            setAdminState($userId, null);
            return;
        }

        if ($state === 'waiting_unblock_id') {
            $targetUserId = (int)$text;
            unblockUser($targetUserId);
            sendMessage($chatId, "✅ User $targetUserId has been unblocked.");
            setAdminState($userId, null);
            return;
        }

        if ($state === 'waiting_credit_data') {
            $parts = explode(' ', $text, 2);
            if (count($parts) === 2) {
                $targetUserId = (int)$parts[0];
                $creditsToAdd = (int)$parts[1];

                $user = getUser($targetUserId);
                if ($user) {
                    $newCredits = ($user['credits'] ?? 0) + $creditsToAdd;
                    updateUser($targetUserId, ['credits' => $newCredits]);
                    sendMessage($chatId, "✅ Added $creditsToAdd credits to user $targetUserId. New balance: $newCredits");
                } else {
                    sendMessage($chatId, "❌ User not found.");
                }
            } else {
                sendMessage($chatId, "❌ Invalid format. Use: USER_ID CREDITS_AMOUNT");
            }
            setAdminState($userId, null);
            return;
        }
    }

    // Handle Terabox URLs
    if (isTeraboxUrl($text)) {
        if (isUserBlocked($userId)) {
            sendMessage($chatId, '❌ You are blocked from using this bot.');
            return;
        }

        $user = getUser($userId);
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
/buy - See available packages";

            sendMessage($chatId, $message);
            return;
        }

        sendMessage($chatId, '⏳ Processing your Terabox link...');

        $result = downloadTerabox($text);

        if ($result['success']) {
            updateUser($userId, ['credits' => $user['credits'] - 1]);

            $successMessage = "✅ *Download Ready!*

📁 *File:* {$result['fileName']}
📊 *Size:* {$result['fileSize']}
💳 *Credits Left:* " . ($user['credits'] - 1) . "

🔗 *Download Link:*
{$result['downloadLink']}

⚡ *Note:* Link expires after some time. Download quickly!";

            sendMessage($chatId, $successMessage);
        } else {
            $errorMessage = "❌ *Error Processing Link*

{$result['error']}

Please try again or check if the link is valid.";

            sendMessage($chatId, $errorMessage);
        }

        return;
    }

    if (!empty($text)) {
        sendMessage($chatId, "❌ This doesn't appear to be a valid Terabox URL. Please send a Terabox link.\n\nType /help for more information.");
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

    answerCallbackQuery($callbackQuery['id']);

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

            $referralMessage = "🔗 *Your Referral Link*

https://t.me/{$botInfo['result']['username']}?start=$userId

💰 *How it works:*
• Share your link with friends
• When someone joins, you get 1 credit
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
            if (!isAdmin($userId)) return;
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
            foreach ($users as $user) {
                if ($count >= 15) break;
                $status = $user['isBlocked'] ? '🚫' : '✅';
                $usersList[] = "$status {$user['firstName']} - ID: {$user['userId']} - Credits: {$user['credits']} - Refs: " . ($user['totalReferrals'] ?? 0);
                $count++;
            }

            $totalUsers = count($users);
            $totalReferrals = getTotalReferrals();

            $usersText = implode("\n\n", $usersList);
            $message = "👥 *Member Status (Latest 15)*\n\n$usersText\n\n📊 *Summary:*\n👥 Total Users: $totalUsers\n🎁 All Referrals: $totalReferrals";

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
            sendMessage($chatId, "💳 *Add Credits*\n\nSend in format: USER_ID CREDITS_AMOUNT\nExample: 123456789 50");
            break;

        case 'admin_refresh':
            if (!isAdmin($userId)) return;
            editMessageText($chatId, $messageId, "🔄 Refreshing...");
            showAdminPanel($chatId);
            break;

        case 'check_membership':
            if (checkForceJoin($userId)) {
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

                editMessageText($chatId, $messageId, $welcomeMessage, ['reply_markup' => $keyboard]);
            } else {
                answerCallbackQuery($callbackQuery['id'], "❌ Please join all required channels first!");
            }
            break;
    }
}

// Initialize database
initDatabase();

// Handle webhook or run as web server
if (php_sapi_name() === 'cli') {
    // CLI mode - start built-in server
    echo "🤖 Starting PHP built-in server on 0.0.0.0:8000\n";
    echo "🔗 Bot Token: " . substr($BOT_TOKEN, 0, 10) . "...\n";
    echo "✅ Server starting successfully!\n";
    exec("php -S 0.0.0.0:8000 -t .");
} else {
    // Web mode - handle webhook
    handleWebhook();
}
?>
