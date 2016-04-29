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

// The callbacks we'll use
// =======================

// General
// -------

$failHandler = function ($data) {
    echo "Failed to get something!";
};

// Getting channels
// -----------------

$gotChannels = function ($channels) {
    echo "<p><b>Your channels</b></p>";
    /** @var \Slack\Channel $channel */
    foreach ($channels as $channel) {

        if ($channel->data['is_member'] && !$channel->isArchived()) {
            echo sprintf(
                '<p>%s</p>',
                $channel->getName()
            );
        }
    }
};

// Getting groups (private channels and multi-person DMs)
// -------------------------------------------------------

$gotGroups = function ($groups) {
    echo "<p><b>Your private groups</b></p>";
    /** @var \Slack\Group $group */
    foreach ($groups as $group) {
        if (!$group->isArchived()) {
            echo sprintf(
                '<p>%s</p>',
                $group->getName()
            );
        }
    }
};

// Getting IMs
// -----------

$gotDMs = function($dms) use (&$usersById) {
    echo '<p><b>Got DMs!</b></p>';
    /** @var \Slack\DirectMessageChannel $dm */
    foreach ($dms as $dm) {
        $user = $usersById[$dm->data['user']];
        if (!$user->isDeleted()) {
            echo sprintf('<p>%s</p>', $user->getUsername());
        }
    }
};

// Actually call the stuff
// ========================

$client->getUsers()->then(function ($users) use (
    $client, $gotChannels, $gotDMs, $gotGroups, $failHandler, &$usersById
) {

    /** @var \Slack\User $user */
    foreach ($users as $user) {
        $usersById[$user->getId()] = $user;
    }

    // build up an array of users, keyed on ID

    $client->getChannels()->then($gotChannels, $failHandler);
    $client->getDMs()->then($gotDMs, $failHandler);
    $client->getGroups()->then($gotGroups, $failHandler);

}, $failHandler);

// Don't forget to run the loop!
// ==============================

$loop->run();
