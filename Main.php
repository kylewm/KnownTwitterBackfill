<?php

namespace IdnoPlugins\TwitterBackfill;

use Idno\Common\Plugin;
use Idno\Core\Idno;

class Main extends Plugin
{

    function registerPages()
    {
        Idno::site()->addPageHandler('twitter/backfill/cron', '\IdnoPlugins\TwitterBackfill\Pages\Cron');
    }

}