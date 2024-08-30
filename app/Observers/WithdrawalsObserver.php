<?php

namespace App\Observers;

use App\Model\Withdrawal;
use App\Providers\EmailsServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\PaymentsServiceProvider;
use App\Providers\SettingsServiceProvider;
use App\Providers\WithdrawalsServiceProvider;
use App\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class WithdrawalsObserver
{
    /**
     * Listen to the Withdrawal updating event.
     *
     * @param  \App\Model\Withdrawal  $withdrawal
     * @return void
     */
    public function saving(Withdrawal $withdrawal)
    {
        if ($withdrawal->getOriginal('status') == 'requested' && $withdrawal->status != 'requested') {
            if(!$withdrawal->processed) {
                if ($withdrawal->status == 'rejected') {
                    self::handleWithdrawalRejection($withdrawal);
                } elseif ($withdrawal->status = 'approved') {
                    PaymentsServiceProvider::createTransactionForWithdrawal($withdrawal);

                    $emailSubject = __('Your withdrawal request has been approved.');
                    $button = [
                        'text' => __('My payments'),
                        'url' => route('my.settings', ['type'=>'payments']),
                    ];

                    self::processWithdrawalNotifications($withdrawal, $emailSubject, $button);
                    // mark withdrawal as processed
                    $withdrawal->processed = true;
                    // Adding fee if enabled
                    if(getSetting('payments.withdrawal_allow_fees')){
                        $withdrawal->fee = $withdrawal->amount * (getSetting('payments.withdrawal_default_fee_percentage') / 100);
                    }
                }
            }
        }
    }

    /**
     * Handles the Withdrawal deletion event.
     *
     * @param Withdrawal $withdrawal
     * @return void
     */
    public function deleted(Withdrawal $withdrawal)
    {
        if(!$withdrawal->processed){
            self::handleWithdrawalRejection($withdrawal, true);
        }
    }

    /**
     * Returns money to the user and send notifications for a rejected/deleted withdrawal
     * @param $withdrawal
     * @param $skipNotficationEntry
     */
    private function handleWithdrawalRejection($withdrawal, $skipNotficationEntry = false){
        WithdrawalsServiceProvider::creditUserForRejectedWithdrawal($withdrawal);
        $emailSubject = __('Your withdrawal request has been denied.');
        $button = [
            'text' => __('Try again'),
            'url' => route('my.settings', ['type'=>'wallet']),
        ];

        self::processWithdrawalNotifications($withdrawal, $emailSubject, $button, $skipNotficationEntry);
        // mark withdrawal as processed
        $withdrawal->processed = true;

    }

    /**
     * Creates email / user notifications
     * @param $withdrawal
     * @param $emailSubject
     * @param $button
     * @param $skipNotficationEntry
     */
    private function processWithdrawalNotifications($withdrawal, $emailSubject, $button, $skipNotficationEntry = false){
        // Sending out the user notification
        $user = User::find($withdrawal->user_id);
        try{
            App::setLocale($user->settings['locale']);
        }
        catch (\Exception $e){
            App::setLocale('en');
        }
        EmailsServiceProvider::sendGenericEmail(
            [
                'email' => $user->email,
                'subject' => $emailSubject,
                'title' => __('Hello, :name,', ['name'=>$user->name]),
                'content' => __('Email withdrawal processed', [
                        'siteName' => getSetting('site.name'),
                        'status' => __($withdrawal->status),
                    ]).($withdrawal->status == 'approved' ? ' '.SettingsServiceProvider::getWebsiteFormattedAmount($withdrawal->amount) . (getSetting('payments.withdrawal_allow_fees') ? '(-'.SettingsServiceProvider::getWebsiteCurrencySymbol().($withdrawal->amount * (getSetting('payments.withdrawal_default_fee_percentage') / 100) ).' taxes)' : '') .' '.__('has been sent to your account.') : ''),
                'button' => $button,
            ]
        );

        // If withdrawal is deleted - do not create notification entry
        if(!$skipNotficationEntry){
            NotificationServiceProvider::createApprovedOrRejectedWithdrawalNotification($withdrawal);
        }
    }
}
