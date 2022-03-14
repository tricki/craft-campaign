<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\controllers;

use Craft;
use craft\base\Element;
use craft\controllers\CategoriesController;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use DateTime;
use putyourlightson\campaign\assets\CampaignEditAsset;
use putyourlightson\campaign\assets\ReportsAsset;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\CampaignElement;
use putyourlightson\campaign\records\ContactCampaignRecord;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class CampaignsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Require permission
        $this->requirePermission('campaign:campaigns');

        return parent::beforeAction($action);
    }

    /**
     * Creates a new unpublished draft and redirects to its edit page.
     *
     * @see CategoriesController::actionCreate()
     * @since 2.0.0
     */
    public function actionCreate(string $campaignTypeHandle): Response
    {
        $campaignType = Campaign::$plugin->campaignTypes->getCampaignTypeByHandle($campaignTypeHandle);

        if (!$campaignType) {
            throw new BadRequestHttpException("Invalid campaign type handle: $campaignTypeHandle");
        }

        $site = Cp::requestedSite();

        if (!$site) {
            throw new ForbiddenHttpException('User not authorized to edit content in any sites.');
        }

        $user = Craft::$app->getUser()->getIdentity();

        $campaign = Craft::createObject(CampaignElement::class);
        $campaign->siteId = $site->id;
        $campaign->campaignTypeId = $campaignType->id;

        if (!$campaign->canSave($user)) {
            throw new ForbiddenHttpException('User not authorized to save this campaign.');
        }

        $campaign->title = $this->request->getQueryParam('title');
        $campaign->slug = $this->request->getQueryParam('slug');
        if ($campaign->title && !$campaign->slug) {
            $campaign->slug = ElementHelper::generateSlug($campaign->title, null, $site->language);
        }
        if (!$campaign->slug) {
            $campaign->slug = ElementHelper::tempSlug();
        }

        $campaign->setScenario(Element::SCENARIO_ESSENTIALS);
        if (!Craft::$app->getDrafts()->saveElementAsDraft($campaign, Craft::$app->getUser()->getId(), null, null, false)) {
            throw new ServerErrorHttpException(sprintf('Unable to save campaign as a draft: %s', implode(', ', $campaign->getErrorSummary(true))));
        }

        return $this->redirect($campaign->getCpEditUrl());
    }

    /**
     * Main edit page.
     */
    public function actionEdit(int $campaignId = null): Response
    {
        $this->view->registerAssetBundle(CampaignEditAsset::class);
        $this->view->registerAssetBundle(ReportsAsset::class);

        // Set the selected subnav item by adding it to the global variables
        Craft::$app->view->getTwig()->addGlobal('selectedSubnavItem', 'campaigns');

        /** @var Response|CpScreenResponseBehavior $response */
        $response = Craft::$app->runAction('elements/edit', [
            'elementId' => $campaignId,
        ]);

        // Add actions
        $campaign = Campaign::$plugin->campaigns->getCampaignById($campaignId);

        if ($campaign === null) {
            return $response;
        }

        if ($campaign->getStatus() == CampaignElement::STATUS_SENT) {
            $response->addAltAction(
                Craft::t('campaign', 'Close this campaign'),
                [
                    'action' => 'campaign/campaigns/close',
                    'confirm' => Craft::t('campaign', 'Are you sure you want to close this campaign? This will remove all contact activity related to this campaign. This action cannot be undone.'),
                ],
            );
        }

        return $response;
    }

    /**
     * Sends a test
     */
    public function actionSendTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $campaignId = $this->request->getRequiredBodyParam('campaignId');
        $campaign = Campaign::$plugin->campaigns->getCampaignById($campaignId);

        if (!$campaign) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Campaign not found.'));
        }

        $contactIds = $this->request->getBodyParam('contactIds');

        if (empty($contactIds)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('campaign', 'At least one contact must be submitted.')]);
        }

        $contacts = Campaign::$plugin->contacts->getContactsByIds($contactIds);

        foreach ($contacts as $contact) {
            if (!Campaign::$plugin->campaigns->sendTest($campaign, $contact)) {
                return $this->asJson(['success' => false, 'error' => Craft::t('campaign', 'Couldn’t send test email.')]);
            }
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Closes a campaign.
     */
    public function actionClose(): ?Response
    {
        $this->requirePostRequest();

        $campaignId = $this->request->getRequiredBodyParam('campaignId');
        $campaign = Campaign::$plugin->campaigns->getCampaignById($campaignId);

        if (!$campaign) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Campaign not found.'));
        }

        // Set closed date to now
        $campaign->dateClosed = new DateTime();

        if (!Craft::$app->getElements()->saveElement($campaign)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'errors' => $campaign->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t close campaign.'));

            // Send the campaign back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'campaign' => $campaign,
            ]);

            return null;
        }

        // Delete all contact activity for this campaign
        ContactCampaignRecord::deleteAll(['campaignId' => $campaign->id]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Campaign closed.'));

        return $this->redirectToPostedUrl($campaign);
    }
}
