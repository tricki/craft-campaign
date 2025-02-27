<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\utilities;

use Craft;
use craft\base\Utility;
use putyourlightson\campaign\Campaign;

class CampaignUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('campaign', 'Campaign');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'campaign';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@putyourlightson/campaign/icon-mask.svg');
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('campaign/_utility', [
            'settings' => Campaign::$plugin->settings,
        ]);
    }
}
