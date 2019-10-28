<?php
/**
 * Created by Bruno DA SILVA, working for Combodo
 * Date: 22/07/19
 * Time: 16:54
 */

namespace Combodo\StripeV3\Action;


use Combodo\StripeV3\Exception\TokenNotFound;
use Combodo\StripeV3\Keys;
use Combodo\StripeV3\Model\StripePaymentDetails;
use Combodo\StripeV3\Request\handleCheckoutCompletedEvent;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetBinaryStatus;
use Payum\Core\Request\GetToken;
use Payum\Core\Request\Notify;
use Payum\Core\Security\TokenInterface;
use Stripe\Event;

class CheckoutCompletedEventAction implements ActionInterface, GatewayAwareInterface, CheckoutCompletedInformationProvider
{
    use GatewayAwareTrait;

    /** @var GetBinaryStatus $status */
    private $status;
    /** @var TokenInterface $token  */
    private $token;

    public function __construct()
    {
        $this->status             = null;
        $this->token              = null;
    }


    /**
     * {@inheritDoc}
     *
     * @param handleCheckoutCompletedEvent $request
     */
    public function execute($request)
    {

        RequestNotSupportedException::assertSupports($this, $request);

        $event = $request->getEvent();
        $this->handleEvent($event);

    }

    /**
     * @param Event $event
     */
    private function handleEvent(Event $event)
    {
        $checkoutSession    = $event->data->object;
        $tokenHash          = $checkoutSession->client_reference_id;

        $this->token = $this->findTokenByHash($tokenHash);
        $this->status = $this->findStatusByToken($this->token);

        $this->status->markCaptured();

        $this->completePaymentDetails($this->status->getFirstModel(), $checkoutSession);
    }

    /**
     * @param $tokenHash
     */
    private function findTokenByHash($tokenHash): TokenInterface
    {
        $getTokenRequest = new GetToken($tokenHash);
        try {
            $this->gateway->execute($getTokenRequest);
        } catch (LogicException $exception) {
            throw new TokenNotFound('The requested token was not found');
        }
        $token = $getTokenRequest->getToken();

        return $token;
    }

    private function findStatusByToken(TokenInterface $token): GetBinaryStatus
    {
        $status = new GetBinaryStatus($token);
        $this->gateway->execute($status);

        if (empty($status->getValue())) {
            throw new LogicException('The payment status could not be fetched');
        }

        return $status;
    }

    private function completePaymentDetails($payment, $checkoutSession)
    {
        if ($payment instanceof StripePaymentDetails) {
            $payment->setCheckoutSessionId($checkoutSession->id);
            $payment->setPaymentIntentId($checkoutSession->payment_intent ?? '');
            $payment->setSubscriptionId($checkoutSession->subscription ?? '');
        }

        $details                        = $payment->getDetails(true);
        $details['checkout_session_id'] = $checkoutSession->id;
        $details['subscription_id']     = $checkoutSession->subscription;
        $details['payment_intent_id']   = $checkoutSession->payment_intent;
        $details['stripe_session']      = $checkoutSession->__toArray();
        $payment->setDetails($details);
    }

    public function getStatus() : GetBinaryStatus
    {
        return $this->status;
    }

    public function getToken() : TokenInterface
    {
        return $this->token;
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof handleCheckoutCompletedEvent
            ;
    }
}
