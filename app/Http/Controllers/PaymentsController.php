<?php

namespace App\Http\Controllers;

use App\Helpers\PaymentHelper;
use App\Http\Requests\CreateTransactionRequest;
use App\Model\Subscription;
use App\Model\Transaction;
use App\Model\Withdrawal;
use App\Providers\InvoiceServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\PaymentRequestServiceProvider;
use App\Providers\PaymentsServiceProvider;
use App\Providers\PostsHelperServiceProvider;
use App\Providers\WithdrawalsServiceProvider;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Stripe\StripeClient;
use Yabacon\Paystack;

class PaymentsController extends Controller
{
    protected $paymentHandler;

    /**
     * PaymentsController constructor.
     * @param PaymentsServiceProvider $paymentsProvider
     */
    public function __construct(PaymentHelper $paymentHandler)
    {
        $this->paymentHandler = $paymentHandler;
    }

    public function paymentInitiateValidator(CreateTransactionRequest $request){
        return response()->json([
            'status' => 200
        ], 200);
    }

    /**
     * Initiates the payment based on the required provider.
     * @param CreateTransactionRequest $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function initiatePayment(CreateTransactionRequest $request)
    {
        $transactionType = $request->get('transaction_type');
        $redirectLink = null;
        // generate one time transaction
        try {
            $this->updateUserBillingDetails($request);

            $transaction = new Transaction();
            $transaction['sender_user_id'] = Auth::user()->id;
            $transaction['recipient_user_id'] = $request->get('recipient_user_id');
            $transaction['post_id'] = $request->get('post_id');
            $transaction['user_message_id'] = $request->get('user_message_id');
            $transaction['type'] = $transactionType;
            $transaction['status'] = Transaction::INITIATED_STATUS;
            $transaction['amount'] = $request->get('amount');
            $transaction['currency'] = config('app.site.currency_code');
            $transaction['payment_provider'] = $request->get('provider');
            $transaction['taxes'] = $request->get('taxes');
            $transaction['stream_id'] = $request->get('stream');
            $errorMessage = __('Something went wrong with this transaction. Please try again');

            $recipientUser = User::query()->where('id', $transaction['recipient_user_id'])->first();
            if ($transaction['amount'] <= 0 || (!$recipientUser && $transactionType !== Transaction::DEPOSIT_TYPE)) {
                return $this->paymentHandler->redirectByTransaction($transaction, $errorMessage);
            }

            if(!$this->paymentHandler->validateTransaction($transaction, $recipientUser)) {
                return $this->paymentHandler->redirectByTransaction($transaction, $errorMessage);
            }

            if ($transaction['payment_provider'] == Transaction::PAYPAL_PROVIDER) {
                $this->paymentHandler->initiatePaypalContext();
            }

            if (in_array($transaction['payment_provider'], [Transaction::STRIPE_PROVIDER, Transaction::OXXO_PROVIDER])) {
                $redirectLink = $this->paymentHandler->generateStripeSessionByTransaction($transaction);
                // if we cannot fetch a redirect link it means stripe session generation process failed
                if ($redirectLink == null) {
                    $transaction['status'] = Transaction::DECLINED_STATUS;
                    $transaction->save();
                    return $this->paymentHandler->redirectByTransaction($transaction, $errorMessage = __('Failed generating stripe session'));
                }
            }

            if ($transaction['payment_provider'] == Transaction::CREDIT_PROVIDER) {
                $userAvailableAmount = $this->paymentHandler->getLoggedUserAvailableAmount();
                // check if user have enough money to pay with credit for this transaction
                if ($userAvailableAmount < $transaction['amount']) {
                    $errorMessage = __("You don't have enough money to pay with credit for this transaction. Please try with another payment method");

                    return $this->paymentHandler->redirectByTransaction($transaction, $errorMessage);
                }
            }

            switch ($transactionType) {
                case Transaction::TIP_TYPE:
                case Transaction::CHAT_TIP_TYPE:
                case Transaction::STREAM_ACCESS:
                case Transaction::POST_UNLOCK:
                case Transaction::MESSAGE_UNLOCK:
                    $userId = Auth::user()->id;
                    $postId = $transaction['post_id'];
                    $streamId = $transaction['stream_id'];
                    $messageId = $transaction['user_message_id'];
                    if($recipientUser->id === $transaction['sender_user_id']) {
                        return $this->paymentHandler->redirectByTransaction(
                            $transaction,
                            $errorMessage = __('Cannot pay to yourself.')
                        );
                    }

                    if($transactionType === Transaction::POST_UNLOCK && PostsHelperServiceProvider::userPaidForPost($userId, $postId)){
                        return $this->paymentHandler->redirectByTransaction(
                            $transaction,
                            $errorMessage = __('You already unlocked this post.')
                        );
                    } elseif($transactionType === Transaction::STREAM_ACCESS && PostsHelperServiceProvider::userPaidForStream($userId, $streamId)){
                        return $this->paymentHandler->redirectByTransaction(
                            $transaction,
                            $errorMessage = __('You already paid for this streaming')
                        );
                    } elseif($transactionType === Transaction::MESSAGE_UNLOCK && PostsHelperServiceProvider::userPaidForMessage($userId, $messageId)){
                        return $this->paymentHandler->redirectByTransaction(
                            $transaction,
                            $errorMessage = __('You already paid access for this message')
                        );
                    }

                    if ($transaction['payment_provider'] == Transaction::PAYPAL_PROVIDER) {
                        $redirectLink = $this->paymentHandler->initiateOneTimePaypalTransaction($transaction);
                    } elseif ($transaction['payment_provider'] == Transaction::CREDIT_PROVIDER) {
                        $this->paymentHandler->generateOneTimeCreditTransaction($transaction);
                    } elseif($transaction['payment_provider'] == Transaction::COINBASE_PROVIDER){
                        $redirectLink = $this->paymentHandler->generateCoinBaseTransaction($transaction);
                    } elseif($transaction['payment_provider'] == Transaction::NOWPAYMENTS_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generateNowPaymentsTransaction($transaction);
                    } elseif($transaction['payment_provider'] == Transaction::CCBILL_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generateCCBillOneTimePaymentTransaction($transaction);
                    } elseif($transaction['payment_provider'] == Transaction::PAYSTACK_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generatePaystackTransaction($transaction, Auth::user()->email);
                    } elseif($transaction['payment_provider'] == Transaction::MERCADO_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generateMercadoTransaction($transaction);
                    }
                    break;
                case Transaction::DEPOSIT_TYPE:
                    $transaction['recipient_user_id'] = Auth::user()->id;
                    if ($transaction['payment_provider'] == Transaction::PAYPAL_PROVIDER) {
                        $redirectLink = $this->paymentHandler->initiateOneTimePaypalTransaction($transaction);
                    } elseif($transaction['payment_provider'] == Transaction::COINBASE_PROVIDER){
                        $redirectLink = $this->paymentHandler->generateCoinBaseTransaction($transaction);
                    } elseif($transaction['payment_provider'] == Transaction::NOWPAYMENTS_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generateNowPaymentsTransaction($transaction);
                    } elseif($transaction['payment_provider'] == Transaction::CCBILL_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generateCCBillOneTimePaymentTransaction($transaction);
                    } elseif($transaction['payment_provider'] == Transaction::PAYSTACK_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generatePaystackTransaction($transaction, Auth::user()->email);
                    } elseif($transaction['payment_provider'] == Transaction::MERCADO_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generateMercadoTransaction($transaction);
                    }
                    break;
                case Transaction::ONE_MONTH_SUBSCRIPTION:
                case Transaction::THREE_MONTHS_SUBSCRIPTION:
                case Transaction::SIX_MONTHS_SUBSCRIPTION:
                case Transaction::YEARLY_SUBSCRIPTION:
                    if($recipientUser->id === $transaction['sender_user_id']) {
                        return $this->paymentHandler->redirectByTransaction(
                            $transaction,
                            $errorMessage = __('Cannot subscribe to yourself.')
                        );
                    }

                    if (PostsHelperServiceProvider::hasActiveSub($transaction['sender_user_id'], $transaction['recipient_user_id'])) {
                        $errorMessage = __('You already have an active subscription for this user.');

                        return $this->paymentHandler->redirectByTransaction($transaction, $errorMessage);
                    }

                    if ($transaction['payment_provider'] == Transaction::PAYPAL_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generatePaypalSubscriptionByTransaction($transaction);
                    } elseif ($transaction['payment_provider'] == Transaction::STRIPE_PROVIDER) {
                        $this->paymentHandler->generateStripeSubscriptionByTransaction($transaction);
                    } elseif ($transaction['payment_provider'] == Transaction::CREDIT_PROVIDER) {
                        $this->paymentHandler->generateCreditSubscriptionByTransaction($transaction);
                    } elseif ($transaction['payment_provider'] == Transaction::CCBILL_PROVIDER) {
                        $redirectLink = $this->paymentHandler->generateCCBillSubscriptionPayment($transaction);
                    }
                    break;
                default:
                    return $this->paymentHandler->redirectByTransaction($transaction);
            }
            $transaction->save();

            if ($transaction['payment_provider'] === Transaction::CREDIT_PROVIDER
                && $transaction['status'] === Transaction::APPROVED_STATUS) {
                $this->paymentHandler->creditReceiverForTransaction($transaction);
                $this->paymentHandler->deductMoneyFromUserWalletForCreditTransaction($transaction, Auth::user()->wallet);
                $this->paymentHandler->createNewTipNotificationForCreditTransaction($transaction);
                NotificationServiceProvider::createPPVNotificationByTransaction($transaction);
            }

            try {
                // create payment request for this transaction and leave it on initiated status
                if($transaction['payment_provider'] === Transaction::MANUAL_PROVIDER){
                    $manualPaymentFiles = $request->get('manual_payment_files');
                    $manualPaymentDescription = $request->get('manual_payment_description');
                    PaymentRequestServiceProvider::createDepositPaymentRequestByTransaction($transaction, $manualPaymentFiles, $manualPaymentDescription);
                }
            } catch (\Exception $exception) {
                Log::channel('payments')->error("Failed processing manual deposit payment request: ".$transaction->id." error: ".$exception->getMessage());
            }

            if ($transaction != null) {
                try {
                    $invoice = InvoiceServiceProvider::createInvoiceByTransaction($transaction);
                    if ($invoice != null) {
                        $transaction->invoice_id = $invoice->id;
                        $transaction->save();
                    }
                } catch (\Exception $exception) {
                    Log::channel('payments')->error("Failed generating invoice for transaction: ".$transaction->id." error: ".$exception->getMessage());
                }
            }
        } catch (\Exception $exception) {
            Log::channel('payments')->error("Payment failed -> error message: " . $exception->getMessage());
            Log::channel('payments')->error("Payment failed", [$exception->getTraceAsString()]);

            return Redirect::route('feed')
                ->with('error', __('Payment failed.'));
        }

        // Url generated successfully
        if (isset($redirectLink) && in_array($transaction['payment_provider'], Transaction::ALLOWED_PAYMENT_PROVIDERS)) {
            // redirect on payment provider checkout page
            return Redirect::away($redirectLink);
        }
        return $this->paymentHandler->redirectByTransaction($transaction);
    }

    /**
     * Handles the deposit request response.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function executePaypalPayment(Request $request)
    {
        // Get the payment ID before session clear
        $payment_id = $request->get('paymentId');

        // Checking for valid request
        if (empty($request->get('token'))) {
            return Redirect::route('my.settings', ['type' => 'deposit'])
                ->with('error', __('Looks like the payment process has been cancelled.')); // warning
        }

        // find paypal transaction and update it
        $transaction = Transaction::query()->where(['paypal_transaction_token' => $request->get('token')])->first();
        if ($transaction != null) {
            if ($transaction->type != null) {
                if ($this->paymentHandler->isSubscriptionPayment($transaction->type) && $transaction->subscription_id != null) {
                    $this->paymentHandler->executePaypalAgreementPayment($transaction);
                    $transaction->save();
                } else {
                    if (empty($request->get('PayerID'))) {
                        return $this->paymentHandler->redirectByTransaction($transaction);
                    }

                    $this->paymentHandler->executeOneTimePaypalPayment($request, $transaction, $payment_id);
                    $transaction->save();
                }
            }

            if ($transaction != null && $transaction->status === Transaction::APPROVED_STATUS
                && ($transaction->type === Transaction::TIP_TYPE || $transaction->type === Transaction::CHAT_TIP_TYPE)) {
                NotificationServiceProvider::createNewTipNotification($transaction);
            }
        }

        return $this->paymentHandler->redirectByTransaction($transaction);
    }

    /**
     * Stripe payment confirmation endpoint / webhook.
     */
    public function stripePaymentsHook()
    {
        app('debugbar')->disable();

        $endpoint_secret = getSetting('payments.stripe_webhooks_secret');
        $payload = @file_get_contents('php://input');
        if (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        } else {
            // Invalid payload
            http_response_code(400);
            exit();
        }

        $event = null;
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            exit();
        }
        Log::channel('payments')->info('Stripe payload received. Proceeding with completing the payment & fulfill the order.');
        Log::channel('payments')->debug($event);

