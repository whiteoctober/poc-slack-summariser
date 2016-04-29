<?php

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

/** @var \Slack\User[] $usersById */
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

$gotMessagesFunctionMaker = function($type, $channelOrWhatever) use (&$allTheData, &$usersById) {
    return function ($payload) use (&$allTheData, &$usersById, $type, $channelOrWhatever) {
        /** @var Payload $payload */
        $data = $payload->getData();

        $unread = (int)$data['unread_count_display'];
        $messages = $data['messages'];

        if ($unread > 0) {
            $message = $messages[$unread - 1]; // -1 as arrays are 0-indexed
            $message['user'] = $usersById[$message['user']];
            $allTheData[$type][$channelOrWhatever->getId()]['channel'] = $channelOrWhatever;
            $allTheData[$type][$channelOrWhatever->getId()]['message'] = $message;
        }
    };
};

// Getting channels
// -----------------

$gotChannel = function ($channel) use (&$allTheData, &$usersById, $client, $failHandler, $gotMessagesFunctionMaker) {
    // $channel->getUnreadCount() seems slightly unreliable with some false positives,
    // but we check against the unread again in the callback from channels.history where it's more accurate
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

$gotGroups = function ($groups) use (&$allTheData) {
    /** @var \Slack\Group $group */
    foreach ($groups as $group) {
        if (!$group->isArchived()) {
            $allTheData['groups'][] = $group;
        }
    }
};

// Getting IMs
// -----------

$gotDMs = function($dms) use (&$usersById, &$allTheData) {
    /** @var \Slack\DirectMessageChannel $dm */
    foreach ($dms as $dm) {
        $user = $usersById[$dm->data['user']];
        if (!$user->isDeleted()) {
            $allTheData['dms'][] = [
                'dm' => $dm,
                'user' => $user,
            ];
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

echo "<h1>Your public channels</h1>";

foreach ($allTheData['channels'] as $channelArray) {
    /** @var \Slack\Channel $channel */
    $channel = $channelArray['channel'];
    echo sprintf(
        '<b>%s</b> (%d)<br/>',
        $channel->getName(),
        $channel->getUnreadCount()
    );

    $message = $channelArray['message'];
    echo (sprintf(
        '<blockquote><i>%s</i> %s: %s</blockquote>',
        date('j M Y G:i', $message['ts']),
        $message['user']->getUsername(),
        $message['text']
    ));
}

echo "<h1>Your private channels and multi-person DMs</h1>";

/** @var \Slack\Group $group */
foreach ($allTheData['groups'] as $group) {
    echo $group->getName() . '<br/>';
}

echo "<h1>Your DMs</h1>";

foreach ($allTheData['dms'] as $dmAndUser) {
    /** @var \Slack\DirectMessageChannel $dm */
    /** @var \Slack\User $user */
    $dm = $dmAndUser['dm'];
    $user = $dmAndUser['user'];

    echo $user->getUsername() . '<br/>';
}
