<?php

// http://stackoverflow.com/a/17539561/328817
header("content-type: text/html; charset=UTF-8");

use Slack\ClientObject;
use Slack\User;
use Slack\Payload;

require __DIR__ . '/vendor/autoload.php';

// Die if no token
// ================

if (!array_key_exists('token', $_GET)) {
    echo '<p>Go and get a token from <a href="https://api.slack.com/docs/oauth-test-tokens">https://api.slack.com/docs/oauth-test-tokens</a>!</p>';
    die();
}

// Setup
// ======

$loop = \React\EventLoop\Factory::create();

$client = new \Slack\ApiClient($loop);
$client->setToken($_GET['token']);

/** @var User[] $usersById */
$usersById = [];

$allTheData = [
    'channels' => [],
    'groups' => [],
    'dms' => [],
];

// The callbacks we'll use
// =======================

// General
// -------

$failHandler = function ($data) {
    echo "<p>Failed to get something!</p>";
    var_dump($data);
};

// Getting messages
// -----------------

/**
 * @param string $type
 * @param ClientObject $channelOrWhatever
 * @param null|\Slack\User $dmUser -- if $type == 'dms', pass in the user who the DM channel is for
 *
 * @return Closure
 */
$gotMessagesFunctionMaker = function($type, $channelOrWhatever, $dmUser = null) use (&$allTheData, &$usersById) {
    return function ($payload) use (&$allTheData, &$usersById, $type, $channelOrWhatever, $dmUser) {
        /** @var Payload $payload */
        $data = $payload->getData();

        $unread = $channelOrWhatever->data['unread_count'];
        $messages = $data['messages'];

        if ($unread > 0) {
            $message = $messages[$unread - 1]; // -1 as arrays are 0-indexed
            // Messages from bots have a username key set with their username in.
            // Messages from users don't, but do have the user ID (not username) stored under the `user` key.
            if (!array_key_exists('username', $message)) {
                $message['username'] = $usersById[$message['user']]->getUsername();
            }
            $allTheData[$type][$channelOrWhatever->getId()]['channel'] = $channelOrWhatever;
            $allTheData[$type][$channelOrWhatever->getId()]['message'] = $message;

            if ($dmUser) {
                $allTheData[$type][$channelOrWhatever->getId()]['dmUser'] = $dmUser;
            }
        }
    };
};

// Getting channels
// -----------------

$gotChannel = function ($channel) use (&$allTheData, &$usersById, $client, $failHandler, $gotMessagesFunctionMaker) {
    /** @var \Slack\Channel $channel */
    if ($channel->getUnreadCount()) {
        $gotMessages = $gotMessagesFunctionMaker('channels', $channel);

        $client->apiCall('channels.history', [
            'channel' => $channel->getId(),
            'unreads' => 1
        ])->then($gotMessages, $failHandler);
    }
};

$gotChannels = function ($channels) use (&$allTheData, $client, $gotChannel, $failHandler) {
    /** @var \Slack\Channel $channel */
    foreach ($channels as $channel) {

        if ($channel->data['is_member'] && !$channel->isArchived()) {
            $client->getChannelById($channel->getId())->then($gotChannel, $failHandler);
        }
    }
};

// Getting groups (private channels and multi-person DMs)
// -------------------------------------------------------

$gotGroup = function ($group) use ($client, $gotMessagesFunctionMaker, $failHandler) {
    /** @var \Slack\Group $group */
    if ($group->getUnreadCount()) {
        $gotMessages = $gotMessagesFunctionMaker('groups', $group);

        $client->apiCall('groups.history', [
            'channel' => $group->getId(),
            'unreads' => 1
        ])->then($gotMessages, $failHandler);
    }
};

$gotGroups = function ($groups) use ($client, $gotGroup, $failHandler) {
    /** @var \Slack\Group $group */
    foreach ($groups as $group) {
        if (!$group->isArchived()) {
            $client->getGroupById($group->getId())->then($gotGroup, $failHandler);
        }
    }
};

// Getting IMs
// -----------

