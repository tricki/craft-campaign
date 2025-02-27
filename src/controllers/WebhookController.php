<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\controllers;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use craft\web\Controller;
use EllipticCurve\Ecdsa;
use EllipticCurve\PublicKey;
use EllipticCurve\Signature;
use GuzzleHttp\Exception\ConnectException;
use putyourlightson\campaign\Campaign;
use yii\base\ErrorException;
use yii\log\Logger;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class WebhookController extends Controller
{
    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    /**
     * @var bool Disable Snaptcha validation
     */
    public bool $enableSnaptchaValidation = false;

    /**
     * @inheritdoc
     */
    protected int|bool|array $allowAnonymous = [
        'test',
        'amazon-ses',
        'mailersend',
        'mailgun',
        'mandrill',
        'postmark',
        'sendgrid',
    ];

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Verify API key
        $key = $this->request->getParam('key');
        $apiKey = App::parseEnv(Campaign::$plugin->settings->apiKey);

        if ($key === null || empty($apiKey) || $key != $apiKey) {
            throw new ForbiddenHttpException('Unauthorised access.');
        }

        return parent::beforeAction($action);
    }

    /**
     * Test webhook.
     */
    public function actionTest(): ?Response
    {
        return $this->asSuccess('Test success.');
    }

    /**
     * Amazon SES
     * https://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-examples.html
     */
    public function actionAmazonSes(): ?Response
    {
        $this->requirePostRequest();

        // Instantiate the Message and Validator
        $message = Message::fromRawPostData();

        $validator = new MessageValidator();

        // Validate the message
        try {
            $validator->validate($message);
        } catch (InvalidSnsMessageException) {
            return $this->asRawFailure('SNS message validation error.');
        }

        // Check the type of the message and handle the subscription.
        if ($message['Type'] === 'SubscriptionConfirmation') {
            // Confirm the subscription by sending a GET request to the SubscribeURL
            $client = Craft::createGuzzleClient([
                'timeout' => 5,
                'connect_timeout' => 5,
            ]);

            try {
                $client->get($message['SubscribeURL']);
            } catch (ConnectException) {
            }
        }

        if ($message['Type'] === 'Notification') {
            $body = Json::decodeIfJson($message['Message']);
            $eventType = $body['notificationType'] ?? null;

            if ($eventType == 'Complaint') {
                $email = $body['complaint']['complainedRecipients'][0]['emailAddress'];
                return $this->callWebhook('complained', $email);
            }
            if ($eventType == 'Bounce' && $body['bounce']['bounceType'] == 'Permanent') {
                $email = $body['bounce']['bouncedRecipients'][0]['emailAddress'];
                return $this->callWebhook('bounced', $email);
            }

            return $this->asRawFailure('Event `' . ($eventType ?? '') . '` not found.');
        }

        return $this->asRawFailure('No event provided.');
    }

    /**
     * MailerSend
     * https://developers.mailersend.com/api/v1/webhooks.html
     *
     * @since 2.10.0
     */
    public function actionMailersend(): Response|string
    {
        $this->requirePostRequest();

        $body = $this->request->getRawBody();

        if (!$this->isValidMailersendRequest($body)) {
            return $this->asRawFailure('Signature could not be authenticated.');
        }

        $events = Json::decodeIfJson($body);
        $eventType = $events['type'] ?? null;
        $email = $events['data']['email']['recipient']['email'] ?? '';

        // Check if this is a test webhook request
        $from = $events['data']['email']['from'] ?? '';
        if ($from == 'test@example.com') {
            return $this->asRawSuccess('Test success.');
        }

        if ($eventType == 'activity.spam_complaint') {
            return $this->callWebhook('complained', $email);
        }

        if ($eventType == 'activity.hard_bounced') {
            return $this->callWebhook('bounced', $email);
        }

        if ($eventType) {
            return $this->asRawFailure('Event type `' . $eventType . '` not found.');
        }

        return $this->asRawFailure('No event provided.');
    }

    /**
     * Mailgun
     */
    public function actionMailgun(): ?Response
    {
        $this->requirePostRequest();

        // Get event data from raw body
        $body = Json::decodeIfJson($this->request->getRawBody());
        $signatureGroup = $body['signature'] ?? null;
        $eventData = $body['event-data'] ?? null;

        $signature = $signatureGroup['signature'] ?? '';
        $timestamp = $signatureGroup['timestamp'] ?? '';
        $token = $signatureGroup['token'] ?? '';
        $event = $eventData['event'] ?? '';
        $severity = $eventData['severity'] ?? '';
        $reason = $eventData['reason'] ?? '';
        $email = $eventData['recipient'] ?? '';

        // Support legacy Mailgun webhooks.
        if ($eventData === null) {
            $signature = $this->request->getBodyParam('signature', '');
            $timestamp = $this->request->getBodyParam('timestamp', '');
            $token = $this->request->getBodyParam('token', '');
            $event = $this->request->getBodyParam('event', '');
            $email = $this->request->getBodyParam('recipient', '');
        }

        if (!$this->isValidMailgunRequest($signature, $timestamp, $token)) {
            return $this->asRawFailure('Signature could not be authenticated.');
        }

        // Check if this is a test webhook request
        if ($email == 'alice@example.com') {
            return $this->asRawSuccess('Test success.');
        }

        if ($event == 'complained') {
            return $this->callWebhook('complained', $email);
        }

        // Only mark as bounced if the reason indicates that it is a hard bounce.
        // https://github.com/putyourlightson/craft-campaign/issues/178
        if ($event == 'failed' && $severity == 'permanent'
            && ($reason == 'bounce' || $reason == 'suppress-bounce')
        ) {
            return $this->callWebhook('bounced', $email);
        }

        // Support legacy Mailgun webhook event.
        if ($event == 'bounced') {
            return $this->callWebhook('bounced', $email);
        }

        if ($event) {
            return $this->asRawFailure('Event `' . $event . '` not found.');
        }

        return $this->asRawFailure('No event provided.');
    }

    /**
     * Mandrill
     */
    public function actionMandrill(): ?Response
    {
        $this->requirePostRequest();

        $events = $this->request->getBodyParam('mandrill_events');
        $events = Json::decodeIfJson($events);

        if (is_array($events)) {
            foreach ($events as $event) {
                $eventType = $event['event'] ?? '';
                $email = $event['msg']['email'] ?? '';

                if ($eventType == 'spam') {
                    return $this->callWebhook('complained', $email);
                }
                if ($eventType == 'hard_bounce') {
                    return $this->callWebhook('bounced', $email);
                }
            }

            $eventTypes = array_filter(array_map(fn($event) => $event['event'] ?? null, $events));

            return $this->asRawFailure('Event `' . implode(', ', $eventTypes) . '` not found.');
        }

        return $this->asRawFailure('No event provided.');
    }

    /**
     * Postmark
     */
    public function actionPostmark(): ?Response
    {
        $this->requirePostRequest();

        // Ensure IP address is coming from Postmark if allowed IP addresses are set
        // https://postmarkapp.com/support/article/800-ips-for-firewalls#webhooks
        $allowedIpAddresses = Campaign::$plugin->settings->postmarkAllowedIpAddresses;

        if ($allowedIpAddresses && !in_array($this->request->getRemoteIP(), $allowedIpAddresses)) {
            return $this->asRawFailure('IP address not allowed.');
        }

        $eventType = $this->request->getBodyParam('RecordType');
        $email = $this->request->getBodyParam('Email') ?: $this->request->getBodyParam('Recipient');

        // https://postmarkapp.com/developer/webhooks/spam-complaint-webhook
        if ($eventType == 'SpamComplaint') {
            return $this->callWebhook('complained', $email);
        } // https://postmarkapp.com/developer/webhooks/bounce-webhook
        elseif ($eventType == 'Bounce') {
            $bounceType = $this->request->getBodyParam('Type');

            if ($bounceType == 'HardBounce') {
                return $this->callWebhook('bounced', $email);
            }
        } // https://postmarkapp.com/developer/webhooks/subscription-change-webhook
        elseif ($eventType == 'SubscriptionChange') {
            $suppress = $this->request->getBodyParam('SuppressSending');

            if ($suppress) {
                $reason = $this->request->getBodyParam('SuppressionReason');

                if ($reason == 'SpamComplaint') {
                    return $this->callWebhook('complained', $email);
                } elseif ($reason == 'HardBounce') {
                    return $this->callWebhook('bounced', $email);
                } else {
                    return $this->callWebhook('unsubscribed', $email);
                }
            }
        }

        if ($eventType) {
            return $this->asRawFailure('Event `' . $eventType . '` not found.');
        }

        return $this->asRawFailure('No event provided.');
    }

    /**
     * SendGrid
     */
    public function actionSendgrid(): ?Response
    {
        $this->requirePostRequest();

        $body = $this->request->getRawBody();
        $events = Json::decodeIfJson($body);

        if (!$this->isValidSendgridRequest($body)) {
            return $this->asRawFailure('Signature could not be authenticated.');
        }

        if (is_array($events)) {
            foreach ($events as $event) {
                $eventType = $event['event'] ?? '';
                $email = $event['email'] ?? '';

                // Check if this is a test webhook request
                if ($email == 'example@test.com') {
                    return $this->asRawSuccess('Test success.');
                }

                // https://docs.sendgrid.com/for-developers/tracking-events/event#engagement-events
                if ($eventType == 'spamreport') {
                    return $this->callWebhook('complained', $email);
                }

                // https://docs.sendgrid.com/for-developers/tracking-events/event#delivery-events
                if ($eventType == 'bounce') {
                    return $this->callWebhook('bounced', $email);
                }
            }

            $eventTypes = array_filter(array_map(fn($event) => $event['event'] ?? null, $events));

            return $this->asRawFailure('Event `' . implode(', ', $eventTypes) . '` not found.');
        }

        return $this->asRawFailure('No event provided.');
    }

    /**
     * Calls a webhook.
     */
    private function callWebhook(string $event, string $email = null): Response
    {
        Campaign::$plugin->log('Webhook request: ' . $this->request->getRawBody(), [], Logger::LEVEL_WARNING);

        if ($email === null) {
            return $this->asRawFailure('No email provided.');
        }

        $contact = Campaign::$plugin->contacts->getContactByEmail($email);

        if ($contact === null) {
            return $this->asRawSuccess();
        }

        if ($event == 'complained') {
            Campaign::$plugin->webhook->complain($contact);
        } elseif ($event == 'bounced') {
            Campaign::$plugin->webhook->bounce($contact);
        } elseif ($event == 'unsubscribed') {
            Campaign::$plugin->webhook->unsubscribe($contact);
        }

        return $this->asRawSuccess();
    }

    /**
     * Returns a raw response success.
     */
    private function asRawSuccess(string $message = ''): Response
    {
        return $this->asRaw(Craft::t('campaign', $message));
    }

    /**
     * Returns a raw response failure.
     */
    private function asRawFailure(string $message = ''): Response
    {
        Campaign::$plugin->log($message, [], Logger::LEVEL_WARNING);

        return $this->asRaw(Craft::t('campaign', $message))
            ->setStatusCode(400);
    }

    /**
     * @link https://developers.mailersend.com/api/v1/webhooks.html#security
     */
    private function isValidMailersendRequest(string $body): bool
    {
        if (!Campaign::$plugin->settings->validateWebhookRequests) {
            return true;
        }

        $signingSecret = (string)App::parseEnv(Campaign::$plugin->settings->mailersendWebhookSigningSecret);
        $signature = $this->request->headers->get('Signature', '');
        $hashedValue = hash_hmac('sha256', $body, $signingSecret);

        return hash_equals($signature, $hashedValue);
    }

    /**
     * @link https://documentation.mailgun.com/en/latest/user_manual.html#webhooks
     */
    private function isValidMailgunRequest(string $signature, string $timestamp, string $token): bool
    {
        if (!Campaign::$plugin->settings->validateWebhookRequests) {
            return true;
        }

        $signingKey = (string)App::parseEnv(Campaign::$plugin->settings->mailgunWebhookSigningKey);
        $hashedValue = hash_hmac('sha256', $timestamp . $token, $signingKey);

        return hash_equals($signature, $hashedValue);
    }

    /**
     * @link https://docs.sendgrid.com/for-developers/tracking-events/getting-started-event-webhook-security-features
     */
    private function isValidSendgridRequest(string $body): bool
    {
        if (!Campaign::$plugin->settings->validateWebhookRequests) {
            return true;
        }

        $signature = $this->request->headers->get('X-Twilio-Email-Event-Webhook-Signature', '');
        $timestamp = $this->request->headers->get('X-Twilio-Email-Event-Webhook-Timestamp', '');
        $verificationKey = (string)App::parseEnv(Campaign::$plugin->settings->sendgridWebhookVerificationKey);

        // https://github.com/sendgrid/sendgrid-php/blob/9335dca98bc64456a72db73469d1dd67db72f6ea/lib/eventwebhook/EventWebhook.php#L23-L26
        $publicKey = PublicKey::fromString($verificationKey);

        // https://github.com/sendgrid/sendgrid-php/blob/9335dca98bc64456a72db73469d1dd67db72f6ea/lib/eventwebhook/EventWebhook.php#L39-L46
        $timestampedBody = $timestamp . $body;
        $decodedSignature = Signature::fromBase64($signature);

        try {
            return Ecdsa::verify($timestampedBody, $decodedSignature, $publicKey);
        } catch (ErrorException) {
            return false;
        }
    }
}
