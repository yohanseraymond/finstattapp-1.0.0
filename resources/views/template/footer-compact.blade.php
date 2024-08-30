<footer class="d-none d-md-block">
    <!-- A grey container -->
    <div class="greycontainer">
        <!-- A black container -->
        <div class="blackcontainer">
            <!-- Container to indent the content -->
            <div class="container">
                <div class="copyRightInfo d-flex flex-column-reverse flex-md-row d-md-flex justify-content-md-between py-3">
                    <div class="d-flex align-items-center">
                        <p class="mb-0">&copy; {{date('Y')}} {{getSetting('site.name')}}. {{__('All rights reserved.')}}</p>
                    </div>
                    <div class="d-flex align-items-center">
                        <ul class="d-flex flex-row nav mb-0 footer-social-links">
                            @if(getSetting('social-links.facebook_url'))
                                <li class="nav-item">
                                    <a class="nav-link pe-1 ml-2" href="{{getSetting('social-links.facebook_url')}}" target="_blank">
                                        @include('elements.icon',['icon'=>'logo-facebook','variant'=>'medium','classes' => 'text-lg opacity-8'])
                                    </a>
                                </li>
                            @endif
                            @if(getSetting('social-links.twitter_url'))
                                <li class="nav-item">
                                    <a class="nav-link pe-1 ml-2" href="{{getSetting('social-links.twitter_url')}}" target="_blank">
                                        @include('elements.icon',['icon'=>'logo-twitter','variant'=>'medium','classes' => 'text-lg opacity-8'])
                                    </a>
                                </li>
                            @endif
                            @if(getSetting('social-links.instagram_url'))
                                <li class="nav-item">
                                    <a class="nav-link pe-1 ml-2" href="{{getSetting('social-links.instagram_url')}}" target="_blank">
                                        @include('elements.icon',['icon'=>'logo-instagram','variant'=>'medium','classes' => 'text-lg opacity-8'])
                                    </a>
                                </li>
                            @endif
                            @if(getSetting('social-links.whatsapp_url'))
                                <li class="nav-item">
                                    <a class="nav-link pe-1 ml-2" href="{{getSetting('social-links.whatsapp_url')}}" target="_blank">
                                        @include('elements.icon',['icon'=>'logo-whatsapp','variant'=>'medium','classes' => 'text-lg opacity-8'])
                                    </a>
                                </li>
                            @endif
                            @if(getSetting('social-links.tiktok_url'))
                                <li class="nav-item">
                                    <a class="nav-link pe-1 ml-2" href="{{getSetting('social-links.tiktok_url')}}" target="_blank">
                                        @include('elements.icon',['icon'=>'logo-tiktok','variant'=>'medium','classes' => 'text-lg opacity-8'])
                                    </a>
                                </li>
                            @endif
                            @if(getSetting('social-links.youtube_url'))
                                <li class="nav-item">
                                    <a class="nav-link pe-1 ml-2" href="{{getSetting('social-links.youtube_url')}}" target="_blank">
                                        @include('elements.icon',['icon'=>'logo-youtube','variant'=>'medium','classes' => 'text-lg opacity-8'])
                                    </a>
                                </li>
                            @endif
                            @if(getSetting('social-links.telegram_link'))
                                <li class="nav-item">
                                    <a class="nav-link pe-1 ml-2" href="{{getSetting('social-links.telegram_link')}}" target="_blank">
                                        @include('elements.icon',['icon'=>'paper-plane','variant'=>'medium','classes' => 'text-lg opacity-8'])
                                    </a>
                                </li>
                            @endif
                        </ul>



                    </div>

                </div>
            </div>
        </div>
    </div>
</footer>
