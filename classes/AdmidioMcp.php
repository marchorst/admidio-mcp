<?php

declare(strict_types=1);

namespace AdmidioMcp;

use Admidio\Infrastructure\Plugins\PluginAbstract;
use Admidio\UI\Presenter\PagePresenter;

final class AdmidioMcp extends PluginAbstract
{
    public static function doRender(?PagePresenter $page = null): bool
    {
        if ($page !== null) {
            $page->show();
        }

        return true;
    }
}