        try {
            if ($event->type === 'checkout.session.completed') {
            // Payment is successful and the subscription is created.
            $session = $event->data->object;
            if ($session->id != null) {
                // don't update oxxo transactions here
                $oxxoTransaction = Transaction::query()->where(['stripe_session_id' => $session->id, 'payment_provider' => Transaction::OXXO_PROVIDER])->first();
                if(!$oxxoTransaction) {
                    $this->paymentHandler->updateTransactionByStripeSessionId($session->id);
                }
            }
            // Occurs whenever a customer's subscription ends.
            } elseif ($event->type === 'customer.subscription.deleted' && isset($event->data->object) && $event->data->object->id != null) {
                $subscription = Subscription::query()->where('stripe_subscription_id', $event->data->object->id)->first();
                if ($subscription != null) {
                    $subscription->status = Subscription::CANCELED_STATUS;

                    $subscription->update();
                }
            } elseif (($event->type === 'invoice.paid' || $event->type === 'invoice.payment_failed') && isset($event->data->object)) {
                $paymentSucceeded = $event->type === 'invoice.paid' ? true : false;
                $stripe = new StripeClient(getSetting('payments.stripe_secret_key'));
                $stripeInvoice = $stripe->invoices->retrieve($event->data->object->id);
                if ($stripeInvoice != null && $stripeInvoice->subscription) {
                    $stripeSub = $stripe->subscriptions->retrieve($stripeInvoice->subscription);
                    if ($stripeSub != null && $stripeSub->id != null) {
                        $subscription = Subscription::query()->where('stripe_subscription_id', $stripeSub->id)->first();
                        if ($subscription != null && isset($subscription->expires_at) && $subscription->expires_at < new \DateTime()) {
                            $this->paymentHandler->createSubscriptionRenewalTransaction($subscription, $paymentSucceeded, $event->data->object->id);
                            // update subscription expire date
                            if ($paymentSucceeded) {
                                $subscription->status = Subscription::ACTIVE_STATUS;
                                $date = new \DateTime();
                                $subscription->expires_at = $date->setTimestamp($stripeSub->current_period_end);
                            } else {
                                if ($subscription->expires_at <= new \DateTime()) {
                                    $subscription->status = Subscription::EXPIRED_STATUS;
                                } else {
                                    $subscription->status = Subscription::FAILED_STATUS;
                                }
                            }
                            $subscription->save();
                        }
                    }
                }
            } elseif ($event->type === 'charge.refunded' && isset($event->data->object) && $event->data->object->payment_intent != null) {
                $transaction = Transaction::query()->where('stripe_transaction_id', $event->data->object->payment_intent)->with('subscription')->first();
                if ($transaction) {
                    if($transaction->status === Transaction::APPROVED_STATUS){
                        $this->paymentHandler->deductMoneyFromUserForRefundedTransaction($transaction);
                    }

                    $transaction->status =Transaction::REFUNDED_STATUS;
                    $transaction->save();

                    if($transaction->subscription != null){
                        $transaction->subscription->status = Subscription::SUSPENDED_STATUS;
                        $transaction->subscription->expires_at = new \DateTime('now', new \DateTimeZone('UTC'));
                        $transaction->subscription->save();
                    }
                }
            // handles oxxo (or other stripe payment providers) related hooks
            } elseif(($event->type === 'checkout.session.async_payment_succeeded' || $event->type === 'checkout.session.async_payment_failed') && isset($event->data->object) && $event->data->object->id != null) {
                $this->paymentHandler->updateTransactionByStripeSessionId($event->data->object->id);
            }
        } catch (\Exception $exception) {
            Log::channel('payments')->error($exception->getMessage());
        }

