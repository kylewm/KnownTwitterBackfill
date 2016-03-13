# KnownTwitterBackfill

Polls your Twitter stream, looking for new notes and replies that are not represented on your site. If found, it will attempt to copy them back.

This plugin adds an endpoint at `/twitter/backfill/cron`. POST to this URL periodically (e.g. call `curl -X POST ...` in a cron job) to invoke the poller.

This plugin is a PESOS experiment prompted by [Is POSSE passé?](https://groups.google.com/forum/#!topic/known-dev/_GGQpLHqdQI) and inspired by [OwnYourResponses](https://github.com/snarfed/ownyourresponses). I am using it on my site, but would not recommend it for general use just yet.

Current Limitations:
- Checks only the most recent 200 tweets. Does not backfill your historical tweets, although this would be a reasonable feature to add.
- Does not fetch likes.
- No special handling for photos. They are posted as a Status with a link to a twitter photo.
- No special handling for retweets. They are posted as a Status starting with "RT @-username"
