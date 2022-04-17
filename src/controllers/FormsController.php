<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\controllers;

use Craft;
use craft\helpers\App;
use putyourlightson\campaign\base\BaseMessageController;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\helpers\RecaptchaHelper;

use putyourlightson\campaign\models\PendingContactModel;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FormsController extends BaseMessageController
{
    /**
     * @inheritdoc
     */
    protected int|bool|array $allowAnonymous = true;

    /**
     * Subscribes the provided email to a mailing list.
     */
    public function actionSubscribe(): ?Response
    {
        $this->requirePostRequest();
        $this->_validateRecaptcha();

        // Get mailing list by slug
        $mailingListSlug = $this->request->getRequiredParam('mailingList');
        $mailingList = Campaign::$plugin->mailingLists->getMailingListBySlug($mailingListSlug);

        if ($mailingList === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Mailing list not found.'));
        }

        $email = $this->request->getRequiredParam('email');
        $referrer = $this->request->getReferrer() ?: '';

        // Get contact if it exists
        $contact = Campaign::$plugin->contacts->getContactByEmail($email);

        if ($contact === null) {
            $contact = new ContactElement();
            $contact->email = $email;
        }

        // Disallow if blocked
        if ($contact->blocked !== null) {
            throw new MethodNotAllowedHttpException(Craft::t('campaign', 'This email address is blocked from subscribing.'));
        }

        // Set field values
        $contact->setFieldValuesFromRequest('fields');

        // If subscribe verification required
        if ($mailingList->getMailingListType()->subscribeVerificationRequired) {
            // Mock before save so we can validate the contact
            $contact->beforeSave(true);

            // Validate the contact
            if (!$contact->validate()) {
                return $this->asModelFailure($contact, '', 'contact', [
                    'errors' => $contact->getErrors(),
                ]);
            }

            // Create pending contact
            $pendingContact = new PendingContactModel();
            $pendingContact->email = $email;
            $pendingContact->mailingListId = $mailingList->id;
            $pendingContact->source = $referrer;
            $pendingContact->fieldData = $contact->getSerializedFieldValues();

            // Save pending contact
            if (!Campaign::$plugin->pendingContacts->savePendingContact($pendingContact)) {
                return $this->asModelFailure($pendingContact, '', 'pendingContact', [
                    'contact' => $contact,
                    'errors' => $pendingContact->getErrors(),
                ]);
            }

            // Send verification email
            Campaign::$plugin->forms->sendVerifySubscribeEmail($pendingContact, $mailingList);
        }
        else {
            // Save contact
            if (!Craft::$app->getElements()->saveElement($contact)) {
                return $this->asModelFailure($contact, '', 'contact', [
                    'errors' => $contact->getErrors(),
                ]);
            }

            Campaign::$plugin->forms->subscribeContact($contact, $mailingList, 'web', $referrer);
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess();
        }

        if ($this->request->getBodyParam('redirect')) {
            return $this->redirectToPostedUrl();
        }

        return $this->renderMessageTemplate($mailingList->getMailingListType()->subscribeSuccessTemplate, [
            'title' => $mailingList->mailingListType->subscribeVerificationRequired ? Craft::t('campaign', 'Subscribed') : Craft::t('campaign', 'Subscribe'),
            'message' => $mailingList->mailingListType->subscribeVerificationRequired ? Craft::t('campaign', 'Thank you for subscribing to the mailing list. Please check your email for a verification link.') : Craft::t('campaign', 'You have successfully subscribed to the mailing list.'),
            'mailingList' => $mailingList,
        ]);
    }

    /**
     * Unsubscribes the provided email from a mailing list.
     */
    public function actionUnsubscribe(): ?Response
    {
        $this->requirePostRequest();
        $this->_validateRecaptcha();

        // Get mailing list by slug
        $mailingListSlug = $this->request->getRequiredParam('mailingList');
        $mailingList = Campaign::$plugin->mailingLists->getMailingListBySlug($mailingListSlug);

        if ($mailingList === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Mailing list not found.'));
        }

        // Ensure unsubscribing through a form is allowed
        if (!$mailingList->getMailingListType()->unsubscribeFormAllowed) {
            throw new ForbiddenHttpException('Unsubscribing through a form is not allowed for this mailing list.');
        }

        $email = $this->request->getRequiredParam('email');

        // Get contact by email
        $contact = Campaign::$plugin->contacts->getContactByEmail($email);

        if ($contact === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'A contact with that email address could not be found.'));
        }

        // Send verification email
        Campaign::$plugin->forms->sendVerifyUnsubscribeEmail($contact, $mailingList);

        return $this->asModelSuccess($contact, '', 'contact');
    }

    /**
     * Updates a contact.
     */
    public function actionUpdateContact(): ?Response
    {
        $this->requirePostRequest();
        $this->_validateRecaptcha();

        // Get verified contact
        $contact = $this->_getVerifiedContact();

        if ($contact === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Contact not found.'));
        }

        // Set the field values using the fields location
        $fieldsLocation = $this->request->getParam('fieldsLocation', 'fields');
        $contact->setFieldValuesFromRequest($fieldsLocation);

        // Save it
        if (!Campaign::$plugin->forms->updateContact($contact)) {
            return $this->asModelFailure($contact, Craft::t('campaign', 'Couldn’t save contact.'), 'contact', [
                'errors' => $contact->getErrors(),
            ]);
        }

        return $this->asModelSuccess($contact, '', 'contact');
    }

    /**
     * Verifies and subscribes a pending contact to a mailing list.
     */
    public function actionVerifySubscribe(): Response
    {
        // Get pending contact ID
        $pid = $this->request->getParam('pid');

        if ($pid === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Invalid verification link.'));
        }

        // Verify pending contact
        $pendingContact = Campaign::$plugin->pendingContacts->verifyPendingContact($pid);

        if ($pendingContact === null) {
            if (!Campaign::$plugin->pendingContacts->getIsPendingContactTrashed($pid)) {
                throw new NotFoundHttpException(Craft::t('campaign', 'Verification link has expired'));
            }

            if ($this->request->getBodyParam('redirect')) {
                return $this->redirectToPostedUrl();
            }

            $mlid = $this->request->getParam('mlid');
            $mailingList = null;

            if ($mlid) {
                $mailingList = Campaign::$plugin->mailingLists->getMailingListById($mlid);
            }

            if ($mailingList) {
                return $this->renderMessageTemplate($mailingList->getMailingListType()->subscribeSuccessTemplate, [
                    'title' => Craft::t('campaign', 'Verified'),
                    'message' => Craft::t('campaign', 'You have successfully verified your email address and subscribed to the mailing list.'),
                    'mailingList' => $mailingList,
                    'contact' => null,
                ]);
            }
            else {
                return $this->renderMessageTemplate('', [
                    'title' => Craft::t('campaign', 'Verified'),
                    'message' => Craft::t('campaign', 'You have successfully verified your email address and subscribed to the mailing list.'),
                ]);
            }
        }

        // Get contact
        $contact = Campaign::$plugin->contacts->getContactByEmail($pendingContact->email);

        if ($contact === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Contact not found.'));
        }

        // Get mailing list
        $mailingList = Campaign::$plugin->mailingLists->getMailingListById($pendingContact->mailingListId);

        if ($mailingList === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Mailing list not found.'));
        }

        Campaign::$plugin->forms->subscribeContact($contact, $mailingList, 'web', $pendingContact->source, true);

        if ($this->request->getBodyParam('redirect')) {
            return $this->redirectToPostedUrl($contact);
        }

        return $this->renderMessageTemplate($mailingList->getMailingListType()->subscribeSuccessTemplate, [
            'title' => Craft::t('campaign', 'Verified'),
            'message' => Craft::t('campaign', 'You have successfully verified your email address and subscribed to the mailing list.'),
            'mailingList' => $mailingList,
            'contact' => $contact,
        ]);
    }

    /**
     * Verifies and unsubscribes the provided contact from a mailing list.
     */
    public function actionVerifyUnsubscribe(): ?Response
    {
        // Get verified contact
        $contact = $this->_getVerifiedContact();

        if ($contact === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Contact not found.'));
        }

        // Get mailing list by ID
        $mailingListId = $this->request->getRequiredParam('mlid');
        $mailingList = Campaign::$plugin->mailingLists->getMailingListById($mailingListId);

        if ($mailingList === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Mailing list not found.'));
        }

        Campaign::$plugin->forms->unsubscribeContact($contact, $mailingList);

        if ($this->request->getBodyParam('redirect')) {
            return $this->redirectToPostedUrl($contact);
        }

        return $this->renderMessageTemplate($mailingList->getMailingListType()->unsubscribeSuccessTemplate, [
            'title' => Craft::t('campaign', 'Unsubscribed'),
            'message' => Craft::t('campaign', 'You have successfully unsubscribed from the mailing list.'),
            'mailingList' => $mailingList,
        ]);
    }

    /**
     * Validates reCAPTCHA if enabled.
     */
    private function _validateRecaptcha(): void
    {
        // Validate reCAPTCHA if enabled
        if (Campaign::$plugin->settings->reCaptcha) {
            $response = $this->request->getParam('g-recaptcha-response');

            if ($response === null) {
                throw new ForbiddenHttpException(App::parseEnv(Campaign::$plugin->settings->reCaptchaErrorMessage));
            }

            RecaptchaHelper::validateRecaptcha($response, $this->request->getUserIP());
        }
    }

    /**
     * Gets contact by CID, verified by UID.
     */
    private function _getVerifiedContact(): ?ContactElement
    {
        $cid = $this->request->getParam('cid');
        $uid = $this->request->getParam('uid');

        if ($cid === null) {
            return null;
        }

        $contact = Campaign::$plugin->contacts->getContactByCid($cid);

        if ($contact === null) {
            return null;
        }

        // Verify UID
        if ($uid === null || $contact->uid !== $uid) {
            return null;
        }

        return $contact;
    }
}
