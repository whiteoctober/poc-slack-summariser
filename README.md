# Slack Summariser

## Introduction

This project is a proof-of-concept created at the 2016 White October Hack Day.

It provides you with a one-page overview showing you the oldest unread message in each of your channels and DMs.

## Improvements

As this is only a proof-of-concept, there are lots of areas that need improving.

The most important is to [do authentication properly using OAuth](https://api.slack.com/docs/oauth), rather than providing the token directly, which is very insecure.

Following on from that, it might be nice to improve the look and feel, and probably use a templating engine rather than writing the HTML directly.
