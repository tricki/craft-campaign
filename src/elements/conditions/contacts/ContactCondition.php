<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\elements\conditions\contacts;

use craft\elements\conditions\ElementCondition;

/**
 * @since 2.0.0
 */
class ContactCondition extends ElementCondition
{
    /**
     * @inerhitdoc
     */
    public bool $sortable = true;

    /**
     * @inheritdoc
     */
    protected function selectableConditionRules(): array
    {
        return array_merge(parent::selectableConditionRules(), [
            EmailConditionRule::class,
            LastActivityConditionRule::class,
            CampaignActivityConditionRule::class,
        ]);
    }
}
