<?php

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/examples',
        __DIR__ . '/src',
        __DIR__ . '/tests/pest',
        __FILE__,
    ]);
    $ecsConfig->parallel();
    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
