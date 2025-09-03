<?php

namespace MauticPlugin\MauticPostmarkBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;

class MauticPostmarkBundle extends PluginBundleBase
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}

