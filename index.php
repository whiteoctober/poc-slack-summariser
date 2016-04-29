<?php

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

// Getting channels
// -----------------

$gotChannel = function ($channel) use (&$allTheData) {
    /** @var \Slack\Channel $channel */
    if ($channel->getUnreadCount()) {
        $allTheData['channels'][$channel->getId()] = $channel;
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

echo "<p><b>Your public channels</b></p>";

/** @var \Slack\Channel $channel */
foreach ($allTheData['channels'] as $channel) {
    echo sprintf(
        '%s, (%d)<br/>',
        $channel->getName(),
        $channel->getUnreadCount()
    );
}

echo "<p><b>Your private channels and multi-person DMs</b></p>";

/** @var \Slack\Group $group */
foreach ($allTheData['groups'] as $group) {
    echo $group->getName() . '<br/>';
}

echo "<p><b>Your DMs</b></p>";

foreach ($allTheData['dms'] as $dmAndUser) {
    /** @var \Slack\DirectMessageChannel $dm */
    /** @var \Slack\User $user */
    $dm = $dmAndUser['dm'];
    $user = $dmAndUser['user'];

    echo $user->getUsername() . '<br/>';
}