        http_response_code(200);
    }

    /**
     * Gets stripe transaction status and redirects.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function getStripePaymentStatus(Request $request)
    {
        $transaction = $this->paymentHandler->updateTransactionByStripeSessionId($request->get('session_id'));
        NotificationServiceProvider::createTipNotificationByTransaction($transaction);
        NotificationServiceProvider::createPPVNotificationByTransaction($transaction);

        return $this->paymentHandler->redirectByTransaction($transaction);
    }

    /**
     * Handles Coinbase payment execution
     * @param Request $request
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function checkAndUpdateCoinbaseTransaction(Request $request)
    {
        $coinbaseTransactionToken = $request->get('token');
        $transaction = Transaction::query()->where('coinbase_transaction_token', $coinbaseTransactionToken)->first();
        if ($transaction != null) {
            $this->paymentHandler->checkAndUpdateCoinbaseTransaction($transaction);
            $transaction->save();
        }
        NotificationServiceProvider::createTipNotificationByTransaction($transaction);
        NotificationServiceProvider::createPPVNotificationByTransaction($transaction);

        return $this->paymentHandler->redirectByTransaction($transaction);
    }

    /**
     * Handles Coinbase payments hook
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function coinbaseHook(Request $request){
        if(!getSetting('payments.coinbase_webhook_key')){
            return response()->json([
                'status' => 400
            ], 400);
        }

        $payload = json_decode($request->getContent(), true);
        $computedSignature = hash_hmac('sha256', $request->getContent(), getSetting('payments.coinbase_webhook_key'));

        // Validate the webhook signature
        if (hash_equals($computedSignature, $request->server('HTTP_X_CC_WEBHOOK_SIGNATURE'))) {
            Log::channel('payments')->info("coinbase payload: ", [$payload]);
            if(isset($payload['event']) && isset($payload['event']['type']) && isset($payload['event']['data']) && isset($payload['event']['data']['id'])){
                if($payload['event']['type'] === 'charge:failed' || $payload['event']['type'] === 'charge:confirmed'){
                    $transaction = Transaction::query()->where('coinbase_charge_id', $payload['event']['data']['id'])->first();
                    if($transaction != null){
                        if($payload['event']['type'] === 'charge:failed'){
                            $transaction->status = Transaction::CANCELED_STATUS;
                            $transaction->save();
                        } else if ($payload['event']['type'] === 'charge:confirmed') {
                            $transaction->status = Transaction::APPROVED_STATUS;
                            $transaction->save();
                            $this->paymentHandler->creditReceiverForTransaction($transaction);
                            NotificationServiceProvider::createTipNotificationByTransaction($transaction);
                            NotificationServiceProvider::createPPVNotificationByTransaction($transaction);
                        }
                    }
                }
            }
        } else {
            Log::channel('payments')->info('Coinbase signature validation failed.');

            return response()->json([
                'status' => 400
            ], 400);
        }

        return response()->json([
            'status' => 200
        ], 200);
    }

    /**
     * Paypal handling webhook method.
     *
     * @param Request $request
     */
    public function paypalPaymentsHook(Request $request)
    {
        try {
            $webhookContent = json_decode($request->getContent(), true);
            $eventType = $webhookContent['event_type'];
            $cancelStatuses = ['partially_refunded', 'refunded', 'denied'];
            $resourceContent = $webhookContent['resource'];

            Log::channel('payments')->info('Paypal payload received. Proceeding with completing the payment & fulfill the order.');
            Log::channel('payments')->debug($webhookContent);

            switch ($eventType) {
                case 'PAYMENT.SALE.COMPLETED':
                    // handle recurring payments (one month subscriptions)
                    if (array_key_exists('billing_agreement_id', $resourceContent) && ! empty($resourceContent['billing_agreement_id'])) {
                        $agreementId = $resourceContent['billing_agreement_id'];
                        $this->paymentHandler->verifyPayPalAgreement($agreementId, null, $resourceContent['id']);
                    // handle one time payments
                    } elseif (array_key_exists('parent_payment', $resourceContent) && ! empty($resourceContent['parent_payment']) && empty($resourceContent['state'])) {
                        $transaction = Transaction::query()->where('paypal_transaction_id', $resourceContent['parent_payment'])->first();
                        if ($transaction != null && $transaction->status == Transaction::INITIATED_STATUS) {
                            if ($resourceContent['state'] == 'completed') {
                                $transaction->status = Transaction::APPROVED_STATUS;
                            } elseif (in_array($resourceContent['state'], $cancelStatuses)) {
                                $transaction->status = Transaction::CANCELED_STATUS;
                            } elseif ($resourceContent['state'] == 'pending') {
                                $transaction->status = Transaction::PENDING_STATUS;
                            }

                            $transaction->save();

                            if ($transaction->status == Transaction::APPROVED_STATUS) {
                                $this->paymentHandler->creditReceiverForTransaction($transaction);
                            }
                        }
                    }
                    break;
                case 'BILLING.SUBSCRIPTION.EXPIRED':
                case 'BILLING.SUBSCRIPTION.CANCELLED':
                case 'BILLING.SUBSCRIPTION.SUSPENDED':
                    if (isset($resourceContent['id']) && $resourceContent['id'] != null && isset($resourceContent['state']) && $resourceContent['state'] != null) {
                        // find a subscription by this id
                        $subscription = Subscription::query()->where('paypal_agreement_id', $resourceContent['id'])->first();
                        if ($subscription != null) {
                            if ($resourceContent['state'] == 'Cancelled') {
                                $subscription->status = Subscription::CANCELED_STATUS;
                            } elseif ($resourceContent['state'] == 'Suspended') {
                                $subscription->status = Subscription::SUSPENDED_STATUS;
                            } elseif ($resourceContent['state'] == 'Expired') {
                                $subscription->status = Subscription::EXPIRED_STATUS;
                            }

                            $subscription->save();
                        }
                    }
                    break;
                case 'PAYMENT.SALE.REFUNDED':
                    if (array_key_exists('parent_payment', $resourceContent) && ! empty($resourceContent['parent_payment'])) {
                        $transaction = Transaction::query()->where('paypal_transaction_id', $resourceContent['parent_payment'])->with('subscription')->first();
                        if ($transaction) {
                            if($transaction->status === Transaction::APPROVED_STATUS){
                                $this->paymentHandler->deductMoneyFromUserForRefundedTransaction($transaction);
                            }

                            $transaction->status = Transaction::REFUNDED_STATUS;
                            $transaction->save();

                            if($transaction->subscription != null){
                                $transaction->subscription->status = Subscription::SUSPENDED_STATUS;
                                $transaction->subscription->expires_at = new \DateTime('now', new \DateTimeZone('UTC'));
                                $transaction->subscription->save();
                            }
                        }
                    }
                    break;
            }
        } catch (\Exception $exception) {
            Log::channel('payments')->error($exception->getMessage());
        }

        http_response_code(200);
    }

    /**
     * Method used for saving user billing details.
     *
     * @param $request
     */
    public function updateUserBillingDetails($request)
    {
        $firstName = $request->get('first_name');
        $lastName = $request->get('last_name');
        $billingAddress = $request->get('billing_address');
        $country = $request->get('country');
        $city = $request->get('city');
        $state = $request->get('state');
        $postcode = $request->get('postcode');

        // update user billing details if they changed
        if ($firstName != null || $lastName != null || $billingAddress != null) {
            $loggedUser = Auth::user();

            if ($loggedUser != null) {
                $updateData = [];
                if ($firstName != null && $firstName != $loggedUser->first_name) {
                    $updateData['first_name'] = $firstName;
                }

                if ($lastName != null && $lastName != $loggedUser->last_name) {
                    $updateData['last_name'] = $lastName;
                }

                if ($billingAddress != null && $billingAddress != $loggedUser->billing_address) {
                    $updateData['billing_address'] = $billingAddress;
                }

                if ($country != null && $country != $loggedUser->country) {
                    $updateData['country'] = $country;
                }

                if ($state != null && $state != $loggedUser->state) {
                    $updateData['state'] = $state;
                }

                if ($city != null && $city != $loggedUser->city) {
                    $updateData['city'] = $city;

                }

                if ($postcode != null && $postcode != $loggedUser->postcode) {
                    $updateData['postcode'] = $postcode;

                }
                if(!empty($updateData)) {
                    $loggedUser->update($updateData);
                }
            }
        }
    }

    /**
     * Handles NowPayments payment execution
     * @param Request $request
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function checkAndUpdateNowPaymentsTransaction(Request $request)
    {
        $nowPaymentsTransactionToken = $request->get('orderId');
        $transaction = null;
        if($nowPaymentsTransactionToken) {
            $transaction = Transaction::query()->where('nowpayments_order_id', $nowPaymentsTransactionToken)->first();
            if ($transaction) {
                $this->paymentHandler->checkAndUpdateNowPaymentsTransaction($transaction);
                $transaction->save();
            }
        }

        return $this->paymentHandler->redirectByTransaction($transaction);
    }

    /**
     * Process NowPayments IPN hooks
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function nowPaymentsHook(Request $request){
        if(!getSetting('payments.nowpayments_ipn_secret_key')){
            Log::channel('payments')->info("NowPayments hook error: missing IPN secret key");
            return response()->json([
                'status' => 400
            ], 400);
        }

        try{
            if (isset($_SERVER['HTTP_X_NOWPAYMENTS_SIG']) && !empty($_SERVER['HTTP_X_NOWPAYMENTS_SIG'])) {
                $received_hmac = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'];
                $request_json = $request->getContent();
                $payload = json_decode($request_json, true);
                Log::channel('payments')->info("NowPayments hook received: ", [$payload]);
                ksort($payload);
                $sorted_request_json = json_encode($payload, JSON_UNESCAPED_SLASHES);
                if ($request_json !== false && !empty($request_json)) {
                    $hmac = hash_hmac("sha512", $sorted_request_json, trim(getSetting('payments.nowpayments_ipn_secret_key')));
                    if ($hmac == $received_hmac) {
                        Log::channel('payments')->info("NowPayments hook payload: ", [$payload]);
                        if(isset($payload['order_id']) && isset($payload['payment_status']) && isset($payload['payment_id'])) {
                            $transaction = Transaction::query()->where('nowpayments_order_id', $payload['order_id'])->with('receiver')->first();
                            if($transaction){
                                if(in_array($transaction->status, [Transaction::INITIATED_STATUS, Transaction::PENDING_STATUS, Transaction::PARTIALLY_PAID_STATUS])){
                                    // payment approved
                                    if($payload['payment_status'] === 'finished') {
                                        $transaction->status = Transaction::APPROVED_STATUS;
                                        $this->paymentHandler->creditReceiverForTransaction($transaction);
                                        NotificationServiceProvider::createTipNotificationByTransaction($transaction);
                                        NotificationServiceProvider::sendApprovedDepositTransactionEmailNotification($transaction);
                                        NotificationServiceProvider::createPPVNotificationByTransaction($transaction);
                                        // payment pending
                                    } elseif ($transaction->status !== Transaction::PENDING_STATUS && in_array($payload['payment_status'], ['waiting', 'confirming', 'sending'])) {
                                        $transaction->nowpayments_payment_id = $payload['payment_id'];
                                        $transaction->status = Transaction::PENDING_STATUS;
                                        // payment partially paid
                                    } elseif ($payload['payment_status'] === 'partially_paid' && $transaction->status !== Transaction::PARTIALLY_PAID_STATUS) {
                                        $transaction->status = Transaction::PARTIALLY_PAID_STATUS;
                                        NotificationServiceProvider::sendNowPaymentsPartiallyPaidTransactionEmailNotification($transaction);
                                        // payment expired or failed
                                    } elseif (in_array($payload['payment_status'], ['expired', 'failed'])) {
                                        $transaction->status = Transaction::DECLINED_STATUS;
                                    }
                                    $transaction->save();
                                    // handle refund
                                } else if($transaction->status === Transaction::APPROVED_STATUS && $payload['payment_status'] === 'refunded') {
                                    $this->paymentHandler->deductMoneyFromUserForRefundedTransaction($transaction);
                                    $transaction->status = Transaction::REFUNDED_STATUS;
                                    $transaction->save();
                                }
                            }
                        }

                        return response()->json([
                            'status' => 200
                        ], 200);
                    } else {
                        Log::channel('payments')->info('NowPayments HMAC signature does not match');
                    }
                } else {
                    Log::channel('payments')->info('NowPayments Error reading POST data');
                }
            } else {
                Log::channel('payments')->info('NowPayments No HMAC signature sent.');
            }
        } catch (\Exception $exception){
            Log::channel('payments')->info("NowPayments hook error: ", [$exception->getMessage()]);
        }

        return response()->json([
            'status' => 400
        ], 400);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function processCCBillTransaction(Request $request)
    {
        $paymentToken = $request->get('token');
        $transaction = null;
        if($paymentToken) {
            $transaction = Transaction::query()->where('ccbill_payment_token', $paymentToken)->first();
        }

        return $this->paymentHandler->redirectByTransaction($transaction);
    }

    /**
     * @param Request $request
     * @throws \Exception
     */
    public function ccBillHook(Request $request)
    {
        $ccBillAccountNumber = $request->get('clientAccnum');
        $ccBillSubAccountNumber = $request->get('clientSubacc');
        $eventType = $request->get('eventType');

        try {
            // check if this webhook comes with the right ccbill account numbers
            if ($ccBillAccountNumber === getSetting('payments.ccbill_account_number')
                && ($ccBillSubAccountNumber === getSetting('payments.ccbill_subaccount_number_recurring')
                    || $ccBillSubAccountNumber === getSetting('payments.ccbill_subaccount_number_one_time'))) {
                $content = $request->getContent();
                // handles possible UTF8 incorrectly encoded characters coming from CCBill
                $utfEncodedContent = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
                $eventBody = json_decode($utfEncodedContent, true, 512, JSON_THROW_ON_ERROR);
                Log::channel('payments')->debug('CCBill hook received eventType: ' . $eventType);
                Log::channel('payments')->debug('CCBill hook received: ', [$eventBody]);

                // handle payment success or failure
                if (isset($eventBody['X-token']) && in_array($eventType, ['NewSaleSuccess', 'NewSaleFailure'])) {
                    $transaction = Transaction::where('ccbill_payment_token', $eventBody['X-token'])->with('subscription')->first();
                    if ($transaction) {
                        $subscriptionId = isset($eventBody['subscriptionId']) ? $eventBody['subscriptionId'] : null;
                        $saleSuccess = $eventType === 'NewSaleSuccess' ? true : false;
                        $transaction->ccbill_transaction_id = isset($eventBody['transactionId']) ? $eventBody['transactionId'] : null;
                        $transaction->ccbill_subscription_id = $subscriptionId;
                        $transaction->status = $saleSuccess ? Transaction::APPROVED_STATUS : Transaction::DECLINED_STATUS;
                        $transaction->save();

                        if($this->paymentHandler->isSubscriptionPayment($transaction->type) && $transaction->subscription) {
                            $subscription = $transaction->subscription;
                            $subscription->ccbill_subscription_id = $subscriptionId;
                            if($saleSuccess) {
                                $expiresDate = new \DateTime('+'.$this->paymentHandler->getCCBillRecurringPeriodInDaysByTransaction($transaction).' days', new \DateTimeZone('UTC'));
                                if ($subscription->status != Subscription::ACTIVE_STATUS) {
                                    $subscription->status = Subscription::ACTIVE_STATUS;
                                    $subscription->expires_at = $expiresDate;

                                    NotificationServiceProvider::createNewSubscriptionNotification($subscription);
                                } else {
                                    $subscription->expires_at = $expiresDate;
                                }

                            } else {
                                $subscription->status = Subscription::FAILED_STATUS;
                            }
                            $subscription->save();
                        }


                        if ($transaction->status == Transaction::APPROVED_STATUS) {
                            $this->paymentHandler->creditReceiverForTransaction($transaction);
                            NotificationServiceProvider::createTipNotificationByTransaction($transaction);
                            NotificationServiceProvider::createPPVNotificationByTransaction($transaction);
                        }
                    }
                    // handle refund
                } elseif(isset($eventBody['transactionId']) && $eventType === 'Refund') {
                    $transaction = Transaction::where('ccbill_transaction_id', $eventBody['transactionId'])->with('subscription')->first();
                    if ($transaction->status === Transaction::APPROVED_STATUS) {
                        $this->paymentHandler->deductMoneyFromUserForRefundedTransaction($transaction);
                    }

                    $transaction->status = Transaction::REFUNDED_STATUS;
                    $transaction->save();

                    if ($transaction->subscription != null) {
                        $transaction->subscription->status = Subscription::SUSPENDED_STATUS;
                        $transaction->subscription->expires_at = new \DateTime('now', new \DateTimeZone('UTC'));
                        $transaction->subscription->save();
                    }
                    // handle renewal success / failure, cancellation or expiration
                } elseif ($eventBody['subscriptionId'] && in_array($eventType, ['RenewalSuccess', 'Renewal Failure', 'Cancellation', 'Expiration'])) {
                    $subscription = Subscription::where('ccbill_subscription_id', $eventBody['subscriptionId'])->first();
                    if ($subscription) {
                        if ($eventType === 'RenewalSuccess') {
                            $this->paymentHandler->createSubscriptionRenewalTransaction($subscription, $paymentSucceeded = true, $eventBody['subscriptionId']);
                            $expiresDate = new \DateTime($eventBody['nextRenewalDate'], new \DateTimeZone('UTC'));
                            $subscription->expires_at = $expiresDate;
                            if ($subscription->status != Subscription::ACTIVE_STATUS) {
                                $subscription->status = Subscription::ACTIVE_STATUS;

                                NotificationServiceProvider::createNewSubscriptionNotification($subscription);
                            }
                        } elseif ($eventType === 'Renewal Failure') {
                            $subscription->status = Subscription::SUSPENDED_STATUS;
                        } elseif ($eventType === 'Cancellation') {
                            $subscription->status = Subscription::CANCELED_STATUS;
                            $subscription->canceled_at = new \DateTime();
                        } elseif ($eventType === 'Expiration') {
                            $subscription->status = Subscription::EXPIRED_STATUS;
                        }

                        $subscription->save();
                    }
                }
            }
        } catch (\Exception $exception) {
            Log::channel('payments')->debug('CCBill hook error:', [$exception->getMessage()]);
        }
    }

    /**
     * Verifies paystack payment by calling their API and updating transaction in our side
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verifyPaystackTransaction(Request $request) {
        $reference = $request->get('reference');
        $transaction = $this->paymentHandler->verifyPaystackTransaction($reference);

        return $this->paymentHandler->redirectByTransaction($transaction);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function paystackHook(Request $request) {
        // Retrieve the request's body and parse it as JSON
        $event = Paystack\Event::capture();

        /* Verify that the signature matches one of your keys*/
        $my_keys = [
            'live'=>getSetting('payments.paystack_secret_key'),
            'test'=>getSetting('payments.paystack_secret_key'),
        ];
        $owner = $event->discoverOwner($my_keys);
        if(!$owner){
            return;
        }
        Log::channel('payments')->debug('Paystack hook received: ', [$event]);

        switch($event->obj->event){
            // charge.success
            case 'charge.success':
                if('success' === $event->obj->data->status){
                    $this->paymentHandler->verifyPaystackTransaction($event->obj->data->reference);
                }
                break;
            case 'refund.processed':
                if($event->obj->data->transaction_reference) {
                    $transaction = Transaction::where('paystack_payment_token', $event->obj->data->transaction_reference)->first();
                    if($transaction->status === Transaction::APPROVED_STATUS){
                        $transaction->status = Transaction::REFUNDED_STATUS;
                        $transaction->save();
                        $this->paymentHandler->deductMoneyFromUserForRefundedTransaction($transaction);
                    }
                }

                break;
        }

        http_response_code(200);
    }

    /**
     * Verifies MercadoPago transaction
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verifyMercadoTransaction(Request $request) {
        $paymentId = $request->query->get('payment_id');
        $transaction = $this->paymentHandler->verifyMercadoTransaction($paymentId);

        return $this->paymentHandler->redirectByTransaction($transaction);
    }

    /**
     * Handles MercadoPago hooks
     * @param Request $request
     * @return void
     */
    public function mercadoHook(Request $request) {
        $content = json_decode($request->getContent(), true);
        Log::channel('payments')->debug("MercadoPago hook received: ", [$content]);

        if(isset($content['data']) && isset($content['data']['id']) && isset($content['action'])) {
            switch ($content['action']) {
                case 'payment.created':
                case 'payment.updated':
                    $this->paymentHandler->verifyMercadoTransaction($content['data']['id']);
                    break;
            }
        }

        http_response_code(200);
    }

    public function stripeConnectHook() {
        app('debugbar')->disable();

        $endpoint_secret = getSetting('payments.withdrawal_stripe_connect_webhooks_secret');
        $payload = @file_get_contents('php://input');
        if (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        } else {
            // Invalid payload
            http_response_code(400);
            exit();
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            exit();
        }
        Log::channel('withdrawals')->info('StripeConnect payload received.');
        Log::channel('withdrawals')->debug($event);

        try {
            if(isset($event->data->object)) {
                if ($event->type === 'account.updated') {
                    $connectedAccountId = $event->data->object->id;
                    $user = User::query()->where('stripe_account_id', $connectedAccountId)->first();
                    if ($user) {
                        $verified = WithdrawalsServiceProvider::userDoneStripeOnboarding($user);
                        if($verified) {
                            $user->stripe_onboarding_verified = true;
                            $user->save();
                        }
                    }
                } elseif(in_array($event->type, ['payout.failed', 'payout.canceled'])) {
                    $payoutId = $event->data->object->id;
                    $withdrawal = Withdrawal::query()->where('stripe_payout_id', $payoutId)->first();
                    if($withdrawal) {
                        $oldWithdrawalStatus = $withdrawal->status;
                        $withdrawal->status = Withdrawal::REJECTED_STATUS;
                        // if withdrawal was already processed and approved before we'll have to send
                        // the money back to the user as the observer won't do any processing in this case
                        if($withdrawal->processed && $oldWithdrawalStatus === Withdrawal::APPROVED_STATUS) {
                            WithdrawalsServiceProvider::creditUserForRejectedWithdrawal($withdrawal);
                        }
                        $withdrawal->save();
                    }
                } elseif($event->type === 'payout.paid') {
                    $payoutId = $event->data->object->id;
                    $withdrawal = Withdrawal::query()->where('stripe_payout_id', $payoutId)->first();
                    if($withdrawal) {
                        $withdrawal->status = Withdrawal::APPROVED_STATUS;
                        $withdrawal->save();
                    }
                }
            }
        } catch (\Exception $exception) {
            Log::channel('withdrawals')->error($exception->getMessage());
        }

        http_response_code(200);
    }
}
