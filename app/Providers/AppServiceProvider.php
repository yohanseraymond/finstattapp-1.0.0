<?php

namespace App\Providers;

use App\Model\Attachment;
use App\Model\PaymentRequest;
use App\Model\Post;
use App\Model\Stream;
use App\Model\Subscription;
use App\Model\Transaction;
use App\Model\UserMessage;
use App\Model\UserVerify;
use App\Model\Withdrawal;
use App\Observers\AttachmentsObserver;
use App\Observers\PaymentRequestsObserver;
use App\Observers\PostApprovalObserver;
use App\Observers\StreamsObserver;
use App\Observers\SubscriptionsObserver;
use App\Observers\TransactionsObserver;
use App\Observers\UserMessagesObserver;
use App\Observers\UsersObserver;
use App\Observers\UserVerifyObserver;
use App\Observers\WithdrawalsObserver;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     * TODO: Delete this once on L10
     * @return void
     */
    public function register()
    {
        //
        // code in `register` method
        Event::listen(MigrationsStarted::class, function (){
            if (env('ALLOW_DISABLED_PK')) {
                DB::statement('SET SESSION sql_require_primary_key=0');
            }
        });

        Event::listen(MigrationsEnded::class, function (){
            if (env('ALLOW_DISABLED_PK')) {
                DB::statement('SET SESSION sql_require_primary_key=1');
            }
        });
    }

    /**
     * Bootstrap any application services.
     * TODO: Delete this once on L10
     * @return void
     */
    public function boot()
    {
        if (! InstallerServiceProvider::checkIfInstalled()) {
            return false;
        }
        UserVerify::observe(UserVerifyObserver::class);
        Withdrawal::observe(WithdrawalsObserver::class);
        PaymentRequest::observe(PaymentRequestsObserver::class);
        UserMessage::observe(UserMessagesObserver::class);
        Attachment::observe(AttachmentsObserver::class);
        Transaction::observe(TransactionsObserver::class);
        Post::observe(PostApprovalObserver::class);
        Subscription::observe(SubscriptionsObserver::class);
        User::observe(UsersObserver::class);
        Stream::observe(StreamsObserver::class);
        if(getSetting('security.enforce_app_ssl')){
            \URL::forceScheme('https');
        }
        Schema::defaultStringLength(191); // TODO: Maybe move it as the first line
        if(!InstallerServiceProvider::glck()){
            dd(base64_decode('SW52YWxpZCBzY3JpcHQgc2lnbmF0dXJl'));
        }
        // Overriding timezone, if provided
        if(getSetting('site.timezone')){
            config(['app.timezone' => getSetting('site.timezone')]);
            date_default_timezone_set(getSetting('site.timezone'));
        }
        Paginator::useBootstrap();
    }
}
