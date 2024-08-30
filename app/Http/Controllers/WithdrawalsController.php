<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateWithdrawalRequest;
use App\Model\Withdrawal;
use App\Providers\EmailsServiceProvider;
use App\Providers\GenericHelperServiceProvider;
use App\Providers\PaymentsServiceProvider;
use App\Providers\SettingsServiceProvider;
use App\Providers\StripeServiceProvider;
use App\Providers\WithdrawalsServiceProvider;
use App\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Stripe\Payout;

class WithdrawalsController extends Controller
{
    /**
     * Method used for requesting an withdrawal request from the admin.
     *
     * @param CreateWithdrawalRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestWithdrawal(CreateWithdrawalRequest $request)
    {
        try {
            $amount = $request->request->get('amount');
            $message = $request->request->get('message');
            $identifier = $request->request->get('identifier');
            $method = $request->request->get('method');

            $user = Auth::user();
            if ($amount != null && $user != null) {
                if ($user->wallet == null) {
                    $user->wallet = GenericHelperServiceProvider::createUserWallet($user);
                }

                if(floatval($amount) === floatval(PaymentsServiceProvider::getWithdrawalMinimumAmount()) && floatval($amount) > $user->wallet->total){
                    return response()->json(
                        [
                            'success' => false,
                            'message' => __("You don't have enough credit to withdraw. Minimum amount is: ", ['minAmount' => PaymentsServiceProvider::getWithdrawalMinimumAmount()])
                        ]
                    );
                }

                if (floatval($amount) > $user->wallet->total) {
                    return response()->json(['success' => false, 'message' => __('You cannot withdraw this amount, try with a lower one')]);
                }

                $fee = 0;
                if(getSetting('payments.withdrawal_allow_fees') && floatval(getSetting('payments.withdrawal_default_fee_percentage')) > 0) {
                    $fee = (floatval(getSetting('payments.withdrawal_default_fee_percentage')) / 100) * floatval($amount);
                }

                Withdrawal::create([
                    'user_id' => Auth::user()->id,
                    'amount' => floatval($amount),
                    'status' => Withdrawal::REQUESTED_STATUS,
                    'message' => $message,
                    'payment_method' => $method,
                    'payment_identifier' => $identifier,
                    'fee' => $fee
                ]);

                $user->wallet->update([
                    'total' => $user->wallet->total - floatval($amount),
                ]);

                $totalAmount = number_format($user->wallet->total, 2, '.', '');
                $pendingBalance = number_format($user->wallet->pendingBalance, 2, '.', '');

                // Sending out admin email
                $adminEmails = User::where('role_id', 1)->select(['email', 'name'])->get();
                foreach ($adminEmails as $user) {
                    EmailsServiceProvider::sendGenericEmail(
                        [
                            'email' => $user->email,
                            'subject' => __('Action required | New withdrawal request'),
                            'title' => __('Hello, :name,', ['name' => $user->name]),
                            'content' => __('There is a new withdrawal request on :siteName that requires your attention.', ['siteName' => getSetting('site.name')]),
                            'button' => [
                                'text' => __('Go to admin'),
                                'url' => route('voyager.dashboard').'/withdrawals',
                            ],
                        ]
                    );
                }

                return response()->json([
                    'success' => true,
                    'message' => __('Successfully requested withdrawal'),
                    'totalAmount' => SettingsServiceProvider::getWebsiteFormattedAmount($totalAmount),
                    'pendingBalance' => SettingsServiceProvider::getWebsiteFormattedAmount($pendingBalance),
                ]);
            }
        } catch (\Exception $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()]);
        }

        return response()->json(['success' => false, 'message' => __('Something went wrong, please try again')],500);
    }

    public function onboarding() {
        $user = Auth::user();

        try {
            // redirect user to the form where he must add his details for the first time
            $onboardingType = "account_onboarding";
            // check if user have a stripe account created
            if(!$user->stripe_account_id) {
                WithdrawalsServiceProvider::createStripeAccountForUser($user);
            }

            // check if user done onboarding and if so just redirect him to only update his details
            if(WithdrawalsServiceProvider::userDoneStripeOnboarding($user)) {
                $onboardingType = "account_update";
            }

            // create account link (Stripe hosted UI to complete verification / onboarding process)
            $accountLink = StripeServiceProvider::createStripeAccountLink($user->stripe_account_id, $onboardingType);
        } catch (\Exception $exception) {
            Log::channel('withdrawals')->error(
                'StripeConnect onboarding failed being initiated',
                ['error' => $exception->getMessage(), 'userId' => $user->id]
            );
            return back()->with('error', __('Onboarding initiation failed, please retry or contact support'));
        }

        // redirect on Stripe hosted UI
        return Redirect::away($accountLink->url);
    }

    public function approveWithdrawal($withdrawalId) {
        $withdrawal = Withdrawal::query()->where('id', $withdrawalId)->with('user')->first();

        if(!$withdrawal) {
            return response()->json(['success' => false, 'error' => __('Withdrawal not found')],404);
        }

        if($withdrawal->status !== Withdrawal::REQUESTED_STATUS) {
            return response()->json(['success' => false, 'error' => __('Withdrawal already processed')],400);
        }

        try{
            $payoutSucceeded = true;
            if($withdrawal->payment_method === 'Stripe Connect') {
                $payoutSucceeded = false;
                // transfer money to the connected account first if not already
                if(!$withdrawal->stripe_transfer_id) {
                    $transfer = StripeServiceProvider::createConnectedAccountTransfer($withdrawal, $withdrawal->user->stripe_account_id);
                    $withdrawal->stripe_transfer_id = $transfer->id;
                    // save withdrawal at this point as in case something goes wrong with payout we'll have the transfer made already
                    $withdrawal->save();
                }

                // create the payout
                $payout = StripeServiceProvider::createManualPayout($withdrawal->user->stripe_account_id);
                $withdrawal->stripe_payout_id = $payout->id;

                if($payout->status === Payout::STATUS_PAID) {
                    $payoutSucceeded = true;
                }

                if($payout->status === Payout::STATUS_FAILED) {
                    $withdrawal->status = Withdrawal::REJECTED_STATUS;
                }

                $withdrawal->save();
            }

            // only update the withdrawal status to approved if the payout was successful in case payment method is StripeConnect
            // otherwise leave it for webhook to decide
            if($payoutSucceeded) {
                $withdrawal->status = Withdrawal::APPROVED_STATUS;
                $withdrawal->save();
            }
        } catch (\Exception $exception) {
            return response()->json(['success' => false, 'error' => 'Error: "'.$exception->getMessage().'"'],500);
        }

        $message = $payoutSucceeded ? __("Withdrawal approved successfully") : __("Withdrawal payout initiated");

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function rejectWithdrawal($withdrawalId) {
        $withdrawal = Withdrawal::query()->where('id', $withdrawalId)->first();

        if(!$withdrawal) {
            return response()->json(['success' => false, 'error' => __('Withdrawal not found')],404);
        }

        if($withdrawal->status !== Withdrawal::REQUESTED_STATUS) {
            return response()->json(['success' => false, 'error' => __('Withdrawal already processed')],400);
        }

        try{
            $withdrawal->status = Withdrawal::REJECTED_STATUS;
            $withdrawal->save();
        } catch (\Exception $exception) {
            return response()->json(['success' => false, 'error' => 'Error: "'.$exception->getMessage().'"'],500);
        }

        return response()->json(['success' => true, 'message' => __("Withdrawal rejected successfully")]);
    }
}