$gotDM = function ($dm) use (&$usersById, $client, $gotMessagesFunctionMaker, $failHandler) {
    /** @var \Slack\DirectMessageChannel $dm */
    $user = $usersById[$dm->data['user']];
    $gotMessages = $gotMessagesFunctionMaker('dms', $dm, $user);

    $client->apiCall('im.history', [
        'channel' => $dm->getId(),
        'unreads' => 1
    ])->then($gotMessages, $failHandler);
};

$gotDMs = function($dms) use (&$usersById, &$allTheData, $client, $gotDM, $failHandler) {
    /** @var \Slack\DirectMessageChannel $dm */
    foreach ($dms as $dm) {
        $user = $usersById[$dm->data['user']];
        if (!$user->isDeleted()) {
            $client->getDMById($dm->getId())->then($gotDM, $failHandler);
        }
    }
};

// Actually call the stuff
// ========================

$client->getUsers()->then(function ($users) use (
    $client, $gotChannels, $gotDMs, $gotGroups, $failHandler, &$usersById
) {

    // build up an array of users, keyed on ID
    /** @var \Slack\User $user */
    foreach ($users as $user) {
        $usersById[$user->getId()] = $user;
    }

    // call stuff that relies on this (these will in turn make further calls)

    $client->getChannels()->then($gotChannels, $failHandler);
    $client->getDMs()->then($gotDMs, $failHandler);
    $client->getGroups()->then($gotGroups, $failHandler);

}, $failHandler);

// Run the loop and parse the data to write out the output
// =======================================================

$loop->run();

function formatUserMention($username) {
    return "<span class=\"user-mention\">@{$username}</span>";
}

function formatMessage($message, $usersById)
{
    // Sort out user mentions

    $message = str_replace('<!channel>', formatUserMention('channel'), $message);

    $message = preg_replace_callback('/<@([^>]+)>/', function ($string) use (&$usersById) {
        /** @var User[] $usersById */
        $username = $string[1];

        if (array_key_exists($username, $usersById)) {
            $user = $usersById[$username];

            return formatUserMention($user->getUsername());
        }

        return $string[0];
    }, $message);

    return $message;
}

function printMessage(array $message, $usersById)
{
    echo (sprintf(
        '<blockquote><p class="msg-header"><span class="username">%s</span> <span class="msg-date">%s</span></p><p>%s</p></blockquote>',
        $message['username'],
        date('j M Y G:i', $message['ts']),
        formatMessage($message['text'], $usersById)
    ));
}

function printChannelName($name, $extraStuff = '')
{
    echo sprintf('<p class="channel-name"><span class="channel-hash">#</span> <b>%s</b>%s</p>', $name, $extraStuff);
}

echo <<<EOD
<!doctype html>
<html>
    <head>
        <link rel="stylesheet" type="text/css" href="style.css">
        <meta charset="UTF-8">
        <title>Slack Headlines</title>
    </head>

    <body>
EOD;


echo "<h1>Your public channels</h1>";

foreach ($allTheData['channels'] as $channelArray) {
    /** @var \Slack\Channel $channel */
    $channel = $channelArray['channel'];

    printChannelName($channel->getName(), sprintf(
        ' (%d)',
        $channel->getUnreadCount()
    ));
    printMessage($channelArray['message'], $usersById);
}

echo "<h1 class=\"later-header\">Your private channels and multi-person DMs</h1>";

foreach ($allTheData['groups'] as $groupArray) {
    /** @var \Slack\Group $group */
    $group = $groupArray['channel'];

    printChannelName($group->getName());
    printMessage($groupArray['message'], $usersById);
}

echo "<h1 class=\"later-header\">Your DMs</h1>";

foreach ($allTheData['dms'] as $dmArray) {

    /** @var \Slack\DirectMessageChannel $dm */
    $dm = $dmArray['channel'];

    /** @var User $user */
    $user = $dmArray['dmUser'];

    printChannelName($user->getUsername());
    printMessage($dmArray['message'], $usersById);
}

echo <<<EOD
    </body>
</html>
EOD;

