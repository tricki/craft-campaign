<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\services;

use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\MailingListElement;
use putyourlightson\campaign\jobs\SyncJob;
use putyourlightson\campaign\records\ContactMailingListRecord;

use Craft;
use craft\base\Component;
use craft\behaviors\FieldLayoutBehavior;
use craft\elements\User;

/**
 * SyncService
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.2.0
 */
class SyncService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SyncEvent
     */
    const EVENT_BEFORE_SYNC = 'beforeSync';

    /**
     * @event SyncEvent
     */
    const EVENT_AFTER_SYNC = 'afterSync';

    // Public Methods
    // =========================================================================

    /**
     * Queues a sync
     *
     * @param MailingListElement $mailingList
     */
    public function queueSync(MailingListElement $mailingList)
    {
        // Add sync job to queue
        Craft::$app->getQueue()->push(new SyncJob(['mailingListId' => $mailingList->id]));
    }

    /**
     * Syncs a user
     *
     * @param User $user
     */
    public function syncUser(User $user)
    {
        $userGroups = $user->getGroups();

        foreach ($userGroups as $userGroup) {
            $mailingLists = MailingListElement::find()
                ->syncedUserGroupId($userGroup->id)
                ->all();

            foreach ($mailingLists as $mailingList) {
                $this->syncUserMailingList($user, $mailingList);
            }
        }
    }

    /**
     * Syncs a user to a mailing list
     *
     * @param User $user
     * @param MailingListElement $mailingList
     */
    public function syncUserMailingList(User $user, MailingListElement $mailingList)
    {
        // Get contact with same email as user
        $contact = ContactElement::find()
            ->email($user->email)
            ->one();

        if ($contact === null) {
            $contact = new ContactElement();
            $contact->email = $user->email;
        }

        // Set contact's field values from user's field values
        $contact->setFieldValues($user->getFieldValues());

        Craft::$app->getElements()->saveElement($contact);

        // Get contact mailing list record
        $contactMailingListRecord = ContactMailingListRecord::find()
            ->where([
                'contactId' => $contact->id,
                'mailingListId' => $mailingList->id,
            ])
            ->one();

        // If contact mailing list record does not exist then create it and subscribe
        if ($contactMailingListRecord === null) {
            $contactMailingListRecord = new ContactMailingListRecord();
            $contactMailingListRecord->contactId = $contact->id;
            $contactMailingListRecord->mailingListId = $mailingList->id;

            $contactMailingListRecord->subscriptionStatus = 'subscribed';
            $contactMailingListRecord->subscribed = new \DateTime();

            $contactMailingListRecord->save();
        }
    }
}