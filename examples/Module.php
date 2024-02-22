<?php

namespace modules;

use Craft;
use craft\base\conditions\BaseCondition;
use craft\events\RegisterConditionRuleTypesEvent;
use modules\conditions\sendouts\LastEntryHasImageConditionRule;
use modules\conditions\sendouts\MondayMorningSendoutConditionRule;
use modules\conditions\sendouts\RecentEntriesPublishedConditionRule;
use putyourlightson\campaign\elements\conditions\sendouts\SendoutScheduleCondition;
use yii\base\Event;

class Module extends \yii\base\Module
{
    public function init(): void
    {
        Craft::setAlias('@modules', __DIR__);

        parent::init();

        Event::on(
            SendoutScheduleCondition::class,
            BaseCondition::EVENT_REGISTER_CONDITION_RULE_TYPES,
            function(RegisterConditionRuleTypesEvent $event) {
                $event->conditionRuleTypes[] = LastEntryHasImageConditionRule::class;
                $event->conditionRuleTypes[] = MondayMorningSendoutConditionRule::class;
                $event->conditionRuleTypes[] = RecentEntriesPublishedConditionRule::class;
            }
        );
    }
}
