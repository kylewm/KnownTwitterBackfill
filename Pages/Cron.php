<?php

namespace IdnoPlugins\TwitterBackfill\Pages;

use Idno\Common\Page;
use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Entities\User;
use DateTime;

class Cron extends Page
{

    private $reverseLookup = [];

    function post()
    {
        set_time_limit(600); // 10 minutes should be enough

        $twitterPlugin = Idno::site()->plugins()->Twitter;

        if (!$twitterPlugin) {
            $this->setResponse(400);
            echo "Missing required Twitter plugin";
            return;
        }

        foreach (User::get() as $user) {
            if (is_array($user->twitter)) {
                Idno::site()->session()->logUserOn($user);
                foreach ($user->twitter as $username => $details) {
                    $api = $twitterPlugin->connect($username);
                    $this->backfill($user, $username, $api);
                }
            }
        }

        Idno::site()->session()->logUserOff();
    }

    private function backfill($user, $username, &$api)
    {
        $params = [
            'trim_user' => true,
            'count' => 200,

        ];
        if (!empty($user->twitter_backfill[$username]['last_id'])) {
            $params['since_id'] = $user->twitter_backfill[$username]['last_id'];
        }

        $code = $api->request('GET', $api->url('1.1/statuses/user_timeline'), $params);

        if ($code != 200) {
            Idno::site()->logging()->warning("TwitterBackfill: Failed to fetch user_timeline for $username");
            return;
        }

        $tweets = json_decode($api->response['response'], true);
        foreach ($tweets as $tweet) {
            $this->processTweet($user, $username, $tweet, $tweets);
        }

        if ($tweets) {
            $user->twitter_backfill[$username]['last_id'] = $tweets[0]['id_str'];
            $user->save();
        }
    }

    private function processTweet($user, $username, $tweet, $allTweets)
    {
        $id = $tweet['id_str'];
        $tweetUrl = "https://twitter.com/$username/status/$id";

        Idno::site()->logging()->debug("Processing tweet: $tweetUrl");

        $preexisting = $this->lookupSyndicationUrl($user, $tweetUrl);
        if ($preexisting) {
            Idno::site()->logging()->debug("An entity for this tweet already exists: $preexisting");
            return;
        }

        $reId = $tweet['in_reply_to_status_id_str'];
        $reScreenName = $tweet['in_reply_to_screen_name'];

        $created = self::parseDateTime($tweet['created_at']);
        $text = $tweet['text'];

        if (!empty($tweet['entities']['urls'])) {
            $urls = $tweet['entities']['urls'];
            // reverse order so we process the later urls first
            usort($urls, function ($a, $b) {
                $comp = $a['indices'][0] - $b['indices'][0];
                return -$comp;
            });

            foreach ($urls as $urlData) {
                $start = $urlData['indices'][0];
                $length = $urlData['indices'][1] - $start;
                $text = substr_replace($text, $urlData['expanded_url'], $start, $length);
            }
        }

        if ($reId) {
            $note = new \IdnoPlugins\Status\Reply();
            $note->inreplyto = "https://twitter.com/$reScreenName/status/$reId";
        } else {
            $note = new \IdnoPlugins\Status\Status();
        }

        $note->created = $created;
        $note->body = $text;
        $note->setPosseLink('twitter', $tweetUrl, '@'.$username, $id, $username);

        // uncomment to disable PuSH during backfill
        // $savedHub = Idno::site()->config()->hub;
        // Idno::site()->config()->hub = false;

        $note->publish(true);

        // uncomment to disable PuSH during backfill
        // Idno::site()->config()->hub = $savedHub;
        Idno::site()->logging()->debug("created new note: " . $note->getURL());
    }

    private function lookupSyndicationUrl($user, $url)
    {
        if (isset($this->reverseLookup[$user->getUUID()])) {
            $lookup = &$this->reverseLookup[$user->getUUID()];
        } else {
            $lookup = [];

            Idno::site()->logging()->debug(time() . " building reverse lookup table for " . $user->getUUID());

            $limit = 200; // 200 at a time
            for ($offset = 0 ; $offset < 2000 ; $offset += $limit) {
                $entities = Entity::getFromAll(array(['owner' => $user->getUUID()]), array(), $limit, $offset);
                Idno::site()->logging()->debug(time() . " fetched ".count($entities)." starting at $offset");

                if (!$entities) {
                    break;
                }

                foreach ($entities as $entity) {
                    if ($entity) {
                        foreach ($entity->getPosseLinks() as $service => $posseLinks) {
                            if (is_string($posseLinks)) {
                                $lookup[$posseLinks] = $entity->getUUID();
                            } else {
                                foreach ($posseLinks as $posseLink) {
                                    $lookup[$posseLink['url']] = $entity->getUUID();
                                }
                            }
                        }
                    }
                }
            }

            $this->reverseLookup[$user->getUUID()] = &$lookup;
        }

        return isset($lookup[$url]) ? $lookup[$url] : false;
    }

    private static function parseDateTime($str) {
        $dt = DateTime::createFromFormat('D M d H:i:s O Y', $str);
        return $dt->getTimestamp();
    }

}
