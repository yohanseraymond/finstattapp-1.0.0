<div class="input-group mb-3 mt-3">
    <div class="input-group-prepend">
        <span class="input-group-text" id="amount-label">@include('elements.icon',['icon'=>'cash-outline','variant'=>'medium'])</span>
    </div>
    <input class="form-control"
           placeholder="{{ \App\Providers\PaymentsServiceProvider::getWithdrawalAmountLimitations() }}"
           aria-label="Username"
           aria-describedby="amount-label"
           id="withdrawal-amount"
           type="number"
           min="{{\App\Providers\PaymentsServiceProvider::getWithdrawalMinimumAmount()}}"
           step="1"
           max="{{\App\Providers\PaymentsServiceProvider::getWithdrawalMaximumAmount()}}">
    <div class="invalid-feedback">{{__('Please enter a valid amount')}}</div>
    <div class="input-group mb-3 mt-3">
        <div class="d-flex flex-row w-100">
            <div class="form-group w-50 pr-2">
                <label for="paymentMethod">{{__('Payment method')}}</label>
                <select class="form-control" id="payment-methods" name="payment-methods">
                    @foreach(\App\Providers\PaymentsServiceProvider::getWithdrawalsAllowedPaymentMethods() as $paymentMethod)
                        <option value="{{$paymentMethod}}">{{__($paymentMethod)}}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group w-50 pl-2 update-stripe-connect-box d-none">
                <label id="update-stripe-connect-label" for="update-stripe-connect">{{__('Update details')}}</label>
                <a href="{{route('withdrawals.onboarding')}}">
                    <button id="update-stripe-connect" class="btn btn-primary btn-block rounded mr-0">{{__('Update')}}</button>
                </a>
            </div>
            <div class="form-group w-50 pl-2 input-label">
                <label id="payment-identifier-label" for="withdrawal-payment-identifier">{{__("Bank account")}}</label>
                <input class="form-control" type="text" id="withdrawal-payment-identifier" name="payment-identifier">
            </div>
        </div>
        <div class="form-group w-100 input-message">
            <label for="withdrawal-message">{{__('Message (Optional)')}}</label>
            <textarea placeholder="{{__('Bank account, notes, etc')}}" class="form-control" id="withdrawal-message" rows="2"></textarea>
            <span class="invalid-feedback" role="alert">
                {{__('Please add your withdrawal notes: EG: Paypal or Bank account.')}}
            </span>
        </div>
    </div>
    <div class="stripe-connect-label d-none">
        @if(!Auth::user()->country_id)
            <span>{{__("You must set the country on your profile before you can start onboarding and withdraw money")}}</span>
        @elseif(!Auth::user()->stripe_onboarding_verified)
            <span>{{__("We're using Stripe to get you paid quickly and keep your personal and payment information secure. Thousands of companies around the world trust Stripe to process payments for their users. Set up a Stripe account to get paid with us")}}</span>
        @endif
    </div>
    <div class="payment-error error text-danger d-none mt-3">{{__('Add all required info')}}</div>
    <div class="stripe-connect-buttons d-none w-100">
        @if(!Auth::user()->country_id)
            <div class="mt-3">
                <div>
                    <a href="{{route('my.settings',['type'=>'profile'])}}">
                        <button class="btn btn-primary btn-block rounded mr-0">{{__('Set your country')}}</button>
                    </a>
                </div>
            </div>
        @elseif(!Auth::user()->stripe_onboarding_verified)
            <div class="mt-3">
                <div>
                    <a href="{{route('withdrawals.onboarding')}}">
                        <button class="btn btn-primary btn-block rounded mr-0">{{!Auth::user()->stripe_account_id ? __('Start onboarding') : __('Update details')}}</button>
                    </a>
                </div>
            </div>
        @endif
    </div>
    <button class="btn btn-primary btn-block rounded mr-0 withdrawal-continue-btn" type="submit">{{__('Request withdrawal')}}</button>
</div>
