@extends('layouts.user-no-nav')
@section('page_title', $stream->name)


@section('styles')
    <link rel="stylesheet" href="{{asset('/libs/video.js/dist/video-js.min.css')}}">
    <link rel="stylesheet" href="{{asset('/css/player-theme.css')}}">
    {!!
        Minify::stylesheet([
            '/libs/dropzone/dist/dropzone.css',
            '/css/pages/checkout.css',
            '/css/pages/stream.css',
         ])->withFullUrl()
    !!}
@stop

@section('scripts')
    <script type="text/javascript" src="{{asset('/libs/video.js/dist/video.min.js')}}"></script>
    <script type="text/javascript" src="{{asset('/libs/videojs-contrib-quality-levels/dist/videojs-contrib-quality-levels.min.js')}}"></script>
    <script type="text/javascript" src="{{asset('/libs/videojs-http-source-selector/dist/videojs-http-source-selector.min.js')}}"></script>
    {!!
        Minify::javascript([
            '/libs/dropzone/dist/dropzone.js',
            '/js/FileUpload.js',
            '/js/pages/stream.js',
            '/js/pages/lists.js',
            '/libs/videojs-contrib-quality-levels/dist/videojs-contrib-quality-levels.min.js',
            '/libs/videojs-http-source-selector/dist/videojs-http-source-selector.min.js',
            '/libs/pusher-js-auth/lib/pusher-auth.js',
            '/js/pages/checkout.js',
         ])->withFullUrl()
    !!}
@stop

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="pt-4 d-flex justify-content-between align-items-center px-3 pb-3 border-bottom">
                <h5 class="text-truncate text-bold mb-0 {{(Cookie::get('app_theme') == null ? (getSetting('site.default_user_theme') == 'dark' ? '' : 'text-dark-r') : (Cookie::get('app_theme') == 'dark' ? '' : 'text-dark-r'))}}">{{$stream->name}}</h5>
                @if(!isset($streamEnded))
                    @if(StreamsHelper::getUserInProgressStream())
                        <button class="btn btn-outline-danger btn-sm px-3 mb-0 d-flex align-items-center" onclick="Streams.showStreamEditDialog('create')">
                            <div class="mr-1">{{__("Streaming")}}</div>
                            <div><div class="blob red"></div></div>
                        </button>
                    @endif
                @endif
            </div>
            <div class="px-3 pt-3">
                <div class="stream-wrapper row">
                    <div class="stream-video col-12">
                        @if($stream->canWatchStream)
                            <video id="my_video_1" class="video-js vjs-fluid vjs-theme-forest" controls preload="auto" autoplay muted>
                                <source src="{{isset($streamEnded) ? 'https://'.$stream->vod_link : $stream->hls_link}}" type="application/x-mpegURL">
                            </video>
                        @else
                            <div class="row d-flex justify-content-center align-items-center">
                                <div class="col-12">
                                    <div class="card p-5">
                                        <div class="p-4 p-md-5">
                                            <img src="{{asset('/img/live-stream-locked.svg')}}" class="stream-locked">
                                        </div>
                                        <div class="d-flex align-items-center justify-content-center" style="">
                                            <span>🔒 {{__("Live stream requires a")}} @if(isset($subLocked)) {{__("valid")}}
                                                <a href="javascript:void(0);" class="stream-subscribe-label to-tooltip"
                                                   @if(!GenericHelper::creatorCanEarnMoney($stream->user))
                                                   data-placement="top"
                                                   title="{{__('This creator cannot earn money yet')}}"
                                                   @endif
                                                >{{__("user subscription")}}</a>@endif
                                                @if(isset($priceLocked))
                                                    @if(isset($subLocked)){{__("and an")}}@endif <a href="javascript:void(0);" class="stream-unlock-label to-tooltip"
                                                                                                    @if(!GenericHelper::creatorCanEarnMoney($stream->user))
                                                                                                    data-placement="top"
                                                                                                    title="{{__('This creator cannot earn money yet')}}"
                                                    @endif
                                                    >{{__("one time fee")}}</a>
                                                @endif.
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card pb-3">
                    <div class="p-3">
                        @include('elements.streams.stream-details-banner')
                    </div>
                    @include('elements.streams.stream-chat')
                </div>


            </div>
        </div>

    @include('elements.checkout.checkout-box')
    @include('elements.report-user-or-post',['reportStatuses' => ListsHelper::getReportTypes()])

@stop
