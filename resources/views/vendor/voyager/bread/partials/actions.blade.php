@if($data)
    @php
                // need to recreate object because policy might depend on record data
                $class = get_class($action);
                $action = new $class($dataType, $data);
    @endphp
    @can ($action->getPolicy(), $data)
        @if ($action->shouldActionDisplayOnRow($data))
            @if($action instanceof \TCG\Voyager\Actions\ViewAction and $dataType->name === 'invoices' and isset($data->id))
                <a target="_blank" href="{{ route('invoices.get', ['id' => $data->id]) }}" title="{{ $action->getTitle() }}" {!! $action->convertAttributesToHtml() !!}>
                    <i class="{{ $action->getIcon() }}"></i> <span class="hidden-xs hidden-sm">{{ $action->getTitle() }}</span>
                </a>
            @else
                @if($action instanceof \TCG\Voyager\Actions\ViewAction and $dataType->name === 'users' and isset($data->id) && Auth::user()->role_id === 1)
                    <a class="impersonate btn btn-sm btn-danger pull-right view" target="_blank" href="{{ route('admin.impersonate', ['id' => $data->id]) }}" title="{{ __("Impersonate") }}">
                        <i class="voyager-person"></i> <span class="hidden-xs hidden-sm">{{ __('Login') }}</span>
                    </a>
                @endif
                @if($action instanceof \TCG\Voyager\Actions\ViewAction and $dataType->name === 'user_verifies' and isset($data->id) && Auth::user()->role_id === 1)
                    <a class="impersonate btn btn-sm btn-danger pull-right view" target="_blank" href="{{route('profile',['username'=>\App\User::where('id', $data->user_id)->first()->username])}}" title="{{ __("Profile") }}">
                        <i class="voyager-person"></i> <span class="hidden-xs hidden-sm">{{ __('Profile') }}</span>
                    </a>
                @endif
                @if($action instanceof \TCG\Voyager\Actions\ViewAction and $dataType->name === 'posts' and isset($data->id) && Auth::user()->role_id === 1)
                    <a class="impersonate btn btn-sm btn-danger pull-right view" target="_blank" href="{{ route('posts.get', ['post_id' => $data->id, 'username' => $data->user->username]) }}" title="{{ __("Link") }}">
                        <i class="voyager-world"></i> <span class="hidden-xs hidden-sm">{{ __('Link') }}</span>
                    </a>
                @endif
                @if($action instanceof \TCG\Voyager\Actions\ViewAction and $dataType->name === 'public_pages' and isset($data->id))
                    <a class="impersonate btn btn-sm btn-danger pull-right view" target="_blank" href="{{route('pages.get',['slug' => $data->slug])}}" title="{{ __("Link") }}">
                        <i class="voyager-world"></i> <span class="hidden-xs hidden-sm">{{ __('Link') }}</span>
                    </a>
                @endif
                @if($action instanceof \TCG\Voyager\Actions\ViewAction and $dataType->name === 'streams' and isset($data->id))
                    <a class="impersonate btn btn-sm btn-danger pull-right view" target="_blank" href="{{route('public.stream.get',['streamID'=>$data->id,'slug'=>$data->slug])}}" title="{{ __("Link") }}">
                        <i class="voyager-world"></i> <span class="hidden-xs hidden-sm">{{ __('Link') }}</span>
                    </a>
                @endif
                @if($action instanceof \TCG\Voyager\Actions\ViewAction and $dataType->name === 'user_reports' and isset($data->id))
                    <div class="btn-group pull-right ml-half-1">
                        <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown"><i class="voyager-world"></i> <span class="hidden-xs hidden-sm">{{ __("Link") }} <span class="caret"></span></button>
                        <ul class="dropdown-menu" role="menu">
                            @php
                                try {
                                    if ($data->stream_id) {
                                        $type = 'stream';
                                        $internalUrl = rtrim(getSetting('site.app_url'), '/') . '/admin/streams/' . $data->stream_id;
                                        $frontEndUrl = route('public.stream.get', ['streamID' => $data->reportedStream->id, 'slug' => $data->reportedStream->slug]);
                                    } elseif ($data->message_id) {
                                        $type = 'message';
                                        $internalUrl = rtrim(getSetting('site.app_url'), '/') . '/admin/user-messages/' . $data->message_id;
                                    } elseif ($data->post_id) {
                                        $type = 'post';
                                        $internalUrl = rtrim(getSetting('site.app_url'), '/') . '/admin/user-posts/' . $data->post_id;
                                        $frontEndUrl = route('posts.get', ['post_id' => $data->post_id, 'username' => $data->reportedUser->username]);
                                    } else {
                                        $type = 'user';
                                        $internalUrl = rtrim(getSetting('site.app_url'), '/') . '/admin/users/' . $data->receiver_id;
                                        $frontEndUrl = route('profile', ['username' => $data->reportedUser->username]);
                                    }
                                } catch (\Exception $e) {
                                    $type = 'unknown';
                                    $internalUrl = '#';
                                    $frontEndUrl = '#';
                                }
                            @endphp
                            <li><a href="{{$internalUrl}}" target="_blank">Admin side</a></li>
                            @if($type !== 'message')
                                <li><a href="{{$frontEndUrl}}" target="_blank">User side</a></li>
                            @endif
                        </ul>
                    </div>
                @endif
                @if($action instanceof \TCG\Voyager\Actions\ViewAction and $dataType->name === 'withdrawals' and isset($data->id) && Auth::user()->role_id === 1 && $data->status === \App\Model\Withdrawal::REQUESTED_STATUS)
                    <div class="btn-group pull-right ml-half-1">
                        <button type="button" class="btn btn-success manage-button-dropdown dropdown-toggle-{{$data->id}}" data-toggle="dropdown"><i class="voyager-world"></i> <span class="hidden-xs hidden-sm">{{ __("Manage") }} <span class="caret"></span></button>
                        <ul class="dropdown-menu" role="menu">
                            <li>
                                <a class="impersonate btn btn-sm btn-danger pull-right view approve-withdrawal-button approve-button-{{$data->id}}" href="#" data-toggle="modal" data-target="#approve-withdrawal" data-value="{{$data->id}}">
                                    <i class="voyager-wallet"></i> <span class="hidden-xs hidden-sm">{{ __('Approve') }}</span>
                                </a>
                            </li>
                            <li>
                                <a class="reject-withdrawal btn btn-sm btn-danger pull-right view reject-button-{{$data->id}}" target="_blank" href="#" title="{{ __("Reject") }}" onclick="event.preventDefault(); Admin.rejectWithdrawal({{$data->id}})">
                                    <i class="voyager-power"></i> <span class="hidden-xs hidden-sm">{{ __('Reject') }}</span>
                                </a>
                            </li>
                        </ul>
                        <div class="modal fade" id="approve-withdrawal" tabindex="-1" role="dialog" aria-labelledby="approveWithdrawalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header text-center">
                                        <h3>{{__('Approve withdrawal')}}</h3>
                                    </div>
                                    <div class="modal-body text-center">
                                        {{__('By approving the withdrawal you accept sending the money to the user. If this withdrawal payment method is Stripe Connect then the money are sent to the user bank account linked with the Stripe connected account.')}}
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-dismiss="modal">{{__("Cancel")}}</button>
                                        <a class="approve-withdrawal btn btn-danger btn-ok" href="#" data-dismiss="modal" onclick="event.preventDefault(); Admin.approveWithdrawal()">
                                            {{ __('Approve') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
                <a href="{{ $action->getRoute($dataType->name) }}" title="{{ $action->getTitle() }}" {!! $action->convertAttributesToHtml() !!}>
                    <i class="{{ $action->getIcon() }}"></i> <span class="hidden-xs hidden-sm">{{ $action->getTitle() }}</span>
                </a>
            @endif
        @endif
    @endcan
@elseif (method_exists($action, 'massAction'))
    <form method="post" action="{{ route('voyager.'.$dataType->slug.'.action') }}" class="display-inline">
        {{ csrf_field() }}
        <button type="submit" {!! $action->convertAttributesToHtml() !!}><i class="{{ $action->getIcon() }}"></i>  {{ $action->getTitle() }}</button>
        <input type="hidden" name="action" value="{{ get_class($action) }}">
        <input type="hidden" name="ids" value="" class="selected_ids">
    </form>
@endif
